<?php

declare(strict_types=1);

namespace LangChain\Utils\Testing;

use LangChain\Tracers\BaseTracer;
use LangChain\Tracers\Run;

/**
 * A tracer that records every completed root run in memory.
 *
 * Port of `FakeTracer` from `@langchain/core/utils/testing`.
 *
 * This is the tracer the ported tool tests assert against: it lets a test check
 * what a tool actually received — most importantly, that structured arguments
 * stay structured in the run record rather than being stringified into
 * something unassertable.
 */
final class FakeTracer extends BaseTracer
{
    public string $name = 'fake_tracer';

    /** @var list<Run> Every persisted root run, in completion order. */
    public array $runs = [];

    protected function persistRun(Run $run): void
    {
        $this->runs[] = $run;
    }
}
