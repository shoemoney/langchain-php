<?php

declare(strict_types=1);

namespace LangChain\Tracers;

/**
 * A tracer that records completed root runs in memory.
 *
 * Port of `RunCollectorCallbackHandler` from `@langchain/core/tracers/run_collector`.
 *
 * This is the tracer you reach for when you want to assert on a run after the
 * fact — which run ids were minted, how many children a chain produced, what a
 * tool received as input — without standing up a LangSmith project.
 *
 * `exampleId` stamps `reference_example_id` onto every collected run, which is
 * what ties a traced execution back to a dataset row. It is null rather than
 * absent by default so the key is always present and consumers do not have to
 * branch on it.
 */
final class RunCollectorCallbackHandler extends BaseTracer
{
    public string $name = 'run_collector';

    public ?string $exampleId;

    /** @var list<Run> Every persisted root run, in completion order. */
    public array $tracedRuns = [];

    public function __construct(?string $exampleId = null)
    {
        $this->exampleId = $exampleId;
        parent::__construct(['awaitHandlers' => true]);
    }

    protected function persistRun(Run $run): void
    {
        $run->extra['reference_example_id'] = $this->exampleId;
        $this->tracedRuns[] = $run;
    }
}
