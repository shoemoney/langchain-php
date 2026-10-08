<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use LangGraph\Agents\RunnableCallable;
use LangGraph\Utils\RunnableCallable as BaseRunnableCallable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for `agents/RunnableCallable.ts`, which has no upstream test file.
 */
#[CoversClass(RunnableCallable::class)]
final class RunnableCallableTest extends TestCase
{
    public function testItIsTheLangGraphRunnableCallableWithState(): void
    {
        self::assertInstanceOf(BaseRunnableCallable::class, new RunnableCallable(static fn (): int => 1));
    }

    public function testTheFunctionAlwaysReceivesInputAndConfig(): void
    {
        $seen = null;
        $node = new RunnableCallable(static function (mixed $input, RunnableConfig $config) use (&$seen): string {
            $seen = [$input, $config->tags];

            return 'out';
        }, name: 'worker', tags: ['t1']);

        self::assertSame('out', $node->invoke('in'));
        self::assertSame(['in', ['t1']], $seen);
        self::assertSame('worker', $node->getName());
    }

    public function testStateIsNullUntilTheFirstRunThenTracksTheLastReturnValue(): void
    {
        $n = 0;
        $node = new RunnableCallable(static function () use (&$n): array {
            $n++;

            return ['run' => $n];
        });

        self::assertNull($node->getState());
        $node->invoke(null);
        self::assertSame(['run' => 1], $node->getState());
        $node->invoke(null);
        self::assertSame(['run' => 2], $node->getState());
    }

    public function testSetStateMergesIntoTheRecordedState(): void
    {
        $node = new RunnableCallable(static fn (): array => ['a' => 1, 'b' => 2]);
        $node->invoke(null);

        $node->setState(['b' => 3, 'c' => 4]);

        self::assertSame(['a' => 1, 'b' => 3, 'c' => 4], $node->getState());
    }

    public function testSetStateOnAFreshNodeStartsFromTheGivenPatch(): void
    {
        $node = new RunnableCallable(static fn (): int => 1);
        $node->setState(['x' => 1]);

        self::assertSame(['x' => 1], $node->getState());
    }

    public function testAReturnedRunnableIsRecursedIntoAndNotRecordedAsState(): void
    {
        $node = new RunnableCallable(static fn (): RunnableLambda => RunnableLambda::from(static fn (mixed $in): string => 'inner:' . $in));

        self::assertSame('inner:x', $node->invoke('x'));
        self::assertNull($node->getState());
    }

    public function testWithRecurseOffAReturnedRunnableIsKeptAsTheResultAndRecorded(): void
    {
        $returned = RunnableLambda::from(static fn (mixed $in): string => 'inner');
        $node = new RunnableCallable(static fn (): RunnableLambda => $returned, recurse: false);

        self::assertSame($returned, $node->invoke('x'));
        self::assertSame($returned, $node->getState());
    }
}
