<?php

declare(strict_types=1);

namespace LangGraph\Stream;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use LangGraph\Stream\Transformers\LifecycleTransformer;
use LangGraph\Stream\Transformers\MessagesTransformer;
use LangGraph\Stream\Transformers\SubgraphDiscoveryTransformer;
use LangGraph\Stream\Transformers\ValuesTransformer;

/**
 * The public run stream for a graph execution: protocol events plus ergonomic projections.
 *
 * Port of `GraphRunStream` and `createGraphRunStream` from `stream/run-stream.ts`, with the engine entry
 * point `Pregel.streamEvents(..., {version: "v3"})` as {@see self::create()}.
 *
 * Iterating the stream yields protocol events (see {@see Types}) at or below its namespace. Projections:
 *
 *  - {@see self::subgraphs()}   direct child {@see SubgraphRunStream}s as they are discovered
 *  - {@see self::values()}      state snapshots; {@see self::output()} is the final state
 *  - {@see self::messages()}    one {@see ChatModelStream} per AI message; {@see self::messagesFrom()} by node
 *  - {@see self::lifecycle()}   started / running / completed / interrupted / failed entries
 *  - {@see self::interrupted()}, {@see self::interrupts()}, {@see self::abort()}, {@see self::signal()}
 *  - {@see self::extensions()}  projections of user transformers; native transformer projections are read as
 *    properties (`$run->toolCalls`)
 *
 * KNOWN NON-EXACT BEHAVIOURS, all from PHP having no event loop:
 *
 *  - The run is PULL-DRIVEN. Upstream starts a background pump the moment the stream is created; here
 *    nothing runs until a consumer asks for more (iterating anything on the stream, or calling
 *    {@see self::output()}), and each request advances the engine one chunk. Event order is the engine's.
 *  - `values` is not a promise-like. `output()` is the awaitable half: it drives the run to completion and
 *    returns the final state, or throws the run's failure.
 *  - Abort is observed between chunks.
 *  - The engine stream of this port carries no subgraph namespaces (`Pregel::stream()` yields
 *    `[mode, payload]`). A run created by {@see self::create()} reports the root namespace for everything
 *    except `messages`, whose namespace is recovered from the chunk metadata. Subgraph-internal events are
 *    therefore not surfaced by `create()`. A source that carries `[namespace, mode, payload]` chunks, via
 *    {@see self::fromSource()}, gets full subgraph discovery and lifecycle.
 */
class RunStream implements \IteratorAggregate, StreamHandle
{
    /** @var list<string> */
    private readonly array $path;

    private readonly Deferred $valuesDone;

    private readonly AbortSignal $abortSignal;

    private ?StreamChannel $valuesLog = null;

    private ?\IteratorAggregate $messagesIterable = null;

    private ?\IteratorAggregate $lifecycleIterable = null;

    private ?\IteratorAggregate $subgraphsIterable = null;

    /** @var array<string, mixed> */
    private array $native = [];

    /**
     * @param list<string> $path namespace of this stream (empty for the root)
     * @param array<string, mixed> $extensions merged projections of the user's transformers
     */
    public function __construct(
        array $path,
        protected readonly Mux $mux,
        private readonly int $discoveryStart = 0,
        private readonly int $eventStart = 0,
        private readonly array $extensions = [],
        ?AbortSignal $abortSignal = null,
    ) {
        $this->path = $path;
        $this->abortSignal = $abortSignal ?? new AbortSignal();
        $this->valuesDone = new Deferred();
    }

    /**
     * Run a compiled graph and stream it as protocol events (`streamEvents` with `version: "v3"`).
     *
     * The graph is asked for every mode the protocol maps AND this port's engine can emit: `values`,
     * `updates`, `messages` (as protocol lifecycle events) and `tools`. `custom` and `tasks` are not
     * produced by this port's engine and are not requested.
     *
     * @param array<string, mixed>|list<mixed>|object|null $input
     * @param list<StreamTransformer|callable(): StreamTransformer> $transformers user transformers (instances or factories)
     */
    public static function create(
        Pregel $graph,
        mixed $input,
        ?RunnableConfig $config = null,
        array $transformers = [],
        ?AbortSignal $abortSignal = null,
    ): self {
        $abortSignal ??= new AbortSignal();
        $config ??= new RunnableConfig();
        $config = $config->with(['options' => array_merge($config->options, ['version' => 'v3'])]);
        if ($config->signal === null) {
            $config->signal = $abortSignal;
        }

        $modes = array_values(array_intersect(
            Convert::STREAM_EVENTS_V3_MODES,
            array_merge(Pregel::SUPPORTED_STREAM_MODES, Pregel::HANDLER_STREAM_MODES),
        ));

        $source = (static function () use ($graph, $input, $config, $modes): \Generator {
            $run = clone $graph;
            $run->streamMode = $modes;

            foreach ($run->stream($input, $config) as $chunk) {
                // The engine drops the namespace of a messages chunk, but the metadata it carries holds
                // the task's checkpoint namespace (`node:taskId`, `|`-joined for subgraphs), which is the
                // namespace upstream's subgraph-aware stream puts on the chunk. Recover it, so
                // `messages()` finds node-level messages exactly one level below the root.
                $namespace = $chunk[0] === 'messages' && \is_array($chunk[1] ?? null) && \is_array($chunk[1][1] ?? null)
                    ? ($chunk[1][1]['langgraph_checkpoint_ns'] ?? null)
                    : null;
                if (\is_string($namespace) && $namespace !== '') {
                    yield [explode(Constants::CHECKPOINT_NAMESPACE_SEPARATOR, $namespace), 'messages', $chunk[1]];

                    continue;
                }

                yield $chunk;
            }
        })();

        return self::fromSource($source, $transformers, $abortSignal);
    }

    /**
     * Build a run stream over a chunk source, with the built-in transformers registered
     * (`createGraphRunStream`). A chunk is `[namespace, mode, payload]` or `[mode, payload]`.
     *
     * Built-ins register in this order: subgraph discovery, lifecycle, values, messages. Discovery first, so
     * downstream transformers (notably lifecycle) see child namespaces with their handles in place; user
     * transformers follow.
     *
     * @param iterable<mixed> $source
     * @param list<StreamTransformer|callable(): StreamTransformer> $transformers
     */
    public static function fromSource(iterable $source, array $transformers = [], ?AbortSignal $abortSignal = null): self
    {
        $mux = new Mux();
        $pull = $mux->pull(...);

        // Lifecycle is created first so the discovery factory can close over its log to wire each child's
        // `.lifecycle` view.
        $lifecycle = new LifecycleTransformer();
        $lifecycleProjection = $lifecycle->init();
        $lifecycleLog = $mux->attach($lifecycleProjection['_lifecycleLog']);

        $discovery = new SubgraphDiscoveryTransformer(
            $mux,
            static function (array $path, int $discoveryStart, int $eventStart) use ($mux, $lifecycleLog): SubgraphRunStream {
                $sub = new SubgraphRunStream($path, $mux, $discoveryStart, $eventStart);
                $sub->setSubgraphsIterable(SubgraphDiscoveryTransformer::filterHandles($mux->discoveries, $path, $discoveryStart));
                // Skip lifecycle entries emitted before discovery (the root's `started`). The entry for this
                // discovery itself lands after the factory returns, so the child still receives its own.
                $sub->setLifecycleIterable(LifecycleTransformer::filterEntries($lifecycleLog, $path, $lifecycleLog->size()));

                return $sub;
            },
        );
        $subgraphsProjection = $discovery->init();

        $mux->addTransformer($discovery);
        $mux->addTransformer($lifecycle);

        $valuesTransformer = new ValuesTransformer([]);
        $valuesProjection = $valuesTransformer->init();
        $mux->attach($valuesProjection['_valuesLog']);
        $messagesTransformer = new MessagesTransformer([], null, $pull);
        $messagesProjection = $messagesTransformer->init();
        $mux->addTransformer($valuesTransformer);
        $mux->addTransformer($messagesTransformer);

        $extensions = [];
        $nativeProjections = [];
        foreach ($transformers as $factory) {
            $transformer = $factory instanceof StreamTransformer ? $factory : $factory();
            $mux->addTransformer($transformer);
            $projection = $transformer->init();
            if (Types::isNativeTransformer($transformer)) {
                $nativeProjections[] = $projection;
                // Native projections stay in process (never forwarded as protocol events), but their
                // channels still need the driver so iterating them advances the run.
                foreach (\is_array($projection) ? $projection : [] as $value) {
                    if ($value instanceof StreamChannel) {
                        $mux->attach($value);
                    }
                }

                continue;
            }
            if (\is_array($projection)) {
                $extensions = array_merge($extensions, $projection);
                $mux->wireChannels($projection);
            }
        }

        $abortSignal ??= new AbortSignal();
        $root = new static([], $mux, 0, 0, $extensions, $abortSignal);

        foreach ($nativeProjections as $projection) {
            if (\is_array($projection)) {
                $root->assignNative($projection);
            }
        }

        $root->setValuesLog($valuesProjection['_valuesLog']);
        $root->setMessagesIterable($messagesProjection['messages']);
        $root->setLifecycleIterable($lifecycleProjection['lifecycle']);
        $root->setSubgraphsIterable($subgraphsProjection['subgraphs']);

        $mux->register([], $root);

        $steps = Mux::pumpSteps(
            $source,
            $mux,
            static fn (): mixed => $abortSignal->aborted()
                ? ($abortSignal->reason() ?? new \RuntimeException('This operation was aborted'))
                : null,
        );
        $started = false;
        $mux->setPuller(static function () use ($steps, &$started): bool {
            if (!$started) {
                $started = true;
                $steps->current();

                return true;
            }
            if (!$steps->valid()) {
                return false;
            }
            $steps->next();

            return true;
        });

        return $root;
    }

    /** @return list<string> */
    public function path(): array
    {
        return $this->path;
    }

    /**
     * Merged projections from the user's transformers.
     *
     * @return array<string, mixed>
     */
    public function extensions(): array
    {
        return $this->extensions;
    }

    /**
     * Every protocol event at or below this stream's namespace, from its starting offset.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function getIterator(): \Generator
    {
        return $this->mux->subscribeEvents($this->path, $this->eventStart);
    }

    /**
     * Child {@see SubgraphRunStream}s of this namespace, as discovered.
     *
     * @return \IteratorAggregate<int, SubgraphRunStream>
     */
    public function subgraphs(): \IteratorAggregate
    {
        return $this->subgraphsIterable
            ?? SubgraphDiscoveryTransformer::filterHandles($this->mux->discoveries, $this->path, $this->discoveryStart);
    }

    /**
     * State snapshots as they arrive. Use {@see self::output()} for the final one.
     *
     * @return \IteratorAggregate<int, mixed>
     */
    public function values(): \IteratorAggregate
    {
        if ($this->valuesLog !== null) {
            return $this->valuesLog->toAsyncIterable();
        }

        $mux = $this->mux;
        $path = $this->path;
        $eventStart = $this->eventStart;

        return new ReplayableIterable(static function () use ($mux, $path, $eventStart): \Generator {
            foreach ($mux->subscribeEvents($path, $eventStart) as $event) {
                if ($event['method'] === 'values' && \count($event['params']['namespace']) === \count($path)) {
                    yield $event['params']['data'];
                }
            }
        });
    }

    /**
     * Every AI message lifecycle at this namespace level, in order.
     *
     * @return \IteratorAggregate<int, ChatModelStream>
     */
    public function messages(): \IteratorAggregate
    {
        if ($this->messagesIterable !== null) {
            return $this->messagesIterable;
        }
        // Lazily scoped to this stream's path, for subgraph streams the mux creates dynamically. Uses
        // addTransformer, which replays buffered events, so messages emitted before the first call are kept.
        $transformer = new MessagesTransformer($this->path, null, $this->mux->pull(...));
        $projection = $transformer->init();
        $this->mux->addTransformer($transformer);
        $this->messagesIterable = $projection['messages'];

        return $this->messagesIterable;
    }

    /**
     * Messages produced by one graph node.
     *
     * @return \IteratorAggregate<int, ChatModelStream>
     */
    public function messagesFrom(string $node): \IteratorAggregate
    {
        $transformer = new MessagesTransformer($this->path, $node, $this->mux->pull(...));
        $projection = $transformer->init();
        $this->mux->addTransformer($transformer);

        return $projection['messages'];
    }

    /**
     * Lifecycle entries (`started`, `completed`, ...) in emission order. Empty for a stream not built by
     * {@see self::fromSource()} and not wired.
     *
     * @return \IteratorAggregate<int, array<string, mixed>>
     */
    public function lifecycle(): \IteratorAggregate
    {
        return $this->lifecycleIterable ?? new ReplayableIterable(static function (): \Generator {
            yield from [];
        });
    }

    /**
     * The final state. Drives the run to completion.
     *
     * @throws \Throwable the run's failure
     * @throws \LogicException when the run cannot finish (no source attached and never resolved)
     */
    public function output(): mixed
    {
        while (!$this->valuesDone->isSettled() && $this->mux->pull()) {
            // Keep pulling.
        }

        return $this->valuesDone->value();
    }

    /** Whether the run ended on a human-in-the-loop interrupt. */
    public function interrupted(): bool
    {
        return $this->mux->interrupted();
    }

    /**
     * Interrupt payloads collected so far.
     *
     * @return list<array{interruptId: string, payload: mixed}>
     */
    public function interrupts(): array
    {
        return $this->mux->interrupts();
    }

    /** Abort the run; observed at the next chunk boundary. */
    public function abort(mixed $reason = null): void
    {
        $this->abortSignal->abort($reason);
    }

    public function signal(): AbortSignal
    {
        return $this->abortSignal;
    }

    public function resolveValues(mixed $values): void
    {
        $this->valuesDone->resolve($values);
    }

    public function rejectValues(mixed $error): void
    {
        $this->valuesDone->reject($error);
    }

    public function setValuesLog(StreamChannel $log): void
    {
        $this->valuesLog = $log;
    }

    public function setMessagesIterable(\IteratorAggregate $iterable): void
    {
        $this->messagesIterable = $iterable;
    }

    public function setLifecycleIterable(\IteratorAggregate $iterable): void
    {
        $this->lifecycleIterable = $iterable;
    }

    public function setSubgraphsIterable(\IteratorAggregate $iterable): void
    {
        $this->subgraphsIterable = $iterable;
    }

    /**
     * Expose a native transformer's projections directly on this stream, as `$run->name`.
     *
     * @param array<string, mixed> $projection
     */
    public function assignNative(array $projection): void
    {
        $this->native = array_merge($this->native, $projection);
    }

    public function __get(string $name): mixed
    {
        if (!\array_key_exists($name, $this->native)) {
            throw new \OutOfBoundsException(sprintf('Unknown run stream projection "%s".', $name));
        }

        return $this->native[$name];
    }

    public function __isset(string $name): bool
    {
        return isset($this->native[$name]);
    }
}
