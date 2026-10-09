<?php

declare(strict_types=1);

namespace LangGraph\Stream;

/**
 * Observes protocol events during a run and builds derived projections.
 *
 * Port of the `StreamTransformer` interface in `stream/types.ts`. Only `init()` and `process()` are required.
 * The optional hooks are looked up by the mux with `method_exists`, as upstream's `?:` members are:
 *
 *  - `onRegister(StreamEmitter $emitter): void` called when the mux attaches the transformer;
 *  - `finalize(): mixed` the run completed (a returned {@see Deferred} is ignored: there is no event loop to wait on);
 *  - `fail(mixed $error): void` the run failed.
 *
 * Extend {@see AbstractStreamTransformer} to get no-op defaults.
 */
interface StreamTransformer
{
    /**
     * Called once before the run starts. The returned projection is merged into the run stream's extensions;
     * named {@see StreamChannel}s in it are auto-forwarded by the mux, {@see Deferred}s are flushed on close.
     */
    public function init(): mixed;

    /**
     * Called for each protocol event before it joins the main log.
     *
     * @param array<string, mixed> $event
     * @return bool false drops the event from the main log
     */
    public function process(array $event): bool;
}
