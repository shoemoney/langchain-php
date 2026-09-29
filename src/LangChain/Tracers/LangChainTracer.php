<?php

declare(strict_types=1);

namespace LangChain\Tracers;

/**
 * The tracer enabled by `LANGCHAIN_TRACING`, keyed by name `langchain_tracer`.
 *
 * In the TypeScript original this posts every run to LangSmith over HTTP via the
 * `langsmith` client. That transport is **not** part of this port: `langsmith` is
 * a separate npm package with its own retry, batching, and multipart-upload
 * machinery, and the port does not include it.
 *
 * What is ported is everything up to the point of persistence — the run tree, the
 * dotted orders, the trace ids, and the collection surface — which is also all
 * that is needed to make tracing observable from PHP. This subclass therefore
 * records runs in memory and exposes them through {@see self::$tracedRuns}.
 *
 * Subclass and override {@see self::persistRun()} to ship runs somewhere real;
 * that is the single seam the upstream HTTP client plugs into.
 */
class LangChainTracer extends BaseTracer
{
    public string $name = 'langchain_tracer';

    /** @var list<Run> Completed root runs, awaiting delivery. */
    public array $tracedRuns = [];

    protected function persistRun(Run $run): void
    {
        $this->tracedRuns[] = $run;
    }
}
