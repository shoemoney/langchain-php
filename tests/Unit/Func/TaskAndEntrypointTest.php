<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Func;

use LangGraph\Func\Func;
use LangGraph\Pregel\Pregel;
use LangGraph\Pregel\Retry\RetryPolicy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Port of `describe("task and entrypoint decorators")` from `langgraph-core/src/tests/func.test.ts`,
 * the `describe.each([true, false])` block run with and without a checkpointer.
 *
 * Not ported, with reasons:
 *  - "should be cancelable via AbortSignal": this port has no abort signal; tasks run sequentially.
 *  - "can use a stream writer" / "can stream subgraph results": both need the `custom` stream
 *    mode and `config.writer`, which `Pregel` does not produce (it supports `updates`, `values`,
 *    `debug`, `messages`, `tools`).
 *  - "disallows use of async generator as an entrypoint / a task": PHP has no async generators;
 *    the plain-generator case covers the rule.
 */
#[CoversClass(Func::class)]
#[CoversClass(Pregel::class)]
final class TaskAndEntrypointTest extends FuncTestCase
{
    #[DataProvider('checkpointerModes')]
    public function testBasicTaskAndEntrypoint(bool $withCheckpointer): void
    {
        $mapperCallCount = 0;
        $mapper = Func::task('mapper', static function (int $input) use (&$mapperCallCount): string {
            ++$mapperCallCount;

            return "{$input}{$input}";
        });

        $entrypointCallCount = 0;
        $graph = Func::entrypoint(
            ['checkpointer' => self::saver($withCheckpointer), 'name' => 'graph'],
            static function (array $inputs) use ($mapper, &$entrypointCallCount): array {
                ++$entrypointCallCount;

                return self::all(array_map($mapper, $inputs));
            },
        );

        $result = $graph->invoke([1, 2, 3], self::config($withCheckpointer));

        self::assertSame(['11', '22', '33'], $result);
        self::assertSame(3, $mapperCallCount);
        self::assertSame(1, $entrypointCallCount);
    }

    #[DataProvider('checkpointerModes')]
    public function testStreamsInTheCorrectOrder(bool $withCheckpointer): void
    {
        $foo = Func::task('foo', static fn (array $state): array => ['a' => "{$state['a']}foo", 'b' => 'bar']);
        $bar = Func::task('bar', static fn (string $a, string $b, ?string $c = null): array => ['a' => $a . $b, 'c' => ($c ?? '') . 'bark']);
        $baz = Func::task('baz', static fn (array $state): array => ['a' => "{$state['a']}baz", 'c' => 'something else']);

        $graph = Func::entrypoint(
            ['checkpointer' => self::saver($withCheckpointer), 'name' => 'graph'],
            static function (array $state) use ($foo, $bar, $baz): array {
                $fooRes = self::await($foo($state));
                $barRes = self::await($bar($fooRes['a'], $fooRes['b']));

                return self::await($baz($barRes));
            },
        );

        self::assertSame(
            [
                ['foo' => ['a' => '0foo', 'b' => 'bar']],
                ['bar' => ['a' => '0foobar', 'c' => 'bark']],
                ['baz' => ['a' => '0foobarbaz', 'c' => 'something else']],
                ['graph' => ['a' => '0foobarbaz', 'c' => 'something else']],
            ],
            self::updates($graph, ['a' => '0'], self::config($withCheckpointer)),
        );
    }

    #[DataProvider('checkpointerModes')]
    public function testTaskWithRetryPolicy(bool $withCheckpointer): void
    {
        $attempts = 0;
        $failingTask = Func::task(
            ['name' => 'failingTask', 'retry' => new RetryPolicy(maxAttempts: 3, logWarning: false)],
            static function () use (&$attempts): string {
                ++$attempts;
                if ($attempts < 3) {
                    throw new \RuntimeException('Task failed');
                }

                return 'success';
            },
        );

        $graph = Func::entrypoint(
            ['checkpointer' => self::saver($withCheckpointer), 'name' => 'retryGraph'],
            static fn () => $failingTask(),
        );

        self::assertSame('success', $graph->invoke([], self::config($withCheckpointer)));
        self::assertSame(3, $attempts);
    }

    public function testTaskRetryPolicyGivesUpAfterMaxAttempts(): void
    {
        $attempts = 0;
        $alwaysFails = Func::task(
            ['name' => 'alwaysFails', 'retry' => ['maxAttempts' => 2, 'logWarning' => false]],
            static function () use (&$attempts): never {
                ++$attempts;
                throw new \RuntimeException("attempt {$attempts}");
            },
        );
        $graph = Func::entrypoint(['name' => 'graph'], static fn () => $alwaysFails());

        try {
            $graph->invoke([]);
            self::fail('the task error should surface once the retry budget is spent');
        } catch (\RuntimeException $e) {
            self::assertSame('attempt 2', $e->getMessage());
        }
        self::assertSame(2, $attempts);
    }

    #[DataProvider('checkpointerModes')]
    public function testEachTasksUpdateIsEmittedBeforeTheEntrypointsOwn(bool $withCheckpointer): void
    {
        // Upstream asserts each `slowTask` chunk arrives while `lastIdx` still equals its idx, i.e.
        // the chunk is delivered as the task finishes. A PHP generator cannot yield from inside the
        // entrypoint's synchronous call, so the loop hands the chunks over after the entrypoint
        // returns: same chunks, same order, delivered together. What survives is the ordering
        // guarantee - every task update precedes the entrypoint's.
        $calls = [];
        $slowTask = Func::task('slowTask', static function (int $idx) use (&$calls): array {
            $calls[] = $idx;

            return ['idx' => $idx];
        });

        $graph = Func::entrypoint(
            ['name' => 'streamGraph', 'checkpointer' => self::saver($withCheckpointer)],
            static function () use ($slowTask): array {
                $first = self::await($slowTask(0));
                $second = self::await($slowTask(1));

                return [$first, $second];
            },
        );

        self::assertSame(
            [
                ['slowTask' => ['idx' => 0]],
                ['slowTask' => ['idx' => 1]],
                ['streamGraph' => [['idx' => 0], ['idx' => 1]]],
            ],
            self::updates($graph, [], self::config($withCheckpointer)),
        );
        self::assertSame([0, 1], $calls);
    }

    #[DataProvider('checkpointerModes')]
    public function testPropagatesErrorsThrownFromEntrypoints(bool $withCheckpointer): void
    {
        $graph = Func::entrypoint(
            ['name' => 'graph', 'checkpointer' => self::saver($withCheckpointer)],
            static function (): never {
                throw new \RuntimeException('test error');
            },
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('test error');
        $graph->invoke([], self::config($withCheckpointer));
    }

    #[DataProvider('checkpointerModes')]
    public function testPropagatesErrorsThrownFromTasks(bool $withCheckpointer): void
    {
        $errorTask = Func::task('errorTask', static function (): never {
            throw new \RuntimeException('test error');
        });
        $graph = Func::entrypoint(
            ['name' => 'graph', 'checkpointer' => self::saver($withCheckpointer)],
            static function () use ($errorTask): void {
                self::await($errorTask());
            },
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('test error');
        $graph->invoke([], self::config($withCheckpointer));
    }

    #[DataProvider('checkpointerModes')]
    public function testDisallowsUseOfAGeneratorAsAnEntrypoint(bool $withCheckpointer): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Generators are disallowed as entrypoints. For streaming responses, use config.write.');

        Func::entrypoint(
            ['name' => 'graph', 'checkpointer' => self::saver($withCheckpointer)],
            static function (): \Generator {
                yield 'a';
                yield 'b';
            },
        );
    }

    #[DataProvider('checkpointerModes')]
    public function testDisallowsUseOfAGeneratorAsATask(bool $withCheckpointer): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Generators are disallowed as tasks. For streaming responses, use config.write.');

        Func::task('task', static function (): \Generator {
            yield 'a';
            yield 'b';
        });
    }

    public function testATaskCalledOutsideARunSaysSo(): void
    {
        $task = Func::task('lonely', static fn (): int => 1);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('A task can only be called from within an entrypoint or a StateGraph node.');
        $task();
    }

    public function testTaskAndEntrypointRequireAName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Func::task(['retry' => null], static fn (): int => 1);
    }
}
