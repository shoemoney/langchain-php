<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Middleware;

use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangChain\Tests\Unit\Agents\Support\LocalProviderServer;
use LangChain\Tracers\StreamEvent;
use LangChain\Utils\Testing\FakeToolCallingChatModel;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware\Constants;
use LangGraph\Agents\Middleware\SummarizationMiddleware;
use LangGraph\Agents\Middleware\Utils;
use LangGraph\Agents\Runtime;
use LangGraph\Agents\Utils as AgentUtils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `langchain/src/agents/middleware/tests/summarization.test.ts`.
 *
 * The summarization model is a hand-rolled mock recording its `invoke()` calls, as upstream's `vi.fn` is.
 *
 * Not converted: `can be created using a model string` (needs `initChatModel` and a mocked provider, WP-20)
 * and the two `run.messages` cases of `internal call suppression` (they read the `streamEvents` v3 protocol,
 * which this port does not have).
 */
#[CoversClass(SummarizationMiddleware::class)]
final class SummarizationMiddlewareTest extends TestCase
{
    /** @var list<string> */
    private array $deprecations = [];

    private bool $capturing = false;

    /** Collect the E_USER_DEPRECATED notices the deprecated options raise (upstream: `console.warn`). */
    private function captureDeprecations(): void
    {
        $this->capturing = true;
        set_error_handler(function (int $level, string $message): bool {
            $this->deprecations[] = $message;

            return true;
        }, \E_USER_DEPRECATED);
    }

    protected function tearDown(): void
    {
        if ($this->capturing) {
            restore_error_handler();
            $this->capturing = false;
        }
        $this->deprecations = [];
    }

    /**
     * The `createMockSummarizationModel` of the test: answers a fixed summary and records every call.
     *
     * @return object{calls: list<array{0: string, 1: mixed}>, profile?: array<string, mixed>, model?: string}
     */
    private static function mockSummarizer(?\Closure $answer = null): object
    {
        return new class ($answer) {
            /** @var list<array{0: string, 1: mixed}> */
            public array $calls = [];

            public mixed $profile = null;

            public mixed $model = null;

            public function __construct(private readonly ?\Closure $answer)
            {
            }

            public function invoke(string $prompt, mixed $config = null): array
            {
                $this->calls[] = [$prompt, $config];
                if ($this->answer !== null) {
                    return ($this->answer)($prompt);
                }

                if (str_contains($prompt, 'Context Extraction Assistant')) {
                    return ['content' => 'Previous conversation covered: project architecture discussion, challenges with scalability, and recommendations for improvement. Key decisions: use microservices, implement caching, optimize database queries.'];
                }

                return ['content' => 'Summary of previous conversation.'];
            }
        };
    }

    private static function mainModel(): FakeToolCallingChatModel
    {
        return AgentAssertions::fakeChat([
            new AIMessage('I understand your project. Let me analyze the architecture.'),
            new AIMessage([
                'content' => "I'll check the weather for you.",
                'tool_calls' => [['id' => 'call_1', 'name' => 'get_weather', 'args' => ['location' => 'NYC']]],
            ]),
            new AIMessage("Based on the weather data, it's sunny in NYC."),
            new AIMessage("Here's my recommendation based on everything we discussed."),
        ]);
    }

    private static function singleResponseModel(string $response = 'Response'): FakeToolCallingChatModel
    {
        return AgentAssertions::fakeChat([new AIMessage($response)]);
    }

    /** @param array<string, mixed> $options */
    private static function agent(object $model, array $options): \LangGraph\Agents\ReactAgent
    {
        return Agent::create(['model' => $model, 'middleware' => [SummarizationMiddleware::create($options)]]);
    }

    /** @return list<BaseMessage> */
    private static function longConversation(): array
    {
        $x = str_repeat('x', 200);

        return [
            new HumanMessage("I'm working on a complex software project. {$x}"),
            new AIMessage("I understand your project. Let me help. {$x}"),
            new HumanMessage("Here are more details about the architecture. {$x}"),
            new AIMessage("That's interesting. Tell me more. {$x}"),
            new HumanMessage("More information here. {$x}"),
            new AIMessage("Got it. {$x}"),
            new HumanMessage('What do you recommend?'),
        ];
    }

    /** @param list<BaseMessage> $messages */
    private static function runBeforeModel(array $middleware, array $messages, mixed $runtime): ?array
    {
        return Utils::getHookFunction($middleware['beforeModel'])(['messages' => $messages], $runtime);
    }

    private static function content(BaseMessage $message): string
    {
        return \is_string($message->content) ? $message->content : (string) json_encode($message->content);
    }

    private static function assertIsSummary(BaseMessage $message, string $prefix = 'Here is a summary of the conversation to date'): void
    {
        self::assertInstanceOf(HumanMessage::class, $message);
        self::assertStringContainsString($prefix, self::content($message));
        self::assertSame(['lc_source' => 'summarization'], $message->additional_kwargs);
    }

    // ---- summary call config -----------------------------------------------------------------------

    public function testShouldTagTheSummarizationModelInvocationWithLcSourceMetadata(): void
    {
        $summarizer = self::mockSummarizer();
        $middleware = SummarizationMiddleware::create(['model' => $summarizer, 'trigger' => ['tokens' => 50], 'keep' => ['messages' => 2]]);

        self::runBeforeModel($middleware, self::longConversation(), new Runtime(context: []));

        self::assertCount(1, $summarizer->calls);
        $config = $summarizer->calls[0][1];
        self::assertInstanceOf(RunnableConfig::class, $config);
        self::assertSame('summarization', $config->metadata['lc_source']);
        self::assertContains(Constants::INTERNAL_CALL_TAG, $config->tags);
    }

    public function testShouldMergeLcSourceMetadataWithParentRunnableConfigFromRuntime(): void
    {
        $summarizer = self::mockSummarizer();
        $middleware = SummarizationMiddleware::create(['model' => $summarizer, 'trigger' => ['tokens' => 50], 'keep' => ['messages' => 2]]);

        // Metadata and tags ride on the runtime object
        $runtime = (object) ['context' => [], 'metadata' => ['test_parent' => 'metadata'], 'tags' => ['test_parent_tag']];

        self::runBeforeModel($middleware, self::longConversation(), $runtime);

        self::assertCount(1, $summarizer->calls);
        $config = $summarizer->calls[0][1];
        self::assertSame('metadata', $config->metadata['test_parent']);
        self::assertSame('summarization', $config->metadata['lc_source']);
        self::assertContains('test_parent_tag', $config->tags);
    }

    // ---- triggers ---------------------------------------------------------------------------------

    public function testShouldTriggerSummarizationWhenTokenCountExceedsThreshold(): void
    {
        $summarizer = self::mockSummarizer();
        $agent = self::agent(self::mainModel(), ['model' => $summarizer, 'trigger' => ['tokens' => 50], 'keep' => ['messages' => 2]]);

        $result = $agent->invoke(['messages' => self::longConversation()]);

        self::assertNotSame([], $summarizer->calls);
        self::assertIsSummary($result['messages'][0]);
        self::assertStringContainsString('Previous conversation covered:', self::content($result['messages'][0]));
        // Only the recent messages are kept: summary + kept messages + the new response
        self::assertLessThanOrEqual(4, \count($result['messages']));
    }

    public function testShouldTriggerSummarizationWhenTokenCountExceedsThresholdDeprecatedSyntax(): void
    {
        $this->captureDeprecations();

        $summarizer = self::mockSummarizer();
        $agent = self::agent(self::mainModel(), ['model' => $summarizer, 'maxTokensBeforeSummary' => 50, 'messagesToKeep' => 2]);

        $result = $agent->invoke(['messages' => self::longConversation()]);

        self::assertNotSame([], $summarizer->calls);
        self::assertIsSummary($result['messages'][0]);
        self::assertLessThanOrEqual(4, \count($result['messages']));

        self::assertSame(['maxTokensBeforeSummary is deprecated. Use `trigger: { tokens: value }` instead.', 'messagesToKeep is deprecated. Use `keep: { messages: value }` instead.'], $this->deprecations);
    }

    public function testShouldNotTriggerSummarizationWhenBelowTokenThreshold(): void
    {
        $summarizer = self::mockSummarizer();
        $agent = self::agent(self::mainModel(), ['model' => $summarizer, 'trigger' => ['tokens' => 5000], 'keep' => ['messages' => 10]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Hello'), new AIMessage('Hi there!'), new HumanMessage('How are you?')]]);

        self::assertSame([], $summarizer->calls);
        // 3 original + 1 new response
        self::assertCount(4, $result['messages']);
    }

    public function testShouldTriggerSummarizationWithMultipleTriggerConditionsOrLogic(): void
    {
        $summarizer = self::mockSummarizer();
        $agent = self::agent(self::mainModel(), [
            'model' => $summarizer,
            // The first condition is met (5 messages >= 3); the second is not (needs 3000 tokens).
            'trigger' => [['messages' => 3], ['tokens' => 3000, 'messages' => 6]],
            'keep' => ['messages' => 2],
        ]);

        $result = $agent->invoke(['messages' => [
            new HumanMessage('Message 1'),
            new AIMessage('Response 1'),
            new HumanMessage('Message 2'),
            new AIMessage('Response 2'),
            new HumanMessage('Message 3'),
        ]]);

        self::assertNotSame([], $summarizer->calls);
        self::assertIsSummary($result['messages'][0]);
    }

    // ---- cutoffs ----------------------------------------------------------------------------------

    public function testShouldPreserveAIToolMessagePairsTogether(): void
    {
        $this->captureDeprecations();

        $summarizer = self::mockSummarizer();
        $toolCallMessage = new AIMessage([
            'content' => 'Let me check the weather.',
            'tool_calls' => [['id' => 'call_123', 'name' => 'get_weather', 'args' => ['location' => 'Paris']]],
        ]);
        $model = AgentAssertions::fakeChat([new AIMessage('Based on the weather, I recommend taking an umbrella.')]);
        $x = str_repeat('x', 100);

        $agent = self::agent($model, ['model' => $summarizer, 'maxTokensBeforeSummary' => 400, 'messagesToKeep' => 4]);

        $result = $agent->invoke(['messages' => [
            new HumanMessage("Old conversation part 1. {$x}"),
            new AIMessage("Old response 1. {$x}"),
            new HumanMessage("Old conversation part 2. {$x}"),
            new AIMessage("Old response 2. {$x}"),
            // This AI/Tool pair should be kept together
            $toolCallMessage,
            new ToolMessage(['content' => 'Weather in Paris: Rainy, 15°C', 'tool_call_id' => 'call_123']),
            new HumanMessage('Thanks for checking the weather!'),
        ]]);

        $hasToolCall = array_filter($result['messages'], static fn (BaseMessage $m): bool => $m instanceof AIMessage && $m->toolCalls !== []) !== [];
        $hasToolMessage = array_filter($result['messages'], static fn (BaseMessage $m): bool => $m instanceof ToolMessage && $m->toolCallId === 'call_123') !== [];

        // Both present or both absent: the pair is not split
        self::assertTrue($hasToolCall);
        self::assertTrue($hasToolMessage);

        self::assertSame(['maxTokensBeforeSummary is deprecated. Use `trigger: { tokens: value }` instead.', 'messagesToKeep is deprecated. Use `keep: { messages: value }` instead.'], $this->deprecations);
    }

    public function testShouldHandleTokenBasedKeepConfiguration(): void
    {
        $summarizer = self::mockSummarizer();
        $agent = self::agent(self::singleResponseModel(), ['model' => $summarizer, 'trigger' => ['tokens' => 50], 'keep' => ['tokens' => 50]]);
        $x = str_repeat('x', 100);

        $result = $agent->invoke(['messages' => [
            new HumanMessage("Message 1: {$x}"),
            new AIMessage("Response 1: {$x}"),
            new HumanMessage("Message 2: {$x}"),
            new AIMessage("Response 2: {$x}"),
            new HumanMessage("Message 3: {$x}"),
            new AIMessage("Response 3: {$x}"),
            new HumanMessage('Final question'),
        ]]);

        self::assertNotSame([], $summarizer->calls);
        self::assertIsSummary($result['messages'][0]);
        self::assertInstanceOf(AIMessage::class, $result['messages'][1]);
        self::assertStringContainsString("Response 3: {$x}", self::content($result['messages'][1]));
        self::assertInstanceOf(HumanMessage::class, $result['messages'][2]);
        self::assertStringContainsString('Final question', self::content($result['messages'][2]));
        self::assertInstanceOf(AIMessage::class, $result['messages'][3]);
        self::assertStringContainsString('Response', self::content($result['messages'][3]));
    }

    public function testShouldHandleFractionBasedTriggerAndKeepWithModelProfile(): void
    {
        $summarizer = self::mockSummarizer();
        $summarizer->profile = ['maxInputTokens' => 8192];
        $modelWithProfile = self::singleResponseModel();

        // Trigger at 10% of the context window (819 tokens); keep 5% (409 tokens)
        $agent = self::agent($modelWithProfile, ['model' => $summarizer, 'trigger' => ['fraction' => 0.1], 'keep' => ['fraction' => 0.05]]);

        $messages = [];
        for ($i = 0; $i < 10; ++$i) {
            $messages[] = new HumanMessage("Message {$i}: " . str_repeat('x', 200));
            $messages[] = new AIMessage("Response {$i}: " . str_repeat('x', 200));
        }

        $result = $agent->invoke(['messages' => $messages]);

        self::assertNotSame([], $summarizer->calls);
        self::assertInstanceOf(HumanMessage::class, $result['messages'][0]);
    }

    public function testShouldHandleFractionBasedTriggerAndKeepWithoutModelProfile(): void
    {
        $summarizer = self::mockSummarizer();
        // No profile: the context size comes from the model name (unknown names get 4097 tokens)
        $summarizer->model = 'claude-sonnet-4-20250514';

        $agent = self::agent(self::mainModel(), ['model' => $summarizer, 'trigger' => ['fraction' => 0.5], 'keep' => ['fraction' => 0.05]]);

        $messages = [];
        for ($i = 0; $i < 100; ++$i) {
            $messages[] = new HumanMessage("Message {$i}: " . str_repeat('x', 200));
            $messages[] = new AIMessage("Response {$i}: " . str_repeat('x', 200));
        }

        $result = $agent->invoke(['messages' => $messages]);

        self::assertNotSame([], $summarizer->calls);
        self::assertCount(5, $result['messages']);
        self::assertIsSummary($result['messages'][0]);
        self::assertInstanceOf(AIMessage::class, $result['messages'][1]);
        self::assertStringContainsString('Response 98: xxxxxxxxxx', self::content($result['messages'][1]));
        self::assertInstanceOf(HumanMessage::class, $result['messages'][2]);
        self::assertStringContainsString('Message 99: xxxxxxxxxxx', self::content($result['messages'][2]));
        self::assertInstanceOf(AIMessage::class, $result['messages'][3]);
        self::assertStringContainsString('Response 99: xxxxxxxxxxx', self::content($result['messages'][3]));
        self::assertInstanceOf(AIMessage::class, $result['messages'][4]);
        self::assertSame('I understand your project. Let me analyze the architecture.', $result['messages'][4]->content);
    }

    public function testShouldThrowErrorWhenFractionBasedConfigUsedWithoutModelProfile(): void
    {
        $summarizer = self::mockSummarizer();
        $agent = self::agent(self::mainModel(), ['model' => $summarizer, 'trigger' => ['fraction' => 0.5], 'keep' => ['messages' => 10]]);

        // The error is thrown during invocation, not creation
        $this->expectExceptionMessage('Model profile information is required');

        $agent->invoke(['messages' => [new HumanMessage('Test message')]]);
    }

    // ---- trimming ---------------------------------------------------------------------------------

    public function testShouldHandleTrimTokensToSummarizeParameter(): void
    {
        $summarizer = self::mockSummarizer();
        $agent = self::agent(self::mainModel(), [
            'model' => $summarizer,
            'trigger' => ['tokens' => 50],
            'keep' => ['messages' => 3],
            'trimTokensToSummarize' => 100, // Limit the tokens sent to the summarization model
        ]);
        $x = str_repeat('x', 200);

        $result = $agent->invoke(['messages' => [
            new HumanMessage("Message 1: {$x}"),
            new AIMessage("Response 1: {$x}"),
            new HumanMessage("Message 2: {$x}"),
            new AIMessage("Response 2: {$x}"),
            new HumanMessage("Message 3: {$x}"),
            new AIMessage("Response 3: {$x}"),
            new HumanMessage('Final question'),
        ]]);

        self::assertCount(1, $summarizer->calls);
        $summaryPrompt = $summarizer->calls[0][0];
        self::assertStringContainsString('Messages to summarize:', $summaryPrompt);
        // Uses the getBufferString format (Human:, AI:) instead of JSON
        self::assertStringNotContainsString('Human: Message 1: xxxxxxxxxxxxxxx', $summaryPrompt);
        self::assertStringContainsString('AI: Response 2: xxxxxxxxxxxxxxx', $summaryPrompt);
        self::assertStringNotContainsString('Human: Message 3: xxxxxxxxxxxxxxx', $summaryPrompt);

        self::assertCount(5, $result['messages']);
        self::assertIsSummary($result['messages'][0]);
        self::assertInstanceOf(HumanMessage::class, $result['messages'][1]);
        self::assertStringContainsString('Message 3: xxxxxxxxxx', self::content($result['messages'][1]));
        self::assertInstanceOf(AIMessage::class, $result['messages'][2]);
        self::assertStringContainsString('Response 3: xxxxxxxxxxx', self::content($result['messages'][2]));
        self::assertInstanceOf(HumanMessage::class, $result['messages'][3]);
        self::assertStringContainsString('Final question', self::content($result['messages'][3]));
        self::assertInstanceOf(AIMessage::class, $result['messages'][4]);
        self::assertSame('I understand your project. Let me analyze the architecture.', $result['messages'][4]->content);
    }

    public function testShouldHandleTrimTokensToSummarizeSetToUndefinedNoTrimming(): void
    {
        $summarizer = self::mockSummarizer();
        // trimTokensToSummarize is not specified: the default budget is far above this conversation
        $agent = self::agent(self::mainModel(), ['model' => $summarizer, 'trigger' => ['tokens' => 50], 'keep' => ['messages' => 2]]);
        $x = str_repeat('x', 200);

        $result = $agent->invoke(['messages' => [
            new HumanMessage("Message 1: {$x}"),
            new AIMessage("Response 1: {$x}"),
            new HumanMessage("Message 2: {$x}"),
            new AIMessage("Response 2: {$x}"),
            new HumanMessage("Message 3: {$x}"),
            new AIMessage("Response 3: {$x}"),
            new HumanMessage('Final question'),
        ]]);

        self::assertCount(1, $summarizer->calls);
        $summaryPrompt = $summarizer->calls[0][0];
        self::assertStringContainsString('Messages to summarize:', $summaryPrompt);
        self::assertStringContainsString('Human: Message 1: xxxxxxxxxxxxxxx', $summaryPrompt);
        self::assertStringContainsString('AI: Response 2: xxxxxxxxxxxxxxx', $summaryPrompt);
        self::assertStringContainsString('Human: Message 3: xxxxxxxxxxxxxxx', $summaryPrompt);
        self::assertStringNotContainsString('AI: Response 3: xxxxxxxxxxxxxxx', $summaryPrompt);

        self::assertCount(4, $result['messages']);
        self::assertIsSummary($result['messages'][0]);
        self::assertInstanceOf(AIMessage::class, $result['messages'][1]);
        self::assertStringContainsString('Response 3: xxxxxxxxxxx', self::content($result['messages'][1]));
        self::assertInstanceOf(HumanMessage::class, $result['messages'][2]);
        self::assertStringContainsString('Final question', self::content($result['messages'][2]));
        self::assertInstanceOf(AIMessage::class, $result['messages'][3]);
        self::assertSame('I understand your project. Let me analyze the architecture.', $result['messages'][3]->content);
    }

    // ---- token counter and configuration ----------------------------------------------------------

    public function testShouldUseCustomTokenCounterWhenProvided(): void
    {
        $summarizer = self::mockSummarizer();
        $counterCalls = 0;
        // Counts words instead (1 word = 1 token)
        $customTokenCounter = static function (array $messages) use (&$counterCalls): int {
            ++$counterCalls;
            $wordCount = 0;
            foreach ($messages as $message) {
                if (\is_string($message->content)) {
                    $wordCount += \count(explode(' ', $message->content));
                }
            }

            return $wordCount;
        };

        $agent = self::agent(self::mainModel(), [
            'model' => $summarizer,
            'trigger' => ['tokens' => 50], // 50 words
            'keep' => ['messages' => 2],
            'tokenCounter' => $customTokenCounter,
        ]);

        $agent->invoke(['messages' => [
            new HumanMessage('This is a long message with many words that should trigger the summarization because it has more than fifty words in total when combined with other messages in the conversation history and this sentence adds even more words to ensure we exceed the threshold.'),
            new AIMessage('This is another long response with many words to add to the total count and make sure we definitely exceed the threshold for summarization to occur and this adds even more words.'),
            new HumanMessage('Another message with some words.'),
            new AIMessage('Another response with some words.'),
            new HumanMessage('Short question?'),
        ]]);

        // The custom token counter was used and summarization was triggered
        self::assertGreaterThan(0, $counterCalls);
        self::assertNotSame([], $summarizer->calls);
    }

    public function testShouldHandleEmptyConversationGracefully(): void
    {
        $this->captureDeprecations();

        $summarizer = self::mockSummarizer();
        $agent = self::agent(self::mainModel(), ['model' => $summarizer, 'maxTokensBeforeSummary' => 100, 'messagesToKeep' => 5]);

        $result = $agent->invoke(['messages' => []]);

        // Does not crash and adds a response
        self::assertGreaterThan(0, \count($result['messages']));
        self::assertSame([], $summarizer->calls);

        self::assertSame(['maxTokensBeforeSummary is deprecated. Use `trigger: { tokens: value }` instead.', 'messagesToKeep is deprecated. Use `keep: { messages: value }` instead.'], $this->deprecations);
    }

    public function testShouldValidateContextSizeSchemaCorrectly(): void
    {
        $summarizer = self::mockSummarizer();

        // Valid configurations
        SummarizationMiddleware::create(['model' => $summarizer, 'trigger' => ['tokens' => 1000], 'keep' => ['messages' => 10]]);
        SummarizationMiddleware::create(['model' => $summarizer, 'trigger' => ['fraction' => 0.8], 'keep' => ['tokens' => 5000]]);
        // messages = 0 is allowed (keep nothing)
        SummarizationMiddleware::create(['model' => $summarizer, 'trigger' => ['tokens' => 1000], 'keep' => ['messages' => 0]]);

        $invalid = [
            'fraction > 1' => ['trigger' => ['fraction' => 1.5], 'keep' => ['messages' => 10]],
            'tokens <= 0' => ['trigger' => ['tokens' => -100], 'keep' => ['messages' => 10]],
            'keep with multiple properties' => ['trigger' => ['tokens' => 1000], 'keep' => ['messages' => 10, 'tokens' => 5000]],
        ];
        foreach ($invalid as $case => $options) {
            try {
                SummarizationMiddleware::create(['model' => $summarizer, ...$options]);
                self::fail("{$case} should be rejected");
            } catch (\InvalidArgumentException $e) {
                self::assertStringStartsWith('Invalid summarization middleware options: ', $e->getMessage(), $case);
            }
        }
    }

    public function testCanBeCreatedUsingAModelString(): void
    {
        // Upstream mocks the anthropic package; here the real InitChatModel builds a ChatAnthropic, which needs a key.
        $previous = getenv('ANTHROPIC_API_KEY');
        putenv('ANTHROPIC_API_KEY=sk-ant-test');
        try {
            $middleware = SummarizationMiddleware::create(['model' => 'anthropic:claude-sonnet-4-20250514', 'trigger' => ['tokens' => 100], 'keep' => ['messages' => 2]]);

            self::assertSame('SummarizationMiddleware', $middleware['name']);
            // The string resolves when the hook runs; below the trigger it leaves the conversation alone.
            self::assertNull(self::runBeforeModel($middleware, [new HumanMessage('hi')], new Runtime(context: [])));
        } finally {
            putenv($previous === false ? 'ANTHROPIC_API_KEY' : 'ANTHROPIC_API_KEY=' . $previous);
        }
    }

    public function testAModelStringWritesTheSummaryItsResolvedModelReturns(): void
    {
        $server = LocalProviderServer::start(['/api/chat' => LocalProviderServer::ollamaReply('Summary from the string model.')]);
        putenv('OLLAMA_BASE_URL=' . $server->baseUrl);
        try {
            $middleware = SummarizationMiddleware::create(['model' => 'ollama:llama3', 'trigger' => ['messages' => 4], 'keep' => ['messages' => 2]]);

            $result = self::runBeforeModel($middleware, self::longConversation(), new Runtime(context: []));

            self::assertSame(1, \count($server->requests()));
            self::assertSame('llama3', $server->body(0)['model']);
            self::assertStringContainsString('Messages to summarize:', json_encode($server->body(0)));
            $summary = array_values(array_filter($result['messages'], static fn (mixed $m): bool => $m instanceof HumanMessage && ($m->additional_kwargs['lc_source'] ?? null) === 'summarization'));
            self::assertCount(1, $summary);
            self::assertStringContainsString('Summary from the string model.', self::content($summary[0]));
        } finally {
            putenv('OLLAMA_BASE_URL');
            $server->stop();
        }
    }

    public function testAModelStringTheRegistryCannotResolveFailsWhenTheHookRuns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to infer model provider for { model: nosuchprovider:model }');

        $middleware = SummarizationMiddleware::create(['model' => 'nosuchprovider:model', 'trigger' => ['tokens' => 100], 'keep' => ['messages' => 2]]);

        self::runBeforeModel($middleware, [new HumanMessage('hi')], new Runtime(context: []));
    }

    public function testShouldHandleTriggerSetToUndefinedDisabled(): void
    {
        $summarizer = self::mockSummarizer();
        // trigger is not specified: summarization is disabled
        $agent = self::agent(self::mainModel(), ['model' => $summarizer, 'keep' => ['messages' => 10]]);
        $x = str_repeat('x', 500);
        $messages = [new HumanMessage("Message 1: {$x}"), new AIMessage("Response 1: {$x}"), new HumanMessage("Message 2: {$x}")];

        $result = $agent->invoke(['messages' => $messages]);

        self::assertSame([], $summarizer->calls);
        self::assertGreaterThan(\count($messages), \count($result['messages']));
    }

    public function testShouldNotStartPreservedMessagesWithAIMessageContainingToolCalls(): void
    {
        $summarizer = self::mockSummarizer();
        $agent = self::agent(self::mainModel(), ['model' => $summarizer, 'trigger' => ['tokens' => 100], 'keep' => ['messages' => 4]]);
        $x = str_repeat('x', 400);

        $result = $agent->invoke(['messages' => [
            new HumanMessage("First message with some content to take up tokens. {$x}"),
            new AIMessage("First response. {$x}"),
            new HumanMessage("Second message with more content to build up tokens. {$x}"),
            new AIMessage("Second response. {$x}"),
            new HumanMessage("Third message with even more content. {$x}"),
            new AIMessage("Third response. {$x}"),
            // This HumanMessage should be preserved before the tool call pair
            new HumanMessage('Let me search for information.'),
            // This AI message with tool calls must NOT be the first preserved message
            new AIMessage(['content' => "I'll search for that.", 'tool_calls' => [['id' => 'call_1', 'name' => 'search', 'args' => ['query' => 'test']]]]),
            new ToolMessage(['content' => 'Search results', 'tool_call_id' => 'call_1']),
            new HumanMessage('What did you find?'),
        ]]);

        $summaryIndex = null;
        foreach ($result['messages'] as $i => $message) {
            if (str_contains(self::content($message), 'summary')) {
                $summaryIndex = $i;
                break;
            }
        }
        self::assertNotNull($summaryIndex);
        self::assertIsSummary($result['messages'][$summaryIndex]);

        $preserved = \array_slice($result['messages'], $summaryIndex + 1);
        self::assertNotSame([], $preserved);
        self::assertFalse($preserved[0] instanceof AIMessage && AgentUtils::hasToolCalls($preserved[0]));
    }

    public function testShouldUseDefaultSummaryPrefixWhenNotProvided(): void
    {
        $summarizer = self::mockSummarizer();
        $agent = self::agent(self::mainModel(), ['model' => $summarizer, 'trigger' => ['tokens' => 50], 'keep' => ['messages' => 2]]);
        $x = str_repeat('x', 200);

        $result = $agent->invoke(['messages' => [
            new HumanMessage("Message 1: {$x}"),
            new AIMessage("Response 1: {$x}"),
            new HumanMessage("Message 2: {$x}"),
            new AIMessage("Response 2: {$x}"),
            new HumanMessage('Final question'),
        ]]);

        self::assertNotSame([], $summarizer->calls);
        self::assertIsSummary($result['messages'][0], 'Here is a summary of the conversation to date:');
    }

    public function testShouldUseCustomSummaryPrefixWhenProvided(): void
    {
        $summarizer = self::mockSummarizer();
        $customPrefix = 'Custom summary prefix for testing:';
        $agent = self::agent(self::mainModel(), ['model' => $summarizer, 'trigger' => ['tokens' => 50], 'keep' => ['messages' => 2], 'summaryPrefix' => $customPrefix]);
        $x = str_repeat('x', 200);

        $result = $agent->invoke(['messages' => [
            new HumanMessage("Message 1: {$x}"),
            new AIMessage("Response 1: {$x}"),
            new HumanMessage("Message 2: {$x}"),
            new AIMessage("Response 2: {$x}"),
            new HumanMessage('Final question'),
        ]]);

        self::assertNotSame([], $summarizer->calls);
        $summary = $result['messages'][0];
        self::assertIsSummary($summary, $customPrefix);
        self::assertStringNotContainsString('Here is a summary of the conversation to date:', self::content($summary));
    }

    // ---- internal call suppression ----------------------------------------------------------------

    private const SUMMARY = 'INTERNAL_SUMMARY_OUTPUT';
    private const SUMMARY_PREFIX = 'Here is a summary of the conversation to date:';
    private const MAIN = 'Main model answer.';

    /** @return list<BaseMessage> */
    private static function suppressionInput(): array
    {
        $x = str_repeat('x', 200);

        return [
            new HumanMessage("Message 1: {$x}"),
            new AIMessage("Response 1: {$x}"),
            new HumanMessage("Message 2: {$x}"),
            new AIMessage("Response 2: {$x}"),
            new HumanMessage('Final question'),
        ];
    }

    private static function suppressionAgent(object $summarizer): \LangGraph\Agents\ReactAgent
    {
        return self::agent(self::singleResponseModel(self::MAIN), [
            'model' => $summarizer,
            'trigger' => ['tokens' => 50],
            'keep' => ['messages' => 2],
        ]);
    }

    /**
     * What upstream's `collectV3Messages` gathers from `run.messages`: each message's text and the number of
     * `content-block-delta` events across them.
     *
     * @return array{texts: list<string>, deltaCount: int}
     */
    private static function collectRunMessages(\LangGraph\Stream\RunStream $run): array
    {
        $texts = [];
        $deltaCount = 0;
        foreach ($run->messages() as $message) {
            $texts[] = $message->text();
            foreach ($message as $event) {
                if (($event['event'] ?? null) === 'content-block-delta') {
                    ++$deltaCount;
                }
            }
        }

        return ['texts' => $texts, 'deltaCount' => $deltaCount];
    }

    public function testOmitsTheSummarizationCallFromRunMessages(): void
    {
        $agent = self::suppressionAgent(AgentAssertions::fakeChat([new AIMessage(self::SUMMARY)]));

        ['texts' => $texts] = self::collectRunMessages($agent->streamEvents(['messages' => self::suppressionInput()], null, 'v3'));

        self::assertNotContains(self::SUMMARY, $texts);
        self::assertContains(self::MAIN, $texts);
        self::assertSame([], array_filter($texts, static fn (string $t): bool => str_contains($t, self::SUMMARY_PREFIX)));
    }

    public function testOmitsAStreamingSummarizationCallFromRunMessages(): void
    {
        $agent = self::suppressionAgent(new \LangChain\Utils\Testing\FakeListChatModel(['responses' => [self::SUMMARY]]));

        ['texts' => $texts, 'deltaCount' => $deltaCount] = self::collectRunMessages($agent->streamEvents(['messages' => self::suppressionInput()], null, 'v3'));

        self::assertNotContains(self::SUMMARY, $texts);
        self::assertContains(self::MAIN, $texts);
        // The main model's synthetic delta only; a leaked stream would add one per character.
        self::assertSame(1, $deltaCount);
    }

    public function testOmitsTheSummarizationCallFromStreamMessages(): void
    {
        $summarizer = AgentAssertions::fakeChat([new AIMessage(self::SUMMARY)]);
        $agent = self::suppressionAgent($summarizer);

        $texts = [];
        $agent->graph->streamMode = ['messages'];
        foreach ($agent->stream(['messages' => self::suppressionInput()]) as [$mode, $chunk]) {
            if ($mode !== 'messages') {
                continue;
            }
            $message = $chunk[0];
            $text = \is_string($message->content) ? $message->content : '';
            if ($text !== '' && !$message instanceof ToolMessage) {
                $texts[] = $text;
            }
        }

        self::assertNotContains(self::SUMMARY, $texts);
        self::assertContains(self::MAIN, $texts);
        self::assertSame([], array_filter($texts, static fn (string $t): bool => str_contains($t, self::SUMMARY_PREFIX)));
    }

    public function testStillSurfacesTheSummarizationCallOnStreamEventsV2(): void
    {
        $summarizer = AgentAssertions::fakeChat([new AIMessage(self::SUMMARY)]);
        $agent = self::suppressionAgent($summarizer);

        $sources = [];
        foreach ($agent->streamEvents(['messages' => self::suppressionInput()], null, 'v2') as $event) {
            self::assertInstanceOf(StreamEvent::class, $event);
            if ($event->event !== 'on_chat_model_end') {
                continue;
            }
            $output = $event->data['output'] ?? null;
            if ($output instanceof BaseMessage && $output->content === self::SUMMARY) {
                $sources[] = $event->metadata['lc_source'] ?? null;
            }
        }

        // Suppression is scoped to LangGraph's messages channel, not Core's callbacks.
        self::assertSame(['summarization'], $sources);
    }

    // ---- cutoff safety ----------------------------------------------------------------------------

    public function testShouldMoveCutoffBackwardToPreserveAIToolPairsWhenCutoffLandsOnToolMessage(): void
    {
        $summarizer = self::mockSummarizer();
        $agent = self::agent(self::singleResponseModel('Final response'), [
            'model' => $summarizer,
            'trigger' => ['tokens' => 100],
            'keep' => ['tokens' => 150], // A token budget that would land the cutoff on the ToolMessage
        ]);

        $result = $agent->invoke(['messages' => [
            new HumanMessage(str_repeat('x', 300)), // ~75 tokens
            new AIMessage([
                'content' => str_repeat('y', 200), // ~50 tokens
                'tool_calls' => [['id' => 'call_preserve', 'name' => 'test_tool', 'args' => ['test' => true]]],
            ]),
            new ToolMessage(['content' => str_repeat('z', 50), 'tool_call_id' => 'call_preserve', 'name' => 'test_tool']), // ~12 tokens
            new HumanMessage(str_repeat('a', 180)), // ~45 tokens
            new HumanMessage(str_repeat('b', 160)), // ~40 tokens
        ]]);

        $summaryIndex = null;
        foreach ($result['messages'] as $i => $message) {
            if ($message instanceof HumanMessage && \is_string($message->content) && str_contains($message->content, 'Here is a summary')) {
                $summaryIndex = $i;
                break;
            }
        }
        self::assertNotNull($summaryIndex);
        $preserved = \array_slice($result['messages'], $summaryIndex + 1);

        // The AI/Tool pair is kept together
        self::assertNotSame([], array_filter($preserved, static fn (BaseMessage $m): bool => $m instanceof AIMessage && $m->toolCalls !== []));
        self::assertNotSame([], array_filter($preserved, static fn (BaseMessage $m): bool => $m instanceof ToolMessage && $m->toolCallId === 'call_preserve'));
    }

    public function testShouldHandleOrphanToolMessageByAdvancingForward(): void
    {
        $summarizer = self::mockSummarizer();
        $agent = self::agent(self::singleResponseModel('Final response'), ['model' => $summarizer, 'trigger' => ['tokens' => 50], 'keep' => ['messages' => 2]]);

        // A ToolMessage with no matching AIMessage (orphan)
        $result = $agent->invoke(['messages' => [
            new HumanMessage(str_repeat('x', 200)),
            new AIMessage('No tool calls here'),
            new ToolMessage(['content' => 'Orphan result', 'tool_call_id' => 'orphan_call', 'name' => 'orphan_tool']),
            new HumanMessage(str_repeat('y', 200)),
            new HumanMessage('Final question'),
        ]]);

        // Does not crash and the conversation continues
        self::assertGreaterThan(0, \count($result['messages']));
    }

    public function testShouldPreserveManyParallelToolCallsTogetherWithAIMessage(): void
    {
        $summarizer = self::mockSummarizer();
        $agent = self::agent(self::singleResponseModel('All files read and summarized'), ['model' => $summarizer, 'trigger' => ['tokens' => 100], 'keep' => ['messages' => 5]]);

        $toolCalls = [];
        for ($i = 0; $i < 10; ++$i) {
            $toolCalls[] = ['id' => "call_{$i}", 'name' => 'read_file', 'args' => ['file' => "file{$i}.txt"]];
        }
        $toolMessages = array_map(
            static fn (array $tc): ToolMessage => new ToolMessage(['content' => 'Contents of ' . $tc['args']['file'], 'tool_call_id' => $tc['id'], 'name' => $tc['name']]),
            $toolCalls,
        );

        $result = $agent->invoke(['messages' => [
            new HumanMessage(str_repeat('x', 500)), // Long message to trigger summarization
            new AIMessage(['content' => "I'll read all 10 files", 'tool_calls' => $toolCalls]),
            ...$toolMessages,
            new HumanMessage('Now summarize them'),
        ]]);

        $summaryIndex = null;
        foreach ($result['messages'] as $i => $message) {
            if ($message instanceof HumanMessage && \is_string($message->content) && str_contains($message->content, 'Here is a summary')) {
                $summaryIndex = $i;
                break;
            }
        }
        self::assertNotNull($summaryIndex, 'summarization should have run');

        $preserved = \array_slice($result['messages'], $summaryIndex + 1);
        $preservedAI = null;
        foreach ($preserved as $message) {
            if ($message instanceof AIMessage && $message->toolCalls !== []) {
                $preservedAI = $message;
                break;
            }
        }

        // If the AIMessage with tool calls is preserved, all its ToolMessages are too
        if ($preservedAI !== null) {
            $ids = array_column($preservedAI->toolCalls, 'id');
            $matching = array_filter($preserved, static fn (BaseMessage $m): bool => $m instanceof ToolMessage && \in_array($m->toolCallId, $ids, true));
            self::assertCount(\count($preservedAI->toolCalls), $matching);
        }
    }

    public function testShouldUseGetBufferStringFormatToAvoidTokenInflationFromMessageMetadata(): void
    {
        $capturedPrompt = '';
        $summarizer = self::mockSummarizer(static function (string $prompt) use (&$capturedPrompt): array {
            $capturedPrompt = $prompt;

            return ['content' => 'Summary of the conversation.'];
        });
        $summarizer->profile = [];

        $agent = self::agent(self::mainModel(), ['model' => $summarizer, 'trigger' => ['tokens' => 50], 'keep' => ['messages' => 1]]);

        $agent->invoke(['messages' => [
            new HumanMessage('What is the weather in NYC?'),
            new AIMessage([
                'content' => 'Let me check the weather for you.',
                'tool_calls' => [['name' => 'get_weather', 'args' => ['city' => 'NYC'], 'id' => 'call_123']],
            ]),
            new ToolMessage(['content' => '72F and sunny', 'tool_call_id' => 'call_123', 'name' => 'get_weather']),
            new AIMessage(['content' => 'It is 72F and sunny in NYC! ' . str_repeat('x', 200)]),
            new HumanMessage('Thanks!'),
        ]]);

        self::assertNotSame('', $capturedPrompt);
        // The prompt uses the compact role-prefixed format
        self::assertStringContainsString('Human:', $capturedPrompt);
        self::assertStringContainsString('AI:', $capturedPrompt);
        self::assertStringContainsString('Tool:', $capturedPrompt);

        // ...and not the verbose metadata a JSON dump would carry
        self::assertStringNotContainsString('"type": "human"', $capturedPrompt);
        self::assertStringNotContainsString('"type": "ai"', $capturedPrompt);
        self::assertStringNotContainsString('"additional_kwargs"', $capturedPrompt);
        self::assertStringNotContainsString('"response_metadata"', $capturedPrompt);

        // The tool calls are still included (as JSON appended to the AI message)
        self::assertStringContainsString('get_weather', $capturedPrompt);
    }

    // ---- not in upstream's unit file --------------------------------------------------------------

    public function testGetProfileLimitsReadsTheProfileThenTheModelName(): void
    {
        $withProfile = new class () {
            public array $profile = ['maxInputTokens' => 1234];
            public string $model = 'gpt-4o';
        };
        $nullProfile = new class () {
            public array $profile = ['maxInputTokens' => null];
            public string $model = 'gpt-4o';
        };
        $byName = new class () {
            public string $modelName = 'gpt-4o-mini';
        };
        $profileMethod = new class () {
            public function profile(): array
            {
                return ['maxInputTokens' => 99];
            }
        };

        self::assertSame(1234, SummarizationMiddleware::getProfileLimits($withProfile));
        self::assertNull(SummarizationMiddleware::getProfileLimits($nullProfile));
        self::assertSame(128000, SummarizationMiddleware::getProfileLimits($byName));
        self::assertSame(99, SummarizationMiddleware::getProfileLimits($profileMethod));
        self::assertNull(SummarizationMiddleware::getProfileLimits(new \stdClass()));
    }

    public function testASummaryModelFailureBecomesTheSummaryText(): void
    {
        $summarizer = self::mockSummarizer(static fn (): array => throw new \RuntimeException('boom'));
        $middleware = SummarizationMiddleware::create(['model' => $summarizer, 'trigger' => ['tokens' => 50], 'keep' => ['messages' => 2]]);

        $update = self::runBeforeModel($middleware, self::longConversation(), new Runtime(context: []));

        self::assertStringContainsString('Error generating summary: RuntimeException: boom', $update['messages'][1]->content);
    }

    public function testTheRewriteStartsWithRemoveAllMessagesAndReusesTheFirstId(): void
    {
        $summarizer = self::mockSummarizer();
        $middleware = SummarizationMiddleware::create(['model' => $summarizer, 'trigger' => ['messages' => 3], 'keep' => ['messages' => 2]]);
        $messages = [new HumanMessage(['content' => 'one', 'id' => 'm1']), new AIMessage(['content' => 'two', 'id' => 'm2']), new HumanMessage(['content' => 'three', 'id' => 'm3']), new AIMessage(['content' => 'four', 'id' => 'm4'])];

        $update = self::runBeforeModel($middleware, $messages, new Runtime(context: []));

        self::assertSame('__remove_all__', $update['messages'][0]->id);
        self::assertSame('m1', $update['messages'][1]->id);
        self::assertSame(['m3', 'm4'], [$update['messages'][2]->id, $update['messages'][3]->id]);
    }

    public function testChunkMessagesAreSummarizedLikeAnyOther(): void
    {
        $summarizer = self::mockSummarizer();
        $middleware = SummarizationMiddleware::create(['model' => $summarizer, 'trigger' => ['messages' => 2], 'keep' => ['messages' => 1]]);

        $update = self::runBeforeModel(
            $middleware,
            [new HumanMessage('one'), new AIMessageChunk(['content' => 'two']), new HumanMessage('three')],
            new Runtime(context: []),
        );

        self::assertNotNull($update);
        self::assertCount(1, $summarizer->calls);
    }
}
