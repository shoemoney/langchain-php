<?php

declare(strict_types=1);

namespace LangChain\Tracers;

/**
 * A {@see RunLogPatch} together with the state it produces.
 *
 * Port of `RunLog` from `@langchain/core/tracers/log_stream`. `state` is the run state document:
 * `id`, `name`, `type`, `streamed_output` (chunks from the root run), `final_output` and `logs`
 * (sub-run `LogEntry` arrays keyed by run name, with `:2`, `:3` ... suffixes for repeated names).
 */
final class RunLog extends RunLogPatch
{
    /** @var array<string, mixed> */
    public array $state;

    /**
     * @param array{ops?: list<array{op: string, path: string, value?: mixed}>, state: array<string, mixed>} $fields
     */
    public function __construct(array $fields)
    {
        parent::__construct($fields);
        $this->state = $fields['state'];
    }

    public function concat(RunLogPatch $other): RunLog
    {
        $ops = array_merge($this->ops, $other->ops);

        /** @var array<string, mixed> $state */
        $state = RunLogPatchApplier::apply($this->state, $other->ops);

        return new RunLog(['ops' => $ops, 'state' => $state]);
    }

    public static function fromRunLogPatch(RunLogPatch $patch): self
    {
        /** @var array<string, mixed> $state */
        $state = RunLogPatchApplier::apply([], $patch->ops);

        return new self(['ops' => $patch->ops, 'state' => $state]);
    }
}
