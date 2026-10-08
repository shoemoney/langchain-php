<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangChain\Tests\Unit\Agents\Support\FakeToolCallingModel;
use LangChain\Tools\Schema;
use LangChain\Tools\ToolRuntime;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware;
use LangGraph\Agents\ReactAgent;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Pregel\Command;
use LangGraph\Store\InMemoryStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * The `createAgent` describe block of `langchain/src/agents/tests/reactAgent.test.ts`.
 *
 * Not converted, and why:
 *  - "should work with structured response", "should support native response format" and the returnDirect case
 *    "calls the model after a failed returnDirect tool when a response format is set": structured responses
 *    (`toolStrategy` / `providerStrategy`) are WP-21c;
 *  - "OpenAI API selection / uses the Responses API for openai: strings": model id strings need `initChatModel` (WP-20);
 *  - "Can accept RunnableToolLike": `Runnable::asTool()` does not exist in this port;
 *  - the two "tracing metadata" tests: they assert on a LangSmith tracer client, which is out of scope;
 *  - "supports zod 3/4 schemas ...": converted without its `toolStrategy` response format (the schema half is kept).
 * The abort-signal, withConfig and metadata blocks are in the sibling `ReactAgent*Test` files.
 */
#[CoversClass(ReactAgent::class)]
#[CoversClass(Agent::class)]
final class ReactAgentTest extends TestCase
{
    public function testShouldWorkWithNoPrompt(): void
    {
        $checkpointer = new MemorySaver();
        $agent = Agent::create(['model' => new FakeToolCallingModel(), 'tools' => [], 'checkpointer' => $checkpointer]);

        $inputs = [new HumanMessage('hi?')];
        $thread = ['configurable' => ['thread_id' => '123']];
        $response = $agent->invoke(['messages' => $inputs], $thread);

        self::assertCount(2, $response['messages']);
        self::assertSame('hi?', $response['messages'][0]->content);
        AgentAssertions::assertSameMessage(new AIMessage(['name' => 'model', 'content' => 'hi?', 'id' => '0']), $response['messages'][1]);

        $saved = $checkpointer->getTuple(['configurable' => ['thread_id' => '123']]);
        self::assertNotNull($saved);
        $savedMessages = $saved->checkpoint->channelValues['messages'];
        self::assertCount(2, $savedMessages);
        self::assertSame('hi?', $savedMessages[0]->content);
        AgentAssertions::assertSameMessage(new AIMessage(['name' => 'model', 'content' => 'hi?', 'id' => '0']), $savedMessages[1]);
    }

    public function testShouldPropagateCheckpointerSetAfterConstruction(): void
    {
        $agent = Agent::create(['model' => new FakeToolCallingModel(), 'tools' => []]);

        $externalCheckpointer = new MemorySaver();
        $agent->checkpointer = $externalCheckpointer;
        self::assertSame($externalCheckpointer, $agent->checkpointer);

        $agent->invoke(['messages' => [new HumanMessage('hi?')]], ['configurable' => ['thread_id' => 'propagation-test']]);

        $saved = $externalCheckpointer->getTuple(['configurable' => ['thread_id' => 'propagation-test']]);
        self::assertNotNull($saved);
        $savedMessages = $saved->checkpoint->channelValues['messages'];
        self::assertSame('hi?', $savedMessages[0]->content);
        AgentAssertions::assertSameMessage(new AIMessage(['name' => 'model', 'content' => 'hi?', 'id' => '0']), $savedMessages[1]);
    }

    public function testShouldPropagateStoreSetAfterConstruction(): void
    {
        $agent = Agent::create(['model' => new FakeToolCallingModel(), 'tools' => []]);

        self::assertNull($agent->store);

        $newStore = new InMemoryStore();
        $agent->store = $newStore;
        self::assertSame($newStore, $agent->store);
        self::assertSame($newStore, $agent->graph->store);
    }

    public function testShouldRejectLlmWithBoundTools(): void
    {
        $model = new FakeToolCallingModel();
        $searchTool = AgentAssertions::searchApi();

        // A model with bound tools.
        $modelWithTools = $model->bindTools([$searchTool]);

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage(
            "The provided LLM already has bound tools. "
            . "Please provide an LLM without bound tools to createAgent. "
            . "The agent will bind the tools provided in the 'tools' parameter."
        );

        Agent::create(['model' => $modelWithTools, 'tools' => [$searchTool]]);
    }

    public function testShouldWorkWithStringPrompt(): void
    {
        $agent = Agent::create(['model' => new FakeToolCallingModel(), 'tools' => [], 'systemPrompt' => 'Foo']);

        $inputs = [new HumanMessage('hi?')];
        $response = $agent->invoke(['messages' => $inputs]);

        self::assertCount(2, $response['messages']);
        AgentAssertions::assertSameMessage(
            new AIMessage(['name' => 'model', 'content' => 'Foo-hi?', 'id' => '0', 'tool_calls' => []]),
            $response['messages'][1],
        );
    }

    public function testShouldValidateMessagesCorrectly(): void
    {
        $agent = Agent::create(['model' => new FakeToolCallingModel(), 'tools' => []]);

        // A single human message works.
        $first = $agent->invoke(['messages' => [new HumanMessage("What's the weather?")]]);
        self::assertCount(2, $first['messages']);

        // Human + AI works.
        $second = $agent->invoke(['messages' => [
            new HumanMessage("What's the weather?"),
            new AIMessage('The weather is sunny and 75°F.'),
        ]]);
        self::assertCount(3, $second['messages']);
    }

    public function testShouldWorkWithAsyncNoPrompt(): void
    {
        $checkpointer = new MemorySaver();
        $agent = Agent::create(['model' => new FakeToolCallingModel(), 'tools' => [], 'checkpointer' => $checkpointer]);

        $response = $agent->invoke(['messages' => [new HumanMessage('hi?')]], ['configurable' => ['thread_id' => '123']]);

        self::assertCount(2, $response['messages']);
        AgentAssertions::assertSameMessage(new AIMessage(['name' => 'model', 'content' => 'hi?', 'id' => '0']), $response['messages'][1]);

        $saved = $checkpointer->getTuple(['configurable' => ['thread_id' => '123']]);
        self::assertNotNull($saved);
        self::assertArrayHasKey('messages', $saved->checkpoint->channelValues);
    }

    public function testShouldSupportToolReturningDirectResults(): void
    {
        $toolReturnDirect = tool(
            static fn (array $in): string => 'Direct result: ' . $in['input'],
            ['name' => 'toolReturnDirect', 'description' => 'A tool that returns directly.', 'schema' => Schema::object(['input' => ['type' => 'string', 'description' => 'Input string']], ['input']), 'returnDirect' => true],
        );
        $toolNormal = tool(
            static fn (array $in): string => 'Normal result: ' . $in['input'],
            ['name' => 'toolNormal', 'description' => 'A normal tool.', 'schema' => Schema::object(['input' => ['type' => 'string', 'description' => 'Input string']], ['input'])],
        );

        // Test direct return for toolReturnDirect.
        $firstToolCall = [['name' => 'toolReturnDirect', 'args' => ['input' => 'Test direct'], 'id' => '1']];

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [$firstToolCall, []]]),
            'tools' => [$toolReturnDirect, $toolNormal],
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage(['content' => 'Test direct', 'id' => 'hum0'])]]);

        self::assertCount(3, $result['messages']);
        AgentAssertions::assertSameMessage(new HumanMessage(['content' => 'Test direct', 'id' => 'hum0']), $result['messages'][0]);
        AgentAssertions::assertSameMessage(
            new AIMessage([
                'content' => 'Test direct',
                'id' => '0',
                'name' => 'model',
                'tool_calls' => array_map(static fn (array $tc): array => [...$tc, 'type' => 'tool_call'], $firstToolCall),
            ]),
            $result['messages'][1],
        );
        self::assertInstanceOf(ToolMessage::class, $result['messages'][2]);
        self::assertSame('Direct result: Test direct', $result['messages'][2]->content);
        self::assertSame('toolReturnDirect', $result['messages'][2]->name);
        self::assertSame('1', $result['messages'][2]->toolCallId);

        // Test normal tool behavior.
        $secondToolCall = [['name' => 'toolNormal', 'args' => ['input' => 'Test normal'], 'id' => '2']];

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [$secondToolCall, []]]),
            'tools' => [$toolReturnDirect, $toolNormal],
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage(['content' => 'Test normal', 'id' => 'hum1'])]]);

        self::assertCount(4, $result['messages']);
        AgentAssertions::assertSameMessage(new HumanMessage(['content' => 'Test normal', 'id' => 'hum1']), $result['messages'][0]);
        self::assertInstanceOf(AIMessage::class, $result['messages'][1]);
        self::assertCount(1, $result['messages'][1]->toolCalls);
        self::assertInstanceOf(ToolMessage::class, $result['messages'][2]);
        self::assertSame('Normal result: Test normal', $result['messages'][2]->content);
        self::assertSame('toolNormal', $result['messages'][2]->name);
        self::assertInstanceOf(AIMessage::class, $result['messages'][3]);
        self::assertCount(0, $result['messages'][3]->toolCalls);
    }

    public function testReturnDirectToolThatThrowsGoesBackToTheModelInsteadOfEndingTheRun(): void
    {
        $attempts = 0;
        $render = tool(
            static function (array $in) use (&$attempts): string {
                $attempts++;
                if ($attempts === 1) {
                    throw new \Exception('invalid_ui: ' . $in['ui']);
                }

                return 'rendered ' . $in['ui'];
            },
            ['name' => 'render', 'description' => 'Render the UI.', 'schema' => Schema::object(['ui' => ['type' => 'string']], ['ui']), 'returnDirect' => true],
        );
        $renderCall = static fn (string $id, string $ui): array => [['name' => 'render', 'args' => ['ui' => $ui], 'id' => $id]];
        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [$renderCall('1', 'Tabel'), $renderCall('2', 'Table'), []]]),
            'tools' => [$render],
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('show the invoice')]]);

        // Two model calls: the failed call went back to the model, and the successful retry ended the run.
        self::assertCount(2, AgentAssertions::ofType($result['messages'], AIMessage::class));
        $toolMessages = AgentAssertions::ofType($result['messages'], ToolMessage::class);
        self::assertSame(['error', 'success'], array_map(static fn (ToolMessage $m): string => $m->additional_kwargs['status'] ?? 'success', $toolMessages));
        $last = $result['messages'][array_key_last($result['messages'])];
        self::assertInstanceOf(ToolMessage::class, $last);
        self::assertSame('rendered Table', $last->content);
    }

    public function testReturnDirectToolWithInvalidArgumentsGoesBackToTheModelInsteadOfEndingTheRun(): void
    {
        $render = tool(
            static fn (array $in): string => 'rendered ' . $in['ui'],
            ['name' => 'render', 'description' => 'Render the UI.', 'schema' => Schema::object(['ui' => ['type' => 'string', 'enum' => ['Table', 'Chart']]], ['ui']), 'returnDirect' => true],
        );
        $renderCall = static fn (string $id, string $ui): array => [['name' => 'render', 'args' => ['ui' => $ui], 'id' => $id]];
        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [$renderCall('1', 'Tabel'), $renderCall('2', 'Table'), []]]),
            'tools' => [$render],
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('show the invoice')]]);

        self::assertCount(2, AgentAssertions::ofType($result['messages'], AIMessage::class));
        $toolMessages = AgentAssertions::ofType($result['messages'], ToolMessage::class);
        self::assertSame(['error', 'success'], array_map(static fn (ToolMessage $m): string => $m->additional_kwargs['status'] ?? 'success', $toolMessages));
        self::assertSame('rendered Table', $result['messages'][array_key_last($result['messages'])]->content);
    }

    public function testShouldWorkWithStoreIntegration(): void
    {
        $add = tool(
            static fn (array $in): int|float => $in['a'] + $in['b'],
            ['name' => 'add', 'description' => 'Adds a and b', 'schema' => Schema::object(['a' => ['type' => 'number'], 'b' => ['type' => 'number']], ['a', 'b'])],
        );

        // The system prompt modifier works.
        $agent = Agent::create([
            'model' => new FakeToolCallingModel(),
            'tools' => [$add],
            'middleware' => [
                Middleware::create([
                    'name' => 'prompt',
                    'wrapModelCall' => static fn (array $request, callable $handler): mixed => $handler([...$request, 'systemPrompt' => 'User name is Alice']),
                ]),
            ],
        ]);

        $response = $agent->invoke(['messages' => [['role' => 'user', 'content' => 'hi']]]);

        // The system message was applied.
        self::assertCount(2, $response['messages']);
        self::assertSame('User name is Alice-hi', $response['messages'][1]->content);
    }

    public function testShouldHandleMixedCommandAndNonCommandToolOutputs(): void
    {
        // A tool that returns a Command with no effect, just for testing mixed outputs.
        $commandTool = tool(
            static fn (array $in): Command => new Command(update: []),
            ['name' => 'commandTool', 'description' => 'A tool that returns a Command object', 'schema' => Schema::object(['action' => ['type' => 'string']], ['action'])],
        );
        $normalTool = tool(
            static fn (array $in): string => 'Normal result: ' . $in['input'],
            ['name' => 'normalTool', 'description' => 'A normal tool that returns a string', 'schema' => Schema::object(['input' => ['type' => 'string']], ['input'])],
        );

        $mixedToolCalls = [
            ['name' => 'commandTool', 'args' => ['action' => 'test_command'], 'id' => 'cmd_1'],
            ['name' => 'normalTool', 'args' => ['input' => 'test_normal'], 'id' => 'norm_1'],
        ];

        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [$mixedToolCalls]]),
            'tools' => [$commandTool, $normalTool],
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage(['content' => 'Test mixed outputs', 'id' => 'test1'])]]);

        // Human + AI message with tool calls + the tool message of normalTool; commandTool's Command adds no message.
        self::assertCount(3, $result['messages']);

        $byType = static fn (string $type): ?BaseMessage => array_values(array_filter($result['messages'], static fn (BaseMessage $m): bool => $m->getType() === $type))[0] ?? null;
        $aiMessage = $byType('ai');
        self::assertInstanceOf(AIMessage::class, $aiMessage);
        self::assertCount(2, $aiMessage->toolCalls);
        self::assertContains('commandTool', array_column($aiMessage->toolCalls, 'name'));
        self::assertContains('normalTool', array_column($aiMessage->toolCalls, 'name'));

        self::assertSame('Normal result: test_normal', $byType('tool')?->content);
        self::assertSame('Test mixed outputs', $byType('human')?->content);
    }

    public function testShouldWorkWithIncludeAgentNameInline(): void
    {
        // A fake model that answers with a message that already has a name.
        $model = new class () extends FakeToolCallingModel {
            protected function generate(array $messages, array $options = [], ?\LangChain\Tracers\CallbackManagerForLLMRun $runManager = null): \LangChain\LanguageModels\Outputs\ChatResult
            {
                $content = $messages[\count($messages) - 1]->content;
                if (\count($messages) > 1) {
                    $content = implode('-', array_filter(array_map(static fn (BaseMessage $m): string => (string) $m->content, $messages)));
                }
                $messageId = (string) $this->indexRef->current;
                $this->indexRef->current = ($this->indexRef->current + 1) % max(1, \count($this->toolCalls));

                return new \LangChain\LanguageModels\Outputs\ChatResult([
                    new \LangChain\LanguageModels\Outputs\ChatGeneration(new AIMessage(['content' => $content, 'id' => $messageId, 'name' => 'test-agent']), $content),
                ]);
            }
        };

        $agent = Agent::create(['model' => $model, 'tools' => [], 'name' => 'test-agent', 'includeAgentName' => 'inline']);

        $inputs = [new HumanMessage('Hello agent')];
        $response = $agent->invoke(['messages' => $inputs]);

        self::assertCount(2, $response['messages']);
        self::assertSame('Hello agent', $response['messages'][0]->content);

        // The AI message went through withAgentName.
        $aiMessage = $response['messages'][1];
        self::assertSame('Hello agent', $aiMessage->content);
        self::assertSame('test-agent', $aiMessage->name);
    }

    public function testWorksWithToolsThatReturnContentAndArtifactResponseFormat(): void
    {
        // A tool whose body returns a record: it is serialized to JSON as the tool message content.
        $searchApi = tool(
            static fn (array $in): array => ['content' => 'some response format', 'artifact' => '123'],
            ['name' => 'search_api', 'description' => 'A simple API that returns content with artifact.', 'schema' => Schema::object(['query' => ['type' => 'string']])],
        );

        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([
                new AIMessage(['content' => 'result1', 'tool_calls' => [['name' => 'search_api', 'id' => 'tool_abcd123', 'args' => ['query' => 'foo']]]]),
                new AIMessage('result2'),
            ]),
            'tools' => [$searchApi],
            'systemPrompt' => 'You are a helpful assistant',
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Hello Input!')]]);

        self::assertCount(4, $result['messages']);
        $toolMessage = json_decode($result['messages'][2]->content, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('some response format', $toolMessage['content']);
        self::assertSame('123', $toolMessage['artifact']);
    }

    public function testShouldThrowIfNoModelOptionIsProvided(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('`model` option is required to create an agent.');

        Agent::create(['tools' => []]);
    }

    public function testShouldMakePassedInStateAvailableInContext(): void
    {
        $captured = null;
        $stateCheckTool = tool(
            static function (array $in, ToolRuntime $runtime) use (&$captured): string {
                $captured = $runtime->state;

                return (string) json_encode($runtime->state);
            },
            ['name' => 'state_check_tool', 'description' => 'A tool that checks the current task input', 'schema' => Schema::object(['query' => ['type' => 'string']], ['query'])],
        );

        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([
                new AIMessage(['content' => 'result1', 'tool_calls' => [['name' => 'state_check_tool', 'id' => 'tool_abcd123', 'args' => ['query' => 'foo']]]]),
                new AIMessage('result2'),
            ]),
            'tools' => [$stateCheckTool],
            'stateSchema' => ['type' => 'object', 'properties' => ['customField' => ['type' => 'string']]],
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Hello Input!')], 'customField' => 'test-value']);

        self::assertCount(4, $result['messages']);
        $taskInput = json_decode($result['messages'][2]->content, true, 512, \JSON_THROW_ON_ERROR);
        self::assertSame('test-value', $taskInput['customField']);
        self::assertSame('test-value', $captured['customField']);
    }

    public function testSupportsJsonSchemaStateAndContextAcrossTheAgentAndMiddleware(): void
    {
        $seenContexts = [];
        $middleware1 = Middleware::create([
            'name' => 'middleware1',
            'stateSchema' => ['type' => 'object', 'properties' => ['middleware1Value' => ['type' => 'string', 'default' => 'v3-default']]],
            'contextSchema' => ['type' => 'object', 'properties' => ['middleware1Context' => ['type' => 'number']], 'required' => ['middleware1Context']],
            'beforeModel' => static function (array $state, \LangGraph\Agents\Runtime $runtime) use (&$seenContexts): array {
                $seenContexts['middleware1'] = $runtime->context['middleware1Context'];

                return ['middleware1Value' => 'v3-modified'];
            },
        ]);
        $middleware2 = Middleware::create([
            'name' => 'middleware2',
            'stateSchema' => ['type' => 'object', 'properties' => ['middleware2Value' => ['type' => 'string', 'default' => 'v4-default']]],
            'contextSchema' => ['type' => 'object', 'properties' => ['middleware2Context' => ['type' => 'boolean']], 'required' => ['middleware2Context']],
            'beforeModel' => static function (array $state, \LangGraph\Agents\Runtime $runtime) use (&$seenContexts): array {
                $seenContexts['middleware2'] = $runtime->context['middleware2Context'];

                return ['middleware2Value' => 'v4-modified'];
            },
        ]);
        $testTool = tool(
            static fn (array $in): string => 'Result: ' . $in['query'],
            ['name' => 'test_tool', 'description' => 'A test tool', 'schema' => Schema::object(['query' => ['type' => 'string']], ['query'])],
        );

        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([
                new AIMessage(['content' => '', 'tool_calls' => [['name' => 'test_tool', 'id' => 'tool_1', 'args' => ['query' => 'test']]]]),
                new AIMessage('done'),
            ]),
            'tools' => [$testTool],
            'stateSchema' => ['type' => 'object', 'properties' => ['agentCounter' => ['type' => 'number', 'default' => 0], 'agentName' => ['type' => 'string']]],
            'middleware' => [$middleware1, $middleware2],
        ]);

        $result = $agent->invoke(
            ['messages' => [new HumanMessage('Test mixed schemas')], 'agentCounter' => 1, 'agentName' => 'test-agent'],
            ['context' => ['middleware1Context' => 42, 'middleware2Context' => true]],
        );

        // The agent's state schema.
        self::assertSame(1, $result['agentCounter']);
        self::assertSame('test-agent', $result['agentName']);
        // The state of each middleware.
        self::assertSame('v3-modified', $result['middleware1Value']);
        self::assertSame('v4-modified', $result['middleware2Value']);
        // Each middleware saw its own slice of the context.
        self::assertSame(['middleware1' => 42, 'middleware2' => true], $seenContexts);
        self::assertGreaterThan(0, \count($result['messages']));
    }

    public function testSupportsAStateSchemaWithDefaultsInMiddleware(): void
    {
        $seenContext = null;
        $middleware = Middleware::create([
            'name' => 'stateSchemaMiddleware',
            'stateSchema' => ['type' => 'object', 'properties' => ['middlewareValue' => ['type' => 'string', 'default' => 'default']]],
            'contextSchema' => ['type' => 'object', 'properties' => ['middlewareContext' => ['type' => 'number']], 'required' => ['middlewareContext']],
            'beforeModel' => static function (array $state, \LangGraph\Agents\Runtime $runtime) use (&$seenContext): array {
                $seenContext = $runtime->context['middlewareContext'];

                return ['middlewareValue' => 'modified'];
            },
        ]);

        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('Done')]),
            'tools' => [],
            'middleware' => [$middleware],
        ]);

        $result = $agent->invoke(
            ['messages' => [new HumanMessage('Test StateSchema middleware')]],
            ['context' => ['middlewareContext' => 42]],
        );

        self::assertSame(42, $seenContext);
        self::assertSame('modified', $result['middlewareValue']);
        self::assertGreaterThan(0, \count($result['messages']));
    }
}
