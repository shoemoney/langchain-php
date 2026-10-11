<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableLambda;
use LangChain\Utils\Await;
use LangGraph\Channels\LastValue;
use LangGraph\Errors\GraphBubbleUp;
use LangGraph\Func\Func;
use LangGraph\Pregel\CallScheduler;
use LangGraph\Pregel\ChannelWrite;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use LangGraph\Pregel\PregelLoop;
use LangGraph\Pregel\PregelNode;
use LangGraph\Pregel\PregelRunner;
use LangGraph\Pregel\Retry\RetryPolicy;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Pregel::class)]
#[CoversClass(PregelLoop::class)]
#[CoversClass(PregelRunner::class)]
#[CoversClass(CallScheduler::class)]
#[CoversClass(StateGraph::class)]
final class GraphLevelRetryPolicyTest extends TestCase
{
    private static function policy(int $attempts): RetryPolicy
    {
        return new RetryPolicy(initialInterval: 1, maxAttempts: $attempts, jitter: false, logWarning: false);
    }

    public static function policies(): iterable
    {
        yield 'graph fallback' => [null, 3, 3];
        yield 'node wins' => [2, 5, 2];
        yield 'node opts out' => [1, 5, 1];
        yield 'no policy' => [null, null, 1];
    }

    #[DataProvider('policies')]
    public function testOrdinaryNodePolicyPrecedence(?int $nodeLimit, ?int $graphLimit, int $expected): void
    {
        $calls = 0;
        $error = new \RuntimeException('node exhausted');
        $graph = (new StateGraph(Annotation::root(['value' => Annotation::last()])))
            ->addNode('node', static function () use (&$calls, $error): array {
                ++$calls;
                throw $error;
            }, ['retryPolicy' => $nodeLimit === null ? null : self::policy($nodeLimit)])
            ->addEdge(Constants::START, 'node')
            ->compile(['retryPolicy' => $graphLimit === null ? null : self::policy($graphLimit)]);

        try {
            $graph->invoke(['value' => 'input']);
            self::fail('The exhausted node must throw.');
        } catch (\RuntimeException $caught) {
            self::assertSame($error, $caught);
        }
        self::assertSame($expected, $calls);
    }

    public function testGraphFallbackCanRecoverAndPublishOnlyTheSuccessfulAttempt(): void
    {
        $calls = 0;
        $graph = (new StateGraph(Annotation::root(['value' => Annotation::last()])))
            ->addNode('flaky', static function () use (&$calls): array {
                if (++$calls < 3) {
                    throw new \RuntimeException('transient');
                }

                return ['value' => 'recovered'];
            })
            ->addEdge(Constants::START, 'flaky')
            ->compile(['retryPolicy' => self::policy(3)]);

        self::assertSame(['value' => 'recovered'], $graph->invoke(['value' => 'input']));
        self::assertSame(3, $calls);
    }

    #[DataProvider('policies')]
    public function testCallTaskPolicyPrecedenceWithoutRestartingItsParent(?int $taskLimit, ?int $graphLimit, int $expected): void
    {
        $calls = 0;
        $parentCalls = 0;
        $error = new \RuntimeException('child exhausted');
        $task = Func::task(['name' => 'child', 'retry' => $taskLimit === null ? null : self::policy($taskLimit)], static function () use (&$calls, $error): never {
            ++$calls;
            throw $error;
        });
        $graph = Func::entrypoint('parent', static function () use ($task, $error, &$parentCalls): string {
            ++$parentCalls;
            try {
                Await::sync($task());
            } catch (\RuntimeException $caught) {
                self::assertSame($error, $caught);

                return 'child exhausted';
            }

            self::fail('The child must fail.');
        });
        $graph->retryPolicy = $graphLimit === null ? null : self::policy($graphLimit);

        self::assertSame('child exhausted', $graph->invoke([]));
        self::assertSame($expected, $calls);
        self::assertSame(1, $parentCalls);
    }

    public function testCallTaskRecoversUsingGraphFallback(): void
    {
        $calls = 0;
        $task = Func::task('flaky', static function (int $input) use (&$calls): int {
            if (++$calls < 3) {
                throw new \RuntimeException('transient child');
            }

            return $input * 2;
        });
        $graph = Func::entrypoint('parent', static fn (int $input): int => Await::sync($task($input)));
        $graph->retryPolicy = self::policy(3);

        self::assertSame(14, $graph->invoke(7));
        self::assertSame(3, $calls);
    }

    public static function executionPaths(): iterable
    {
        yield 'ordinary' => [false];
        yield 'call' => [true];
    }

    #[DataProvider('executionPaths')]
    public function testGraphRetryOnCanDeclineAnError(bool $call): void
    {
        $calls = 0;
        $decisions = [];
        $error = new \RuntimeException('do not retry');
        $policy = new RetryPolicy(initialInterval: 1, maxAttempts: 4, jitter: false, retryOn: static function (\Throwable $caught) use (&$decisions): bool {
            $decisions[] = $caught;

            return false;
        }, logWarning: false);
        $body = static function () use (&$calls, $error): never {
            ++$calls;
            throw $error;
        };
        if ($call) {
            $task = Func::task('child', $body);
            $graph = Func::entrypoint('parent', static function () use ($task): string {
                try {
                    Await::sync($task());
                } catch (\RuntimeException) {
                    return 'declined';
                }

                self::fail('The child must fail.');
            });
            $graph->retryPolicy = $policy;
            self::assertSame('declined', $graph->invoke([]));
        } else {
            $graph = (new StateGraph(Annotation::root(['value' => Annotation::last()])))
                ->addNode('node', $body)->addEdge(Constants::START, 'node')->compile(['retryPolicy' => $policy]);
            try {
                $graph->invoke(['value' => 'input']);
                self::fail('The node must fail.');
            } catch (\RuntimeException $caught) {
                self::assertSame($error, $caught);
            }
        }
        self::assertSame(1, $calls);
        self::assertSame([$error], $decisions);
    }

    #[DataProvider('executionPaths')]
    public function testBubbleUpBypassesEvenAnAlwaysRetryPredicate(bool $call): void
    {
        $calls = 0;
        $decisions = 0;
        $signal = new GraphBubbleUp('control flow');
        $policy = new RetryPolicy(initialInterval: 1, maxAttempts: 4, jitter: false, retryOn: static function () use (&$decisions): bool {
            ++$decisions;

            return true;
        }, logWarning: false);
        $body = static function () use (&$calls, $signal): never {
            ++$calls;
            throw $signal;
        };
        if ($call) {
            $task = Func::task('child', $body);
            $graph = Func::entrypoint('parent', static fn () => Await::sync($task()));
            $graph->retryPolicy = $policy;
        } else {
            $graph = (new StateGraph(Annotation::root(['value' => Annotation::last()])))
                ->addNode('node', $body)->addEdge(Constants::START, 'node')->compile(['retryPolicy' => $policy]);
        }
        try {
            $graph->invoke($call ? [] : ['value' => 'input']);
            self::fail('The control signal must propagate.');
        } catch (GraphBubbleUp $caught) {
            self::assertSame($signal, $caught);
        }
        self::assertSame(1, $calls);
        self::assertSame(0, $decisions);
    }

    public function testExhaustedHandlerNeverRestartsTheNodeWrapper(): void
    {
        $nodeCalls = 0;
        $handlerCalls = 0;
        $error = new \RuntimeException('handler exhausted');
        $graph = (new StateGraph(Annotation::root(['value' => Annotation::last()])))
            ->addNode('handled', static function () use (&$nodeCalls): never {
                ++$nodeCalls;
                throw new \RuntimeException('node exhausted');
            }, [
                'retryPolicy' => self::policy(2),
                'errorHandler' => static function () use (&$handlerCalls, $error): never {
                    ++$handlerCalls;
                    throw $error;
                },
            ])
            ->addEdge(Constants::START, 'handled')
            ->compile(['retryPolicy' => self::policy(3)]);

        try {
            $graph->invoke(['value' => 'input']);
            self::fail('The exhausted handler must throw.');
        } catch (\RuntimeException $caught) {
            self::assertSame($error, $caught);
        }
        self::assertSame(2, $nodeCalls, 'Node policy wins; engine never restarts the wrapper.');
        self::assertSame(3, $handlerCalls, 'Handler inherits the graph policy once.');
    }

    public function testHandledNodeInheritsGraphPolicyInsideTheWrapper(): void
    {
        $nodeCalls = 0;
        $handlerCalls = 0;
        $graph = (new StateGraph(Annotation::root(['value' => Annotation::last()])))
            ->addNode('handled', static function () use (&$nodeCalls): never {
                ++$nodeCalls;
                throw new \RuntimeException('node exhausted');
            }, ['errorHandler' => static function () use (&$handlerCalls): array {
                if (++$handlerCalls < 2) {
                    throw new \RuntimeException('handler transient');
                }

                return ['value' => 'handled'];
            }])
            ->addEdge(Constants::START, 'handled')
            ->compile(['retryPolicy' => self::policy(3)]);

        self::assertSame(['value' => 'handled'], $graph->invoke(['value' => 'input']));
        self::assertSame(3, $nodeCalls);
        self::assertSame(2, $handlerCalls);
    }

    public function testDefaultHandlerPolicyWinsOverGraphFallback(): void
    {
        $nodeCalls = 0;
        $handlerCalls = 0;
        $graph = (new StateGraph(Annotation::root(['value' => Annotation::last()])))
            ->setNodeDefaults(['retryPolicy' => self::policy(1)])
            ->addNode('handled', static function () use (&$nodeCalls): never {
                ++$nodeCalls;
                throw new \RuntimeException('node exhausted');
            }, [
                'retryPolicy' => self::policy(2),
                'errorHandler' => static function () use (&$handlerCalls): never {
                    ++$handlerCalls;
                    throw new \RuntimeException('handler exhausted');
                },
            ])
            ->addEdge(Constants::START, 'handled')->compile(['retryPolicy' => self::policy(4)]);
        try {
            $graph->invoke(['value' => 'input']);
            self::fail('The handler must fail.');
        } catch (\RuntimeException $error) {
            self::assertSame('handler exhausted', $error->getMessage());
        }
        self::assertSame(2, $nodeCalls);
        self::assertSame(1, $handlerCalls);
    }

    #[DataProvider('executionPaths')]
    public function testHandledWrapperNeverRetriesBubbleUp(bool $fromHandler): void
    {
        $nodeCalls = 0;
        $handlerCalls = 0;
        $decisions = 0;
        $signal = new GraphBubbleUp('control flow');
        $policy = new RetryPolicy(initialInterval: 1, maxAttempts: 3, jitter: false, retryOn: static function () use (&$decisions): bool {
            ++$decisions;

            return true;
        }, logWarning: false);
        $graph = (new StateGraph(Annotation::root(['value' => Annotation::last()])))
            ->addNode('handled', static function () use (&$nodeCalls, $signal, $fromHandler): never {
                ++$nodeCalls;
                throw $fromHandler ? new \RuntimeException('node exhausted') : $signal;
            }, ['errorHandler' => static function () use (&$handlerCalls, $signal): never {
                ++$handlerCalls;
                throw $signal;
            }])
            ->addEdge(Constants::START, 'handled')->compile(['retryPolicy' => $policy]);
        try {
            $graph->invoke(['value' => 'input']);
            self::fail('The control signal must propagate.');
        } catch (GraphBubbleUp $caught) {
            self::assertSame($signal, $caught);
        }
        self::assertSame($fromHandler ? 3 : 1, $nodeCalls);
        self::assertSame($fromHandler ? 1 : 0, $handlerCalls);
        self::assertSame($fromHandler ? 2 : 0, $decisions, 'Only ordinary node failures reach retryOn.');
    }

    public function testDirectPregelConstructorPolicyRetriesItsTask(): void
    {
        $calls = 0;
        $graph = new Pregel(
            nodes: ['node' => new PregelNode(
                channels: ['input'],
                triggers: ['input'],
                bound: new RunnableLambda(static function (int $input) use (&$calls): int {
                    if (++$calls < 3) {
                        throw new \RuntimeException('transient');
                    }

                    return $input * 2;
                }),
                writers: [new ChannelWrite([['channel' => 'output', 'value' => ChannelWrite::passthrough()]])],
            )],
            channels: ['input' => new LastValue(), 'output' => new LastValue()],
            inputChannels: 'input',
            outputChannels: 'output',
            retryPolicy: self::policy(3),
            triggerToNodes: ['input' => ['node']],
        );

        self::assertSame(14, $graph->invoke(7));
        self::assertSame(3, $calls);
    }

    public function testHandledWrapperUsesGraphRetryOnForNodeAndHandler(): void
    {
        $nodeCalls = 0;
        $handlerCalls = 0;
        $decisions = [];
        $nodeError = new \RuntimeException('node declined');
        $handlerError = new \RuntimeException('handler declined');
        $policy = new RetryPolicy(initialInterval: 1, maxAttempts: 4, jitter: false, retryOn: static function (\Throwable $error) use (&$decisions): bool {
            $decisions[] = $error;

            return false;
        }, logWarning: false);
        $graph = (new StateGraph(Annotation::root(['value' => Annotation::last()])))
            ->addNode('handled', static function () use (&$nodeCalls, $nodeError): never {
                ++$nodeCalls;
                throw $nodeError;
            }, ['errorHandler' => static function () use (&$handlerCalls, $handlerError): never {
                ++$handlerCalls;
                throw $handlerError;
            }])
            ->addEdge(Constants::START, 'handled')->compile(['retryPolicy' => $policy]);
        try {
            $graph->invoke(['value' => 'input']);
            self::fail('The handler must fail.');
        } catch (\RuntimeException $caught) {
            self::assertSame($handlerError, $caught);
        }
        self::assertSame(1, $nodeCalls);
        self::assertSame(1, $handlerCalls);
        self::assertSame([$nodeError, $handlerError], $decisions);
    }
}
