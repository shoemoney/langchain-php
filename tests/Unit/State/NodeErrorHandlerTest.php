<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\State;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Errors\NodeError;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Retry\RetryPolicy;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangGraph\Pregel\interrupt;

/**
 * Port of `langgraph-core/src/tests/node_error_handler.test.ts`: node-level error handlers.
 *
 * Differences from upstream, all forced by the engine (`Pregel*.php` is out of this package's reach):
 *
 *  - upstream schedules the handler as its own checkpointed task, so "preserves the failure context
 *    across a checkpoint resume" interrupts *before* the `__error_handler__<node>` task and resumes
 *    into it. Here the handler runs inline after the node's retries, so there is no such task to stop
 *    before; the case is converted to "the handled outcome is what gets checkpointed";
 *  - the two async-handler cases collapse into the synchronous ones (PHP has no async functions);
 *    the second is kept as "the handler also receives the config and the NodeError in it".
 */
#[CoversClass(StateGraph::class)]
final class NodeErrorHandlerTest extends TestCase
{
    private static function foo(): \LangGraph\State\AnnotationRoot
    {
        return Annotation::root(['foo' => Annotation::last()]);
    }

    private static function retryTwice(): RetryPolicy
    {
        return new RetryPolicy(initialInterval: 1, maxAttempts: 2, jitter: false, retryOn: static fn (): bool => true, logWarning: false);
    }

    public function testRunsTheHandlerOnlyAfterTheRetryPolicyIsExhausted(): void
    {
        $attempts = 0;
        $handlerCalls = 0;
        $captured = [];

        $graph = (new StateGraph(self::foo()))
            ->addNode('alwaysFailing', static function () use (&$attempts): array {
                $attempts += 1;
                throw new \RuntimeException('Always fails');
            }, [
                'retryPolicy' => self::retryTwice(),
                'errorHandler' => static function (array $state, NodeError $error) use (&$captured, &$handlerCalls, &$attempts): Command {
                    $handlerCalls += 1;
                    $captured = ['node' => $error->node, 'error' => $error->error, 'attemptsSoFar' => $attempts];

                    return new Command(update: ['foo' => 'handled'], goto: 'afterHandler');
                },
            ])
            ->addNode('afterHandler', static fn (array $state): array => ['foo' => $state['foo'] . '_after'])
            ->addEdge(Constants::START, 'alwaysFailing')
            ->compile();

        $result = $graph->invoke(['foo' => '']);

        self::assertSame(2, $attempts);
        self::assertSame(1, $handlerCalls);
        self::assertSame(2, $captured['attemptsSoFar'], 'the handler ran after both attempts, not between them');
        self::assertSame('handled_after', $result['foo']);
        self::assertSame('alwaysFailing', $captured['node']);
        self::assertInstanceOf(\RuntimeException::class, $captured['error']);
        self::assertSame('Always fails', $captured['error']->getMessage());
    }

    public function testTheHandlerCanRouteToARecoveryBranchWithCommandGoto(): void
    {
        $attempts = 0;
        $graph = (new StateGraph(self::foo()))
            ->addNode('alwaysFailing', static function () use (&$attempts): array {
                $attempts += 1;
                throw new \RuntimeException('Always fails');
            }, [
                'retryPolicy' => new RetryPolicy(initialInterval: 1, maxAttempts: 1, jitter: false, logWarning: false),
                'errorHandler' => static fn (): Command => new Command(update: ['foo' => 'handled'], goto: 'nextNode'),
            ])
            ->addNode('nextNode', static fn (array $state): array => ['foo' => $state['foo'] . '_next'])
            ->addEdge(Constants::START, 'alwaysFailing')
            ->compile();

        self::assertSame('handled_next', $graph->invoke(['foo' => ''])['foo']);
        self::assertSame(1, $attempts);
    }

    public function testFailsTheRunWhenTheErrorHandlerItselfThrows(): void
    {
        $graph = (new StateGraph(self::foo()))
            ->addNode('alwaysFailing', static function (): array {
                throw new \RuntimeException('Always fails');
            }, [
                'errorHandler' => static function (): array {
                    throw new \RuntimeException('handler failed');
                },
            ])
            ->addEdge(Constants::START, 'alwaysFailing')
            ->compile();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('handler failed');

        $graph->invoke(['foo' => '']);
    }

    public function testHandlesAFailureThatOccursInsideASubgraphNode(): void
    {
        $captured = [];

        $subgraph = (new StateGraph(self::foo()))
            ->addNode('subFail', static function (): array {
                throw new \RuntimeException('subgraph boom');
            })
            ->addEdge(Constants::START, 'subFail')
            ->compile();

        $parent = (new StateGraph(self::foo()))
            ->addNode('subgraphNode', $subgraph, [
                'errorHandler' => static function (array $state, NodeError $error) use (&$captured): array {
                    $captured = ['node' => $error->node, 'error' => $error->error];

                    return ['foo' => 'handled_by_parent'];
                },
            ])
            ->addEdge(Constants::START, 'subgraphNode')
            ->compile();

        $result = $parent->invoke(['foo' => '']);

        self::assertSame('handled_by_parent', $result['foo']);
        self::assertSame('subgraphNode', $captured['node']);
        self::assertSame('subgraph boom', $captured['error']->getMessage());
    }

    public function testTheHandledOutcomeIsWhatGetsCheckpointed(): void
    {
        $graph = (new StateGraph(self::foo()))
            ->addNode('alwaysFailing', static function (): array {
                throw new \RuntimeException('failed before handler');
            }, [
                'errorHandler' => static fn (array $state, NodeError $error): array => ['foo' => 'handled:' . $error->error->getMessage()],
            ])
            ->addEdge(Constants::START, 'alwaysFailing')
            ->compile(['checkpointer' => new MemorySaver()]);

        $config = new RunnableConfig(configurable: ['thread_id' => 'graph-error-resume']);

        $graph->invoke(['foo' => ''], $config);
        $snapshot = $graph->getState($config);

        self::assertSame('handled:failed before handler', $snapshot->values['foo']);
        self::assertSame([], $snapshot->next, 'the run finished; nothing is left to resume');
    }

    public function testDoesNotSwallowAConcurrentInterrupt(): void
    {
        $graph = (new StateGraph(self::foo()))
            ->addNode('nodeA', static function (): array {
                $val = interrupt('need human input');

                return ['foo' => 'a_' . $val];
            }, ['errorHandler' => static fn (): array => ['foo' => 'handled']])
            ->addNode('nodeB', static fn (): array => [])
            ->addEdge(Constants::START, 'nodeA')
            ->addEdge(Constants::START, 'nodeB')
            ->compile(['checkpointer' => new MemorySaver()]);

        $config = new RunnableConfig(configurable: ['thread_id' => 'test-interrupt-concurrent']);
        $graph->invoke(['foo' => ''], $config);

        $state = $graph->getState($config);
        self::assertNotEmpty($state->tasks);
        $interrupts = array_filter($state->tasks, static fn ($t): bool => $t->interrupts !== []);
        self::assertNotEmpty($interrupts, 'the interrupt reached the checkpoint instead of the handler');
        self::assertNotSame('handled', $state->values['foo'] ?? null);
    }

    public function testResumingAfterTheInterruptStillRunsTheNode(): void
    {
        $graph = (new StateGraph(self::foo()))
            ->addNode('ask', static fn (): array => ['foo' => 'a_' . interrupt('need human input')], [
                'errorHandler' => static fn (): array => ['foo' => 'handled'],
            ])
            ->addEdge(Constants::START, 'ask')
            ->compile(['checkpointer' => new MemorySaver()]);

        $config = new RunnableConfig(configurable: ['thread_id' => 'resume']);
        $graph->invoke(['foo' => ''], $config);

        self::assertSame('a_yes', $graph->invoke(new Command(resume: 'yes'), $config)['foo']);
    }

    public function testRoutesAFailureToTheMatchingNodesHandler(): void
    {
        $state = Annotation::root([
            'route' => Annotation::last(),
            'foo' => Annotation::withReducer(static fn ($a, $b) => array_merge($a, $b), static fn (): array => []),
        ]);

        $graph = (new StateGraph($state))
            ->addNode('routeNode', static fn (): array => ['foo' => []])
            ->addNode('failA', static function (): array {
                throw new \RuntimeException('a failed');
            }, ['errorHandler' => function (array $s, NodeError $error): array {
                self::assertSame('failA', $error->node);

                return ['foo' => ['handled_a']];
            }])
            ->addNode('failB', static function (): array {
                throw new \RuntimeException('b failed');
            }, ['errorHandler' => function (array $s, NodeError $error): array {
                self::assertSame('failB', $error->node);

                return ['foo' => ['handled_b']];
            }])
            ->addEdge(Constants::START, 'routeNode')
            ->addConditionalEdges('routeNode', static fn (array $s) => $s['route'], ['failA', 'failB'])
            ->compile();

        self::assertSame(['handled_a'], $graph->invoke(['route' => 'failA', 'foo' => []])['foo']);
        self::assertSame(['handled_b'], $graph->invoke(['route' => 'failB', 'foo' => []])['foo']);
    }

    public function testStillFailsTheRunForANodeWithoutAnErrorHandler(): void
    {
        $graph = (new StateGraph(self::foo()))
            ->addNode('failWithoutHandler', static function (): array {
                throw new \RuntimeException('no handler');
            })
            ->addEdge(Constants::START, 'failWithoutHandler')
            ->compile();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no handler');

        $graph->invoke(['foo' => '']);
    }

    public function testTheHandlerReceivesTheNodeErrorAndTheConfig(): void
    {
        $attempts = 0;
        $captured = [];

        $graph = (new StateGraph(self::foo()))
            ->addNode('alwaysFailing', static function () use (&$attempts): array {
                $attempts += 1;
                throw new \RuntimeException('Always fails again');
            }, [
                'retryPolicy' => self::retryTwice(),
                'errorHandler' => static function (array $state, NodeError $error, RunnableConfig $config) use (&$captured): array {
                    $captured = [
                        'node' => $error->node,
                        'message' => $error->error->getMessage(),
                        'thread' => $config->configurable['thread_id'] ?? null,
                        'inConfig' => $config->configurable[Constants::CONFIG_KEY_NODE_ERROR] ?? null,
                    ];

                    return ['foo' => 'handled_with_config'];
                },
            ])
            ->addEdge(Constants::START, 'alwaysFailing')
            ->compile(['checkpointer' => new MemorySaver()]);

        $result = $graph->invoke(['foo' => ''], new RunnableConfig(configurable: ['thread_id' => 't-1']));

        self::assertSame(2, $attempts);
        self::assertSame('handled_with_config', $result['foo']);
        self::assertSame('alwaysFailing', $captured['node']);
        self::assertSame('Always fails again', $captured['message']);
        self::assertSame('t-1', $captured['thread']);
        self::assertInstanceOf(NodeError::class, $captured['inConfig'], 'the engine-facing channel for the NodeError is populated');
    }

    public function testAnErrorThePolicyDeclinesToRetryGoesStraightToTheHandler(): void
    {
        $attempts = 0;
        $graph = (new StateGraph(self::foo()))
            ->addNode('failing', static function () use (&$attempts): array {
                $attempts += 1;
                throw new \RuntimeException('not retryable');
            }, [
                'retryPolicy' => new RetryPolicy(maxAttempts: 5, retryOn: static fn (): bool => false, logWarning: false),
                'errorHandler' => static fn (): array => ['foo' => 'handled'],
            ])
            ->addEdge(Constants::START, 'failing')
            ->compile();

        self::assertSame('handled', $graph->invoke(['foo' => ''])['foo']);
        self::assertSame(1, $attempts);
    }

    public function testAHandlerIsNotCalledWhenTheNodeSucceedsOnARetry(): void
    {
        $attempts = 0;
        $handled = false;
        $graph = (new StateGraph(self::foo()))
            ->addNode('flaky', static function () use (&$attempts): array {
                $attempts += 1;
                if ($attempts < 2) {
                    throw new \RuntimeException('not yet');
                }

                return ['foo' => 'ok'];
            }, [
                'retryPolicy' => self::retryTwice(),
                'errorHandler' => static function () use (&$handled): array {
                    $handled = true;

                    return ['foo' => 'handled'];
                },
            ])
            ->addEdge(Constants::START, 'flaky')
            ->compile();

        self::assertSame('ok', $graph->invoke(['foo' => ''])['foo']);
        self::assertFalse($handled);
    }

    public function testRegistersAHiddenHandlerNodeForEachHandledNode(): void
    {
        $builder = (new StateGraph(self::foo()))
            ->addNode('work', static fn (): array => [], ['errorHandler' => static fn (): array => []]);

        self::assertSame('__error_handler__work', $builder->nodes['work']->errorHandlerNode);
        self::assertTrue($builder->nodes['__error_handler__work']->isErrorHandler);
        self::assertNull($builder->nodes['__error_handler__work']->cachePolicy);
    }

    public function testRejectsAHandlerWhoseReservedNameIsTaken(): void
    {
        $builder = (new StateGraph(self::foo()))
            ->addNode('__error_handler__work', static fn (): array => []);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('the reserved name `__error_handler__work` is already in use');

        $builder->addNode('work', static fn (): array => [], ['errorHandler' => static fn (): array => []]);
    }

    public function testAnErrorHandlerNodeNeedsNoIncomingEdgeToPassValidation(): void
    {
        $builder = (new StateGraph(self::foo()))
            ->addNode('work', static fn (): array => [], ['errorHandler' => static fn (): array => []])
            ->addEdge(Constants::START, 'work');

        $builder->validate();
        $this->addToAssertionCount(1);
    }
}
