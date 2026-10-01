<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use LangGraph\Pregel\ChannelWrite;
use LangGraph\Pregel\Constants;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * `ChannelWrite` is a `RunnableInterface` whose `batch()` is part of the runnable contract, so it owes
 * the same two guarantees as every other implementation: honour `returnExceptions`, and yield a LIST.
 *
 * Iteration 432 found its `batch()` was a hand-rolled
 * `array_map(fn ($i) => $this->invoke($i, $config), $inputs)`, which reads neither `$options` nor
 * `array_values($inputs)`. It was never in `BatchReturnExceptionsEverywhereTest`, so nothing executed it.
 * This is the same defect 429 fixed in `RunnableBranch`, and unlike 431's `RunnablePick` case it is
 * **observable**, so a fix here can be mutation-verified.
 *
 * **It needs a dedicated test rather than a provider case**, for two measured reasons:
 *
 *  1. The shared provider passes `null` for `$config`, and `doWrite()` throws
 *     `ChannelWrite requires a write function in config` (ChannelWrite.php:177-181) for EVERY item, so
 *     the shared "exactly one error" expectation could not be met for the right reason.
 *  2. `resolveWrites()` invokes a `mapper` entry with the write's own fixed `value`, not the batch input,
 *     so the flaky mapper is driven by a CALL COUNT rather than by which input is being processed.
 *
 * The fixture therefore supplies the `CONFIG_KEY_SEND` callable the way `Algorithm.php:953` does, and
 * uses a mapper that throws on its first invocation only — giving three results of which exactly one is
 * a Throwable, which is the same shape the shared provider asserts.
 */
#[CoversNothing]
final class PregelRunnableBatchTest extends TestCase
{
    /** @return array{0: RunnableConfig, 1: list<array{string, mixed}>} */
    private static function configCapturingWrites(): array
    {
        $captured = [];

        return [
            RunnableConfig::fromArray([
                'configurable' => [
                    Constants::CONFIG_KEY_SEND => static function (array $entries) use (&$captured): void {
                        $captured[] = $entries;
                    },
                ],
            ]),
            &$captured,
        ];
    }

    public function testChannelWriteBatchCollectsExceptionsInsteadOfThrowingOnTheFirst(): void
    {
        // Throw on the FIRST mapper call only, so exactly one of the three batch items fails.
        $calls = 0;
        $mapper = RunnableLambda::from(static function (mixed $v) use (&$calls): string {
            ++$calls;
            if ($calls === 1) {
                throw new \RuntimeException('first write fails');
            }

            return (string) $v;
        });

        $write = new ChannelWrite([
            ['channel' => 'test-channel', 'value' => null, 'mapper' => $mapper],
        ]);

        [$config] = self::configCapturingWrites();

        $results = $write->batch(['a', 'b', 'c'], $config, ['returnExceptions' => true]);

        self::assertCount(3, $results, 'all three items must produce a slot');
        $errors = array_filter($results, static fn (mixed $r): bool => $r instanceof \Throwable);
        self::assertCount(
            1,
            $errors,
            'returnExceptions=true must collect the failure into its slot instead of aborting the batch',
        );
        self::assertSame('first write fails', $errors[array_key_first($errors)]->getMessage());
    }

    public function testChannelWriteBatchReturnsAListForStringKeyedInput(): void
    {
        $write = new ChannelWrite([]);
        [$config] = self::configCapturingWrites();

        $results = $write->batch(['x' => 'a', 'y' => 'b', 'z' => 'c'], $config);

        self::assertTrue(
            array_is_list($results),
            'upstream Runnable.batch does inputs.map(...) + Promise.all(...), which ALWAYS yields a list; '
                . 'got keys ' . json_encode(array_keys($results)),
        );
    }
}
