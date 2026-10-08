<?php

declare(strict_types=1);

namespace LangChain\Tracers;

/**
 * A list of JSON Patch operations describing how to build the run state from an empty document.
 *
 * Port of `RunLogPatch` from `@langchain/core/tracers/log_stream`. This is the minimal representation
 * of the log, designed to be serialized as JSON and sent over the wire so the other side can rebuild
 * the state with any JSON Patch library.
 *
 * Operations are associative arrays `['op' => 'add'|'replace'|'remove', 'path' => string, 'value' => mixed]`.
 */
class RunLogPatch
{
    /** @var list<array{op: string, path: string, value?: mixed}> */
    public array $ops;

    /**
     * @param array{ops?: list<array{op: string, path: string, value?: mixed}>} $fields
     */
    public function __construct(array $fields = [])
    {
        $this->ops = $fields['ops'] ?? [];
    }

    public function concat(RunLogPatch $other): RunLog
    {
        $ops = array_merge($this->ops, $other->ops);

        /** @var array<string, mixed> $state */
        $state = RunLogPatchApplier::apply([], $ops);

        return new RunLog(['ops' => $ops, 'state' => $state]);
    }
}
