<?php

declare(strict_types=1);

namespace LangGraph\Graph;

use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableInterface;
use LangChain\Utils\Notice;
use LangGraph\Errors\InvalidUpdateError;
use LangGraph\Errors\NodeInterrupt;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Send;
use LangGraph\Utils\RunnableCallable;

use function LangChain\Runnables\coerceToRunnable;

/**
 * A conditional edge: a path function plus an optional map from its answers to destinations.
 *
 * Port of `Branch` from `langgraph-core/src/graph/graph.ts`.
 *
 * A `Branch` owns the two things every conditional edge needs regardless of how the compiled graph
 * names its channels: evaluating the path, and turning its answer into a validated list of
 * destinations ({@see self::destinations()}). What a destination *becomes* is the compiler's business,
 * so it is injected: {@see self::run()} takes a `$writer`, exactly as upstream does, and
 * {@see \LangGraph\Pregel\RunnableBranchWriter} is the `StateGraph` flavour of that writer. Both go
 * through `destinations()`, so path evaluation, `pathMap` lookup and the unknown-destination check
 * exist in one place only.
 */
final class Branch
{
    public readonly RunnableInterface $path;

    /** @var array<string, string>|null Answer => destination, or null when the path names destinations directly. */
    public readonly ?array $ends;

    /**
     * @param callable|RunnableInterface                $path    Maps the source node's output to destinations.
     * @param array<string, string>|list<string>|null   $pathMap A list means "each name routes to itself".
     */
    public function __construct(callable|RunnableInterface $path, ?array $pathMap = null)
    {
        $this->path = coerceToRunnable($path);

        if ($pathMap === null) {
            $this->ends = null;
        } elseif (array_is_list($pathMap)) {
            $ends = [];
            foreach ($pathMap as $name) {
                $ends[(string) $name] = (string) $name;
            }
            $this->ends = $ends;
        } else {
            $this->ends = array_map(strval(...), $pathMap);
        }
    }

    /**
     * Build a branch from upstream's `BranchOptions` shape (`source` is ignored here, as upstream does).
     *
     * @param array{path: callable|RunnableInterface, pathMap?: array<string, string>|list<string>|null, source?: string} $options
     */
    public static function fromOptions(array $options): self
    {
        return new self($options['path'], $options['pathMap'] ?? null);
    }

    /**
     * Wrap this branch as a runnable that evaluates the path and hands the destinations to `$writer`.
     *
     * @param callable(list<string|Send>, RunnableConfig): (RunnableInterface|null) $writer
     * @param (callable(RunnableConfig): mixed)|null                                $reader Replaces the node output as path input.
     */
    public function run(callable $writer, ?callable $reader = null): RunnableInterface
    {
        return new RunnableCallable(
            func: function (mixed $input, RunnableConfig $config) use ($writer, $reader): mixed {
                try {
                    return $this->route($input, $config, $writer, $reader);
                } catch (NodeInterrupt $e) {
                    Notice::record(
                        "[WARN]: 'NodeInterrupt' thrown in conditional edge. This is likely a bug in your graph implementation.\n"
                        . 'NodeInterrupt should only be thrown inside a node, not in edge conditions.'
                    );
                    throw $e;
                }
            },
            name: '<branch_run>',
            trace: false,
        );
    }

    /**
     * Evaluate the path, validate the destinations, and give them to `$writer`.
     *
     * Port of `Branch._route`. A writer may return a runnable (typically a `ChannelWrite`), which
     * `RunnableCallable` then runs with the same input; returning nothing passes the input through.
     *
     * @param callable(list<string|Send>, RunnableConfig): (RunnableInterface|null) $writer
     * @param (callable(RunnableConfig): mixed)|null                                $reader
     */
    public function route(mixed $input, RunnableConfig $config, callable $writer, ?callable $reader = null): mixed
    {
        $destinations = $this->destinations($reader !== null ? $reader($config) : $input, $config);

        $writeResult = $writer($destinations, $config);

        return $writeResult ?? $input;
    }

    /**
     * Run the path and resolve its answer to destinations.
     *
     * An answer of `null`, `false`, `''` or `[]` means "go nowhere" and yields no destinations. Upstream
     * throws for a bare `null`; this port keeps the long-standing `StateGraph` behaviour that a
     * conditional edge may decline to continue. A `null`/empty entry INSIDE a list, or an answer missing
     * from `pathMap`, still throws.
     *
     * @return list<string|Send>
     */
    public function destinations(mixed $input, ?RunnableConfig $config = null): array
    {
        $result = $this->path->invoke($input, $config);

        if ($result === null || $result === false || $result === '' || $result === []) {
            return [];
        }

        $answers = is_array($result) && array_is_list($result) ? $result : [$result];

        $destinations = [];
        foreach ($answers as $answer) {
            if ($answer instanceof Send) {
                $destinations[] = $answer;
            } elseif ($this->ends !== null) {
                $mapped = is_string($answer) || is_int($answer) ? ($this->ends[$answer] ?? null) : null;
                if ($mapped === null || $mapped === '') {
                    throw new \InvalidArgumentException('Branch condition returned unknown or null destination');
                }
                $destinations[] = $mapped;
            } else {
                if ($answer === null || $answer === false || $answer === '') {
                    throw new \InvalidArgumentException('Branch condition returned unknown or null destination');
                }
                $destinations[] = $answer;
            }
        }

        foreach ($destinations as $dest) {
            if ($dest instanceof Send && $dest->node === Constants::END) {
                throw new InvalidUpdateError('Cannot send a packet to the END node');
            }
        }

        return $destinations;
    }
}
