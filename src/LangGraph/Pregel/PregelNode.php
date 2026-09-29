<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableInterface;
use LangChain\Runnables\RunnableLambda;
use LangChain\Runnables\RunnableSequence;

/**
 * A node, described the way the *engine* sees it rather than the way a user does.
 *
 * Port of `PregelNode` from `langgraph-core/src/pregel/read.ts`.
 *
 * A user adds a node as a function. The engine needs five extra facts the
 * function does not carry:
 *
 *  - **which channels it subscribes to** (`channels`) and **which of those are
 *    triggers** (`triggers`). A PULL task is only scheduled when a *trigger*
 *    advances; subscribing to a channel without triggering on it lets a node
 *    read state it did not wake for.
 *  - **how to map channel values to the node's input** (`mapper`), for a node
 *    that takes a single channel rather than the whole state.
 *  - **what to write afterwards** (`writers`) — usually a `ChannelWrite` that
 *    persists the node's return value.
 *  - **policy**: retry, cache, timeout, tags, metadata.
 *
 * The split between `bound` and `writers` is what lets one node both compute and
 * publish. `bound` is the user's runnable; `writers` are the engine's
 * bookkeeping runnables chained after it. {@see self::getNode()} composes them
 * into the single runnable the runner actually invokes.
 *
 * Unlike the TS original, this does **not** extend `RunnableBinding`. A
 * `RunnableBinding` requires a bound runnable, and the engine creates nodes with
 * no bound runnable at all: the `__start__` node exists only to publish input,
 * and branch nodes exist only to route. Those are not malformed bindings, they
 * are nodes whose whole body is a writer. So `bound` is nullable here and
 * {@see self::getNode()} handles the writer-only case explicitly.
 */
class PregelNode
{
    public string $lcGraphName = 'PregelNode';

    /**
     * @param array<string, string>|list<string> $channels  Subscribed channels.
     * @param list<string>                        $triggers Channels that schedule this node.
     * @param RunnableInterface|null              $bound    The user's runnable.
     * @param list<RunnableInterface>             $writers  Post-node runnables.
     * @param callable|null                       $mapper   Channel values -> node input.
     * @param list<string>                        $tags     Trace labels.
     * @param array<string, mixed>                $metadata Extra metadata.
     */
    public function __construct(
        public array $channels = [],
        public array $triggers = [],
        public ?RunnableInterface $bound = null,
        public array $writers = [],
        public mixed $mapper = null,
        public array $tags = [],
        public array $metadata = [],
        public array $kwargs = [],
        public ?Retry\RetryPolicy $retryPolicy = null,
        public ?array $cachePolicy = null,
        public mixed $timeout = null,
        public array $subgraphs = [],
        public array $ends = [],
        public bool $isErrorHandler = false,
        public ?string $errorHandlerNode = null,
        public ?array $config = null,
    ) {
    }

    public function getName(): string
    {
        return 'PregelNode';
    }

    /**
     * The writers to run, with consecutive `ChannelWrite`s collapsed into one.
     *
     * Port of `getWriters`. Two adjacent writers writing the same channels
     * become one writer, because each `ChannelWrite` is a separate step in a
     * traced run and the intermediate state is never observable — the engine
     * collects all writes and applies them together. Collapsing is a
     * readability and tracing win, not a semantic one.
     *
     * @return list<RunnableInterface>
     */
    public function getWriters(): array
    {
        $newWriters = $this->writers;

        while (count($newWriters) > 1
            && $newWriters[count($newWriters) - 1] instanceof ChannelWrite
            && $newWriters[count($newWriters) - 2] instanceof ChannelWrite
        ) {
            $last = array_pop($newWriters);
            $secondLast = array_pop($newWriters);
            \assert($last instanceof ChannelWrite);
            \assert($secondLast instanceof ChannelWrite);

            $newWriters[] = new ChannelWrite(
                array_merge($secondLast->writes, $last->writes),
                $secondLast->tags
            );
        }

        return $newWriters;
    }

    /**
     * The single runnable that represents this node to the runner.
     *
     * Port of `getNode`. `bound` first, then the writers chained onto it. When
     * there is no `bound` (a node that exists only to write — the `__start__`
     * and branch nodes), the writers *are* the node.
     *
     * Returning null would mean "nothing to run"; every node in a compiled
     * graph has at least a writer, so that does not occur in practice.
     */
    public function getNode(): ?RunnableInterface
    {
        $writers = $this->getWriters();
        $hasBound = $this->bound !== null;

        if (!$hasBound) {
            if (count($writers) === 0) {
                return null;
            }
            if (count($writers) === 1) {
                return $writers[0];
            }

            return new RunnableSequence($writers);
        }

        if (count($writers) === 0) {
            return $this->bound;
        }

        return new RunnableSequence(array_merge([$this->bound], $writers));
    }

    /**
     * Subscribe this node to more channels, keeping the existing mapping.
     *
     * Port of `join`. Only valid for a node whose channels are a *map* — a
     * positional list has no names to join onto.
     *
     * @param list<string> $channels
     */
    public function join(array $channels): self
    {
        if (array_is_list($this->channels)) {
            throw new \InvalidArgumentException('all channels must be named when using join()');
        }

        /** @var array<string, string> $joined */
        $joined = $this->channels;
        foreach ($channels as $channel) {
            $joined[$channel] = $channel;
        }

        return new self(
            channels: $joined,
            triggers: $this->triggers,
            bound: $this->bound,
            writers: $this->writers,
            mapper: $this->mapper,
            tags: $this->tags,
            metadata: $this->metadata,
            kwargs: $this->kwargs,
            retryPolicy: $this->retryPolicy,
            cachePolicy: $this->cachePolicy,
            timeout: $this->timeout,
            subgraphs: $this->subgraphs,
            ends: $this->ends,
            isErrorHandler: $this->isErrorHandler,
            errorHandlerNode: $this->errorHandlerNode,
            config: $this->config,
        );
    }
}
