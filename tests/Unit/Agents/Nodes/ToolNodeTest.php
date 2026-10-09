<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Nodes;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\RemoveMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use LangChain\Tools\BaseToolkit;
use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;
use LangChain\Tools\ToolRuntime;
use LangChain\Tracers\CallbackHandler;
use LangGraph\Agents\Errors\MiddlewareError;
use LangGraph\Agents\Errors\ToolInvocationError;
use LangGraph\Agents\Nodes\ToolNode;
use LangGraph\Agents\RunnableCallable;
use LangGraph\Agents\Runtime;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Errors\GraphInterrupt;
use LangGraph\Errors\NodeInterrupt;
use LangGraph\Graph\MessagesAnnotation;
use LangGraph\Graph\MessagesReducer;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Send;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * Port of `langchain/src/agents/nodes/tests/ToolNode.test.ts`: `ToolNode`, `MessagesAnnotation`,
 * `messagesStateReducer`, `ToolNode with Commands` and `ToolNode error handling`.
 *
 * Skipped: the `MessagesZodState` describe (3 cases; Zod-only state), and two error-handling cases that
 * build a `createAgent` ("should use default error handling, only catch ToolInvocationError" and "should
 * use send error tool message if model creates wrong args"); both wait for WP-21b, and their
 * ToolNode-level halves are covered here.
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
                'schema' => Schema::object(['query' => ['type' => 'string', 'description' => 'The query to search for.']], ['query']),
            ],
        );
    }

    private static function throwing(\Throwable $error, string $name = 'tool_with_error'): StructuredTool
    {
        return tool(
            static function () use ($error): never {
                throw $error;
            },
            ['name' => $name, 'description' => 'A tool that throws', 'schema' => Schema::object([])],
        );
    }

    private static function weather(): StructuredTool
    {
        return tool(
            static fn (array $in): string => 'Weather in ' . $in['location'] . ': sunny',
            ['name' => 'get_weather', 'description' => 'Get weather for a location', 'schema' => Schema::object(['location' => ['type' => 'string']], ['location'])],
        );
    }

    // ---- ToolNode ------------------------------------------------------------------------------

    public function testShouldWorkWhenNestedWithACallbackManagerPassed(): void
    {
        $toolNode = new ToolNode([self::searchApi()]);
        $wrapper = RunnableLambda::from(static fn (mixed $_, RunnableConfig $config): array => $toolNode->invoke(
            [self::ai(self::call('search_api', ['query' => 'foo'], 'testid'))],
            $config,
        ));

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

        // Upstream also settles on 1 (its own @todo questions the expected 2): ToolNode is untraced and
        // this port's RunnableLambda opens no chain run, so the one run is the tool's.
        self::assertSame(1, $starts);
    }

    public function testShouldWorkInAStateGraph(): void
    {
        $agentAnnotation = Annotation::root([
            'messages' => Annotation::withReducer(MessagesReducer::messagesStateReducer(...), static fn (): array => []),
            'prop2' => Annotation::last(),
        ]);
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
            foreach ($state['messages'] as $message) {
                if ($message->id !== null && $message->id === $aiMessage->id) {
                    return ['messages' => [$aiMessage2]];
                }
            }

            return ['messages' => [$aiMessage]];
        };
        $shouldContinue = static function (array $state): string {
            $last = $state['messages'][array_key_last($state['messages'])] ?? null;

            return $last instanceof AIMessage && $last->toolCalls !== [] ? 'tools' : Constants::END;
        };

        $graph = (new StateGraph($agentAnnotation))
            ->addNode('agent', $callModel)
            ->addNode('tools', new ToolNode([$weather]))
            ->addEdge(Constants::START, 'agent')
            ->addConditionalEdges('agent', $shouldContinue)
            ->addEdge('tools', 'agent')
            ->compile();

        $res = $graph->invoke(['messages' => []]);

        self::assertCount(3, $res['messages']);
        self::assertSame($aiMessage->toolCalls, $res['messages'][0]->toolCalls);
        $toolMessage = $res['messages'][1];
        self::assertInstanceOf(ToolMessage::class, $toolMessage);
        self::assertSame('weather', $toolMessage->name);
        self::assertSame("It's 60 degrees and foggy.", $toolMessage->content);
        self::assertSame('call_1234', $toolMessage->toolCallId);
        self::assertNull($toolMessage->artifact);
        self::assertSame('FOO', $res['messages'][2]->content);
    }

    // ---- MessagesAnnotation --------------------------------------------------------------------

    public function testMessagesAnnotationShouldAssignIdsProperlyAndAvoidDupingAddedMessages(): void
    {
        $dupe = static fn (array $state): array => ['messages' => $state['messages']];
        $child = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('duper', $dupe)
            ->addNode('duper2', $dupe)
            ->addEdge(Constants::START, 'duper')
            ->addEdge('duper', 'duper2')
            ->compile(['interruptBefore' => ['duper2']]);
        $graph = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('duper', $child)
            ->addNode('duper2', $dupe)
            ->addEdge(Constants::START, 'duper')
            ->addEdge('duper', 'duper2')
            ->compile(['checkpointer' => new MemorySaver()]);

        $config = new RunnableConfig(configurable: ['thread_id' => '1']);
        $res = $graph->invoke(['messages' => [new HumanMessage('should be only one')]], $config);
        $res2 = $graph->invoke(null, $config);

        self::assertCount(1, $res['messages']);
        self::assertCount(1, $res2['messages']);
    }

    // ---- messagesStateReducer ------------------------------------------------------------------

    public function testShouldDedupeMessages(): void
    {
        $deduped = MessagesReducer::messagesStateReducer(
            [new HumanMessage(['id' => 'foo', 'content' => 'bar'])],
            [new HumanMessage(['id' => 'foo', 'content' => 'bar2'])],
        );

        self::assertCount(1, $deduped);
        self::assertSame('bar2', $deduped[0]->content);
    }

    public function testShouldDedupeMessagesIfThereAreDupesOnTheRight(): void
    {
        $deduped = MessagesReducer::messagesStateReducer([], [
            new HumanMessage(['id' => 'foo', 'content' => 'bar']),
            new HumanMessage(['id' => 'foo', 'content' => 'bar2']),
        ]);

        self::assertCount(1, $deduped);
        self::assertSame('bar2', $deduped[0]->content);
    }

    public function testShouldApplyRightSideMessagesInOrder(): void
    {
        $deduped = MessagesReducer::messagesStateReducer(
            [new HumanMessage(['id' => 'foo', 'content' => 'bar3'])],
            [
                new RemoveMessage(['id' => 'foo']),
                new HumanMessage(['id' => 'foo', 'content' => 'bar']),
                new HumanMessage(['id' => 'foo', 'content' => 'bar2']),
            ],
        );

        self::assertCount(1, $deduped);
        self::assertSame('bar2', $deduped[0]->content);
    }

    // ---- ToolNode with Commands ----------------------------------------------------------------

    /** A tool that hands the conversation to `$target`, as a parent-graph Command. */
    private static function transfer(string $name, string $target, bool $arrayUpdate = false): StructuredTool
    {
        return tool(
            static function (array $in, mixed $rm, RunnableConfig $config) use ($name, $target, $arrayUpdate): Command {
                $message = new ToolMessage([
                    'content' => 'Transferred to ' . ucfirst($target),
                    'tool_call_id' => $config->toolCall['id'],
                    'name' => $name,
                ]);

                return new Command(
                    graph: Command::PARENT,
                    update: $arrayUpdate ? [$message] : ['messages' => [$message]],
                    goto: $target,
                );
            },
            ['name' => $name, 'description' => 'Transfer to ' . $target, 'schema' => Schema::object([])],
        );
    }

    private static function sendTool(string $name, string $target): StructuredTool
    {
        return tool(
            static fn (array $in, mixed $rm, RunnableConfig $config): Command => new Command(
                graph: Command::PARENT,
                goto: [new Send($target, ['messages' => [new ToolMessage([
                    'content' => 'Transferred to ' . ucfirst($target),
                    'name' => $name,
                    'tool_call_id' => $config->toolCall['id'],
                ])]])],
            ),
            ['name' => $name, 'description' => 'Transfer to ' . $target, 'schema' => Schema::object([])],
        );
    }

    private static function add(): StructuredTool
    {
        return tool(
            static fn (array $in): string => (string) ($in['a'] + $in['b']),
            ['name' => 'add', 'description' => 'Add two numbers', 'schema' => Schema::object(['a' => ['type' => 'number'], 'b' => ['type' => 'number']], ['a', 'b'])],
        );
    }

    private function assertTransfer(Command $command, string $callId, string $name, bool $arrayUpdate = false): void
    {
        self::assertSame(Command::PARENT, $command->graph);
        self::assertSame('bob', $command->goto);
        $messages = $arrayUpdate ? $command->update : $command->update['messages'];
        self::assertCount(1, $messages);
        self::assertSame('Transferred to Bob', $messages[0]->content);
        self::assertSame($callId, $messages[0]->toolCallId);
        self::assertSame($name, $messages[0]->name);
    }

    public function testCanHandleToolsReturningCommandsWithDictInput(): void
    {
        $transfer = self::transfer('transfer_to_bob', 'bob');
        $asyncTransfer = self::transfer('async_transfer_to_bob', 'bob');

        $result = (new ToolNode([self::add(), $transfer]))->invoke(['messages' => [
            self::ai(self::call('add', ['a' => 1, 'b' => 2], '1'), self::call('transfer_to_bob', [], '2')),
        ]]);

        self::assertCount(2, $result);
        self::assertSame('3', $result[0]['messages'][0]->content);
        self::assertSame('1', $result[0]['messages'][0]->toolCallId);
        self::assertSame('add', $result[0]['messages'][0]->name);
        $this->assertTransfer($result[1], '2', 'transfer_to_bob');

        $single = (new ToolNode([$transfer]))->invoke(['messages' => [self::ai(self::call('transfer_to_bob', [], '1'))]]);
        self::assertCount(1, $single);
        $this->assertTransfer($single[0], '1', 'transfer_to_bob');

        $async = (new ToolNode([$asyncTransfer]))->invoke(['messages' => [self::ai(self::call('async_transfer_to_bob', [], '1'))]]);
        $this->assertTransfer($async[0], '1', 'async_transfer_to_bob');

        $multiple = (new ToolNode([$transfer, $asyncTransfer]))->invoke(['messages' => [
            self::ai(self::call('transfer_to_bob', [], '1'), self::call('async_transfer_to_bob', [], '2')),
        ]]);
        self::assertCount(2, $multiple);
        $this->assertTransfer($multiple[0], '1', 'transfer_to_bob');
        $this->assertTransfer($multiple[1], '2', 'async_transfer_to_bob');
    }

    public function testCanHandleToolsReturningCommandsWithArrayInput(): void
    {
        $transfer = self::transfer('transfer_to_bob', 'bob', true);
        $asyncTransfer = self::transfer('async_transfer_to_bob', 'bob', true);

        $result = (new ToolNode([self::add(), $transfer]))->invoke([
            self::ai(self::call('add', ['a' => 1, 'b' => 2], '1'), self::call('transfer_to_bob', [], '2')),
        ]);

        self::assertCount(2, $result);
        self::assertCount(1, $result[0]);
        self::assertSame('3', $result[0][0]->content);
        self::assertSame('add', $result[0][0]->name);
        $this->assertTransfer($result[1], '2', 'transfer_to_bob', true);

        foreach ([$transfer, $asyncTransfer] as $one) {
            $single = (new ToolNode([$one]))->invoke([self::ai(self::call($one->name, [], '1'))]);
            self::assertCount(1, $single);
            $this->assertTransfer($single[0], '1', $one->name, true);
        }

        $multiple = (new ToolNode([$transfer, $asyncTransfer]))->invoke([
            self::ai(self::call('transfer_to_bob', [], '1'), self::call('async_transfer_to_bob', [], '2')),
        ]);
        self::assertCount(2, $multiple);
        $this->assertTransfer($multiple[0], '1', 'transfer_to_bob', true);
        $this->assertTransfer($multiple[1], '2', 'async_transfer_to_bob', true);
    }

    public function testShouldHandleParentCommandsWithSend(): void
    {
        $result = (new ToolNode([self::sendTool('transfer_to_alice', 'alice'), self::sendTool('transfer_to_bob', 'bob')]))->invoke([
            self::ai(self::call('transfer_to_alice', [], '1'), self::call('transfer_to_bob', [], '2')),
        ]);

        self::assertCount(1, $result);
        self::assertSame(Command::PARENT, $result[0]->graph);
        $sends = $result[0]->goto;
        self::assertCount(2, $sends);
        self::assertContainsOnlyInstancesOf(Send::class, $sends);
        self::assertSame('alice', $sends[0]->node);
        self::assertSame('Transferred to Alice', $sends[0]->args['messages'][0]->content);
        self::assertSame('1', $sends[0]->args['messages'][0]->toolCallId);
        self::assertSame('bob', $sends[1]->node);
        self::assertSame('2', $sends[1]->args['messages'][0]->toolCallId);
    }

    // ---- ToolNode error handling ---------------------------------------------------------------

    private static function invokeWith(ToolNode $node, string $toolName, array $args = [], string $id = 'testid'): mixed
    {
        return $node->invoke(['messages' => [self::ai(self::call($toolName, $args, $id))]]);
    }

    public function testShouldRaiseGraphInterrupt(): void
    {
        $this->expectException(GraphInterrupt::class);

        self::invokeWith(new ToolNode([self::throwing(new GraphInterrupt(), 'tool_with_interrupt')]), 'tool_with_interrupt');
    }

    public function testAnInterruptIsRethrownEvenWhenHandleToolErrorsIsTrue(): void
    {
        $node = new ToolNode([self::throwing(new NodeInterrupt('wait'))], ['handleToolErrors' => true]);

        $this->expectException(NodeInterrupt::class);

        self::invokeWith($node, 'tool_with_error');
    }

    public function testShouldHandleToolErrorsByDefault(): void
    {
        $result = self::invokeWith(new ToolNode([self::throwing(new \Exception('some error'))]), 'tool_with_error');

        self::assertSame("Error: some error\n Please fix your mistakes.", $result['messages'][0]->content);
        self::assertSame('error', $result['messages'][0]->additional_kwargs['status']);
        self::assertSame('testid', $result['messages'][0]->toolCallId);
    }

    public function testRecoversASchemaErrorNestedUnderMultipleMiddlewareErrorWrappers(): void
    {
        $strict = tool(
            static fn (): string => 'ok',
            ['name' => 'strict_tool', 'description' => 'requires a field', 'schema' => Schema::object(['required_field' => ['type' => 'string']], ['required_field'])],
        );
        // Two stacked wrapToolCall middlewares wrap once each: MiddlewareError -> MiddlewareError -> ToolInvocationError.
        $toolNode = new ToolNode([$strict], ['wrapToolCall' => static function (array $request, callable $handler): mixed {
            try {
                return $handler($request);
            } catch (\Throwable $error) {
                throw MiddlewareError::wrap(MiddlewareError::wrap($error, 'inner_middleware'), 'outer_middleware');
            }
        }]);

        $result = self::invokeWith($toolNode, 'strict_tool');

        self::assertInstanceOf(ToolMessage::class, $result['messages'][0]);
        self::assertStringContainsString('did not match expected schema', $result['messages'][0]->content);
        self::assertStringContainsString("Error invoking tool 'strict_tool'", $result['messages'][0]->content);
    }

    public function testStillThrowsNonRecoverableMiddlewareErrorsByDefault(): void
    {
        $okTool = tool(static fn (): string => 'ok', ['name' => 'ok_tool', 'description' => 'a tool', 'schema' => Schema::object([])]);
        $toolNode = new ToolNode([$okTool], ['wrapToolCall' => static function (): never {
            throw MiddlewareError::wrap(new \Exception('boom'), 'test_middleware');
        }]);

        $this->expectException(MiddlewareError::class);
        $this->expectExceptionMessage('boom');

        self::invokeWith($toolNode, 'ok_tool');
    }

    public function testMiddlewareErrorsAreCaughtWhenHandleToolErrorsIsTrue(): void
    {
        $okTool = tool(static fn (): string => 'ok', ['name' => 'ok_tool', 'description' => 'a tool', 'schema' => Schema::object([])]);
        $toolNode = new ToolNode([$okTool], [
            'handleToolErrors' => true,
            'wrapToolCall' => static function (): never {
                throw MiddlewareError::wrap(new \Exception('boom'), 'test_middleware');
            },
        ]);

        $result = self::invokeWith($toolNode, 'ok_tool');

        // A MiddlewareError carries the wrapped error's name, as upstream's `this.name = error.name` does.
        self::assertSame("Exception: boom\n Please fix your mistakes.", $result['messages'][0]->content);
    }

    public function testShouldThrowIfHandleToolErrorsIsFalse(): void
    {
        $node = new ToolNode([self::throwing(new \Exception('some error'))], ['handleToolErrors' => false]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/some error/');

        self::invokeWith($node, 'tool_with_error');
    }

    public function testShouldAllowToHandleToolErrorsWithAFunction(): void
    {
        $errorToThrow = new \Exception('some error');
        $calls = [];
        $node = new ToolNode([self::throwing($errorToThrow, 'tool_with_interrupt')], ['handleToolErrors' => static function (\Throwable $error, array $toolCall) use (&$calls): ?ToolMessage {
            $calls[] = [$error, $toolCall];

            return null;
        }]);

        try {
            self::invokeWith($node, 'tool_with_interrupt');
            self::fail('a handler that returns nothing re-raises');
        } catch (\Exception $e) {
            self::assertSame($errorToThrow, $e);
        }

        self::assertCount(1, $calls);
        self::assertSame($errorToThrow, $calls[0][0]);
        self::assertSame('tool_with_interrupt', $calls[0][1]['name']);
        self::assertSame('testid', $calls[0][1]['id']);
        self::assertSame([], $calls[0][1]['args']);
    }

    public function testShouldReturnAToolMessageIfHandleToolErrorsReturnsAToolMessage(): void
    {
        $node = new ToolNode([self::throwing(new \Exception('some error'))], ['handleToolErrors' => static fn (\Throwable $e, array $toolCall): ToolMessage => new ToolMessage([
            'content' => 'handled error',
            'tool_call_id' => $toolCall['id'],
        ])]);

        $result = self::invokeWith($node, 'tool_with_error');

        self::assertSame('handled error', $result['messages'][0]->content);
        self::assertSame('error', $result['messages'][0]->additional_kwargs['status'], 'status defaults to error');
    }

    public function testTheDefaultHandlerKeepsTheMessageOfAToolInvocationErrorAsIs(): void
    {
        $strict = tool(
            static fn (array $in): string => 'Result: ' . $in['value'],
            ['name' => 'strict_tool', 'description' => 'strict', 'schema' => Schema::object(['value' => ['type' => 'number']], ['value'])],
        );

        $result = self::invokeWith(new ToolNode([$strict]), 'strict_tool', ['value' => '123'], '1');

        $content = $result['messages'][0]->content;
        self::assertStringStartsWith("Error invoking tool 'strict_tool' with kwargs {\"value\":\"123\"} with error: ", $content);
        self::assertStringContainsString('did not match expected schema', $content);
        self::assertStringEndsWith("\n Please fix the error and try again.", $content);
        self::assertSame('error', $result['messages'][0]->additional_kwargs['status']);
    }

    public function testDefaultHandlerOnlyShapesToolInvocationErrorsSpecially(): void
    {
        $call = self::call('t', ['a' => 1], 'id1');

        $invocation = ToolNode::defaultHandleToolErrors(new ToolInvocationError(new \Exception('x'), $call), $call);
        $other = ToolNode::defaultHandleToolErrors(new \Exception('ups 123'), $call);

        self::assertStringStartsWith("Error invoking tool 't'", $invocation->content);
        self::assertSame("Error: ups 123\n Please fix your mistakes.", $other->content);
        self::assertSame('id1', $other->toolCallId);
        self::assertSame('t', $other->name);
    }

    public function testShouldHandleMissingToolNameWithDefaultErrorHandler(): void
    {
        $toolNode = new ToolNode([self::weather()]);

        $result = $toolNode->invoke(['messages' => [self::ai(self::call('nonexistent_tool', ['foo' => 'bar'], 'call_123'))]]);

        self::assertCount(1, $result['messages']);
        $toolMessage = $result['messages'][0];
        self::assertInstanceOf(ToolMessage::class, $toolMessage);
        self::assertStringContainsString('nonexistent_tool is not a valid tool', $toolMessage->content);
        self::assertStringContainsString('get_weather', $toolMessage->content);
        self::assertSame('call_123', $toolMessage->toolCallId);
        self::assertSame('nonexistent_tool', $toolMessage->name);
        self::assertSame('error', $toolMessage->additional_kwargs['status']);
    }

    public function testShouldReturnGracefulErrorForMissingToolEvenWhenHandleToolErrorsIsFalse(): void
    {
        $toolNode = new ToolNode([self::weather()], ['handleToolErrors' => false]);

        $result = $toolNode->invoke(['messages' => [self::ai(self::call('nonexistent_tool', ['foo' => 'bar'], 'call_789'))]]);

        self::assertCount(1, $result['messages']);
        self::assertStringContainsString('nonexistent_tool is not a valid tool', $result['messages'][0]->content);
        self::assertSame('error', $result['messages'][0]->additional_kwargs['status']);
    }

    public function testShouldReturnGracefulErrorForMissingToolRegardlessOfCustomErrorHandler(): void
    {
        $handlerCalled = false;
        $toolNode = new ToolNode([self::weather()], ['handleToolErrors' => static function (\Throwable $e, array $toolCall) use (&$handlerCalled): ToolMessage {
            $handlerCalled = true;

            return new ToolMessage(['content' => 'Custom error: ' . $e->getMessage(), 'tool_call_id' => $toolCall['id'], 'name' => $toolCall['name']]);
        }]);

        $result = $toolNode->invoke(['messages' => [self::ai(self::call('missing_tool', ['x' => 'y'], 'call_custom'))]]);

        self::assertCount(1, $result['messages']);
        self::assertStringContainsString('missing_tool is not a valid tool', $result['messages'][0]->content);
        self::assertStringContainsString('get_weather', $result['messages'][0]->content);
        self::assertSame('call_custom', $result['messages'][0]->toolCallId);
        self::assertSame('error', $result['messages'][0]->additional_kwargs['status']);
        self::assertFalse($handlerCalled, 'missing tools are handled before the custom handler');
    }

    // ---- behaviours with no upstream test of their own -----------------------------------------

    public function testOutputMirrorsTheInputShape(): void
    {
        $node = new ToolNode([self::searchApi()]);
        $message = self::ai(self::call('search_api', ['query' => 'foo'], 'a'));

        $fromList = $node->invoke([$message]);
        $fromState = $node->invoke(['messages' => [$message]]);

        self::assertSame('result for foo', $fromList[0]->content);
        self::assertSame('result for foo', $fromState['messages'][0]->content);
        self::assertSame('search_api', $fromList[0]->name);
    }

    public function testAnsweredToolCallsAreNotRunAgain(): void
    {
        $ran = 0;
        $counter = tool(static function () use (&$ran): string {
            $ran++;

            return 'done';
        }, ['name' => 'counter', 'description' => 'c', 'schema' => Schema::object([])]);

        $result = (new ToolNode([$counter]))->invoke([
            self::ai(self::call('counter', [], 'one'), self::call('counter', [], 'two')),
            new ToolMessage(['content' => 'already', 'tool_call_id' => 'one']),
        ]);

        self::assertSame(1, $ran);
        self::assertCount(1, $result);
        self::assertSame('two', $result[0]->toolCallId);
    }

    public function testInputWithoutAnAiMessageOrMessagesIsRejected(): void
    {
        $node = new ToolNode([self::searchApi()]);

        try {
            $node->invoke([new HumanMessage('hi')]);
            self::fail('expected an error');
        } catch (\Exception $e) {
            self::assertSame('ToolNode only accepts AIMessages as input.', $e->getMessage());
        }

        $this->expectExceptionMessage('ToolNode only accepts BaseMessage[] or { messages: BaseMessage[] } as input.');
        $node->invoke('nope');
    }

    public function testASendPacketRunsOneCallAndHidesRoutingKeysFromTools(): void
    {
        $seen = null;
        $inspector = tool(static function (array $in, ToolRuntime $runtime) use (&$seen): string {
            $seen = $runtime->state;

            return 'ok';
        }, ['name' => 'inspector', 'description' => 'i', 'schema' => Schema::object([])]);

        $result = (new ToolNode([$inspector]))->invoke([
            'lg_tool_call' => self::call('inspector', [], 'send1'),
            'jumpTo' => 'tools',
            'userId' => 'u1',
        ]);

        self::assertSame(['userId' => 'u1'], $seen);
        self::assertSame('send1', $result['messages'][0]->toolCallId);
    }

    public function testToolkitsAreFlattened(): void
    {
        $toolkit = new class ([self::searchApi(), self::weather()]) extends BaseToolkit {
        };

        $node = new ToolNode([$toolkit]);

        self::assertCount(2, $node->tools);
    }

    public function testNameAndTagsComeFromTheOptions(): void
    {
        self::assertSame('tools', (new ToolNode([]))->getName());
        self::assertSame('my_tools', (new ToolNode([], ['name' => 'my_tools', 'tags' => ['t']]))->getName());
        self::assertInstanceOf(RunnableCallable::class, new ToolNode([]));
    }

    public function testAnAbortedSignalMakesErrorsBubbleUpInsteadOfBecomingMessages(): void
    {
        $aborted = false;
        $node = new ToolNode([self::throwing(new \Exception('cancelled mid-flight'))], [
            'handleToolErrors' => true,
            'signal' => static function () use (&$aborted): bool {
                return $aborted;
            },
        ]);

        $before = self::invokeWith($node, 'tool_with_error');
        self::assertSame('error', $before['messages'][0]->additional_kwargs['status']);

        $aborted = true;
        $this->expectExceptionMessage('cancelled mid-flight');
        self::invokeWith($node, 'tool_with_error');
    }

    // ---- wrapToolCall --------------------------------------------------------------------------

    public function testWrapToolCallReceivesTheRequestAndTheRuntime(): void
    {
        $captured = null;
        $node = new ToolNode([self::searchApi()], ['wrapToolCall' => static function (array $request, callable $handler) use (&$captured): mixed {
            $captured = $request;

            return $handler($request);
        }]);

        $result = $node->invoke(['messages' => [self::ai(self::call('search_api', ['query' => 'foo'], 'c1'))], 'userId' => 'u']);

        self::assertSame('result for foo', $result['messages'][0]->content);
        self::assertSame('search_api', $captured['toolCall']['name']);
        self::assertSame('search_api', $captured['tool']->name);
        self::assertSame('u', $captured['state']['userId']);
        self::assertInstanceOf(Runtime::class, $captured['runtime']);
        self::assertSame('c1', $captured['runtime']->toolCallId);
    }

    public function testWrapToolCallCanSupplyAToolTheNodeWasNeverGiven(): void
    {
        $dynamic = tool(static fn (): string => 'from the dynamic tool', ['name' => 'dynamic', 'description' => 'd', 'schema' => Schema::object([])]);
        $node = new ToolNode([], ['wrapToolCall' => static function (array $request, callable $handler) use ($dynamic): mixed {
            self::assertNull($request['tool']);

            return $handler([...$request, 'tool' => $dynamic]);
        }]);

        $result = self::invokeWith($node, 'dynamic');

        self::assertSame('from the dynamic tool', $result['messages'][0]->content);
        self::assertSame('dynamic', $result['messages'][0]->name);
    }

    public function testWrapToolCallCanReplaceTheResultWithoutRunningTheTool(): void
    {
        $ran = false;
        $guarded = tool(static function () use (&$ran): string {
            $ran = true;

            return 'ran';
        }, ['name' => 'guarded', 'description' => 'g', 'schema' => Schema::object([])]);
        $node = new ToolNode([$guarded], ['wrapToolCall' => static fn (array $request): ToolMessage => new ToolMessage([
            'content' => 'blocked',
            'tool_call_id' => $request['toolCall']['id'],
        ])]);

        $result = self::invokeWith($node, 'guarded');

        self::assertFalse($ran);
        self::assertSame('blocked', $result['messages'][0]->content);
    }

    public function testWrapToolCallAnUnregisteredToolStillGetsTheGracefulMessageFromTheBaseHandler(): void
    {
        $node = new ToolNode([self::weather()], ['wrapToolCall' => static fn (array $request, callable $handler): mixed => $handler($request)]);

        $result = self::invokeWith($node, 'ghost');

        self::assertStringContainsString('ghost is not a valid tool, try one of [get_weather]', $result['messages'][0]->content);
    }

    public function testAGraphInterruptThroughWrapToolCallIsNeverWrapped(): void
    {
        $node = new ToolNode([self::weather()], ['wrapToolCall' => static function (): never {
            throw new GraphInterrupt();
        }]);

        $this->expectException(GraphInterrupt::class);

        self::invokeWith($node, 'get_weather', ['location' => 'x']);
    }

    private static function steerTool(string $format = 'content_and_artifact'): StructuredTool
    {
        return tool(
            static fn (array $in): array => [
                new Command(update: ['messages' => [new ToolMessage(['content' => 'cmd', 'tool_call_id' => 'c1', 'name' => 'steer'])]]),
                ['a' => 1],
            ],
            ['name' => 'steer', 'description' => 'steer', 'schema' => Schema::object([]), 'responseFormat' => $format],
        );
    }

    public function testContentAndArtifactToolReturningACommandTupleYieldsTheCommand(): void
    {
        $result = (new ToolNode([self::steerTool()]))->invoke(['messages' => [self::ai(self::call('steer', [], 'c1'))]]);

        self::assertCount(1, $result);
        self::assertInstanceOf(Command::class, $result[0]);
        self::assertSame('cmd', $result[0]->update['messages'][0]->content);
    }

    public function testPlainContentAndArtifactToolStillBecomesAToolMessageWithArtifact(): void
    {
        $plain = tool(
            static fn (array $in): array => ['hello', ['a' => 1]],
            ['name' => 'plain', 'description' => 'p', 'schema' => Schema::object([]), 'responseFormat' => 'content_and_artifact'],
        );
        $result = (new ToolNode([$plain]))->invoke(['messages' => [self::ai(self::call('plain', [], 'c2'))]]);

        self::assertSame('hello', $result['messages'][0]->content);
        self::assertSame(['a' => 1], $result['messages'][0]->artifact);
    }

    public function testCommandTupleSteersARealGraph(): void
    {
        $graph = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('tools', new ToolNode([self::steerTool()]))
            ->addEdge(Constants::START, 'tools')
            ->compile();

        $out = $graph->invoke(['messages' => [self::ai(self::call('steer', [], 'c1'))]]);

        self::assertSame('cmd', end($out['messages'])->content);
    }
}
