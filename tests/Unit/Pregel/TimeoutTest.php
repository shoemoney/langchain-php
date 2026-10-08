<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\BaseCallbackHandler;
use LangGraph\Errors\GraphBubbleUp;
use LangGraph\Errors\NodeTimeoutError;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use LangGraph\Pregel\PregelExecutableTask;
use LangGraph\Pregel\Retry\RetryPolicy;
use LangGraph\Pregel\Send;
use LangGraph\Pregel\Timeout;
use LangGraph\Pregel\TimeoutPolicy;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of the non-timer parts of `langgraph-core/src/tests/timeout.test.ts`.
 *
 * ## What a timeout means here
 *
 * A PHP node runs to completion on one thread, so nothing can interrupt it and no timer can fire
 * while it runs. {@see Timeout} therefore judges the budget AFTER the node returns or throws: the
 * verdict (`run` vs `idle`), the discarded writes, the error and the retry behaviour are
 * upstream's; the pre-emption is not. Upstream itself re-checks wall-clock time after its race for
 * synchronous CPU-bound nodes (its timers cannot fire there either), so the CPU-bound cases below
 * are the ones that port one to one. A node is made "slow" by BURNING CPU for a measured interval
 * ({@see self::burn()}), never by sleeping and never by faking the clock: the clock the code reads is
 * the real one.
 *
 * Not ported, and why:
 *  - "aborts the node's signal when the timeout fires", "does not surface an unhandled rejection
 *    when the abandoned attempt rejects after timeout": both need an attempt that is still running
 *    when the timeout fires (an abandoned background promise). Here the attempt has already
 *    finished by the time a verdict exists;
 *  - "functional API timeout" (3 cases): `task()` / `entrypoint()` are WP-07.
 *  - the `sleep`-based "fires a run/idle timeout" cases are covered by their CPU-bound equivalents.
 */
#[CoversClass(Timeout::class)]
#[CoversClass(TimeoutPolicy::class)]
#[CoversClass(NodeTimeoutError::class)]
final class TimeoutTest extends TestCase
{
    /** Burn CPU for about `$ms` milliseconds (a CPU-bound node), measured on the monotonic clock. */
    private static function burn(float $ms): void
    {
        $end = hrtime(true) / 1_000_000 + $ms;
        while (hrtime(true) / 1_000_000 < $end) {
            // spin
        }
    }

    /**
     * A bare task whose write collector is `$task->writes`, as `Algorithm` wires a real one.
     */
    private static function task(string $name = 'timed'): PregelExecutableTask
    {
        return new PregelExecutableTask(id: 'task-1', name: $name, triggers: [$name]);
    }

    private static function configFor(PregelExecutableTask $task): RunnableConfig
    {
        return new RunnableConfig(configurable: [
            Constants::CONFIG_KEY_SEND => static function (array $writes) use ($task): void {
                foreach ($writes as $write) {
                    $task->writes[] = $write;
                }
            },
            'thread_id' => 'thread-1',
        ]);
    }

    /**
     * @param callable(RunnableConfig): mixed         $func
     * @param int|float|TimeoutPolicy|array<string, mixed> $timeout
     * @return array{0: mixed, 1: ?\Throwable, 2: PregelExecutableTask}
     */
    private static function attempt(callable $func, int|float|TimeoutPolicy|array $timeout, string $name = 'timed'): array
    {
        $task = self::task($name);
        $policy = Timeout::coerceTimeoutPolicy($timeout);
        self::assertNotNull($policy);

        try {
            $result = Timeout::runAttemptWithTimeout($task, self::configFor($task), $policy, $func);

            return [$result, null, $task];
        } catch (\Throwable $e) {
            return [null, $e, $task];
        }
    }

    // ---- coerceTimeoutPolicy ------------------------------------------------------------------

    public function testItNormalizesScalarsAndPoliciesAndRejectsNonPositiveTimeouts(): void
    {
        self::assertNull(Timeout::coerceTimeoutPolicy(null));

        $scalar = Timeout::coerceTimeoutPolicy(1500);
        self::assertEquals(new TimeoutPolicy(1500, null, 'auto'), $scalar);

        $idle = Timeout::coerceTimeoutPolicy(['idleTimeout' => 250]);
        self::assertEquals(new TimeoutPolicy(null, 250, 'auto'), $idle);

        // An empty policy collapses to "no timeout".
        self::assertNull(Timeout::coerceTimeoutPolicy([]));

        foreach ([0, ['idleTimeout' => 0], -5, ['runTimeout' => -1]] as $bad) {
            try {
                Timeout::coerceTimeoutPolicy($bad);
                self::fail('expected a rejection of ' . json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('greater than 0', $e->getMessage());
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('refreshOn must be "auto" or "heartbeat"');
        Timeout::coerceTimeoutPolicy(['runTimeout' => 1, 'refreshOn' => 'nope']);
    }

    public function testAPolicyObjectIsRevalidatedAndFloatsAreAccepted(): void
    {
        self::assertEquals(new TimeoutPolicy(0.5, null, 'heartbeat'), Timeout::coerceTimeoutPolicy(new TimeoutPolicy(0.5, null, 'heartbeat')));
        self::assertNull(Timeout::coerceTimeoutPolicy(new TimeoutPolicy()));

        $this->expectException(\InvalidArgumentException::class);
        Timeout::coerceTimeoutPolicy(new TimeoutPolicy(runTimeout: 0));
    }

    // ---- NodeTimeoutError ---------------------------------------------------------------------

    public function testNodeTimeoutErrorCarriesItsFieldsAndIsNotAGraphBubbleUp(): void
    {
        $err = new NodeTimeoutError(node: 'n', elapsed: 12, kind: 'idle', runTimeout: 100, idleTimeout: 50);

        self::assertSame('n', $err->node);
        self::assertSame('idle', $err->kind);
        self::assertSame(50, $err->timeout);
        self::assertSame(50, $err->idleTimeout);
        self::assertSame(100, $err->runTimeout);
        self::assertSame(12, $err->elapsed);
        self::assertSame(
            'Node "n" exceeded its idle timeout of 50ms without making progress (elapsed: 12ms).',
            $err->getMessage(),
        );
        self::assertTrue(NodeTimeoutError::is($err));
        self::assertFalse(NodeTimeoutError::is(new \RuntimeException()));
        // A timeout is an ordinary node failure, so a retry policy may retry it.
        self::assertNotInstanceOf(GraphBubbleUp::class, $err);

        $run = new NodeTimeoutError(node: 'n', elapsed: 7, kind: 'run', runTimeout: 40);
        self::assertSame(40, $run->timeout);
        self::assertSame('Node "n" exceeded its run timeout of 40ms (elapsed: 7ms).', $run->getMessage());
    }

    public function testNodeTimeoutErrorRequiresTheMatchingTimeoutForTheFiredKind(): void
    {
        try {
            new NodeTimeoutError(node: 'n', elapsed: 1, kind: 'run');
            self::fail('expected an exception');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('runTimeout is required', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('idleTimeout is required');
        new NodeTimeoutError(node: 'n', elapsed: 1, kind: 'idle');
    }

    // ---- runAttemptWithTimeout ------------------------------------------------------------------

    public function testItFiresARunTimeoutForACpuBoundNode(): void
    {
        [$result, $error] = self::attempt(static function (): string {
            self::burn(150);

            return 'done';
        }, 50, 'cpu-bound');

        self::assertNull($result);
        self::assertInstanceOf(NodeTimeoutError::class, $error);
        self::assertSame('run', $error->kind);
        self::assertSame('cpu-bound', $error->node);
        self::assertSame(50, $error->runTimeout);
        self::assertGreaterThanOrEqual(50, $error->elapsed);
    }

    public function testItDiscardsTheWritesOfAnAttemptThatBlewItsBudget(): void
    {
        [, $error, $task] = self::attempt(static function (RunnableConfig $config): string {
            $config->configurable[Constants::CONFIG_KEY_SEND]([['value', 'stale']]);
            self::burn(150);

            return 'done';
        }, 50, 'cpu-writer');

        self::assertInstanceOf(NodeTimeoutError::class, $error);
        self::assertSame([], $task->writes);
    }

    public function testItKeepsTheWritesOfAnAttemptThatFinishedInTime(): void
    {
        [$result, $error, $task] = self::attempt(static function (RunnableConfig $config): string {
            $config->configurable[Constants::CONFIG_KEY_SEND]([['value', 'fresh']]);

            return 'ok';
        }, 1000);

        self::assertNull($error);
        self::assertSame('ok', $result);
        self::assertSame([['value', 'fresh']], $task->writes);
    }

    public function testItDoesNotFalselyTimeOutAFastNode(): void
    {
        [$result, $error] = self::attempt(static function (): string {
            self::burn(10);

            return 'done';
        }, 200, 'cpu-fast');

        self::assertNull($error);
        self::assertSame('done', $result);
    }

    public function testATimeoutReplacesTheErrorTheNodeRaised(): void
    {
        // A node that fails after blowing its budget is reported as the timeout it was; upstream
        // overwrites a late `err` outcome the same way.
        [, $error] = self::attempt(static function (): void {
            self::burn(100);

            throw new \RuntimeException('late failure');
        }, 30);

        self::assertInstanceOf(NodeTimeoutError::class, $error);
    }

    public function testANodeErrorWithinBudgetPropagatesUnchanged(): void
    {
        [, $error] = self::attempt(static function (): void {
            throw new \RuntimeException('fast failure');
        }, 1000);

        self::assertInstanceOf(\RuntimeException::class, $error);
        self::assertSame('fast failure', $error->getMessage());
    }

    public function testItFiresAnIdleTimeoutWhenNoProgressIsMade(): void
    {
        [, $error] = self::attempt(static function (): string {
            self::burn(120);

            return 'late';
        }, ['idleTimeout' => 50], 'idleslow');

        self::assertInstanceOf(NodeTimeoutError::class, $error);
        self::assertSame('idle', $error->kind);
        self::assertSame('idleslow', $error->node);
        self::assertSame(50, $error->idleTimeout);
    }

    public function testHeartbeatsKeepAnIdleNodeAlive(): void
    {
        // 200 ms in total, against a 150 ms idle budget: only the longest SILENCE (40 ms) counts.
        [$result, $error] = self::attempt(static function (RunnableConfig $config): string {
            for ($i = 0; $i < 5; $i++) {
                self::burn(40);
                $config->options['heartbeat']();
            }

            return 'ok';
        }, ['idleTimeout' => 150], 'heartbeating');

        self::assertNull($error);
        self::assertSame('ok', $result);
    }

    public function testASilenceInTheMiddleIsCaughtEvenIfProgressResumesBeforeTheEnd(): void
    {
        // A timer would have fired during the 90 ms gap; the late heartbeat does not undo that.
        [, $error] = self::attempt(static function (RunnableConfig $config): string {
            $config->options['heartbeat']();
            self::burn(90);
            $config->options['heartbeat']();

            return 'ok';
        }, ['idleTimeout' => 50], 'gappy');

        self::assertInstanceOf(NodeTimeoutError::class, $error);
        self::assertSame('idle', $error->kind);
    }

    public function testWritesRefreshTheIdleClockUnderAuto(): void
    {
        [$result, $error] = self::attempt(static function (RunnableConfig $config): string {
            for ($i = 0; $i < 5; $i++) {
                self::burn(40);
                $config->configurable[Constants::CONFIG_KEY_SEND]([['value', $i]]);
            }

            return 'ok';
        }, ['idleTimeout' => 150], 'auto-idle');

        self::assertNull($error);
        self::assertSame('ok', $result);
    }

    public function testUnderRefreshOnHeartbeatOnlyHeartbeatsRefreshTheIdleClock(): void
    {
        // The node writes (an automatic progress signal) but never heartbeats, so the strict idle
        // clock still fires.
        [, $error] = self::attempt(static function (RunnableConfig $config): string {
            for ($i = 0; $i < 5; $i++) {
                self::burn(30);
                $config->configurable[Constants::CONFIG_KEY_SEND]([['value', $i]]);
            }

            return 'ok';
        }, ['idleTimeout' => 80, 'refreshOn' => 'heartbeat'], 'strict-idle');

        self::assertInstanceOf(NodeTimeoutError::class, $error);
        self::assertSame('idle', $error->kind);
    }

    public function testCallbackEventsRefreshTheIdleClockUnderAuto(): void
    {
        $fire = static function (RunnableConfig $config): void {
            foreach ($config->callbacks as $callback) {
                if ($callback instanceof BaseCallbackHandler) {
                    $callback->handleText('tick', 'run-1');
                }
            }
        };

        [$result, $error] = self::attempt(static function (RunnableConfig $config) use ($fire): string {
            for ($i = 0; $i < 4; $i++) {
                self::burn(40);
                $fire($config);
            }

            return 'ok';
        }, ['idleTimeout' => 150], 'callbacks');
        self::assertNull($error);
        self::assertSame('ok', $result);

        // Under `heartbeat` the handler is not even attached.
        [, $strictError] = self::attempt(static function (RunnableConfig $config) use ($fire): string {
            self::assertSame([], $config->callbacks);
            for ($i = 0; $i < 4; $i++) {
                self::burn(40);
                $fire($config);
            }

            return 'ok';
        }, ['idleTimeout' => 150, 'refreshOn' => 'heartbeat'], 'callbacks-strict');
        self::assertInstanceOf(NodeTimeoutError::class, $strictError);
    }

    public function testTheCustomStreamWriterCountsAsProgress(): void
    {
        $task = self::task('writer-progress');
        $policy = Timeout::coerceTimeoutPolicy(['idleTimeout' => 150]);
        $sent = [];
        $config = self::configFor($task);
        $config->options['writer'] = static function (mixed $chunk) use (&$sent): void {
            $sent[] = $chunk;
        };

        $result = Timeout::runAttemptWithTimeout($task, $config, $policy, static function (RunnableConfig $scoped): string {
            for ($i = 0; $i < 4; $i++) {
                self::burn(40);
                $scoped->options['writer']($i);
            }

            return 'ok';
        });

        self::assertSame('ok', $result);
        self::assertSame([0, 1, 2, 3], $sent);
    }

    public function testHeartbeatIsANoOpWithoutAnIdleTimeout(): void
    {
        [$result, $error] = self::attempt(static function (RunnableConfig $config): string {
            $config->options['heartbeat']();

            return 'ok';
        }, 1000);

        self::assertNull($error);
        self::assertSame('ok', $result);
    }

    public function testALateWriteFromAClosedAttemptIsDropped(): void
    {
        $leaked = null;
        [, $error, $task] = self::attempt(static function (RunnableConfig $config) use (&$leaked): string {
            $leaked = $config->configurable[Constants::CONFIG_KEY_SEND];
            self::burn(100);

            return 'late';
        }, 30);
        self::assertInstanceOf(NodeTimeoutError::class, $error);

        // The attempt is over; a straggler that kept the send function must not reach the buffer.
        $leaked([['value', 'straggler']]);

        self::assertSame([], $task->writes);
    }

    public function testALateChildCallFromAClosedAttemptIsRefused(): void
    {
        $task = self::task('caller');
        $config = self::configFor($task);
        $config->configurable[Constants::CONFIG_KEY_CALL] = static fn (): string => 'scheduled';
        $leaked = null;

        try {
            Timeout::runAttemptWithTimeout($task, $config, Timeout::coerceTimeoutPolicy(30), static function (RunnableConfig $scoped) use (&$leaked): string {
                $leaked = $scoped->configurable[Constants::CONFIG_KEY_CALL];
                self::assertSame('scheduled', $leaked());
                self::burn(60);

                return 'late';
            });
            self::fail('expected a timeout');
        } catch (NodeTimeoutError) {
            // expected
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Node "caller" attempt was cancelled after its timeout fired');
        $leaked();
    }

    // ---- through a real graph ---------------------------------------------------------------------

    /**
     * Give a compiled node a timeout. `StateGraph::addNode()` does not forward one yet, so it is set
     * on the node object (read through a local: `nodes` is a readonly array).
     *
     * @param int|float|array<string, mixed> $timeout
     */
    private static function timeoutOf(Pregel $graph, string $node, int|float|array $timeout): void
    {
        $pregelNode = $graph->nodes[$node];
        $pregelNode->timeout = $timeout;
    }

    private static function xSchema(): \LangGraph\State\AnnotationRoot
    {
        return Annotation::root(['x' => Annotation::last()]);
    }

    public function testAGraphNodeRaisesNodeTimeoutErrorWhenItExceedsItsRunTimeout(): void
    {
        $graph = (new StateGraph(self::xSchema()))
            ->addNode('slow', static function (array $state): array {
                self::burn(120);

                return ['x' => $state['x'] + 1];
            })
            ->addEdge(Constants::START, 'slow')
            ->addEdge('slow', Constants::END)
            ->compile();
        self::timeoutOf($graph, 'slow', 50);

        try {
            $graph->invoke(['x' => 1]);
            self::fail('expected NodeTimeoutError');
        } catch (NodeTimeoutError $e) {
            self::assertSame('slow', $e->node);
            self::assertSame('run', $e->kind);
        }
    }

    public function testATimedOutNodeComposesWithARetryPolicyAndTheClockRestartsPerAttempt(): void
    {
        $attempts = 0;
        $graph = (new StateGraph(self::xSchema()))
            ->addNode('flaky', static function (array $state) use (&$attempts): array {
                $attempts++;
                if ($attempts < 2) {
                    self::burn(150);
                }

                return ['x' => $state['x'] + 1];
            }, ['retryPolicy' => new RetryPolicy(initialInterval: 1, maxAttempts: 3, jitter: false, logWarning: false)])
            ->addEdge(Constants::START, 'flaky')
            ->addEdge('flaky', Constants::END)
            ->compile();
        self::timeoutOf($graph, 'flaky', 80);

        // Attempt 1 blows its budget; the default retryOn treats the timeout as retryable; attempt
        // 2 gets a fresh clock and finishes well inside it.
        self::assertSame(['x' => 1], $graph->invoke(['x' => 0]));
        self::assertSame(2, $attempts);
    }

    public function testATimedOutAttemptsWritesDoNotReachTheStateWhenARetrySucceeds(): void
    {
        $attempts = 0;
        $graph = (new StateGraph(Annotation::root(['trail' => Annotation::withReducer(
            static fn (array $a, array $b): array => array_merge($a, $b),
            static fn (): array => [],
        )])))
            ->addNode('flaky', static function () use (&$attempts): array {
                $attempts++;
                if ($attempts === 1) {
                    self::burn(100);

                    return ['trail' => ['stale']];
                }

                return ['trail' => ['fresh']];
            }, ['retryPolicy' => new RetryPolicy(initialInterval: 1, maxAttempts: 2, jitter: false, logWarning: false)])
            ->addEdge(Constants::START, 'flaky')
            ->addEdge('flaky', Constants::END)
            ->compile();
        self::timeoutOf($graph, 'flaky', 40);

        self::assertSame(['trail' => ['fresh']], $graph->invoke(['trail' => []]));
    }

    public function testASendCanOverrideTheTargetNodesTimeoutForOnePushedTask(): void
    {
        $graph = (new StateGraph(self::xSchema()))
            ->addNode('slow', static function (array $state): array {
                self::burn(120);

                return ['x' => ($state['x'] ?? 0) + 1];
            })
            ->addConditionalEdges(
                Constants::START,
                static fn (array $state): array => [new Send('slow', $state, ['idleTimeout' => 50])],
                ['slow'],
            )
            ->addEdge('slow', Constants::END)
            ->compile();
        // A generous node-level idle timeout; the packet's tighter one wins.
        self::timeoutOf($graph, 'slow', ['idleTimeout' => 5000]);

        try {
            $graph->invoke(['x' => 1]);
            self::fail('expected NodeTimeoutError');
        } catch (NodeTimeoutError $e) {
            self::assertSame('slow', $e->node);
            self::assertSame('idle', $e->kind);
            self::assertSame(50, $e->idleTimeout);
        }
    }

    public function testAnInvalidNodeTimeoutIsRejectedWhenTheTaskIsPrepared(): void
    {
        $graph = (new StateGraph(self::xSchema()))
            ->addNode('only', static fn (): array => ['x' => 1])
            ->addEdge(Constants::START, 'only')
            ->addEdge('only', Constants::END)
            ->compile();
        self::timeoutOf($graph, 'only', 0);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('runTimeout must be greater than 0');
        $graph->invoke(['x' => 1]);
    }

    public function testNodesWithoutATimeoutAreNotWrapped(): void
    {
        $graph = (new StateGraph(self::xSchema()))
            ->addNode('only', static function (array $state, RunnableConfig $config): array {
                // No scope was installed: `heartbeat` only exists inside a timed attempt.
                return ['x' => isset($config->options['heartbeat']) ? -1 : $state['x'] + 1];
            })
            ->addEdge(Constants::START, 'only')
            ->addEdge('only', Constants::END)
            ->compile();

        self::assertSame(['x' => 2], $graph->invoke(['x' => 1]));
    }

    public function testATimedNodeReceivesAHeartbeat(): void
    {
        $graph = (new StateGraph(self::xSchema()))
            ->addNode('only', static function (array $state, RunnableConfig $config): array {
                return ['x' => is_callable($config->options['heartbeat'] ?? null) ? 7 : -1];
            })
            ->addEdge(Constants::START, 'only')
            ->addEdge('only', Constants::END)
            ->compile();
        self::timeoutOf($graph, 'only', 1000);

        self::assertSame(['x' => 7], $graph->invoke(['x' => 1]));
        self::assertInstanceOf(Pregel::class, $graph);
    }
}
