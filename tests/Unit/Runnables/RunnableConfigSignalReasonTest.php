<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Runnables\RunnableBinding;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableInterface;
use LangChain\Runnables\RunnableLambda;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangChain\Utils\AsyncCaller;
use LangChain\Utils\AsyncLocalStorage;
use LangGraph\Agents\Nodes\AgentNode;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The signal `mergeConfigs` builds when two callable signals meet reports the first Throwable reason it
 * observes by identity, not a bare `true`. The rest of the boolean composite it replaces is kept: left before
 * right, the first abort stays latched, live is `false`, any other truthy poll is `true`, and a poll that throws
 * propagates without latching.
 *
 * A signal is the port's abort convention (see AsyncCaller): a callable returning `false` while live and `true`
 * or a `\Throwable` (the reason) once aborted. Only the composition is owned here; single signals, deadlines,
 * the consumers and the transports are untouched.
 */
#[CoversClass(RunnableConfig::class)]
final class RunnableConfigSignalReasonTest extends TestCase
{
    private ?AsyncLocalStorage $previousProvider;

    protected function setUp(): void
    {
        $this->previousProvider = AsyncLocalStorage::getGlobalInstance();
        AsyncLocalStorage::setGlobalInstance(new AsyncLocalStorage());
    }

    protected function tearDown(): void
    {
        AsyncLocalStorage::setGlobalInstance($this->previousProvider);
    }

    /**
     * A signal that counts its polls. Poll N (from 0) answers `$answers[N]`; the last answer repeats.
     *
     * @param list<mixed> $answers
     * @return array{0: \Closure(): mixed, 1: \stdClass} the signal and its counter (`->count`)
     */
    private static function counted(array $answers): array
    {
        $polls = new \stdClass();
        $polls->count = 0;
        $signal = static function () use ($polls, $answers): mixed {
            $answer = $answers[min($polls->count, count($answers) - 1)];
            ++$polls->count;

            return $answer;
        };

        return [$signal, $polls];
    }

    /**
     * @param list<\stdClass> $polls
     * @return list<int>
     */
    private static function counts(array $polls): array
    {
        return array_map(static fn (\stdClass $poll): int => $poll->count, $polls);
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function throwableReasons(): iterable
    {
        yield 'Exception' => [new \RuntimeException('caller cancelled')];
        yield 'LogicException' => [new \LogicException('logic cancelled')];
        yield 'Error' => [new \Error('engine fault')];
        yield 'TypeError' => [new \TypeError('wrong type')];
    }

    /** @return iterable<string, array{mixed}> */
    public static function truthyNonThrowables(): iterable
    {
        yield 'true' => [true];
        yield 'int 1' => [1];
        yield 'negative int' => [-1];
        yield 'float' => [1.5];
        yield 'string' => ['why'];
        yield 'string 0.0' => ['0.0'];
        yield 'blank string' => [' '];
        yield 'non-empty array' => [['x']];
        yield 'plain object' => [new \stdClass()];
        yield 'object exposing reason()' => [new class () {
            public function reason(): \Throwable
            {
                return new \RuntimeException('a reason() accessor is never read');
            }
        }];
    }

    /** @return iterable<string, array{mixed}> */
    public static function falseyValues(): iterable
    {
        yield 'null' => [null];
        yield 'false' => [false];
        yield 'int 0' => [0];
        yield 'float 0.0' => [0.0];
        yield 'empty string' => [''];
        yield 'string 0' => ['0'];
        yield 'empty array' => [[]];
    }

    public function testMergingNeverPollsEitherSignal(): void
    {
        [$left, $leftPolls] = self::counted([false]);
        [$right, $rightPolls] = self::counted([new \RuntimeException('never observed')]);

        $merged = RunnableConfig::mergeConfigs(new RunnableConfig(signal: $left), new RunnableConfig(signal: $right));

        self::assertIsCallable($merged->signal);
        self::assertSame([0, 0], self::counts([$leftPolls, $rightPolls]));
    }

    #[DataProvider('throwableReasons')]
    public function testRightReasonKeepsItsIdentityAndIsPolledAfterTheLiveLeft(\Throwable $reason): void
    {
        [$left, $leftPolls] = self::counted([false]);
        [$right, $rightPolls] = self::counted([$reason]);
        $merged = RunnableConfig::mergeConfigs(new RunnableConfig(signal: $left), new RunnableConfig(signal: $right));

        $observed = ($merged->signal)();

        self::assertSame($reason, $observed);
        self::assertSame([1, 1], self::counts([$leftPolls, $rightPolls]));
    }

    #[DataProvider('throwableReasons')]
    public function testLeftReasonKeepsItsIdentityAndTheRightIsNeverPolled(\Throwable $reason): void
    {
        [$left, $leftPolls] = self::counted([$reason]);
        [$right, $rightPolls] = self::counted([new \RuntimeException('never observed')]);
        $merged = RunnableConfig::mergeConfigs(new RunnableConfig(signal: $left), new RunnableConfig(signal: $right));

        $observed = ($merged->signal)();

        self::assertSame($reason, $observed);
        self::assertSame([1, 0], self::counts([$leftPolls, $rightPolls]));
    }

    public function testBothAbortedReportsTheLeftReasonWithoutPollingTheRight(): void
    {
        $leftReason = new \LogicException('left first');
        $rightReason = new \RuntimeException('right second');
        [$left, $leftPolls] = self::counted([$leftReason]);
        [$right, $rightPolls] = self::counted([$rightReason]);
        $merged = RunnableConfig::mergeConfigs(new RunnableConfig(signal: $left), new RunnableConfig(signal: $right));

        $observed = ($merged->signal)();

        self::assertSame($leftReason, $observed);
        self::assertSame([1, 0], self::counts([$leftPolls, $rightPolls]));
    }

    public function testLeftTrueWinsOverARightReasonAndStaysTrue(): void
    {
        [$left] = self::counted([true]);
        [$right, $rightPolls] = self::counted([new \RuntimeException('never observed')]);
        $merged = RunnableConfig::mergeConfigs(new RunnableConfig(signal: $left), new RunnableConfig(signal: $right));

        self::assertTrue(($merged->signal)());
        self::assertTrue(($merged->signal)());
        self::assertSame(0, $rightPolls->count);
    }

    public function testFirstObservedReasonIsStickyWhileTheSignalsMoveOn(): void
    {
        $first = new \RuntimeException('first observed');
        [$left, $leftPolls] = self::counted([false, new \LogicException('left later')]);
        [$right, $rightPolls] = self::counted([$first, new \LogicException('right later')]);
        $merged = RunnableConfig::mergeConfigs(new RunnableConfig(signal: $left), new RunnableConfig(signal: $right));

        self::assertSame($first, ($merged->signal)());
        self::assertSame($first, ($merged->signal)());
        self::assertSame($first, ($merged->signal)());
        self::assertSame([1, 1], self::counts([$leftPolls, $rightPolls]), 'a latched composite does not poll again');
    }

    public function testLiveSignalsReportFalseAndArePolledAgainUntilOneAborts(): void
    {
        $reason = new \RuntimeException('late abort');
        [$left, $leftPolls] = self::counted([false]);
        [$right, $rightPolls] = self::counted([false, false, $reason]);
        $merged = RunnableConfig::mergeConfigs(new RunnableConfig(signal: $left), new RunnableConfig(signal: $right));

        self::assertFalse(($merged->signal)());
        self::assertFalse(($merged->signal)());
        self::assertSame([2, 2], self::counts([$leftPolls, $rightPolls]));
        self::assertSame($reason, ($merged->signal)());
        self::assertSame($reason, ($merged->signal)());
        self::assertSame([3, 3], self::counts([$leftPolls, $rightPolls]));
    }

    #[DataProvider('truthyNonThrowables')]
    public function testOtherTruthyPollsCoerceToStrictTrueFromEitherSide(mixed $value): void
    {
        [$right, $rightPolls] = self::counted([new \RuntimeException('never observed')]);
        $leftAborts = RunnableConfig::mergeConfigs(new RunnableConfig(signal: static fn (): mixed => $value), new RunnableConfig(signal: $right));
        $rightAborts = RunnableConfig::mergeConfigs(new RunnableConfig(signal: static fn (): bool => false), new RunnableConfig(signal: static fn (): mixed => $value));

        self::assertTrue(($leftAborts->signal)());
        self::assertTrue(($leftAborts->signal)());
        self::assertSame(0, $rightPolls->count);
        self::assertTrue(($rightAborts->signal)());
        self::assertTrue(($rightAborts->signal)());
    }

    #[DataProvider('falseyValues')]
    public function testEveryFalseyLeftPollFallsThroughToTheRightReason(mixed $falsey): void
    {
        $reason = new \RuntimeException('right after a falsey left');
        [$left, $leftPolls] = self::counted([$falsey]);
        [$right, $rightPolls] = self::counted([$reason]);
        $merged = RunnableConfig::mergeConfigs(new RunnableConfig(signal: $left), new RunnableConfig(signal: $right));

        $observed = ($merged->signal)();

        self::assertSame($reason, $observed);
        self::assertSame([1, 1], self::counts([$leftPolls, $rightPolls]));
    }

    #[DataProvider('falseyValues')]
    public function testEveryFalseyPollOnBothSidesReportsStrictFalseAndStaysLive(mixed $falsey): void
    {
        [$left, $leftPolls] = self::counted([$falsey]);
        [$right, $rightPolls] = self::counted([$falsey]);
        $merged = RunnableConfig::mergeConfigs(new RunnableConfig(signal: $left), new RunnableConfig(signal: $right));

        self::assertFalse(($merged->signal)());
        self::assertFalse(($merged->signal)());
        self::assertSame([2, 2], self::counts([$leftPolls, $rightPolls]));
    }

    /**
     * Which signal aborts, and how often each of the three was polled by the first poll.
     *
     * @return iterable<string, array{?int, list<int>}>
     */
    public static function variadicAbortPositions(): iterable
    {
        yield 'first signal aborts' => [0, [1, 0, 0]];
        yield 'second signal aborts' => [1, [1, 1, 0]];
        yield 'third signal aborts' => [2, [1, 1, 1]];
        yield 'none abort' => [null, [1, 1, 1]];
    }

    /**
     * Composites built in one variadic call capture one shared latch by reference.
     *
     * @param list<int> $firstPoll
     */
    #[DataProvider('variadicAbortPositions')]
    public function testOneVariadicMergeOfThreeSignalsSharesOneLatch(?int $aborting, array $firstPoll): void
    {
        $reason = new \RuntimeException('which signal');
        $configs = [];
        $polls = [];
        foreach ([0, 1, 2] as $position) {
            [$signal, $polls[$position]] = self::counted([$position === $aborting ? $reason : false]);
            $configs[] = new RunnableConfig(signal: $signal);
        }
        $merged = RunnableConfig::mergeConfigs(...$configs);
        $expected = $aborting === null ? false : $reason;

        self::assertSame($expected, ($merged->signal)());
        self::assertSame($firstPoll, self::counts($polls));
        self::assertSame($expected, ($merged->signal)());
        self::assertSame(
            $aborting === null ? [2, 2, 2] : $firstPoll,
            self::counts($polls),
            $aborting === null ? 'a live composite polls every signal again' : 'a latched composite does not poll again',
        );
    }

    public function testNestedMergeCallsReportTheSameReasonAndPollCountsAsOneVariadicCall(): void
    {
        $reason = new \RuntimeException('second of three');
        [$first, $firstPolls] = self::counted([false]);
        [$second, $secondPolls] = self::counted([$reason]);
        [$third, $thirdPolls] = self::counted([false]);
        $merged = RunnableConfig::mergeConfigs(
            RunnableConfig::mergeConfigs(new RunnableConfig(signal: $first), new RunnableConfig(signal: $second)),
            new RunnableConfig(signal: $third),
        );

        self::assertSame($reason, ($merged->signal)());
        self::assertSame($reason, ($merged->signal)());
        self::assertSame([1, 1, 0], self::counts([$firstPolls, $secondPolls, $thirdPolls]));
    }

    public function testBindingFactoriesComposeBoundCallerAndBothFactorySignalsWithoutPollingOnInvoke(): void
    {
        $reason = new \RuntimeException('first factory');
        [$bound, $boundPolls] = self::counted([false]);
        [$caller, $callerPolls] = self::counted([false]);
        [$factoryA, $factoryAPolls] = self::counted([$reason]);
        [$factoryB, $factoryBPolls] = self::counted([new \LogicException('never observed')]);
        $received = null;
        $binding = new RunnableBinding(
            new RunnableLambda(static function (mixed $input, RunnableConfig $config) use (&$received): string {
                $received = $config;

                return 'ran';
            }),
            config: ['signal' => $bound],
            configFactories: [
                static fn (RunnableConfig $config): RunnableConfig => new RunnableConfig(signal: $factoryA),
                static fn (RunnableConfig $config): array => ['signal' => $factoryB],
            ],
        );
        $polls = [$boundPolls, $callerPolls, $factoryAPolls, $factoryBPolls];

        self::assertSame('ran', $binding->invoke('input', new RunnableConfig(signal: $caller)));
        self::assertInstanceOf(RunnableConfig::class, $received);
        self::assertSame([0, 0, 0, 0], self::counts($polls), 'invoking the binding must not poll');

        self::assertSame($reason, ($received->signal)());
        self::assertSame([1, 1, 1, 0], self::counts($polls));
        self::assertSame($reason, ($received->signal)());
        self::assertSame([1, 1, 1, 0], self::counts($polls), 'a latched composite does not poll again');
    }

    public function testNormalizingOrCopyingTheMergedConfigKeepsTheSameLatchedComposite(): void
    {
        $reason = new \LogicException('latched once');
        [$left, $leftPolls] = self::counted([false]);
        [$right, $rightPolls] = self::counted([$reason]);
        $merged = RunnableConfig::mergeConfigs(new RunnableConfig(signal: $left), new RunnableConfig(signal: $right));
        $normalized = RunnableConfig::normalizeFields($merged);
        $copied = $merged->with(['tags' => ['copy']]);

        self::assertSame($merged->signal, $normalized->signal);
        self::assertSame($merged->signal, $copied->signal);
        self::assertSame($reason, ($normalized->signal)());
        self::assertSame($reason, ($merged->signal)());
        self::assertSame($reason, ($copied->signal)());
        self::assertSame([1, 1], self::counts([$leftPolls, $rightPolls]));
    }

    public function testPollThatThrowsPropagatesWithoutLatchingEitherSide(): void
    {
        $rightFailure = new \DomainException('right poll failed');
        $leftFailure = new \DomainException('left poll failed');
        $rightCalls = 0;
        $leftCalls = 0;
        [$liveLeft, $liveLeftPolls] = self::counted([false]);
        [$liveRight, $liveRightPolls] = self::counted([false]);
        $flakyRight = static function () use (&$rightCalls, $rightFailure): bool {
            if (++$rightCalls === 1) {
                throw $rightFailure;
            }

            return false;
        };
        $flakyLeft = static function () use (&$leftCalls, $leftFailure): bool {
            if (++$leftCalls === 1) {
                throw $leftFailure;
            }

            return false;
        };
        $rightFails = RunnableConfig::mergeConfigs(new RunnableConfig(signal: $liveLeft), new RunnableConfig(signal: $flakyRight));
        $leftFails = RunnableConfig::mergeConfigs(new RunnableConfig(signal: $flakyLeft), new RunnableConfig(signal: $liveRight));

        try {
            ($rightFails->signal)();
            $rightThrown = null;
        } catch (\DomainException $error) {
            $rightThrown = $error;
        }
        try {
            ($leftFails->signal)();
            $leftThrown = null;
        } catch (\DomainException $error) {
            $leftThrown = $error;
        }

        self::assertSame($rightFailure, $rightThrown);
        self::assertSame($leftFailure, $leftThrown);
        self::assertSame(0, $liveRightPolls->count, 'a throwing left poll stops before the right');
        self::assertFalse(($rightFails->signal)(), 'a thrown poll latches nothing');
        self::assertFalse(($leftFails->signal)(), 'a thrown poll latches nothing');
        self::assertSame([2, 2], [$liveLeftPolls->count, $rightCalls]);
        self::assertSame([2, 1], [$leftCalls, $liveRightPolls->count]);
    }

    public function testLoneSignalAndNonCallableInputsKeepTheirReplacementBehaviour(): void
    {
        $opaque = new \stdClass();
        $callable = static fn (): bool => false;

        self::assertSame($callable, RunnableConfig::mergeConfigs(new RunnableConfig(signal: $callable))->signal);
        self::assertSame($opaque, RunnableConfig::mergeConfigs(new RunnableConfig(signal: $opaque))->signal);
        self::assertSame($callable, RunnableConfig::mergeConfigs(new RunnableConfig(signal: $opaque), new RunnableConfig(signal: $callable))->signal);
        self::assertSame($opaque, RunnableConfig::mergeConfigs(new RunnableConfig(signal: $callable), new RunnableConfig(signal: $opaque))->signal);
        self::assertSame($callable, RunnableConfig::mergeConfigs(new RunnableConfig(signal: $callable), new RunnableConfig())->signal);
        self::assertNull(RunnableConfig::mergeConfigs(new RunnableConfig(), new RunnableConfig())->signal);
    }

    /** @return iterable<string, array{mixed}> */
    public static function loneAsymmetryValues(): iterable
    {
        yield 'int 1' => [1];
        yield 'string' => ['why'];
    }

    /**
     * AsyncCaller aborts only on a strict `true` or a Throwable, so a lone signal answering `1` or `'why'` stays
     * live there, while the composite coerces any truthy poll to `true` and aborts. That asymmetry predates the
     * reason change and is pinned, not repaired.
     */
    #[DataProvider('loneAsymmetryValues')]
    public function testLoneTruthyNonTrueSignalStaysLiveInAsyncCallerWhereTheCompositeAborts(mixed $value): void
    {
        $lone = static fn (): mixed => $value;
        $composite = RunnableConfig::mergeConfigs(new RunnableConfig(signal: static fn (): bool => false), new RunnableConfig(signal: $lone));
        $ran = 0;
        $work = static function () use (&$ran): string {
            ++$ran;

            return 'done';
        };
        $caller = new AsyncCaller(maxRetries: 0);

        self::assertSame($lone, RunnableConfig::mergeConfigs(new RunnableConfig(signal: $lone))->signal);
        self::assertSame('done', $caller->callWithOptions(['signal' => $lone], $work));
        self::assertSame(1, $ran);

        try {
            $caller->callWithOptions(['signal' => $composite->signal], $work);
            $aborted = null;
        } catch (\RuntimeException $error) {
            $aborted = $error;
        }

        self::assertInstanceOf(\RuntimeException::class, $aborted);
        self::assertStringStartsWith('AbortError', $aborted->getMessage());
        self::assertSame(1, $ran, 'the work does not run once the composite has aborted');
    }

    /** @return iterable<string, array{string, \Throwable}> */
    public static function agentReasonSides(): iterable
    {
        yield 'caller Exception' => ['caller', new \RuntimeException('caller cancelled')];
        yield 'bound Exception' => ['bound', new \RuntimeException('bound cancelled')];
        yield 'caller Error' => ['caller', new \Error('caller fault')];
        yield 'bound Error' => ['bound', new \Error('bound fault')];
    }

    private static function agentNode(RunnableInterface $model): AgentNode
    {
        return new AgentNode([
            'model' => $model,
            'systemMessage' => new SystemMessage('system'),
            'toolClasses' => [],
            'shouldReturnDirect' => [],
            'middleware' => [],
            'wrapModelCallHookMiddleware' => [],
        ]);
    }

    #[DataProvider('agentReasonSides')]
    public function testActualAgentNodeBoundAndCallerReasonReachesTheCallerAsTheSameThrowableBeforeTheModel(string $side, \Throwable $reason): void
    {
        $model = AgentAssertions::fakeChat([new AIMessage('model ran')]);
        [$bound] = self::counted([$side === 'bound' ? $reason : false]);
        [$caller] = self::counted([$side === 'caller' ? $reason : false]);
        $binding = self::agentNode($model)->withConfig(['signal' => $bound]);

        try {
            $binding->invoke(['messages' => [new HumanMessage('hello')], 'structuredResponse' => []], new RunnableConfig(signal: $caller));
            $caught = null;
        } catch (\Throwable $error) {
            $caught = $error;
        }

        self::assertSame($reason, $caught);
        self::assertSame(0, $model->idx, 'an aborted run must not reach the model');
    }

    public function testActualAgentNodeBoundAndCallerRouteReachesTheModelWhileBothSignalsAreLive(): void
    {
        $model = AgentAssertions::fakeChat([new AIMessage('model ran')]);
        [$bound, $boundPolls] = self::counted([false]);
        [$caller, $callerPolls] = self::counted([false]);
        $binding = self::agentNode($model)->withConfig(['signal' => $bound]);

        $commands = $binding->invoke(['messages' => [new HumanMessage('hello')], 'structuredResponse' => []], new RunnableConfig(signal: $caller));

        self::assertNotEmpty($commands);
        self::assertSame(1, $model->idx, 'the control route does reach the model, so idx 0 above is not vacuous');
        self::assertGreaterThan(0, $boundPolls->count);
        self::assertGreaterThan(0, $callerPolls->count);
    }

    /**
     * AsyncCaller polls before the loop, before each attempt, and after a failed attempt's handler and sleeper.
     * Only explicit user forwarding reaches it: no production path forwards `$config->signal` into AsyncCaller.
     */
    public function testAsyncCallerForwardingRethrowsTheComposedReasonAfterOneFailureHandlerAndSleep(): void
    {
        $reason = new \RuntimeException('user cancelled');
        $failure = new \RuntimeException('first attempt failure');
        $events = [];
        $handled = [];
        $slept = [];
        $attempts = 0;
        $leftPolls = 0;
        $rightPolls = 0;
        $left = static function () use (&$leftPolls): bool {
            ++$leftPolls;

            return false;
        };
        $right = static function () use (&$rightPolls, &$events, $reason): bool|\Throwable {
            $events[] = 'poll';

            return ++$rightPolls === 3 ? $reason : false;
        };
        $caller = new AsyncCaller(
            maxRetries: 2,
            onFailedAttempt: static function (mixed $error) use (&$events, &$handled): void {
                $events[] = 'handler';
                $handled[] = $error;
            },
            sleeper: static function (int|float $milliseconds) use (&$events, &$slept): void {
                $events[] = 'sleep';
                $slept[] = $milliseconds;
            },
        );
        $merged = RunnableConfig::mergeConfigs(new RunnableConfig(signal: $left), new RunnableConfig(signal: $right));
        $work = static function () use (&$attempts, &$events, $failure): void {
            ++$attempts;
            $events[] = 'attempt';

            throw $failure;
        };

        try {
            $caller->callWithOptions(['signal' => $merged->signal], $work);
            $caught = null;
        } catch (\Throwable $error) {
            $caught = $error;
        }

        self::assertSame($reason, $caught);
        self::assertSame(1, $attempts);
        self::assertSame([$failure], $handled);
        self::assertCount(1, $slept);
        self::assertGreaterThanOrEqual(1000, $slept[0]);
        self::assertLessThanOrEqual(2000, $slept[0]);
        self::assertSame(['poll', 'poll', 'attempt', 'handler', 'sleep', 'poll'], $events);
        self::assertSame([3, 3], [$leftPolls, $rightPolls]);
        self::assertSame($reason, ($merged->signal)(), 'the composite stays latched on the reason');
        self::assertSame([3, 3], [$leftPolls, $rightPolls]);
    }

    public function testAsyncCallerForwardingPropagatesAPollThatThrowsAfterOneFailureAndTheCompositeStaysLive(): void
    {
        $pollFailure = new \DomainException('poll failed after the failure');
        $failure = new \RuntimeException('first attempt failure');
        $handled = [];
        $slept = [];
        $attempts = 0;
        $leftPolls = 0;
        $rightPolls = 0;
        $left = static function () use (&$leftPolls): bool {
            ++$leftPolls;

            return false;
        };
        $right = static function () use (&$rightPolls, $pollFailure): bool {
            if (++$rightPolls === 3) {
                throw $pollFailure;
            }

            return false;
        };
        $caller = new AsyncCaller(
            maxRetries: 2,
            onFailedAttempt: static function (mixed $error) use (&$handled): void {
                $handled[] = $error;
            },
            sleeper: static function (int|float $milliseconds) use (&$slept): void {
                $slept[] = $milliseconds;
            },
        );
        $merged = RunnableConfig::mergeConfigs(new RunnableConfig(signal: $left), new RunnableConfig(signal: $right));
        $work = static function () use (&$attempts, $failure): void {
            ++$attempts;

            throw $failure;
        };

        try {
            $caller->callWithOptions(['signal' => $merged->signal], $work);
            $caught = null;
        } catch (\Throwable $error) {
            $caught = $error;
        }

        self::assertSame($pollFailure, $caught);
        self::assertSame(1, $attempts, 'a thrown poll ends the call without another attempt');
        self::assertSame([$failure], $handled);
        self::assertCount(1, $slept);
        self::assertSame([3, 3], [$leftPolls, $rightPolls]);

        self::assertFalse(($merged->signal)(), 'the thrown poll latched nothing');
        self::assertSame([4, 4], [$leftPolls, $rightPolls]);
        self::assertSame('done', $caller->callWithOptions(['signal' => $merged->signal], static fn (): string => 'done'));
        self::assertSame([7, 7], [$leftPolls, $rightPolls], 'a clean call polls before the loop, before the attempt and after the result');
    }
}
