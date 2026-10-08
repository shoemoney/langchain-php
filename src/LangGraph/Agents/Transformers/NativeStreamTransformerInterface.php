<?php

declare(strict_types=1);

namespace LangGraph\Agents\Transformers;

/**
 * A native stream transformer: consumes protocol events and exposes projections.
 *
 * Counterpart of langgraph's `NativeStreamTransformer`, which PHP's Pregel has no run stream to host, so the
 * contract lives next to the transformers that use it.
 *
 * A protocol event is an array `['method' => string, 'params' => ['namespace' => list<string>, 'data' => mixed]]`
 * (`method` is `messages`, `tools`, `tasks`, `values`, `lifecycle`, ...).
 */
interface NativeStreamTransformerInterface
{
    /**
     * The projections this transformer exposes, keyed by name (e.g. `['toolCalls' => StreamChannel]`).
     *
     * @return array<string, StreamChannel>
     */
    public function init(): array;

    /**
     * Consume one event.
     *
     * @param array{method: string, params: array{namespace: list<string>, data: mixed}} $event
     * @return bool true to keep the event flowing to later transformers
     */
    public function process(array $event): bool;

    /** The run completed: settle every pending projection and close the channels. */
    public function finalize(): void;

    /** The run failed: reject every pending projection and fail the channels. */
    public function fail(mixed $error): void;
}
