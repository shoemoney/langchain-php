<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Func;

use LangChain\Runnables\RunnableConfig;
use LangChain\Utils\Await;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use PHPUnit\Framework\TestCase;

/**
 * Shared helpers for the functional-API suites ported from `langgraph-core/src/tests/func.test.ts`.
 *
 * JS awaits promises and `Promise.all`s them; the PHP tasks return settled promises, so the same
 * shape is `Await::sync()` and {@see self::all()}.
 */
abstract class FuncTestCase extends TestCase
{
    /** `[true]` and `[false]`: upstream's `describe.each([true, false])` over "with checkpointer". */
    public static function checkpointerModes(): array
    {
        return ['with checkpointer' => [true], 'without checkpointer' => [false]];
    }

    protected static function saver(bool $withCheckpointer): ?MemorySaver
    {
        return $withCheckpointer ? new MemorySaver() : null;
    }

    protected static function config(bool $withCheckpointer = true, string $threadId = '1'): RunnableConfig
    {
        return new RunnableConfig(configurable: $withCheckpointer ? ['thread_id' => $threadId] : []);
    }

    /** `await promise`. */
    protected static function await(mixed $promise): mixed
    {
        return Await::sync($promise);
    }

    /**
     * `Promise.all`.
     *
     * @param iterable<mixed> $promises
     * @return list<mixed>
     */
    protected static function all(iterable $promises): array
    {
        $out = [];
        foreach ($promises as $promise) {
            $out[] = Await::sync($promise);
        }

        return $out;
    }

    /**
     * Every `updates` chunk of a run.
     *
     * @return list<array<string, mixed>>
     */
    protected static function updates(Pregel $graph, mixed $input, RunnableConfig $config): array
    {
        $out = [];
        foreach ($graph->stream($input, $config) as [$mode, $chunk]) {
            if ($mode === 'updates') {
                $out[] = $chunk;
            }
        }

        return $out;
    }

    /**
     * The interrupts a run raised, as `updates` carries them.
     *
     * @return list<array{id: string|null, value: mixed}>
     */
    protected static function interruptsOf(Pregel $graph, mixed $input, RunnableConfig $config): array
    {
        $out = [];
        foreach (self::updates($graph, $input, $config) as $chunk) {
            foreach ($chunk[Constants::INTERRUPT] ?? [] as $interrupt) {
                $out[] = $interrupt;
            }
        }

        return $out;
    }
}
