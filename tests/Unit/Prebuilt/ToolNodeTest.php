<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prebuilt;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use LangChain\Tools\BaseToolkit;
use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;
use LangChain\Tools\ToolRuntime;
use LangChain\Tracers\CallbackHandler;
use LangChain\Utils\Testing\FakeTool;
use LangGraph\Graph\MessagesAnnotation;
use LangGraph\Graph\MessagesReducer;
use LangGraph\Pregel\Constants;
use LangGraph\Prebuilt\ToolNode;
use LangGraph\Prebuilt\ToolsCondition;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use LangGraph\Store\InMemoryStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * Port of the `ToolNode` describe block of `langgraph-core/src/tests/prebuilt.test.ts` (8 cases), plus the
 * port-specific wiring: `BaseToolkit`, `FakeTool`, the graph store, and callable `handleToolErrors`.
 */
#[CoversClass(ToolNode::class)]
final class ToolNodeTest extends TestCase
{
    private static function call(string $name, array $args, string $id): array
    {
        return ['name' => $name, 'args' => $args, 'id' => $id, 'type' => 'tool_call'];
    }

    private static function ai(array ...$calls): AIMessage
    {
        return new AIMessage(['content' => '', 'tool_calls' => $calls]);
    }

    private static function searchApi(): StructuredTool
    {
        return tool(
            static function (array $in): string {
                if (($in['query'] ?? null) === 'error') {
                    throw new \Exception('Error');
                }

                return 'result for ' . ($in['query'] ?? '');
            },
            [
                'name' => 'search_api',
                'description' => 'A simple API that returns the input string.',
                'schema' => Schema::object(['query' => ['type' => 'string']]),
            ],
        );
    }

    private static function userInfoTool(?callable $observe = null): StructuredTool
    {
        return tool(
            static function (array $in, ToolRuntime $runtime) use ($observe): string {
                if ($observe !== null) {
                    $observe($runtime);
                }

                return ($runtime->state['userId'] ?? null) === 'user_123' ? 'User is John Smith' : 'Unknown user';
            },
            ['name' => 'get_user_info', 'description' => 'Look up user info.', 'schema' => Schema::object([])],
        );
    }

    private static function userState(): \LangGraph\State\AnnotationRoot
    {
        return Annotation::root([
            'messages' => Annotation::withReducer(MessagesReducer::messagesStateReducer(...), static fn (): array => []),
            'userId' => Annotation::last(),
        ]);
    }

    public function testShouldSupportGracefulErrorHandling(): void
    {
        $res = (new ToolNode([self::searchApi()]))->invoke([self::ai(self::call('badtool', [], 'testid'))]);

        self::assertSame("Error: Tool \"badtool\" not found.\n Please fix your mistakes.", $res[0]->content);
        self::assertSame('testid', $res[0]->toolCallId);
        self::assertSame('error', $res[0]->additional_kwargs['status']);
    }

    public function testForwardsGraphStateToToolsViaRuntimeStateInAGraphNode(): void
    {
        $observed = null;
        $graph = (new StateGraph(self::userState()))
            ->addNode('tools', new ToolNode([self::userInfoTool(static function (ToolRuntime $rt) use (&$observed): void {
                $observed = $rt->state['userId'];
            })]))
            ->addEdge(Constants::START, 'tools')
            ->compile();

        $result = $graph->invoke([
            'messages' => [self::ai(self::call('get_user_info', [], 'call_user_info'))],
            'userId' => 'user_123',
        ]);

        self::assertSame('user_123', $observed);
        self::assertSame('User is John Smith', end($result['messages'])->content);
    }

    public function testForwardsInputToToolsViaRuntimeStateOnDirectInvoke(): void
    {
        $observed = null;
        $node = new ToolNode([self::userInfoTool(static function (ToolRuntime $rt) use (&$observed): void {
            $observed = $rt->state['userId'];
        })]);

        $node->invoke([
            'messages' => [self::ai(self::call('get_user_info', [], 'call_user_info'))],
            'userId' => 'user_123',
        ]);

        self::assertSame('user_123', $observed);
    }

    public function testStateIsAlsoReachableThroughTheConfigTheToolWasHanded(): void
    {
        // Stands in for upstream's `getCurrentTaskInput(config)` backwards-compat case: a tool that reads
        // the state from the config argument rather than from a `ToolRuntime`.
        $observed = null;
        $stateTool = tool(
            static function (array $in, mixed $runManager, RunnableConfig $config) use (&$observed): string {
                $observed = ToolRuntime::fromConfig($config)?->state['userId'];

                return 'ok';
            },
            ['name' => 'get_user_info', 'description' => 'x', 'schema' => Schema::object([])],
        );

        $graph = (new StateGraph(self::userState()))
            ->addNode('tools', new ToolNode([$stateTool]))
            ->addEdge(Constants::START, 'tools')
            ->compile();
        $graph->invoke([
            'messages' => [self::ai(self::call('get_user_info', [], 'call_user_info'))],
            'userId' => 'user_123',
        ]);

        self::assertSame('user_123', $observed);
    }

    public function testPassesToolCallIdToHandleToolStartWhenInvokingATool(): void
    {
        $captured = null;
        $recorder = tool(static fn (array $in): string => 'ok', [
            'name' => 'recorder',
            'description' => 'Records config',
            'schema' => Schema::object(['x' => ['type' => 'number']], ['x']),
        ]);

        (new ToolNode([$recorder]))->invoke(
            [self::ai(self::call('recorder', ['x' => 1], 'call_abc123'))],
            new RunnableConfig(callbacks: [
                CallbackHandler::fromMethods([
                    'handleToolStart' => static function (
                        mixed $tool,
                        mixed $input,
                        string $runId,
                        ?string $parentRunId,
                        array $tags,
                        array $metadata,
                        ?string $runName,
                        ?string $toolCallId,
                    ) use (&$captured): void {
                        $captured = $toolCallId;
                    },
                ]),
            ]),
        );

        self::assertSame('call_abc123', $captured);
    }

    public function testShouldWorkWhenNestedWithACallbackManagerPassed(): void
    {
        $node = new ToolNode([self::searchApi()]);
        $wrapper = RunnableLambda::from(
            static fn (mixed $_, RunnableConfig $config): array => $node->invoke(
                [self::ai(self::call('search_api', ['query' => 'foo'], 'testid'))],
                $config,
            ),
        );

        $starts = 0;
        $wrapper->invoke([], new RunnableConfig(callbacks: [
            CallbackHandler::fromMethods([
                'handleChainStart' => static function () use (&$starts): void {
                    $starts++;
                },
                'handleToolStart' => static function () use (&$starts): void {
                    $starts++;
                },
            ]),
        ]));

        // Upstream counts 2 (the lambda's chain run plus the tool run). This port's RunnableLambda does not
        // open a chain run, and ToolNode itself is untraced (`trace = false`), so the one run is the tool's.
        self::assertSame(1, $starts);
    }

    public function testShouldWorkInAStateGraph(): void
    {
        $weather = tool(
            static fn (array $in): string => str_contains(strtolower($in['query']), 'sf') || str_contains(strtolower($in['query']), 'san francisco')
                ? "It's 60 degrees and foggy."
                : "It's 90 degrees and sunny.",
            [
                'name' => 'weather',
                'description' => 'Call to get the current weather for a location.',
                'schema' => Schema::object(['query' => ['type' => 'string']], ['query']),
            ],
        );
        $aiMessage = self::ai(self::call('weather', ['query' => 'SF'], 'call_1234'));
        $aiMessage2 = new AIMessage(['content' => 'FOO']);

        $callModel = static function (array $state) use ($aiMessage, $aiMessage2): array {
            foreach ($state['messages'] as $m) {
                if ($m === $aiMessage || ($m instanceof AIMessage && $m->toolCalls !== [])) {
                    return ['messages' => [$aiMessage2]];
                }
            }

            return ['messages' => [$aiMessage]];
        };

        $graph = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('agent', $callModel)
            ->addNode('tools', new ToolNode([$weather]))
            ->addEdge(Constants::START, 'agent')
            ->addConditionalEdges('agent', ToolsCondition::toolsCondition(...), ['tools', Constants::END])
            ->addEdge('tools', 'agent')
            ->compile();

        $res = $graph->invoke(['messages' => []]);

        self::assertCount(3, $res['messages']);
        [$first, $toolMessage, $last] = $res['messages'];
        self::assertSame('call_1234', $first->toolCalls[0]['id']);
        self::assertInstanceOf(ToolMessage::class, $toolMessage);
        self::assertSame('weather', $toolMessage->name);
        self::assertSame("It's 60 degrees and foggy.", $toolMessage->content);
        self::assertSame('call_1234', $toolMessage->toolCallId);
        self::assertNotNull($toolMessage->id);
        self::assertNull($toolMessage->artifact);
        self::assertSame('FOO', $last->content);
    }

    public function testRuntimeStateWorksWithoutAnAmbientConfigStore(): void
    {
        // Upstream's "works without AsyncLocalStorage (browser-like)" case proves state arrives as an
        // ARGUMENT, not through ambient context. PHP has no AsyncLocalStorage; the equivalent claim is that
        // a direct invoke with no published current config still delivers state to the tool.
        $observed = null;
        $node = new ToolNode([self::userInfoTool(static function (ToolRuntime $rt) use (&$observed): void {
            $observed = $rt->state['userId'];
        })]);

        $node->invoke(
            ['messages' => [self::ai(self::call('get_user_info', [], 'c1'))], 'userId' => 'user_123'],
            new RunnableConfig(),
        );

        self::assertSame('user_123', $observed);
    }

    // ---- port-specific wiring ------------------------------------------------

    public function testRunsTheToolsOfABaseToolkitAndOfAFakeTool(): void
    {
        $toolkit = new class ([new FakeTool(['name' => 'echo', 'description' => 'Echoes its arguments'])]) extends BaseToolkit {
        };

        $res = (new ToolNode([$toolkit]))->invoke([self::ai(self::call('echo', ['a' => 1], 'e1'))]);

        self::assertSame('{"a":1}', $res[0]->content);
        self::assertSame('echo', $res[0]->name);
        self::assertSame('e1', $res[0]->toolCallId);
    }

    public function testToolsAnsweredByAToolMessageAlreadyAreNotRunAgain(): void
    {
        $runs = 0;
        $counter = tool(static function () use (&$runs): string {
            $runs++;

            return 'ran';
        }, ['name' => 'count', 'description' => 'x', 'schema' => Schema::object([])]);

        $res = (new ToolNode([$counter]))->invoke([
            self::ai(self::call('count', [], 'a'), self::call('count', [], 'b')),
            new ToolMessage(['content' => 'done', 'tool_call_id' => 'a']),
        ]);

        self::assertSame(1, $runs);
        self::assertCount(1, $res);
        self::assertSame('b', $res[0]->toolCallId);
    }

    public function testStateInputReturnsMessagesStateOutput(): void
    {
        $res = (new ToolNode([self::searchApi()]))->invoke(['messages' => [self::ai(self::call('search_api', ['query' => 'foo'], 'x'))]]);

        self::assertSame(['messages'], array_keys($res));
        self::assertSame('result for foo', $res['messages'][0]->content);
    }

    public function testRejectsInputWithoutAnAiMessage(): void
    {
        $this->expectExceptionMessage('ToolNode only accepts AIMessages as input.');

        (new ToolNode([self::searchApi()]))->invoke([new HumanMessage('hi')]);
    }

    public function testRejectsInputThatIsNotMessages(): void
    {
        $this->expectExceptionMessage('ToolNode only accepts BaseMessage[] or { messages: BaseMessage[] } as input.');

        (new ToolNode([self::searchApi()]))->invoke(['nope' => 1]);
    }

    public function testHandleToolErrorsFalseRethrowsTheToolsException(): void
    {
        $node = new ToolNode([self::searchApi()], ['handleToolErrors' => false]);

        $this->expectExceptionMessage('Error');

        $node->invoke([self::ai(self::call('search_api', ['query' => 'error'], 'x'))]);
    }

    public function testHandleToolErrorsTrueTurnsTheExceptionIntoAnErrorMessage(): void
    {
        $res = (new ToolNode([self::searchApi()]))->invoke([self::ai(self::call('search_api', ['query' => 'error'], 'x'))]);

        self::assertSame("Error: Error\n Please fix your mistakes.", $res[0]->content);
        self::assertSame('search_api', $res[0]->name);
    }

    public function testHandleToolErrorsCallableChoosesTheMessage(): void
    {
        $seen = null;
        $node = new ToolNode([self::searchApi()], ['handleToolErrors' => static function (\Throwable $e, array $call) use (&$seen): string {
            $seen = [$e->getMessage(), $call['id']];

            return 'custom: ' . $e->getMessage();
        }]);

        $res = $node->invoke([self::ai(self::call('search_api', ['query' => 'error'], 'x'))]);

        self::assertSame('custom: Error', $res[0]->content);
        self::assertSame(['Error', 'x'], $seen);
    }

    public function testHandleToolErrorsCallableMayReturnAToolMessage(): void
    {
        $node = new ToolNode([self::searchApi()], ['handleToolErrors' => static fn (\Throwable $e, array $call): ToolMessage => new ToolMessage([
            'content' => 'handled',
            'tool_call_id' => $call['id'],
        ])]);

        $res = $node->invoke([self::ai(self::call('search_api', ['query' => 'error'], 'x'))]);

        self::assertSame('handled', $res[0]->content);
    }

    public function testToolsReceiveTheGraphStoreThroughTheRuntime(): void
    {
        $store = new InMemoryStore();
        $store->put(['users'], 'ada', ['language' => 'French']);

        $lookup = tool(
            static function (array $in, ToolRuntime $runtime): string {
                return $runtime->store->get(['users'], 'ada')->value['language'];
            },
            ['name' => 'lookup', 'description' => 'x', 'schema' => Schema::object([])],
        );

        $graph = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('tools', new ToolNode([$lookup]))
            ->addEdge(Constants::START, 'tools')
            ->compile(['store' => $store]);

        $res = $graph->invoke(['messages' => [self::ai(self::call('lookup', [], 'l1'))]]);

        self::assertSame('French', end($res['messages'])->content);
    }

    public function testASendPacketRunsASingleToolCallAgainstTheRemainingState(): void
    {
        $seen = null;
        $node = new ToolNode([self::userInfoTool(static function (ToolRuntime $rt) use (&$seen): void {
            $seen = $rt->state;
        })]);

        $res = $node->invoke(['lg_tool_call' => self::call('get_user_info', [], 'sc1'), 'userId' => 'user_123', 'messages' => []]);

        self::assertSame('User is John Smith', $res['messages'][0]->content);
        self::assertArrayNotHasKey('lg_tool_call', $seen);
        self::assertSame('user_123', $seen['userId']);
    }
}
