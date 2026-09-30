<?php

declare(strict_types=1);

namespace LangChain\Tracers;

/**
 * A single traced run: an LLM call, a chain, a tool, or a retriever.
 *
 * Port of the `Run` interface from `@langchain/core/tracers/base`. Every field
 * is public and mutable because that is what the tracers do — `handleLLMStart`
 * creates the run, `handleLLMEnd` fills in `endTime`/`outputs`/`events`, and
 * `_endTrace` files it away.
 *
 * `traceId` and `dottedOrder` are what turn a flat list of runs into a tree.
 * `dottedOrder` is a lexicographically sortable path built from the timestamp,
 * the run id, and the execution order, so sorting runs by it yields a
 * pre-order traversal without any pointer chasing.
 */
final class Run
{
    /**
     * @param string                    $id
     * @param string|null               $parentRunId
     * @param float                     $startTime     Epoch milliseconds.
     * @param float|null                $endTime       Epoch milliseconds; null until the run ends.
     * @param array<string, mixed>      $serialized    The component that produced this run.
     * @param list<RunEvent>            $events
     * @param array<string, mixed>      $inputs
     * @param array<string, mixed>      $outputs
     * @param int                       $executionOrder  Position among siblings; a root run is 1.
     * @param list<Run>                 $childRuns
     * @param int                       $childExecutionOrder  Highest child execution order seen.
     * @param string|null               $runType      'llm' | 'chain' | 'tool' | 'retriever'.
     * @param array<string, mixed>      $extra        Provider extras, metadata, tracer overrides.
     * @param list<string>              $tags
     * @param string|null               $error
     * @param string|null               $traceId
     * @param string|null               $dottedOrder
     * @param string|null               $serializedStartTime
     * @param list<array<string, mixed>> $actions     Agent actions taken during this run.
     */
    public function __construct(
        public string $id,
        public ?string $parentRunId = null,
        public float $startTime = 0.0,
        public ?float $endTime = null,
        public array $serialized = [],
        public array $events = [],
        public array $inputs = [],
        public array $outputs = [],
        public int $executionOrder = 1,
        public array $childRuns = [],
        public int $childExecutionOrder = 1,
        public string $runType = 'chain',
        public array $extra = [],
        public array $tags = [],
        public ?string $error = null,
        public ?string $traceId = null,
        public ?string $dottedOrder = null,
        public ?string $serializedStartTime = null,
        public array $actions = [],
    ) {
    }

    /**
     * Append an event stamped with the current time.
     *
     * @param array<string, mixed> $kwargs
     */
    public function pushEvent(string $name, array $kwargs = []): void
    {
        $this->events[] = new RunEvent($name, self::isoNow(), $kwargs);
    }

    /**
     * `Date.now()` as an ISO-8601 string, the format LangSmith expects.
     */
    public static function isoNow(): string
    {
        return gmdate('Y-m-d\TH:i:s.v\Z');
    }

    /**
     * The dotted-order format for one run: `YYYYMMDDTHHMMSS{order:06d}Z{runId}`.
     *
     * Port of `convertToDottedOrderFormat` from the `langsmith` package, which
     * the TypeScript tracers delegate to. Two runs started in the same
     * millisecond have the same 14-character timestamp prefix, so the field
     * that orders them is the six-digit zero-padded execution order — which is
     * why sorting this string as text reproduces the tree traversal order
     * exactly. The run id is appended last, purely to break ties between two
     * siblings that share an execution order (only possible across a
     * re-rooted tree).
     */
    public static function dottedOrder(float $timestampMs, string $runId, int $executionOrder): string
    {
        return sprintf(
            '%s%06dZ%s',
            gmdate('Ymd\THis', (int) floor($timestampMs / 1000)),
            $executionOrder,
            $runId,
        );
    }

    /**
     * The timestamp half of {@see self::dottedOrder()}, as an ISO-8601 string
     * whose microsecond field carries the execution order — the same ordering
     * information as the dotted order, in a form a client can read.
     *
     * Sub-millisecond precision in a run's start time is not information we
     * have (JS `Date.now()` is milliseconds), and inventing it would make two
     * sibling runs claim to have started at distinguishable instants when they
     * provably have not. Borrowing the execution order keeps start times
     * consistent with dotted order, which is the property downstream sorting
     * actually relies on.
     */
    public static function microsecondPrecisionDatestring(float $timestampMs, int $executionOrder): string
    {
        return gmdate('Y-m-d\TH:i:s', (int) floor($timestampMs / 1000))
            . sprintf('.%06dZ', $executionOrder);
    }

    /**
     * The flat array form the tracer tests compare against.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name(),
            'parent_run_id' => $this->parentRunId,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'serialized' => $this->serialized,
            'events' => array_map(static fn (RunEvent $e): array => $e->toArray(), $this->events),
            'inputs' => $this->inputs,
            'outputs' => $this->outputs,
            'execution_order' => $this->executionOrder,
            'child_runs' => array_map(static fn (Run $r): array => $r->toArray(), $this->childRuns),
            'child_execution_order' => $this->childExecutionOrder,
            'run_type' => $this->runType,
            // `actions` was a documented field of the run, written by
            // `BaseTracer::handleAgentAction` and read by the console handler,
            // yet absent from the serialised form — so a persisted run lost
            // every agent step it had taken. Upstream's `Run` carries it as a
            // public field, which any serialisation of the run includes.
            'actions' => $this->actions,
            'extra' => $this->extra,
            'tags' => $this->tags,
            'error' => $this->error,
            'trace_id' => $this->traceId,
            'dotted_order' => $this->dottedOrder,
            '_serialized_start_time' => $this->serializedStartTime,
        ];
    }

    /**
     * The run's display name: the explicit `runName` if one was passed, else the
     * last segment of the serialized component id.
     *
     * @param array<string, mixed> $serialized
     */
    public function name(array $serialized = []): string
    {
        $explicit = $this->extra['__name'] ?? null;
        if (is_string($explicit) && $explicit !== '') {
            return $explicit;
        }

        $id = $serialized['id'] ?? $this->serialized['id'] ?? null;
        if (is_array($id) && $id !== []) {
            return (string) end($id);
        }

        return $this->runType;
    }
}
