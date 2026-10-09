<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Middleware;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangChain\Tests\Unit\Agents\Support\FlakyChatModel;
use LangChain\Tests\Unit\Agents\Support\LocalProviderServer;
use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;
use LangChain\Utils\Testing\FakeToolCallingChatModel;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware\ToolEmulatorMiddleware;
use LangGraph\Agents\Runtime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * `langchain/src/agents/middleware/tests/toolEmulator.test.ts`.
 *
 * `vi.fn()` tool mocks are call counters on closures. The classic `stream({streamMode: "messages"})` case is
 * ported against the compiled graph's `messages` mode; the `streamEvents` "v3" case has no PHP target (there is
 * no stream transformer protocol in this port) and is skipped.
 */
#[CoversClass(ToolEmulatorMiddleware::class)]
final class ToolEmulatorMiddlewareTest extends TestCase
{
    /** @var array<string, int> how many times each real tool body ran */
    private array $realCalls = [];

    protected function setUp(): void
    {
        $this->realCalls = ['search' => 0, 'calculator' => 0];
    }

    private function searchTool(): StructuredTool
    {
        return tool(function (array $in): string {
            $this->realCalls['search']++;

            return 'Results for: ' . $in['query'];
        }, ['name' => 'search', 'description' => 'Search for information', 'schema' => Schema::object(['query' => ['type' => 'string']], ['query'])]);
    }

    private function calculatorTool(): StructuredTool
    {
        return tool(function (array $in): string {
            $this->realCalls['calculator']++;

            return 'Result: ' . $in['expression'];
        }, ['name' => 'calculator', 'description' => 'Calculate an expression', 'schema' => Schema::object(['expression' => ['type' => 'string']], ['expression'])]);
    }

    /** @param list<AIMessage> $responses */
    private static function fake(array $responses): FakeToolCallingChatModel
    {
        return new FakeToolCallingChatModel(['sleep' => 0, 'responses' => $responses]);
    }

    /** @return array<string, mixed> */
    private static function toolRequest(string $id, string $name, array $args, StructuredTool $tool): array
    {
        return ['toolCall' => ['id' => $id, 'name' => $name, 'args' => $args], 'tool' => $tool, 'state' => [], 'runtime' => new Runtime()];
    }

    /** A real-tool handler that records its calls and returns "Real result". */
    private static function realHandler(int &$calls, string $id, string $name): \Closure
    {
        return static function () use (&$calls, $id, $name): ToolMessage {
            $calls++;

            return new ToolMessage(['content' => 'Real result', 'tool_call_id' => $id, 'name' => $name]);
        };
    }

    // ---- Initialization ------------------------------------------------------------------------

    public function testShouldCreateMiddlewareWithDefaultName(): void
    {
        self::assertSame('ToolEmulatorMiddleware', ToolEmulatorMiddleware::create()['name']);
    }

    public function testShouldAcceptEmptyOptions(): void
    {
        self::assertSame('ToolEmulatorMiddleware', ToolEmulatorMiddleware::create([])['name']);
    }

    public function testShouldAcceptToolsArray(): void
    {
        self::assertSame('ToolEmulatorMiddleware', ToolEmulatorMiddleware::create(['tools' => ['search', 'calculator']])['name']);
    }

    public function testShouldAcceptModelString(): void
    {
        // Lazy: nothing is resolved (and nothing fails while initChatModel is absent) until a tool is emulated.
        self::assertSame('ToolEmulatorMiddleware', ToolEmulatorMiddleware::create(['model' => 'openai:gpt-4'])['name']);
    }

    public function testShouldAcceptBaseChatModelInstance(): void
    {
        self::assertSame('ToolEmulatorMiddleware', ToolEmulatorMiddleware::create(['model' => self::fake([])])['name']);
    }

    // ---- Tool filtering ------------------------------------------------------------------------

    public function testShouldEmulateAllToolsWhenToolsIsUndefined(): void
    {
        $middleware = ToolEmulatorMiddleware::create(['model' => self::fake([new AIMessage('Mocked response')])]);
        $calls = 0;

        $result = $middleware['wrapToolCall'](self::toolRequest('1', 'search', ['query' => 'test'], $this->searchTool()), self::realHandler($calls, '1', 'search'));

        self::assertSame(0, $calls, 'the real handler must not run');
        self::assertInstanceOf(ToolMessage::class, $result);
        self::assertStringContainsString('Mocked response', $result->content);
    }

    public function testShouldEmulateAllToolsWhenToolsIsEmptyArray(): void
    {
        $middleware = ToolEmulatorMiddleware::create(['tools' => [], 'model' => self::fake([new AIMessage('Mocked response')])]);
        $calls = 0;

        $result = $middleware['wrapToolCall'](self::toolRequest('1', 'search', ['query' => 'test'], $this->searchTool()), self::realHandler($calls, '1', 'search'));

        self::assertSame(0, $calls);
        self::assertInstanceOf(ToolMessage::class, $result);
        self::assertStringContainsString('Mocked response', $result->content);
    }

    public function testShouldEmulateSpecificToolsByName(): void
    {
        $middleware = ToolEmulatorMiddleware::create(['tools' => ['search'], 'model' => self::fake([new AIMessage('Mocked response')])]);

        // Emulated tool.
        $searchCalls = 0;
        $searchResult = $middleware['wrapToolCall'](self::toolRequest('1', 'search', ['query' => 'test'], $this->searchTool()), self::realHandler($searchCalls, '1', 'search'));
        self::assertSame(0, $searchCalls);
        self::assertInstanceOf(ToolMessage::class, $searchResult);
        self::assertStringContainsString('Mocked response', $searchResult->content);

        // Non-emulated tool.
        $calcCalls = 0;
        $calcResult = $middleware['wrapToolCall'](self::toolRequest('2', 'calculator', ['expression' => '1+1'], $this->calculatorTool()), self::realHandler($calcCalls, '2', 'calculator'));
        self::assertSame(1, $calcCalls);
        self::assertInstanceOf(ToolMessage::class, $calcResult);
        self::assertStringContainsString('Real result', $calcResult->content);
    }

    public function testShouldEmulateSpecificToolsByToolInstance(): void
    {
        $middleware = ToolEmulatorMiddleware::create(['tools' => [$this->searchTool()], 'model' => self::fake([new AIMessage('Mocked response')])]);
        $calls = 0;

        $result = $middleware['wrapToolCall'](self::toolRequest('1', 'search', ['query' => 'test'], $this->searchTool()), self::realHandler($calls, '1', 'search'));

        self::assertSame(0, $calls);
        self::assertInstanceOf(ToolMessage::class, $result);
    }

    public function testShouldHandleMixedToolNamesAndInstances(): void
    {
        $middleware = ToolEmulatorMiddleware::create(['tools' => ['search', $this->calculatorTool()], 'model' => self::fake([new AIMessage('Mocked response')])]);

        $searchCalls = 0;
        $searchResult = $middleware['wrapToolCall'](self::toolRequest('1', 'search', ['query' => 'test'], $this->searchTool()), self::realHandler($searchCalls, '1', 'search'));
        self::assertSame(0, $searchCalls);
        self::assertInstanceOf(ToolMessage::class, $searchResult);
        self::assertStringContainsString('Mocked response', $searchResult->content);

        $calcCalls = 0;
        $calcResult = $middleware['wrapToolCall'](self::toolRequest('2', 'calculator', ['expression' => '1+1'], $this->calculatorTool()), self::realHandler($calcCalls, '2', 'calculator'));
        self::assertSame(0, $calcCalls);
        self::assertInstanceOf(ToolMessage::class, $calcResult);
        self::assertStringContainsString('Mocked response', $calcResult->content);
    }

    // ---- Integration with the agent ------------------------------------------------------------

    public function testShouldEmulateToolsInAgentExecution(): void
    {
        $model = self::fake([
            new AIMessage(['content' => '', 'tool_calls' => [['id' => '1', 'name' => 'search', 'args' => ['query' => 'test']]]]),
            new AIMessage('Final response'),
        ]);
        $middleware = ToolEmulatorMiddleware::create(['tools' => ['search'], 'model' => self::fake([new AIMessage('Mocked response')])]);
        $agent = Agent::create(['model' => $model, 'tools' => [$this->searchTool()], 'middleware' => [$middleware]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Search for something')]]);

        self::assertSame(0, $this->realCalls['search'], 'the tool must not have been called');
        $toolMessages = AgentAssertions::ofType($result['messages'], ToolMessage::class);
        self::assertGreaterThan(0, \count($toolMessages));
        self::assertStringContainsString('Mocked response', $toolMessages[0]->content);
    }

    public function testShouldAllowNonEmulatedToolsToExecuteNormally(): void
    {
        $model = self::fake([
            new AIMessage(['content' => '', 'tool_calls' => [
                ['id' => '1', 'name' => 'search', 'args' => ['query' => 'test']],
                ['id' => '2', 'name' => 'calculator', 'args' => ['expression' => '1+1']],
            ]]),
            new AIMessage('Final response'),
        ]);
        $middleware = ToolEmulatorMiddleware::create(['tools' => ['search'], 'model' => self::fake([new AIMessage('Mocked response')])]);
        $agent = Agent::create(['model' => $model, 'tools' => [$this->searchTool(), $this->calculatorTool()], 'middleware' => [$middleware]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Do calculations')]]);

        self::assertSame(0, $this->realCalls['search'], 'search is emulated');
        self::assertSame(1, $this->realCalls['calculator'], 'calculator runs normally');
        self::assertSame('Result: 1+1', $result['messages'][\count($result['messages']) - 2]->content);
    }

    public function testShouldEmulateAllToolsWhenNoToolsSpecified(): void
    {
        $model = self::fake([
            new AIMessage(['content' => '', 'tool_calls' => [
                ['id' => '1', 'name' => 'search', 'args' => ['query' => 'test']],
                ['id' => '2', 'name' => 'calculator', 'args' => ['expression' => '1+1']],
            ]]),
            new AIMessage('Final response'),
        ]);
        $middleware = ToolEmulatorMiddleware::create(['model' => self::fake([new AIMessage('Mocked response')])]);
        $agent = Agent::create(['model' => $model, 'tools' => [$this->searchTool(), $this->calculatorTool()], 'middleware' => [$middleware]]);

        $agent->invoke(['messages' => [new HumanMessage('Do things')]]);

        self::assertSame(0, $this->realCalls['search']);
        self::assertSame(0, $this->realCalls['calculator']);
    }

    public function testShouldUseAgentModelWhenNoModelIsProvidedToMiddleware(): void
    {
        $emulated = 'Agent model emulated response';
        $model = self::fake([
            // The agent decides to call the tool.
            new AIMessage(['content' => '', 'tool_calls' => [['id' => '1', 'name' => 'search', 'args' => ['query' => 'test']]]]),
            // The agent model is used for the emulation.
            new AIMessage($emulated),
            // The agent's final response after it receives the tool result.
            new AIMessage('Final response'),
        ]);
        $agent = Agent::create(['model' => $model, 'tools' => [$this->searchTool()], 'middleware' => [ToolEmulatorMiddleware::create(['tools' => ['search']])]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Search for something')]]);

        self::assertSame(0, $this->realCalls['search']);
        $toolMessages = AgentAssertions::ofType($result['messages'], ToolMessage::class);
        self::assertCount(1, $toolMessages);
        self::assertStringContainsString($emulated, $toolMessages[0]->content);
        self::assertStringContainsString('Final response', $result['messages'][array_key_last($result['messages'])]->content);
    }

    public function testShouldPromptTheEmulatorWithTheToolsNameDescriptionAndArguments(): void
    {
        $emulator = new FlakyChatModel(['responses' => [new AIMessage('ok')]]);
        $middleware = ToolEmulatorMiddleware::create(['model' => $emulator]);

        $middleware['wrapToolCall'](self::toolRequest('1', 'search', ['query' => 'test'], $this->searchTool()), static fn (): never => throw new \LogicException('not called'));

        $prompt = $emulator->invokeCalls()[0][0]->content ?? null;
        self::assertSame(
            "You are emulating a tool call for testing purposes.\n\nTool: search\nDescription: Search for information\nArguments: {\"query\":\"test\"}\n\n"
            . "Generate a realistic response that this tool would return given these arguments.\nReturn ONLY the tool's output, no explanation or preamble. Introduce variation into your responses.",
            $prompt,
        );
    }

    // ---- Beyond the upstream cases: a model string -----------------------------------------------

    public function testAModelStringIsResolvedLazilyOnceWithTemperatureOne(): void
    {
        $server = LocalProviderServer::start(['/api/chat' => LocalProviderServer::ollamaReply('From the string model')]);
        putenv('OLLAMA_BASE_URL=' . $server->baseUrl);
        try {
            $agentModel = new FlakyChatModel(['responses' => [new AIMessage('Not used')]]);
            $middleware = ToolEmulatorMiddleware::create(['model' => 'ollama:llama3']);
            self::assertSame([], $server->requests(), 'creating the middleware resolves nothing');
            $middleware['wrapModelCall'](['model' => $agentModel], static fn (array $request): AIMessage => new AIMessage('ok'));

            $first = $middleware['wrapToolCall'](self::toolRequest('1', 'search', ['query' => 'a'], $this->searchTool()), static fn (): never => throw new \LogicException('not called'));
            $second = $middleware['wrapToolCall'](self::toolRequest('2', 'search', ['query' => 'b'], $this->searchTool()), static fn (): never => throw new \LogicException('not called'));

            self::assertSame('From the string model', $first->content);
            self::assertSame('From the string model', $second->content);
            self::assertCount(2, $server->requests());
            $sent = $server->body(0);
            self::assertSame('llama3', $sent['model'], 'the string resolved to the ollama model it names');
            self::assertEquals(1, $sent['options']['temperature'], 'initChatModel(model, { temperature: 1 })');
            self::assertStringContainsString('Tool: search', $sent['messages'][0]['content']);
            self::assertSame([], $agentModel->invokeCalls(), 'the agent model did none of the emulating');
        } finally {
            putenv('OLLAMA_BASE_URL');
            $server->stop();
        }
    }

    public function testAModelStringThatCannotBeInitializedFallsBackToTheAgentModel(): void
    {
        ini_set('error_log', sys_get_temp_dir() . '/tool-emulator-' . getmypid() . '.log');
        $model = self::fake([
            new AIMessage(['content' => '', 'tool_calls' => [['id' => '1', 'name' => 'search', 'args' => ['query' => 'test']]]]),
            new AIMessage('Emulated by the agent model'),
            new AIMessage('Final response'),
        ]);
        $agent = Agent::create(['model' => $model, 'tools' => [$this->searchTool()], 'middleware' => [ToolEmulatorMiddleware::create(['model' => 'bad:model'])]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Search for something')]]);

        $toolMessages = AgentAssertions::ofType($result['messages'], ToolMessage::class);
        self::assertSame('Emulated by the agent model', $toolMessages[0]->content);
        self::assertSame(0, $this->realCalls['search']);
    }

    // ---- Internal call suppression -------------------------------------------------------------

    public function testOmitsTheEmulationCallFromRunMessages(): void
    {
        self::markTestSkipped('streamEvents version "v3" (run.messages) has no PHP target: there is no stream transformer protocol in this port.');
    }

    public function testOmitsTheEmulationCallFromStreamModeMessages(): void
    {
        $emulated = 'EMULATED_TOOL_RESULT';
        $main = 'Main model answer.';
        $agent = Agent::create([
            'model' => self::fake([
                new AIMessage(['content' => 'Calling the tool.', 'tool_calls' => [['id' => 'call_1', 'name' => 'search', 'args' => ['query' => 'cats']]]]),
                new AIMessage($main),
            ]),
            'tools' => [$this->searchTool()],
            'middleware' => [ToolEmulatorMiddleware::create(['model' => self::fake([new AIMessage($emulated)])])],
        ]);
        $agent->graph->streamMode = ['messages'];

        $modelTexts = [];
        $toolTexts = [];
        foreach ($agent->stream(['messages' => [new HumanMessage('search for cats')]]) as [$mode, [$message, $metadata]]) {
            self::assertSame('messages', $mode);
            if ($metadata['langgraph_node'] === 'tools') {
                $toolTexts[] = $message->text();
            } else {
                $modelTexts[] = $message->text();
            }
        }

        // The emulating model call is internal: it never reaches the messages stream as model output...
        self::assertNotContains($emulated, $modelTexts);
        self::assertContains($main, $modelTexts);
        // ...but its result still reaches the caller as the tool's output.
        self::assertSame([$emulated], $toolTexts, 'only the ToolMessage carries the emulated text, not a streamed emulator chunk');
    }
}
