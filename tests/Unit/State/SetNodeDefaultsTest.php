<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\State;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Cache\InMemoryCache;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Errors\NodeError;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Retry\RetryPolicy;
use LangGraph\State\Annotation;
use LangGraph\State\AnnotationRoot;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `langgraph-core/src/tests/set_node_defaults.test.ts`: graph-wide node defaults.
 *
 * Precedence is the thing under test: a node's own value beats the default, and `cachePolicy: false`
 * opts a node out of a default cache policy.
 *
 * Differences from upstream:
 *
 *  - the timeout default is stored on the node but not enforced by this engine, so the test asserts
 *    what is recorded (a number, where upstream normalises to a `TimeoutPolicy` object);
 *  - a node with an error handler runs its retries inside the handler wrapper (the engine cannot
 *    schedule a handler task), so its *compiled* `retryPolicy` is null and the "default retry is
 *    applied" assertions for handled nodes observe behaviour (attempt counts) rather than the field;
 *  - NON-EXACT (cache): combining a default `cachePolicy` with an `errorHandler` caches the handled
 *    result under the failed node's key (upstream never caches a failed task); pinned in
 *    `NodeErrorHandlerTest::testAHandledFailureIsCachedUnderTheFailedNodesKey`;
 *  - the "receives the failing node's input" case uses an Annotation `input` for the node.
 */
#[CoversClass(StateGraph::class)]
final class SetNodeDefaultsTest extends TestCase
{
    private const RECOVERY_NODE = '__default_error_handler__';

    private static function state(): AnnotationRoot
    {
        return Annotation::root(['foo' => Annotation::last()]);
    }

    private static function fastRetry(): RetryPolicy
    {
        return new RetryPolicy(initialInterval: 1, maxAttempts: 3, jitter: false, logWarning: false);
    }

    private static function routedState(): AnnotationRoot
    {
        return Annotation::root([
            'route' => Annotation::last(),
            'foo' => Annotation::withReducer(static fn ($a, $b) => array_merge($a, $b), static fn (): array => []),
        ]);
    }

    // ---- retry ----------------------------------------------------------------

    public function testAppliesTheDefaultRetryPolicyToNodesWithoutTheirOwn(): void
    {
        $attempts = 0;
        $retry = self::fastRetry();
        $graph = (new StateGraph(self::state()))
            ->setNodeDefaults(['retryPolicy' => $retry])
            ->addNode('flaky', static function () use (&$attempts): array {
                $attempts += 1;
                if ($attempts < 3) {
                    throw new \RuntimeException('not yet');
                }

                return ['foo' => 'ok'];
            })
            ->addEdge(Constants::START, 'flaky')
            ->compile();

        self::assertSame('ok', $graph->invoke(['foo' => ''])['foo']);
        self::assertSame(3, $attempts);
        self::assertSame($retry, $graph->nodes['flaky']->retryPolicy);
    }

    public function testAPerNodeRetryPolicyOverridesTheDefault(): void
    {
        $attempts = 0;
        $perNode = new RetryPolicy(initialInterval: 1, maxAttempts: 5, jitter: false, logWarning: false);
        $graph = (new StateGraph(self::state()))
            ->setNodeDefaults(['retryPolicy' => new RetryPolicy(maxAttempts: 1, logWarning: false)])
            ->addNode('flaky', static function () use (&$attempts): array {
                $attempts += 1;
                if ($attempts < 4) {
                    throw new \RuntimeException('not yet');
                }

                return ['foo' => 'ok'];
            }, ['retryPolicy' => $perNode])
            ->addEdge(Constants::START, 'flaky')
            ->compile();

        self::assertSame('ok', $graph->invoke(['foo' => ''])['foo']);
        self::assertSame(4, $attempts);
        self::assertSame($perNode, $graph->nodes['flaky']->retryPolicy);
    }

    public function testAppliesDefaultsWhenSetNodeDefaultsComesAfterAllAddNodeCalls(): void
    {
        $perNode = new RetryPolicy(maxAttempts: 7, logWarning: false);
        $fast = self::fastRetry();
        $graph = (new StateGraph(self::state()))
            ->addNode('a', static fn (): array => ['foo' => 'a'])
            ->addNode('b', static fn (): array => ['foo' => 'b'], ['retryPolicy' => $perNode])
            ->addNode('c', static fn (): array => ['foo' => 'c'])
            ->addEdge(Constants::START, 'a')
            ->addEdge('a', 'b')
            ->addEdge('b', 'c')
            ->setNodeDefaults(['retryPolicy' => $fast, 'cachePolicy' => ['ttl' => 60]])
            ->compile(['cache' => new InMemoryCache()]);

        self::assertSame($fast, $graph->nodes['a']->retryPolicy);
        self::assertSame(['ttl' => 60], $graph->nodes['a']->cachePolicy);
        self::assertSame($perNode, $graph->nodes['b']->retryPolicy);
        self::assertSame(['ttl' => 60], $graph->nodes['b']->cachePolicy);
        self::assertSame($fast, $graph->nodes['c']->retryPolicy);
        self::assertSame(['ttl' => 60], $graph->nodes['c']->cachePolicy);
    }

    public function testMergesFieldsAcrossCallsLaterWinsPerField(): void
    {
        $fast = self::fastRetry();
        $graph = (new StateGraph(self::state()))
            ->setNodeDefaults(['retryPolicy' => new RetryPolicy(maxAttempts: 2, logWarning: false)])
            ->setNodeDefaults(['cachePolicy' => ['ttl' => 60]])
            ->setNodeDefaults(['retryPolicy' => $fast])
            ->addNode('a', static fn (): array => ['foo' => 'a'])
            ->addEdge(Constants::START, 'a')
            ->compile(['cache' => new InMemoryCache()]);

        self::assertSame($fast, $graph->nodes['a']->retryPolicy);
        self::assertSame(['ttl' => 60], $graph->nodes['a']->cachePolicy);
    }

    public function testAppliesTheDefaultToEveryNodeThatLacksItsOwn(): void
    {
        $perNode = new RetryPolicy(maxAttempts: 7, logWarning: false);
        $fast = self::fastRetry();
        $graph = (new StateGraph(self::state()))
            ->setNodeDefaults(['retryPolicy' => $fast])
            ->addNode('a', static fn (): array => ['foo' => 'a'])
            ->addNode('b', static fn (): array => ['foo' => 'b'], ['retryPolicy' => $perNode])
            ->addNode('c', static fn (): array => ['foo' => 'c'])
            ->addEdge(Constants::START, 'a')
            ->addEdge('a', 'b')
            ->addEdge('b', 'c')
            ->compile();

        self::assertSame($fast, $graph->nodes['a']->retryPolicy);
        self::assertSame($perNode, $graph->nodes['b']->retryPolicy);
        self::assertSame($fast, $graph->nodes['c']->retryPolicy);
    }

    // ---- cache ------------------------------------------------------------------

    public function testAppliesTheDefaultCachePolicyToNodesWithoutTheirOwn(): void
    {
        $cache = new InMemoryCache();
        $calls = 0;
        $graph = (new StateGraph(self::state()))
            ->setNodeDefaults(['cachePolicy' => ['ttl' => 60]])
            ->addNode('cached', static function () use (&$calls): array {
                $calls += 1;

                return ['foo' => 'ok'];
            })
            ->addEdge(Constants::START, 'cached')
            ->compile(['cache' => $cache]);

        self::assertSame(['ttl' => 60], $graph->nodes['cached']->cachePolicy);

        $graph->invoke(['foo' => '']);
        $graph->invoke(['foo' => '']);

        self::assertSame(1, $calls, 'the second invocation is served from the cache');
    }

    public function testAcceptsABooleanCachePolicyDefault(): void
    {
        $enabled = (new StateGraph(self::state()))
            ->setNodeDefaults(['cachePolicy' => true])
            ->addNode('a', static fn (): array => ['foo' => 'a'])
            ->addEdge(Constants::START, 'a')
            ->compile(['cache' => new InMemoryCache()]);
        self::assertSame([], $enabled->nodes['a']->cachePolicy, '`true` means cache with the default settings');

        $disabled = (new StateGraph(self::state()))
            ->setNodeDefaults(['cachePolicy' => false])
            ->addNode('a', static fn (): array => ['foo' => 'a'])
            ->addEdge(Constants::START, 'a')
            ->compile();
        self::assertNull($disabled->nodes['a']->cachePolicy);
    }

    public function testAPerNodeCachePolicyOverridesTheDefault(): void
    {
        $graph = (new StateGraph(self::state()))
            ->setNodeDefaults(['cachePolicy' => ['ttl' => 60]])
            ->addNode('a', static fn (): array => ['foo' => 'a'], ['cachePolicy' => ['ttl' => 5]])
            ->addNode('b', static fn (): array => ['foo' => 'b'])
            ->addEdge(Constants::START, 'a')
            ->addEdge('a', 'b')
            ->compile(['cache' => new InMemoryCache()]);

        self::assertSame(['ttl' => 5], $graph->nodes['a']->cachePolicy);
        self::assertSame(['ttl' => 60], $graph->nodes['b']->cachePolicy);
    }

    public function testAPerNodeCachePolicyFalseOptsOutOfTheDefault(): void
    {
        $cachedCalls = 0;
        $uncachedCalls = 0;
        $builder = (new StateGraph(self::state()))
            ->setNodeDefaults(['cachePolicy' => true])
            ->addNode('cached', static function () use (&$cachedCalls): array {
                $cachedCalls += 1;

                return ['foo' => 'cached'];
            })
            ->addNode('uncached', static function () use (&$uncachedCalls): array {
                $uncachedCalls += 1;

                return ['foo' => 'uncached'];
            }, ['cachePolicy' => false]);

        self::assertFalse($builder->nodeSpecs['uncached']->cachePolicy, 'the opt-out is recorded on the builder');

        $graph = $builder
            ->addEdge(Constants::START, 'cached')
            ->addEdge('cached', 'uncached')
            ->compile(['cache' => new InMemoryCache()]);

        self::assertSame([], $graph->nodes['cached']->cachePolicy);
        self::assertNull($graph->nodes['uncached']->cachePolicy);

        $graph->invoke(['foo' => '']);
        $graph->invoke(['foo' => '']);

        self::assertSame(1, $cachedCalls);
        self::assertSame(2, $uncachedCalls);
    }

    // ---- builder hygiene --------------------------------------------------------

    public function testDoesNotMutateBuilderStateAcrossRepeatedCompiles(): void
    {
        $builder = (new StateGraph(self::state()))
            ->setNodeDefaults(['retryPolicy' => self::fastRetry(), 'errorHandler' => static fn (): array => ['foo' => 'h']])
            ->addNode('a', static fn (): array => ['foo' => 'a'])
            ->addEdge(Constants::START, 'a');

        $builder->compile();
        $builder->compile();

        self::assertNull($builder->nodes['a']->retryPolicy, 'the stored spec is untouched');
        self::assertNull($builder->nodes['a']->errorHandlerNode);
        self::assertArrayNotHasKey(self::RECOVERY_NODE, $builder->nodes, 'the shared handler exists only during a compile');
    }

    public function testDoesNotInheritDefaultsIntoSubgraphs(): void
    {
        $inner = (new StateGraph(self::state()))
            ->addNode('innerNode', static fn (): array => ['foo' => 'inner'])
            ->addEdge(Constants::START, 'innerNode')
            ->compile();

        self::assertNull($inner->nodes['innerNode']->retryPolicy);

        $fast = self::fastRetry();
        $outer = (new StateGraph(self::state()))
            ->setNodeDefaults(['retryPolicy' => $fast])
            ->addNode('sub', $inner)
            ->addEdge(Constants::START, 'sub')
            ->compile();

        self::assertSame($fast, $outer->nodes['sub']->retryPolicy, 'the subgraph node picks up the outer default');
        self::assertNull($inner->nodes['innerNode']->retryPolicy, 'the inner graph is unaffected');
    }

    public function testReturnsTheSameBuilderForChaining(): void
    {
        $builder = new StateGraph(self::state());

        self::assertSame($builder, $builder->setNodeDefaults(['retryPolicy' => self::fastRetry()]));
    }

    public function testNothingChangesWhenNoDefaultsAreSet(): void
    {
        $graph = (new StateGraph(self::state()))
            ->addNode('a', static fn (): array => ['foo' => 'a'])
            ->addEdge(Constants::START, 'a')
            ->compile();

        self::assertNull($graph->nodes['a']->retryPolicy);
        self::assertNull($graph->nodes['a']->cachePolicy);
        self::assertNull($graph->nodes['a']->timeout);
        self::assertNull($graph->nodes['a']->errorHandlerNode);
        self::assertArrayNotHasKey(self::RECOVERY_NODE, $graph->nodes);
    }

    // ---- error handler default ----------------------------------------------------

    public function testAppliesTheDefaultErrorHandlerToEveryRegularNodeLackingItsOwn(): void
    {
        $captured = [];
        $graph = (new StateGraph(self::routedState()))
            ->setNodeDefaults(['errorHandler' => static function (mixed $state, NodeError $error) use (&$captured): array {
                $captured[] = $error->node;

                return ['foo' => ['handled_' . $error->node]];
            }])
            ->addNode('routeNode', static fn (array $state) => new Command(goto: $state['route']), ['ends' => ['failA', 'failB']])
            ->addNode('failA', static function (): array {
                throw new \RuntimeException('a failed');
            })
            ->addNode('failB', static function (): array {
                throw new \RuntimeException('b failed');
            })
            ->addEdge(Constants::START, 'routeNode')
            ->compile();

        self::assertSame(['handled_failA'], $graph->invoke(['route' => 'failA', 'foo' => []])['foo']);
        self::assertSame(['handled_failB'], $graph->invoke(['route' => 'failB', 'foo' => []])['foo']);
        self::assertContains('failA', $captured);
        self::assertContains('failB', $captured);
    }

    public function testAPerNodeErrorHandlerOverridesTheDefault(): void
    {
        $captured = [];
        $graph = (new StateGraph(self::routedState()))
            ->setNodeDefaults(['errorHandler' => static function (mixed $state, NodeError $error) use (&$captured): array {
                $captured[] = 'default:' . $error->node;

                return ['foo' => ['default_handled_' . $error->node]];
            }])
            ->addNode('routeNode', static fn (array $state) => new Command(goto: $state['route']), ['ends' => ['failA', 'failB']])
            ->addNode('failA', static function (): array {
                throw new \RuntimeException('a failed');
            }, ['errorHandler' => static function (mixed $state, NodeError $error) use (&$captured): array {
                $captured[] = 'node:' . $error->node;

                return ['foo' => ['node_handled_' . $error->node]];
            }])
            ->addNode('failB', static function (): array {
                throw new \RuntimeException('b failed');
            })
            ->addEdge(Constants::START, 'routeNode')
            ->compile();

        self::assertSame(['node_handled_failA'], $graph->invoke(['route' => 'failA', 'foo' => []])['foo']);
        self::assertContains('node:failA', $captured);
        self::assertNotContains('default:failA', $captured);

        self::assertSame(['default_handled_failB'], $graph->invoke(['route' => 'failB', 'foo' => []])['foo']);
        self::assertContains('default:failB', $captured);
    }

    public function testDoesNotCatchAFailureRaisedByAPerNodeErrorHandler(): void
    {
        $graph = (new StateGraph(self::state()))
            ->setNodeDefaults(['errorHandler' => static fn (): array => ['foo' => 'default recovered']])
            ->addNode('alwaysFailing', static function (): array {
                throw new \RuntimeException('node boom');
            }, ['errorHandler' => static function (): array {
                throw new \RuntimeException('handler boom');
            }])
            ->addEdge(Constants::START, 'alwaysFailing')
            ->compile();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('handler boom');

        $graph->invoke(['foo' => '']);
    }

    public function testFailsTheRunWhenTheDefaultErrorHandlerItselfThrows(): void
    {
        $graph = (new StateGraph(self::state()))
            ->setNodeDefaults(['errorHandler' => static function (): array {
                throw new \RuntimeException('default handler boom');
            }])
            ->addNode('alwaysFailing', static function (): array {
                throw new \RuntimeException('node boom');
            })
            ->addEdge(Constants::START, 'alwaysFailing')
            ->compile();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('default handler boom');

        $graph->invoke(['foo' => '']);
    }

    public function testPassesTheRunnableConfigToTheDefaultErrorHandler(): void
    {
        $threadId = null;
        $graph = (new StateGraph(self::state()))
            ->setNodeDefaults(['errorHandler' => static function (mixed $state, NodeError $error, RunnableConfig $config) use (&$threadId): array {
                $threadId = $config->configurable['thread_id'] ?? null;

                return ['foo' => 'handled'];
            }])
            ->addNode('alwaysFailing', static function (): array {
                throw new \RuntimeException('boom');
            })
            ->addEdge(Constants::START, 'alwaysFailing')
            ->compile(['checkpointer' => new MemorySaver()]);

        $result = $graph->invoke(['foo' => ''], new RunnableConfig(configurable: ['thread_id' => 'thread-xyz']));

        self::assertSame('handled', $result['foo']);
        self::assertSame('thread-xyz', $threadId);
    }

    public function testThrowsAtCompileWhenANodeUsesTheReservedDefaultHandlerName(): void
    {
        $builder = (new StateGraph(self::state()))
            ->setNodeDefaults(['errorHandler' => static fn (): array => ['foo' => 'handled']])
            ->addNode(self::RECOVERY_NODE, static fn (array $state): array => $state)
            ->addEdge(Constants::START, self::RECOVERY_NODE);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage(self::RECOVERY_NODE);

        $builder->compile();
    }

    public function testRunsTheDefaultHandlerOnlyAfterTheDefaultRetryPolicyIsExhausted(): void
    {
        $attempts = 0;
        $captured = null;
        $fast = self::fastRetry();
        $graph = (new StateGraph(self::state()))
            ->setNodeDefaults([
                'retryPolicy' => $fast,
                'errorHandler' => static function (mixed $state, NodeError $error) use (&$captured): array {
                    $captured = $error->error->getMessage();

                    return ['foo' => 'handled'];
                },
            ])
            ->addNode('fail', static function () use (&$attempts): array {
                $attempts += 1;
                throw new \RuntimeException('Always fails');
            })
            ->addEdge(Constants::START, 'fail')
            ->compile();

        self::assertSame('handled', $graph->invoke(['foo' => ''])['foo']);
        self::assertSame($fast->maxAttempts, $attempts);
        self::assertSame('Always fails', $captured);
    }

    public function testAppliesRetryAndTimeoutToTheSharedHandlerNodeButNeverCachePolicy(): void
    {
        $fast = self::fastRetry();
        $graph = (new StateGraph(self::state()))
            ->setNodeDefaults([
                'retryPolicy' => $fast,
                'cachePolicy' => ['ttl' => 60],
                'timeout' => 1000,
                'errorHandler' => static fn (): array => ['foo' => 'handled'],
            ])
            ->addNode('a', static fn (): array => ['foo' => 'a'])
            ->addEdge(Constants::START, 'a')
            ->compile(['cache' => new InMemoryCache()]);

        $handler = $graph->nodes[self::RECOVERY_NODE];
        self::assertSame($fast, $handler->retryPolicy);
        self::assertNotNull($handler->timeout);
        self::assertNull($handler->cachePolicy, 'a handler is never cached');
        self::assertTrue($handler->isErrorHandler);
        self::assertSame(['ttl' => 60], $graph->nodes['a']->cachePolicy);
        self::assertSame(self::RECOVERY_NODE, $graph->nodes['a']->errorHandlerNode);
    }

    public function testDoesNotInheritTheDefaultErrorHandlerIntoSubgraphs(): void
    {
        $inner = (new StateGraph(self::state()))
            ->addNode('innerNode', static fn (): array => ['foo' => 'inner'])
            ->addEdge(Constants::START, 'innerNode')
            ->compile();

        self::assertArrayNotHasKey(self::RECOVERY_NODE, $inner->nodes);
        self::assertNull($inner->nodes['innerNode']->errorHandlerNode);

        $outer = (new StateGraph(self::state()))
            ->setNodeDefaults(['errorHandler' => static fn (): array => ['foo' => 'handled']])
            ->addNode('sub', $inner)
            ->addEdge(Constants::START, 'sub')
            ->compile();

        self::assertArrayHasKey(self::RECOVERY_NODE, $outer->nodes, 'the outer graph materialises its own shared handler');
        self::assertSame(self::RECOVERY_NODE, $outer->nodes['sub']->errorHandlerNode);
        self::assertArrayNotHasKey(self::RECOVERY_NODE, $inner->nodes, 'the inner compiled graph is unaffected');
    }

    public function testIsOrderIndependentForTheErrorHandler(): void
    {
        $graph = (new StateGraph(self::state()))
            ->addNode('fail', static function (): array {
                throw new \RuntimeException('boom');
            })
            ->addEdge(Constants::START, 'fail')
            ->setNodeDefaults(['errorHandler' => static fn (): array => ['foo' => 'handled']])
            ->compile();

        self::assertSame('handled', $graph->invoke(['foo' => ''])['foo']);
    }

    public function testTheDefaultHandlerReceivesTheFailingNodesInputNotTheFullGraphState(): void
    {
        $received = null;
        $graph = (new StateGraph(Annotation::root([
            'a' => Annotation::last(),
            'b' => Annotation::last(),
            'handled' => Annotation::last(),
        ])))
            ->setNodeDefaults(['errorHandler' => static function (mixed $state) use (&$received): array {
                $received = $state;

                return ['handled' => true];
            }])
            ->addNode('fail', static function (): array {
                throw new \RuntimeException('boom');
            }, ['input' => Annotation::root(['a' => Annotation::last()])])
            ->addEdge(Constants::START, 'fail')
            ->compile();

        $result = $graph->invoke(['a' => 'x', 'b' => 'y', 'handled' => false]);

        self::assertTrue($result['handled']);
        self::assertSame('x', $received['a']);
        self::assertArrayNotHasKey('b', $received, 'the graph-only field is absent from the handler input');
    }

    public function testTheTimeoutDefaultIsRecordedAndANodesOwnWins(): void
    {
        $graph = (new StateGraph(self::state()))
            ->setNodeDefaults(['timeout' => 1000])
            ->addNode('a', static fn (): array => ['foo' => 'a'])
            ->addNode('b', static fn (): array => ['foo' => 'b'], ['timeout' => 250])
            ->addEdge(Constants::START, 'a')
            ->addEdge('a', 'b')
            ->compile();

        self::assertSame(1000, $graph->nodes['a']->timeout);
        self::assertSame(250, $graph->nodes['b']->timeout);
    }
}
