<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Middleware;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\HumanMessage;
use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use LangChain\Tests\Unit\Agents\Support\LocalProviderServer;
use LangChain\Tests\Unit\Prebuilt\ReactAgentFixtures;
use LangChain\Tests\Unit\Prebuilt\SpyingToolCallingChatModel;
use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware\Constants;
use LangGraph\Agents\Middleware\LlmToolSelectorMiddleware;
use LangGraph\Agents\ReactAgent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * `langchain/src/agents/middleware/tests/llmToolSelector.test.ts`.
 *
 * Upstream's mock model is a spy whose `withStructuredOutput` returns the model itself, so the selection
 * call and the agent call both land on one `invoke` mock. Here the model is a `SpyingToolCallingChatModel`
 * whose structured-output runnable is replaced by a lambda that records what the selection call was given
 * (messages and config) and answers from a queue.
 *
 * The second streaming case runs on `streamEvents(version: "v3")` upstream (`run.messages`), which is not
 * ported; it is converted to the same check on the state `invoke()` returns.
 */
#[CoversClass(LlmToolSelectorMiddleware::class)]
final class LlmToolSelectorMiddlewareTest extends TestCase
{
    /** @var list<StructuredTool> */
    private array $allTools;

    protected function setUp(): void
    {
        $this->allTools = array_map(
            static fn (string $letter): StructuredTool => tool(
                static fn (array $in): string => "Tool {$letter} response",
                ['name' => "tool{$letter}", 'description' => "Tool {$letter} for testing", 'schema' => Schema::object(['prop' => ['type' => 'string']])],
            ),
            ['A', 'B', 'C', 'D', 'E'],
        );
    }

    /**
     * Make the model's structured output a recording runnable that answers with the queued selections.
     *
     * @param list<list<mixed>|mixed>                                                      $selections
     * @param array<int, array{0: mixed, 1: RunnableConfig|null}>                          $calls
     */
    private static function selectorModel(array $selections, array &$calls, ?SpyingToolCallingChatModel $model = null): SpyingToolCallingChatModel
    {
        $model ??= ReactAgentFixtures::spy([new AIMessage('Response from model')]);
        $model->structuredOutputOverride['runnable'] = RunnableLambda::from(
            static function (mixed $input, ?RunnableConfig $config = null) use (&$calls, &$selections): mixed {
                $calls[] = [$input, $config];

                return array_shift($selections);
            },
        );

        return $model;
    }

    /** @return list<string> */
    private static function names(array $tools): array
    {
        return array_map(static fn (mixed $tool): string => $tool->name, $tools);
    }

    public function testShouldReturnRequestUnchangedWhenNoToolsAreAvailable(): void
    {
        $calls = [];
        $model = self::selectorModel([], $calls);
        $agent = Agent::create(['model' => $model, 'tools' => [], 'middleware' => [LlmToolSelectorMiddleware::create(['maxTools' => 3])]]);

        $agent->invoke(['messages' => [new HumanMessage('Test message')]]);

        // Never calls withStructuredOutput since no tools are available.
        self::assertSame([], $model->structuredOutputCalls);
        self::assertSame([], $calls);
    }

    public function testShouldSuccessfullySelectValidToolsWithinLimit(): void
    {
        $calls = [];
        $model = self::selectorModel([['tools' => ['toolA', 'toolB']]], $calls);
        $agent = Agent::create(['model' => $model, 'tools' => $this->allTools, 'middleware' => [LlmToolSelectorMiddleware::create(['maxTools' => 2])]]);

        $agent->invoke(['messages' => [new HumanMessage('Test message')]]);

        self::assertCount(1, $model->bindToolsCalls);
        self::assertSame(['toolA', 'toolB'], self::names($model->bindToolsCalls[0]));
    }

    public function testShouldLimitToolsToMaxToolsWhenMoreAreSelected(): void
    {
        $calls = [];
        $model = self::selectorModel([['tools' => ['toolA', 'toolB', 'toolC', 'toolD', 'toolE']]], $calls);
        $agent = Agent::create(['model' => $model, 'tools' => $this->allTools, 'middleware' => [LlmToolSelectorMiddleware::create(['maxTools' => 2])]]);

        $agent->invoke(['messages' => [new HumanMessage('Test message')]]);

        // Only the first 2 tools are used.
        self::assertSame(['toolA', 'toolB'], self::names($model->bindToolsCalls[0]));
    }

    public function testShouldThrowErrorWhenInvalidToolsAreSelected(): void
    {
        $calls = [];
        $model = self::selectorModel([['tools' => ['toolA', 'invalidTool', 'toolB']]], $calls);
        $agent = Agent::create(['model' => $model, 'tools' => $this->allTools, 'middleware' => [LlmToolSelectorMiddleware::create(['maxTools' => 2])]]);

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('Model selected invalid tools: invalidTool');

        $agent->invoke(['messages' => [new HumanMessage('Test message')]]);
    }

    public function testShouldUseCustomSystemPrompt(): void
    {
        $calls = [];
        $model = self::selectorModel([['tools' => ['toolA', 'toolB']]], $calls);
        $agent = Agent::create(['model' => $model, 'tools' => $this->allTools, 'middleware' => [LlmToolSelectorMiddleware::create(['maxTools' => 2, 'systemPrompt' => 'Custom system prompt'])]]);

        $agent->invoke(['messages' => [new HumanMessage('Test message')]]);

        $firstCall = $calls[0][0];
        self::assertCount(2, $firstCall);
        self::assertStringContainsString('Custom system prompt', $firstCall[0]->content);
        self::assertStringContainsString('Test message', $firstCall[1]->content);
    }

    public function testShouldIncludeMaxToolsInstructionsInSystemPromptWhenConfigured(): void
    {
        $calls = [];
        $model = self::selectorModel([['tools' => ['toolA', 'toolB']]], $calls);
        $agent = Agent::create(['model' => $model, 'tools' => $this->allTools, 'middleware' => [LlmToolSelectorMiddleware::create(['maxTools' => 3])]]);

        $agent->invoke(['messages' => [new HumanMessage('Test message')]]);

        $firstCall = $calls[0][0];
        self::assertCount(2, $firstCall);
        self::assertStringContainsString('IMPORTANT: List the tool names in order of relevance', $firstCall[0]->content);
        self::assertStringContainsString('only the first 3 will be used', $firstCall[0]->content);
    }

    public function testShouldUseProvidedModelInsteadOfRequestModel(): void
    {
        $calls = [];
        $middlewareModel = self::selectorModel([['tools' => ['toolA', 'toolB']]], $calls);
        $model = ReactAgentFixtures::spy([new AIMessage('Response from model')]);
        $agent = Agent::create([
            'model' => $model,
            'tools' => $this->allTools,
            'middleware' => [LlmToolSelectorMiddleware::create(['maxTools' => 2, 'model' => $middlewareModel])],
        ]);

        $agent->invoke(['messages' => [new HumanMessage('Test message')]]);

        // The agent model answered once and never selected; the middleware model selected once.
        self::assertCount(1, $model->generateCalls);
        self::assertSame([], $model->structuredOutputCalls);
        self::assertCount(1, $middlewareModel->structuredOutputCalls);
        self::assertCount(1, $calls);
    }

    public function testShouldAlwaysIncludeSpecifiedTools(): void
    {
        $calls = [];
        $model = self::selectorModel([['tools' => ['toolA', 'toolB']]], $calls);
        $agent = Agent::create(['model' => $model, 'tools' => $this->allTools, 'middleware' => [LlmToolSelectorMiddleware::create(['maxTools' => 2, 'alwaysInclude' => ['toolE']])]]);

        $agent->invoke(['messages' => [new HumanMessage('Test message')]]);

        // toolA, toolB (selected) + toolE (always included).
        $names = self::names($model->bindToolsCalls[0]);
        self::assertCount(3, $names);
        self::assertContains('toolE', $names);
        self::assertContains('toolA', $names);
        self::assertContains('toolB', $names);
    }

    public function testShouldNotCountAlwaysIncludeToolsAgainstMaxToolsLimit(): void
    {
        $calls = [];
        $model = self::selectorModel([['tools' => ['toolA', 'toolB']]], $calls);
        $agent = Agent::create(['model' => $model, 'tools' => $this->allTools, 'middleware' => [LlmToolSelectorMiddleware::create(['maxTools' => 2, 'alwaysInclude' => ['toolD', 'toolE']])]]);

        $agent->invoke(['messages' => [new HumanMessage('Test message')]]);

        // 4 tools in total: 2 selected + 2 always included.
        $names = self::names($model->bindToolsCalls[0]);
        self::assertCount(4, $names);
        foreach (['toolA', 'toolB', 'toolD', 'toolE'] as $expected) {
            self::assertContains($expected, $names);
        }
    }

    public function testShouldThrowErrorWhenAlwaysIncludeToolNotFound(): void
    {
        $calls = [];
        $model = self::selectorModel([], $calls);
        $agent = Agent::create(['model' => $model, 'tools' => $this->allTools, 'middleware' => [LlmToolSelectorMiddleware::create(['maxTools' => 2, 'alwaysInclude' => ['nonexistentTool']])]]);

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('Tools in alwaysInclude not found in request: nonexistentTool. Available tools: toolA, toolB, toolC, toolD, toolE');

        $agent->invoke(['messages' => [new HumanMessage('Test message')]]);
    }

    public function testShouldReturnRequestUnchangedWhenOnlyAlwaysIncludeToolsAreAvailable(): void
    {
        $calls = [];
        $model = self::selectorModel([], $calls);
        $agent = Agent::create([
            'model' => $model,
            'tools' => $this->allTools,
            'middleware' => [LlmToolSelectorMiddleware::create(['maxTools' => 2, 'alwaysInclude' => ['toolA', 'toolB', 'toolC', 'toolD', 'toolE']])],
        ]);

        $agent->invoke(['messages' => [new HumanMessage('Test message')]]);

        // No withStructuredOutput call, since nothing is available for selection.
        self::assertSame([], $model->structuredOutputCalls);
        // All the tools are bound, since they are all in alwaysInclude.
        self::assertCount(5, $model->bindToolsCalls[0]);
    }

    public function testShouldGetLastUserMessageFromHistory(): void
    {
        $calls = [];
        $model = self::selectorModel([['tools' => ['toolA', 'toolB']]], $calls);
        $agent = Agent::create(['model' => $model, 'tools' => $this->allTools, 'middleware' => [LlmToolSelectorMiddleware::create(['maxTools' => 2])]]);

        $agent->invoke(['messages' => [new HumanMessage('First message'), new AIMessage('AI response'), new HumanMessage('Second message')]]);

        $firstCall = $calls[0][0];
        self::assertCount(2, $firstCall);
        // The last (most recent) user message is the one used.
        self::assertStringContainsString('Second message', $firstCall[1]->content);
    }

    public function testTheSelectionCallIsTaggedAsInternal(): void
    {
        $calls = [];
        $model = self::selectorModel([['tools' => ['toolA']]], $calls);
        $agent = Agent::create(['model' => $model, 'tools' => $this->allTools, 'middleware' => [LlmToolSelectorMiddleware::create(['maxTools' => 1])]]);

        $agent->invoke(['messages' => [new HumanMessage('Test message')]]);

        $config = $calls[0][1];
        self::assertInstanceOf(RunnableConfig::class, $config);
        self::assertContains(Constants::INTERNAL_CALL_TAG, $config->tags);
        self::assertSame('llmToolSelector', $config->metadata['lc_source']);
    }

    public function testTheSelectorOffersTheToolNamesAsAnEnumAndKeepsProviderToolsOut(): void
    {
        $calls = [];
        $model = self::selectorModel([['tools' => ['toolB']]], $calls);
        $provider = ['type' => 'web_search_preview'];
        $middleware = LlmToolSelectorMiddleware::create(['maxTools' => 1, 'alwaysInclude' => ['toolC']]);

        $middleware['wrapModelCall'](
            [
                'model' => $model,
                'messages' => [new HumanMessage('hi')],
                'tools' => [$this->allTools[0], $this->allTools[1], $this->allTools[2], $provider],
                'runtime' => null,
            ],
            static function (array $request) use (&$handled): AIMessage {
                $handled = $request;

                return new AIMessage('ok');
            },
        );

        $schema = $model->structuredOutputCalls[0][0];
        self::assertSame(['toolA', 'toolB'], $schema['properties']['tools']['items']['enum']);
        // The selected tool, then the always-included one, then the provider definition.
        self::assertSame(['toolB', 'toolC'], self::names(\array_slice($handled['tools'], 0, 2)));
        self::assertSame($provider, $handled['tools'][2]);
    }

    public function testAMalformedSelectionResponseIsAnError(): void
    {
        $calls = [];
        $model = self::selectorModel(['not an object'], $calls);
        $middleware = LlmToolSelectorMiddleware::create();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Expected object response with tools array, got string');

        $middleware['wrapModelCall'](
            ['model' => $model, 'messages' => [new HumanMessage('hi')], 'tools' => $this->allTools, 'runtime' => null],
            static fn (array $request): AIMessage => new AIMessage('ok'),
        );
    }

    public function testAStringModelIsResolvedThroughInitChatModelAndMakesTheSelection(): void
    {
        $server = LocalProviderServer::start(['/api/chat' => LocalProviderServer::ollamaReply('{"tools":["toolB"]}')]);
        putenv('OLLAMA_BASE_URL=' . $server->baseUrl);
        try {
            $agentModel = ReactAgentFixtures::spy([new AIMessage('Response from model')]);
            $middleware = LlmToolSelectorMiddleware::create(['model' => 'ollama:llama3', 'maxTools' => 1]);
            $seen = null;

            $middleware['wrapModelCall'](
                ['model' => $agentModel, 'messages' => [new HumanMessage('Use tool B')], 'tools' => $this->allTools, 'runtime' => null],
                static function (array $request) use (&$seen): AIMessage {
                    $seen = $request;

                    return new AIMessage('ok');
                },
            );

            self::assertSame(['toolB'], self::names($seen['tools']), 'the string model chose the tools');
            self::assertSame([], $agentModel->structuredOutputCalls, 'the agent model did not make the selection');
            self::assertCount(1, $server->requests());
            $sent = $server->body(0);
            self::assertSame('llama3', $sent['model']);
            self::assertStringContainsString('toolA', json_encode($sent), 'the selector offered the tool names to the model');
        } finally {
            putenv('OLLAMA_BASE_URL');
            $server->stop();
        }
    }

    public function testAStringModelTheRegistryCannotResolveSurfacesTheInitChatModelError(): void
    {
        $middleware = LlmToolSelectorMiddleware::create(['model' => 'nosuchprovider:model']);
        $handler = static fn (array $request): AIMessage => new AIMessage('ok');
        $request = ['model' => null, 'messages' => [new HumanMessage('hi')], 'tools' => $this->allTools, 'runtime' => null];

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to infer model provider for { model: nosuchprovider:model }');

        $middleware['wrapModelCall']($request, $handler);
    }

    // --- streaming isolation

    /**
     * A scripted chat model that also supports `bindTools()` and a JSON `withStructuredOutput()` (upstream's
     * `FakeListChatModel` does both; the port's does not bind tools).
     *
     * @param list<string> $responses
     */
    private static function listModel(array $responses): BaseChatModel
    {
        return new class($responses) extends BaseChatModel {
            private int $i = 0;

            /** @param list<string> $responses */
            public function __construct(private array $responses)
            {
                parent::__construct([]);
            }

            public function llmType(): string
            {
                return 'list-with-tools';
            }

            private function next(): string
            {
                $response = $this->responses[$this->i % \count($this->responses)];
                ++$this->i;

                return $response;
            }

            protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
            {
                $text = $this->next();

                return new ChatResult([new ChatGeneration(new AIMessage($text), $text)]);
            }

            protected function streamResponseChunks(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): \Generator
            {
                foreach (mb_str_split($this->next()) as $char) {
                    yield new ChatGenerationChunk(new AIMessageChunk($char), $char);
                    $runManager?->handleLLMNewToken($char);
                }
            }

            public function bindTools(array $tools, array $kwargs = []): static
            {
                return $this;
            }

            public function withStructuredOutput(array $schema, array $config = []): Runnable
            {
                return $this->pipe(RunnableLambda::from(static fn (AIMessage $message): mixed => json_decode((string) $message->content, true)));
            }
        };
    }

    /** @return list<StructuredTool> */
    private static function weatherTools(): array
    {
        return [
            tool(static fn (array $in): string => 'Weather in ' . $in['location'] . ': Sunny, 72°F', ['name' => 'get_weather', 'description' => 'Get current weather for a location', 'schema' => Schema::object(['location' => ['type' => 'string']], ['location'])]),
            tool(static fn (array $in): string => 'Customer ' . $in['customerId'] . ': Premium account', ['name' => 'search_database', 'description' => 'Look up customer information by customer ID', 'schema' => Schema::object(['customerId' => ['type' => 'string']], ['customerId'])]),
            tool(static fn (array $in): string => 'Total: $0', ['name' => 'calculate_price', 'description' => 'Calculate pricing with discounts', 'schema' => Schema::object(['items' => ['type' => 'number'], 'discount' => ['type' => 'number']], ['items', 'discount'])]),
        ];
    }

    /**
     * Stream an agent in the `messages` mode. `Agent::create` has no stream mode option and its compiled graph
     * streams `updates` and `values`, so the mode is set on the compiled graph itself.
     *
     * @param array<string, mixed> $input
     * @return \Generator<int, array{0: string, 1: mixed}>
     */
    private static function streamMessages(ReactAgent $agent, array $input): \Generator
    {
        $property = new \ReflectionProperty($agent, 'compiled');
        $compiled = $property->getValue($agent);
        $compiled->streamMode = ['messages'];

        return $compiled->stream($input);
    }

    public function testDoesNotLeakToolSelectorOutputIntoMessagesStream(): void
    {
        $selectorModel = self::listModel([json_encode(['tools' => ['get_weather']])]);
        $agentModel = self::listModel(['The weather in Seoul is sunny and 72°F.']);
        $agent = Agent::create([
            'model' => $agentModel,
            'tools' => self::weatherTools(),
            'middleware' => [LlmToolSelectorMiddleware::create(['model' => $selectorModel, 'maxTools' => 1])],
        ]);

        $parts = [];
        $streamed = '';
        foreach (self::streamMessages($agent, ['messages' => [new HumanMessage("What's the weather in Seoul?")]]) as $chunk) {
            $parts[] = json_encode($chunk);
            [, $payload] = $chunk;
            if (\is_array($payload) && ($payload[0] ?? null) instanceof AIMessageChunk && \is_string($payload[0]->content)) {
                $streamed .= $payload[0]->content;
            }
        }
        $serialized = implode('', $parts);

        self::assertNotSame('', $serialized);
        self::assertStringNotContainsString('{\"tools', $serialized);
        self::assertStringNotContainsString('"content":"tools', $serialized);
        self::assertDoesNotMatchRegularExpression('/"content":"[^"]*tools[^"]*","tool_call_chunks":\[\]/', $serialized);
        // Only the agent's own answer was streamed.
        self::assertSame('The weather in Seoul is sunny and 72°F.', $streamed);
    }

    public function testDoesNotLeakToolSelectorOutputIntoTheRunMessages(): void
    {
        $main = 'The weather in Seoul is sunny and 72°F.';
        $agent = Agent::create([
            'model' => self::listModel([$main]),
            'tools' => self::weatherTools(),
            'middleware' => [LlmToolSelectorMiddleware::create(['model' => self::listModel([json_encode(['tools' => ['get_weather']])]), 'maxTools' => 1])],
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage("What's the weather in Seoul?")]]);

        $texts = array_map(static fn (object $m): string => $m->text(), $result['messages']);
        foreach ($texts as $text) {
            self::assertStringNotContainsString('tools', $text);
        }
        self::assertContains($main, $texts);
    }
}
