<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tests\Unit\Agents\Support\FakeToolCallingChatModel;
use LangChain\Tools\Schema;
use LangGraph\Agents\AgentState;
use LangGraph\Agents\Annotation;
use LangGraph\Agents\Errors\MultipleToolsBoundError;
use LangGraph\Agents\Nodes\ToolNode;
use LangGraph\Agents\Nodes\Utils as NodeUtils;
use LangGraph\Agents\RunnableCallable;
use LangGraph\Agents\Utils;
use LangGraph\Agents\WithAgentName;
use LangGraph\Pregel\Constants;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * The foundations working together on a real graph: the agent state schema, a model node bound to tools
 * and wrapped for agent names, `ToolNode` with composed `wrapToolCall` middleware, and `AgentState`
 * sharing node output. This is the loop `createAgent` (WP-21b) assembles, minus `createAgent` itself.
 */
#[CoversNothing]
final class AgentFoundationsEndToEndTest extends TestCase
{
    private function weatherTool(): \LangChain\Tools\StructuredTool
    {
        return tool(
            static fn (array $in): string => 'It is sunny in ' . $in['location'],
            ['name' => 'get_weather', 'description' => 'Weather', 'schema' => Schema::object(['location' => ['type' => 'string']], ['location'])],
        );
    }

    public function testAModelToolsLoopRunsThroughTheFoundations(): void
    {
        $weather = $this->weatherTool();
        $model = new FakeToolCallingChatModel(['responses' => [
            new AIMessage([
                'content' => '<name>bob</name><content>Let me check.</content>',
                'tool_calls' => [['id' => 'call_1', 'name' => 'get_weather', 'args' => ['location' => 'Tokyo'], 'type' => 'tool_call']],
            ]),
            new AIMessage('Tokyo is sunny.'),
        ]]);

        Utils::validateLLMHasNoBoundTools($model);
        $agentModel = WithAgentName::withAgentName(Utils::bindTools($model, [$weather]), 'inline');

        $trail = [];
        $middleware = [
            'name' => 'audit',
            'stateSchema' => ['type' => 'object', 'properties' => ['auditCount' => ['type' => 'number', 'default' => 0]]],
            'wrapToolCall' => static function (array $request, callable $handler) use (&$trail): mixed {
                $trail[] = 'before:' . $request['toolCall']['name'] . ':' . json_encode(array_keys($request['state']));
                $result = $handler($request);
                $trail[] = 'after:' . $result->content;

                return $result;
            },
        ];

        ['state' => $schema] = Annotation::createAgentState(false, null, [$middleware]);
        $agentState = new AgentState();

        $modelNode = new RunnableCallable(
            func: static fn (array $state, RunnableConfig $config): array => ['messages' => [$agentModel->invoke($state['messages'], $config)]],
            name: 'model',
        );
        $agentState->addNode($middleware, $modelNode);

        $toolNode = new ToolNode([$weather], ['wrapToolCall' => Utils::wrapToolCall([$middleware])]);

        $graph = (new StateGraph($schema))
            ->addNode('model', $modelNode)
            ->addNode('tools', $toolNode)
            ->addEdge(Constants::START, 'model')
            ->addConditionalEdges(
                'model',
                static fn (array $state): string => Utils::hasToolCalls($state['messages'][array_key_last($state['messages'])] ?? null) ? 'tools' : Constants::END,
            )
            ->addEdge('tools', 'model')
            ->compile();

        $seeded = NodeUtils::initializeMiddlewareStates([$middleware], ['messages' => []]);
        $result = $graph->invoke(['messages' => [new HumanMessage('Weather in Tokyo?')], ...$seeded]);

        // human, ai (with the call), tool result, final ai
        self::assertCount(4, $result['messages']);
        [$human, $callMessage, $toolMessage, $final] = $result['messages'];
        self::assertInstanceOf(HumanMessage::class, $human);

        // The agent name was stripped from the model output and became the message name.
        self::assertSame('Let me check.', $callMessage->content);
        self::assertSame('bob', $callMessage->name);

        self::assertInstanceOf(ToolMessage::class, $toolMessage);
        self::assertSame('It is sunny in Tokyo', $toolMessage->content);
        self::assertSame('call_1', $toolMessage->toolCallId);
        self::assertSame('Tokyo is sunny.', $final->content);

        // The second model call saw the first AI message with the name folded back into its text.
        $secondCall = $model->seen[1];
        self::assertSame('<name>bob</name><content>Let me check.</content>', $secondCall[1]->content);
        self::assertNull($secondCall[1]->name);

        // The tool middleware ran around the tool, and saw only `messages` plus its own schema's keys.
        self::assertSame(['before:get_weather:["messages","auditCount"]', 'after:It is sunny in Tokyo'], $trail);

        // The model node's last output is shared through AgentState (and `jumpTo` never leaks).
        self::assertSame(['messages' => [$final]], $agentState->getState('audit'));
    }

    public function testBindingToAModelThatAlreadyHasToolsIsRefused(): void
    {
        $bound = (new FakeToolCallingChatModel())->bindTools([$this->weatherTool()]);

        $this->expectException(MultipleToolsBoundError::class);

        Utils::validateLLMHasNoBoundTools($bound);
    }

    public function testTheLoopIsDrivenByStateInitialisedFromMiddlewareSchemas(): void
    {
        $middleware = [
            'name' => 'counter',
            'stateSchema' => ['type' => 'object', 'properties' => ['seen' => ['type' => 'number', 'default' => 0], '_internal' => ['type' => 'string']], 'required' => ['seen']],
        ];

        $initial = NodeUtils::initializeMiddlewareStates([$middleware], ['messages' => []]);
        ['state' => $schema] = Annotation::createAgentState(false, null, [$middleware]);

        $graph = (new StateGraph($schema))
            ->addNode('bump', static fn (array $state): array => ['seen' => $state['seen'] + 1, '_internal' => 'touched'])
            ->addEdge(Constants::START, 'bump')
            ->compile();

        $result = $graph->invoke(['messages' => [], ...$initial]);

        self::assertSame(['seen' => 0], $initial);
        self::assertSame(1, $result['seen']);
        self::assertSame('touched', $result['_internal']);
    }
}
