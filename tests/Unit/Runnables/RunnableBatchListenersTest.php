<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables;

use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use LangChain\Runnables\RunnableParallel;
use LangChain\Tracers\CallbackHandler;
use LangChain\Tracers\Run;
use PHPUnit\Framework\TestCase;

final class RunnableBatchListenersTest extends TestCase
{
    private static function compositions(bool $throw): array
    {
        $step = new RunnableLambda(static function (string $input) use ($throw): string {
            if ($throw && $input === 'bad') {
                throw new \RuntimeException('batch probe failed');
            }

            return strtoupper($input);
        });

        return [$step->pipe(new RunnableLambda(static fn ($input) => $input)), new RunnableParallel(['answer' => $step])];
    }

    /** Scalar Binding.batch merges once; RootListenersTracer captures its first root only. */
    public function testSequenceAndParallelScalarBatchListenToTheirFirstRootOnly(): void
    {
        foreach (self::compositions(false) as $composition) {
            $events = [];
            $bound = $composition->withListeners([
                'onStart' => static function (Run $run) use (&$events): void { $events[] = ['start', $run->name(), $run->id, $run->inputs]; },
                'onEnd' => static function (Run $run) use (&$events): void { $events[] = ['end', $run->name(), $run->id, $run->outputs]; },
                'onError' => static function () use (&$events): void { $events[] = ['error']; },
            ]);
            $result = $bound->batch(['one', 'two']);
            self::assertSame($composition instanceof RunnableParallel ? [['answer' => 'ONE'], ['answer' => 'TWO']] : ['ONE', 'TWO'], $result);
            self::assertCount(2, $events);
            self::assertSame('start', $events[0][0]);
            self::assertSame('end', $events[1][0]);
            self::assertSame($composition->getName(), $events[0][1]);
            self::assertSame($events[0][2], $events[1][2]);
            self::assertSame(['input' => 'one'], $events[0][3]);
            self::assertSame($composition instanceof RunnableParallel ? ['answer' => 'ONE'] : ['output' => 'ONE'], $events[1][3]);
        }
    }

    public function testSequenceAndParallelScalarBatchReportFirstRootErrorAndContinueWhenRequested(): void
    {
        foreach (self::compositions(true) as $composition) {
            $events = [];
            $bound = $composition->withListeners([
                'onStart' => static function (Run $run) use (&$events): void { $events[] = ['start', $run->id]; },
                'onEnd' => static function () use (&$events): void { $events[] = ['end']; },
                'onError' => static function (Run $run) use (&$events): void {
                    self::assertStringContainsString('batch probe failed', $run->error);
                    $events[] = ['error', $run->id];
                },
            ]);
            $result = $bound->batch(['bad', 'good'], options: ['returnExceptions' => true]);
            self::assertInstanceOf(\RuntimeException::class, $result[0]);
            self::assertSame($composition instanceof RunnableParallel ? ['answer' => 'GOOD'] : 'GOOD', $result[1]);
            self::assertSame(['start', 'error'], array_column($events, 0));
            self::assertSame($events[0][1], $events[1][1]);
        }
    }

    public function testExplicitScalarBatchRunIdBelongsOnlyToTheFirstRootWithoutMutatingCallerConfig(): void
    {
        foreach ([false, true] as $throw) {
            foreach (self::compositions($throw) as $composition) {
                $events = [];
                $rootIds = [];
                $handler = CallbackHandler::fromMethods(['handleChainStart' => static function (
                    $serialized, $input, $id, $runType, $tags, $metadata, $name, $parent,
                ) use (&$rootIds): void {
                    if ($parent === null) {
                        $rootIds[] = $id;
                    }
                }]);
                $id = '11111111-1111-4111-8111-111111111111';
                $config = new RunnableConfig(runId: [$id], callbacks: [$handler], tags: ['caller']);
                $bound = $composition->withListeners([
                    'onStart' => static function (Run $run) use (&$events): void { $events[] = ['start', $run->id]; },
                    'onEnd' => static function (Run $run) use (&$events): void { $events[] = ['end', $run->id]; },
                    'onError' => static function (Run $run) use (&$events): void { $events[] = ['error', $run->id]; },
                ]);
                $result = $bound->batch($throw ? ['bad', 'good'] : ['one', 'two'], $config, ['returnExceptions' => $throw]);
                self::assertCount(2, $result);
                self::assertSame([['start', $id], [$throw ? 'error' : 'end', $id]], $events);
                self::assertCount(2, $rootIds);
                self::assertSame($id, $rootIds[0]);
                self::assertNotSame($id, $rootIds[1]);
                self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $rootIds[1]);
                self::assertSame([$id], $config->runId);
                self::assertSame([$handler], $config->callbacks);
                self::assertSame(['caller'], $config->tags);
            }
        }
    }

    public function testEveryBatchRootDeliversIndependentlyMergedDuplicateHandlersInOrder(): void
    {
        foreach (self::compositions(false) as $composition) {
            $events = [];
            $first = CallbackHandler::fromMethods(['handleChainStart' => static function () use (&$events): void { $events[] = 'first'; }]);
            $second = CallbackHandler::fromMethods(['handleChainStart' => static function () use (&$events): void { $events[] = 'second'; }]);
            $config = RunnableConfig::mergeConfigs(['callbacks' => [$first]], ['callbacks' => [$first, $second]]);
            $composition->withConfig([])->batch(['one', 'two'], $config);
            self::assertSame(['first', 'first', 'second', 'first', 'first', 'second'], $events);
        }
    }
}
