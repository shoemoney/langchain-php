<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Func;

use LangChain\Runnables\RunnableConfig;
use LangChain\Utils\Promise;
use LangGraph\Pregel\Algorithm;
use LangGraph\Pregel\Call;
use LangGraph\Pregel\Checkpoint\Checkpoint;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\NextTaskExtraFields;
use LangGraph\Pregel\PregelExecutableTask;
use LangGraph\Pregel\PregelScratchpad;
use LangGraph\Pregel\Retry\RetryPolicy;
use LangGraph\Pregel\TaskPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `pregel/call.ts` and the call branch of `_prepareSingleTask`: the `Call` payload, the two
 * runnable wrappers, `call()`, and the PUSH task a `Call` becomes.
 */
#[CoversClass(Call::class)]
#[CoversClass(Algorithm::class)]
final class CallTest extends TestCase
{
    private const CHECKPOINT_ID = '00000000-0000-0000-0000-000000000042';

    /** The path of the task that makes the call, as `acceptPush` builds it. */
    private static function callPath(Call $call, int $writeIdx = 0, string $parentId = 'parent-task'): TaskPath
    {
        return new TaskPath([Constants::PUSH, [Constants::PULL, 'graph'], $writeIdx, $parentId, $call]);
    }

    private static function prepare(Call $call, TaskPath $path, bool $forExecution = true, ?RunnableConfig $config = null, int $step = 3): PregelExecutableTask
    {
        $task = Algorithm::prepareSingleTask(
            $path,
            new Checkpoint(id: self::CHECKPOINT_ID),
            null,
            [],
            [],
            $config ?? new RunnableConfig(),
            $forExecution,
            new NextTaskExtraFields(step: $step),
        );
        self::assertNotNull($task);

        return $task;
    }

    public function testIsCallRecognisesOnlyACallPayload(): void
    {
        self::assertTrue(Call::isCall(new Call(static fn () => 1, 'c')));
        self::assertFalse(Call::isCall(['name' => 'c']));
        self::assertFalse(Call::isCall(null));
        self::assertSame('call', Call::LG_TYPE);
    }

    public function testRunnableForFuncSpreadsTheArgumentsAndWritesTheReturnValue(): void
    {
        $writes = [];
        $config = new RunnableConfig(configurable: [
            Constants::CONFIG_KEY_SEND => static function (array $entries) use (&$writes): void {
                array_push($writes, ...$entries);
            },
        ]);

        $runnable = Call::getRunnableForFunc('add', static fn (int $a, int $b): int => $a + $b);
        $runnable->invoke([2, 3], $config);

        self::assertSame([[Constants::RETURN, 5]], $writes);
    }

    public function testRunnableForFuncAwaitsAFunctionThatReturnsAPromise(): void
    {
        $writes = [];
        $config = new RunnableConfig(configurable: [
            Constants::CONFIG_KEY_SEND => static function (array $entries) use (&$writes): void {
                array_push($writes, ...$entries);
            },
        ]);

        Call::getRunnableForFunc('later', static fn (): Promise => Promise::resolved('done'))->invoke([], $config);

        self::assertSame([[Constants::RETURN, 'done']], $writes);
    }

    public function testRunnableForEntrypointPassesTheInputAndTheConfigAndAwaitsAPromise(): void
    {
        $config = new RunnableConfig(configurable: ['thread_id' => 't']);
        $seen = null;

        $runnable = Call::getRunnableForEntrypoint('ep', static function (mixed $input, RunnableConfig $c) use (&$seen): Promise {
            $seen = $c->configurable['thread_id'];

            return Promise::resolved(['in' => $input]);
        });

        self::assertSame(['in' => [1, 2]], $runnable->invoke([1, 2], $config));
        self::assertSame('t', $seen);
    }

    public function testCallHandsTheSchedulerTheFunctionNameArgumentsAndPolicies(): void
    {
        $received = null;
        $retry = new RetryPolicy(maxAttempts: 2);
        $config = new RunnableConfig(configurable: [
            Constants::CONFIG_KEY_CALL => static function (callable $func, string $name, array $args, array $options) use (&$received): Promise {
                $received = [$name, $args, $options['retry'], $options['cache'], $options['timeout']];

                return Promise::resolved($func(...$args));
            },
        ]);
        $func = static fn (int $a, int $b): int => $a * $b;

        $result = PregelScratchpad::withConfig($config, static fn (): Promise => Call::call(
            ['func' => $func, 'name' => 'mul', 'retry' => $retry, 'cache' => ['ttl' => 5], 'timeout' => 100],
            6,
            7,
        ));

        self::assertInstanceOf(Promise::class, $result);
        self::assertSame(42, $result->value());
        self::assertSame(['mul', [6, 7], $retry, ['ttl' => 5], 100], $received);
    }

    public function testCallOutsideARunningTaskThrows(): void
    {
        $this->expectException(\LogicException::class);
        Call::call(['func' => static fn () => 1, 'name' => 'x']);
    }

    public function testACallBecomesAPushTaskWithADeterministicId(): void
    {
        $call = new Call(static fn (int $n): int => $n, 'step', [1]);

        $a = self::prepare($call, self::callPath($call));
        $again = self::prepare($call, self::callPath($call));
        $secondCall = self::prepare($call, self::callPath($call, writeIdx: 1));
        $laterStep = self::prepare($call, self::callPath($call), step: 4);

        self::assertSame($a->id, $again->id, 'the Nth call of a task must keep its id across runs');
        self::assertNotSame($a->id, $secondCall->id, 'a different call position is a different task');
        self::assertNotSame($a->id, $laterStep->id);
        self::assertSame('step', $a->name);
        self::assertSame([1], $a->input);
        self::assertSame([Constants::PUSH], $a->triggers);
        self::assertTrue($a->isExecutable());
        self::assertSame([], $a->writers);
        self::assertStringContainsString("step" . Constants::CHECKPOINT_NAMESPACE_END . $a->id, $a->metadata['checkpoint_ns']);
    }

    public function testACallTaskCarriesTheCallFlagNotTheCallPayload(): void
    {
        $call = new Call(static fn (): int => 1, 'step');
        $task = self::prepare($call, self::callPath($call, writeIdx: 2));

        self::assertSame([Constants::PUSH, [Constants::PULL, 'graph'], 2, true], $task->path->toArray());
        self::assertTrue($task->path->isCallPath());
        self::assertSame($task->path->toArray(), $task->metadata['langgraph_path']);
        // JSON-safe: the payload (a closure) must not reach checkpoint metadata.
        self::assertNotFalse(json_encode($task->metadata));
    }

    public function testADescriptionOnlyCallTaskIsNotExecutable(): void
    {
        $call = new Call(static fn (): int => 1, 'step');
        $task = self::prepare($call, self::callPath($call), forExecution: false);

        self::assertFalse($task->isExecutable());
        self::assertSame('step', $task->name);
        self::assertSame([], $task->interrupts);
    }

    public function testACallTaskCarriesItsRetryPolicyAndCacheKey(): void
    {
        $retry = new RetryPolicy(maxAttempts: 4);
        $call = new Call(static fn (int $n): int => $n, 'cached', [9], $retry, ['ttl' => 30]);

        $task = self::prepare($call, self::callPath($call));
        $same = self::prepare($call, self::callPath($call, writeIdx: 5));
        $otherCall = new Call(static fn (int $n): int => $n, 'cached', [10], $retry, ['ttl' => 30]);
        $other = self::prepare($otherCall, self::callPath($otherCall));

        self::assertSame($retry, $task->retryPolicy);
        self::assertSame([Constants::CACHE_NS_WRITES, 'cached'], $task->cacheKey['ns']);
        self::assertSame(30, $task->cacheKey['ttl']);
        self::assertSame($task->cacheKey['key'], $same->cacheKey['key'], 'the key depends on the arguments, not the position');
        self::assertNotSame($task->cacheKey['key'], $other->cacheKey['key']);
    }

    public function testACallWithoutACachePolicyHasNoCacheKey(): void
    {
        $call = new Call(static fn (): int => 1, 'plain');

        self::assertNull(self::prepare($call, self::callPath($call))->cacheKey);
    }

    public function testACustomCacheKeyFunctionIsUsed(): void
    {
        $call = new Call(static fn (int $n): int => $n, 'keyed', [1], null, ['keyFunc' => static fn (array $input): string => 'fixed']);
        $other = new Call(static fn (int $n): int => $n, 'keyed', [2], null, ['keyFunc' => static fn (array $input): string => 'fixed']);

        self::assertSame(
            self::prepare($call, self::callPath($call))->cacheKey['key'],
            self::prepare($other, self::callPath($other))->cacheKey['key'],
        );
    }

    public function testTheCallTaskGetsItsOwnSchedulerWhenTheEngineProvidesOne(): void
    {
        $call = new Call(static fn (): int => 1, 'inner');
        $parent = null;
        $task = Algorithm::prepareSingleTask(
            self::callPath($call),
            new Checkpoint(id: self::CHECKPOINT_ID),
            null,
            [],
            [],
            new RunnableConfig(),
            true,
            new NextTaskExtraFields(step: 1, call: static function (PregelExecutableTask $caller) use (&$parent): string {
                $parent = $caller;

                return 'scheduled';
            }),
        );

        $schedule = $task->config->configurable[Constants::CONFIG_KEY_CALL];
        self::assertSame('scheduled', $schedule(static fn () => 1, 'child', [], []));
        self::assertSame($task, $parent, 'the scheduler is bound to the task that makes the call');
    }

    public function testCallTasksFoldAfterPullTasksAndInCallOrder(): void
    {
        // A call task's path carries its parent's path as an array segment; the fold orders on it
        // the way JavaScript's `<` does, by string form.
        $make = static fn (string $name, TaskPath $path): PregelExecutableTask => new PregelExecutableTask(
            name: $name,
            writes: [['out', $name]],
            path: $path,
        );
        $channel = new \LangGraph\Channels\Topic(accumulate: true);

        Algorithm::applyWrites(
            new Checkpoint(id: self::CHECKPOINT_ID),
            ['out' => $channel],
            [
                $make('call-1', new TaskPath([Constants::PUSH, [Constants::PULL, 'graph'], 1, true])),
                $make('call-0', new TaskPath([Constants::PUSH, [Constants::PULL, 'graph'], 0, true])),
                $make('graph', new TaskPath([Constants::PULL, 'graph'])),
            ],
            static fn ($v) => Algorithm::increment($v),
        );

        self::assertSame(['graph', 'call-0', 'call-1'], $channel->get());
    }
}
