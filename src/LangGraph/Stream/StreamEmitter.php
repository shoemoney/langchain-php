<?php

declare(strict_types=1);

namespace LangGraph\Stream;

/**
 * The narrow capability handed to a transformer's `onRegister`: it can inject synthesized events and nothing
 * else (no close/fail/register).
 */
interface StreamEmitter
{
    /**
     * Inject an event into the mux pipeline. Every registered transformer (including the emitting one, which
     * must guard against re-entrant self-processing) sees it; `seq` is overwritten by the mux.
     *
     * @param list<string> $ns
     * @param array<string, mixed> $event
     */
    public function push(array $ns, array $event): void;
}
