<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Utils;

/**
 * Port of `utils/signals.ts`.
 *
 * PHP has no `AbortSignal`. Following {@see AsyncCaller::callWithOptions()}, a signal here is a
 * `callable(): bool` that answers "has this been aborted?" and is polled before opening attempts and between reads; it cannot
 * interrupt a read already blocked on the network. Merging therefore yields a callable that is true
 * as soon as ANY input is, which is what the JS controller wired to each source's `abort` event
 * amounts to. JS also forwards the abort `reason`; a boolean has none to forward.
 */
final class Signals
{
    /**
     * @param (callable(): bool)|null ...$signals
     *
     * @return (callable(): bool)|null null when there is nothing to merge
     */
    public static function mergeSignals(?callable ...$signals): ?callable
    {
        $nonNull = array_values(array_filter($signals, static fn (?callable $s): bool => $s !== null));

        if ($nonNull === []) {
            return null;
        }
        if (count($nonNull) === 1) {
            return $nonNull[0];
        }

        $aborted = false;

        return static function () use ($nonNull, &$aborted): bool {
            if ($aborted) {
                return true;
            }
            foreach ($nonNull as $signal) {
                if ($signal()) {
                    $aborted = true;

                    return true;
                }
            }

            return false;
        };
    }

    /**
     * PHP's polled adaptation of `AbortSignal.timeout`: a monotonic deadline,
     * observed when the callable is polled. No timer interrupts synchronous IO.
     * The optional clock returns milliseconds and makes deadline checks deterministic.
     *
     * @param (callable(): int|float)|null $clock
     *
     * @return callable(): bool
     */
    public static function timeoutSignal(int $timeoutMs, ?callable $clock = null): callable
    {
        if ($timeoutMs < 0) {
            throw new \InvalidArgumentException('timeoutMs must be non-negative.');
        }

        $clock ??= static fn (): float => hrtime(true) / 1_000_000;
        $deadline = $clock() + $timeoutMs;

        return static fn (): bool => $clock() >= $deadline;
    }
}
