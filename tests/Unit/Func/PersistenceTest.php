<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Func;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Func\EntrypointFinal;
use LangGraph\Func\Func;
use LangGraph\Pregel\Constants;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Port of `describe("persistence")` from `langgraph-core/src/tests/func.test.ts`, plus the
 * previous-state cases the engine wiring has to get right: `getPreviousState()` reads
 * `CONFIG_KEY_PREVIOUS_STATE`, which the loop fills from the `PREVIOUS` channel of the
 * checkpoint a task is prepared against.
 */
#[CoversClass(Func::class)]
#[CoversClass(EntrypointFinal::class)]
final class PersistenceTest extends FuncTestCase
{
    public function testCanReturnAFinalValueSeparatelyFromThePersistedValue(): void
    {
        $checkpointer = new MemorySaver();
        $graph = Func::entrypoint(
            ['name' => 'graph', 'checkpointer' => $checkpointer],
            static function (int $input) {
                $previous = Func::getPreviousState();

                return Func::final(value: $input + ($previous ?? 0), save: $input);
            },
        );
        $config = self::config();

        $first = $graph->invoke(1, $config);
        $second = $graph->invoke(2, $config);
        $third = $graph->invoke(3, $config);

        // Upstream reads the head checkpoint through `getState().config`; `getState()` cannot
        // snapshot a scalar result here (`StateSnapshot::$values` is array-typed), so the saver
        // is asked directly for the head of the thread.
        $saved = $checkpointer->getTuple(['configurable' => ['thread_id' => '1']]);

        self::assertSame(1, $first);
        self::assertSame(3, $second);
        self::assertSame(5, $third);
        self::assertSame(3, $saved->checkpoint->channelValues[Constants::PREVIOUS]);
    }

    public function testStoresPreviousReturnedValueInState(): void
    {
        $previousStates = [];
        $graph = Func::entrypoint(
            ['name' => 'graph', 'checkpointer' => new MemorySaver()],
            static function (array $inputs) use (&$previousStates): array {
                $previous = Func::getPreviousState();
                $previousStates[] = $previous;

                return $previous === null
                    ? ['current' => $inputs]
                    : ['previous' => $previous, 'current' => $inputs];
            },
        );
        $config = self::config();

        self::assertSame(['current' => ['a' => '1']], $graph->invoke(['a' => '1'], $config));
        self::assertSame(
            ['previous' => ['current' => ['a' => '1']], 'current' => ['a' => '2']],
            $graph->invoke(['a' => '2'], $config),
        );
        self::assertSame(
            [
                'previous' => ['previous' => ['current' => ['a' => '1']], 'current' => ['a' => '2']],
                'current' => ['a' => '3'],
            ],
            $graph->invoke(['a' => '3'], $config),
        );

        // A new thread sees no previous state; the same thread sees what it saved.
        $other = self::config(threadId: '0');
        self::assertSame(['current' => ['a' => '4']], $graph->invoke(['a' => '4'], $other));
        self::assertSame(
            ['previous' => ['current' => ['a' => '4']], 'current' => ['a' => '5']],
            $graph->invoke(['a' => '5'], $other),
        );

        self::assertSame(
            [
                null,
                ['current' => ['a' => '1']],
                ['previous' => ['current' => ['a' => '1']], 'current' => ['a' => '2']],
                null,
                ['current' => ['a' => '4']],
            ],
            $previousStates,
        );
    }

    public function testStoresPreviousReturnedValueInStateAllowsUpdatingState(): void
    {
        $previousStates = [];
        $graph = Func::entrypoint(
            ['name' => 'graph', 'checkpointer' => new MemorySaver()],
            static function (array $inputs) use (&$previousStates): array {
                $previous = Func::getPreviousState();
                $previousStates[] = $previous;

                return ['previous' => $previous, 'current' => $inputs];
            },
        );
        $config = self::config();

        $graph->updateState($config, ['a' => -1]);
        self::assertSame(['previous' => ['a' => -1], 'current' => ['a' => '1']], $graph->invoke(['a' => '1'], $config));

        $second = $graph->invoke(['a' => '2'], $config);
        self::assertSame(['a' => '2'], $second['current']);
        self::assertSame(['previous' => ['a' => -1], 'current' => ['a' => '1']], $second['previous']);

        $graph->invoke(['a' => '3'], $config);

        $graph->updateState($config, ['a' => 3]);
        self::assertSame(['previous' => ['a' => 3], 'current' => ['a' => '4']], $graph->invoke(['a' => '4'], $config));

        self::assertSame(['a' => -1], $previousStates[0]);
        self::assertSame(['a' => 3], $previousStates[3]);
    }

    public function testPreviousStateIsNullWithoutACheckpointer(): void
    {
        $seen = [];
        $graph = Func::entrypoint(['name' => 'graph'], static function (string $in) use (&$seen): string {
            $seen[] = Func::getPreviousState();

            return $in;
        });

        $graph->invoke('a');
        $graph->invoke('b');

        self::assertSame([null, null], $seen);
    }

    public function testATaskSeesThePreviousStateToo(): void
    {
        // The task runs as its own PUSH task: its config must carry the previous state as well.
        $inTask = [];
        $probe = Func::task('probe', static function () use (&$inTask): mixed {
            $inTask[] = Func::getPreviousState();

            return Func::getPreviousState();
        });
        $graph = Func::entrypoint(
            ['name' => 'graph', 'checkpointer' => new MemorySaver()],
            static fn (int $n): EntrypointFinal => Func::final(value: self::await($probe()), save: $n),
        );
        $config = self::config();

        self::assertNull($graph->invoke(10, $config));
        self::assertSame(10, $graph->invoke(20, $config));
        self::assertSame(20, $graph->invoke(30, $config));
        self::assertSame([null, 10, 20], $inTask);
    }

    public function testFinalWithANullSaveClearsThePreviousState(): void
    {
        $seen = [];
        $graph = Func::entrypoint(
            ['name' => 'graph', 'checkpointer' => new MemorySaver()],
            static function (int $n) use (&$seen): EntrypointFinal {
                $seen[] = Func::getPreviousState();

                return Func::final(value: $n, save: $n === 2 ? null : $n);
            },
        );
        $config = self::config();

        $graph->invoke(1, $config);
        $graph->invoke(2, $config);
        $graph->invoke(3, $config);

        self::assertSame([null, 1, null], $seen);
    }

    public function testEntrypointFinalHelper(): void
    {
        $final = Func::final(value: 'v', save: 's');

        self::assertTrue(EntrypointFinal::isEntrypointFinal($final));
        self::assertFalse(EntrypointFinal::isEntrypointFinal(['value' => 'v', 'save' => 's']));
        self::assertSame('v', $final->value);
        self::assertSame('s', $final->save);
        self::assertSame('__pregel_final', EntrypointFinal::LG_TYPE);
    }

    public function testGetPreviousStateOutsideARunSaysSo(): void
    {
        $this->expectException(\LogicException::class);
        Func::getPreviousState();
    }
}
