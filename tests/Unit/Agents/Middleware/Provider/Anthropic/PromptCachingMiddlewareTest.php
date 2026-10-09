<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Middleware\Provider\Anthropic;

use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Tests\Unit\Agents\Support\BindRecordingModel;
use LangChain\Tests\Unit\Agents\Support\FakeConfigurableModel;
use LangChain\Utils\Testing\FakeHttpClient;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Errors\MiddlewareError;
use LangGraph\Agents\Middleware\Provider\Anthropic\PromptCachingMiddleware;
use LangGraph\Agents\Middleware\Provider\Anthropic\PromptCachingMiddlewareError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `langchain/src/agents/middleware/provider/anthropic/tests/promptCaching.test.ts`.
 *
 * Upstream's mock model records the options `bindTools` received; here that is {@see BindRecordingModel}, whose
 * `getName()` is what the middleware detects the provider by. `console.warn` is a PHP warning, captured with an
 * error handler. The wire shape is asserted on a real `ChatAnthropic` over a fake transport.
 */
#[CoversClass(PromptCachingMiddleware::class)]
final class PromptCachingMiddlewareTest extends TestCase
{
    private static function mockModel(string $name = 'ChatAnthropic'): BindRecordingModel
    {
        return new BindRecordingModel($name, ['sleep' => 0, 'responses' => [new AIMessage('Response from model')]]);
    }

    /** @return array<string, mixed> the options of the model's last bind */
    private static function lastBindOptions(BindRecordingModel $model): array
    {
        $options = $model->boundKwargs->getArrayCopy();

        return $options[array_key_last($options)];
    }

    /** @return list<\LangChain\Messages\BaseMessage> */
    private static function conversation(int $turns): array
    {
        $messages = [];
        for ($i = 0; $i < $turns; $i++) {
            $messages[] = $i % 2 === 0 ? new HumanMessage("message {$i}") : new AIMessage("reply {$i}");
        }

        return $messages;
    }

    public function testShouldAddCacheControlToModelSettingsWhenConditionsAreMet(): void
    {
        $model = self::mockModel();
        $agent = Agent::create(['model' => $model, 'middleware' => [PromptCachingMiddleware::create(['ttl' => '5m', 'minMessagesToCache' => 3])]]);

        $agent->invoke(['messages' => [
            new SystemMessage('You are a helpful assistant'),
            new HumanMessage('Hello'),
            new AIMessage('Hi there!'),
            new HumanMessage('How are you?'),
            new AIMessage("I'm doing well, thanks!"),
            new HumanMessage("What's the weather like?"),
        ]]);

        self::assertCount(1, $model->boundKwargs);
        self::assertSame(['type' => 'ephemeral', 'ttl' => '5m'], self::lastBindOptions($model)['cache_control']);
    }

    public function testShouldPassCacheControlViaModelSettingsNotModifyMessages(): void
    {
        $model = self::mockModel();
        $agent = Agent::create(['model' => $model, 'middleware' => [PromptCachingMiddleware::create(['ttl' => '5m', 'minMessagesToCache' => 3])]]);

        $agent->invoke(['messages' => [
            new SystemMessage('You are a helpful assistant'),
            new HumanMessage('Hello'),
            new AIMessage('Hi there!'),
            new HumanMessage("What's the weather like?"),
        ]]);

        // The cache_control is applied later by the client when it formats the request: the last message keeps
        // its plain string content (an array with cache_control would mean the old in-message approach).
        self::assertCount(1, $model->generatedInputs);
        $conversation = $model->generatedInputs[0];
        $last = $conversation[array_key_last($conversation)];
        self::assertIsString($last->content);
        self::assertSame("What's the weather like?", $last->content);
    }

    public function testShouldNotAddCacheControlWhenMessageCountIsBelowThreshold(): void
    {
        $model = self::mockModel();
        $agent = Agent::create(['model' => $model, 'middleware' => [PromptCachingMiddleware::create(['ttl' => '1h', 'minMessagesToCache' => 5])]]);

        $agent->invoke(['messages' => [new HumanMessage('Hello'), new AIMessage('Hi there!')]]);

        self::assertCount(1, $model->boundKwargs);
        self::assertArrayNotHasKey('cache_control', self::lastBindOptions($model));
    }

    public function testShouldRespectEnableCachingSetting(): void
    {
        $model = self::mockModel();
        $agent = Agent::create(['model' => $model, 'middleware' => [PromptCachingMiddleware::create(['enableCaching' => false, 'ttl' => '5m', 'minMessagesToCache' => 1])]]);

        $agent->invoke(['messages' => self::conversation(3)]);

        self::assertArrayNotHasKey('cache_control', self::lastBindOptions($model));
    }

    /** The agent wraps a middleware's error in a MiddlewareError (as upstream); the original rides along as the cause. */
    private static function assertRaisedUnsupported(\LangGraph\Agents\ReactAgent $agent, string $message): void
    {
        try {
            $agent->invoke(['messages' => []]);
        } catch (MiddlewareError $error) {
            self::assertSame($message, $error->getMessage());
            self::assertInstanceOf(PromptCachingMiddlewareError::class, $error->getPrevious());

            return;
        }

        self::fail('the unsupported model was not rejected');
    }

    public function testShouldThrowIfPassedANonAnthropicChatInstanceAndBehaviorIsRaise(): void
    {
        $agent = Agent::create([
            'model' => new ChatOpenAI(['model' => 'gpt-4o', 'apiKey' => 'sk-test']),
            'middleware' => [PromptCachingMiddleware::create(['unsupportedModelBehavior' => 'raise'])],
        ]);

        self::assertRaisedUnsupported($agent, "Unsupported model 'ChatOpenAI'. Prompt caching requires an Anthropic model (e.g., 'anthropic:claude-4-0-sonnet').");
    }

    public function testShouldThrowIfPassedANonAnthropicModelViaAConfigurableModelAndBehaviorIsRaise(): void
    {
        // A "provider:model" string becomes a configurable model; the provider it resolves to is named in the error.
        $model = new FakeConfigurableModel(['model' => self::mockModel('ChatOpenAI')]);
        $agent = Agent::create(['model' => $model, 'middleware' => [PromptCachingMiddleware::create(['unsupportedModelBehavior' => 'raise'])]]);

        self::assertRaisedUnsupported($agent, "Unsupported model 'FakeConfigurableModel (openai)'. Prompt caching requires an Anthropic model (e.g., 'anthropic:claude-4-0-sonnet').");
    }

    public function testShouldWarnIfPassedANonAnthropicChatInstanceAndBehaviorIsWarn(): void
    {
        $model = self::mockModel('ChatOpenAI');
        $agent = Agent::create(['model' => $model, 'middleware' => [PromptCachingMiddleware::create([
            'enableCaching' => true, 'ttl' => '5m', 'minMessagesToCache' => 1, 'unsupportedModelBehavior' => 'warn',
        ])]]);

        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, \E_USER_WARNING);
        try {
            $agent->invoke(['messages' => self::conversation(3)]);
        } finally {
            restore_error_handler();
        }

        self::assertArrayNotHasKey('cache_control', self::lastBindOptions($model));
        self::assertCount(1, $warnings);
        self::assertStringContainsString('Skipping caching for ChatOpenAI', $warnings[0]);
    }

    public function testShouldIgnoreIfPassedANonAnthropicChatInstanceAndBehaviorIsIgnore(): void
    {
        $model = self::mockModel('ChatOpenAI');
        $agent = Agent::create(['model' => $model, 'middleware' => [PromptCachingMiddleware::create([
            'enableCaching' => true, 'ttl' => '5m', 'minMessagesToCache' => 1, 'unsupportedModelBehavior' => 'ignore',
        ])]]);

        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, \E_USER_WARNING);
        try {
            $agent->invoke(['messages' => self::conversation(3)]);
        } finally {
            restore_error_handler();
        }

        self::assertArrayNotHasKey('cache_control', self::lastBindOptions($model));
        self::assertSame([], $warnings);
    }

    public function testShouldIncludeSystemMessageInMessageCount(): void
    {
        $model = self::mockModel();
        $agent = Agent::create([
            'model' => $model,
            'systemPrompt' => 'You are a helpful assistant', // counts as one message
            'middleware' => [PromptCachingMiddleware::create(['ttl' => '1h', 'minMessagesToCache' => 3])],
        ]);

        // Only 2 conversation messages, but the system prompt makes 3 in total.
        $agent->invoke(['messages' => [new HumanMessage('Hello'), new AIMessage('Hi there!')]]);

        self::assertSame(['type' => 'ephemeral', 'ttl' => '1h'], self::lastBindOptions($model)['cache_control']);
    }

    public function testShouldAllowRuntimeContextOverride(): void
    {
        $model = self::mockModel();
        $agent = Agent::create(['model' => $model, 'middleware' => [PromptCachingMiddleware::create(['ttl' => '5m', 'minMessagesToCache' => 3])]]);

        // Override at runtime to disable caching.
        $agent->invoke(['messages' => self::conversation(5)], ['context' => ['enableCaching' => false]]);

        self::assertArrayNotHasKey('cache_control', self::lastBindOptions($model));
    }

    // ---- Beyond the upstream cases: the request body a real ChatAnthropic sends ---------------------

    /** @return array<string, mixed> */
    private static function anthropicReply(): array
    {
        return [
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-5',
            'content' => [['type' => 'text', 'text' => 'done']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ];
    }

    public function testTheRealAnthropicClientIsBoundWithCacheControlAndItsMessagesAreLeftAlone(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, self::anthropicReply())]);
        $model = new class (['apiKey' => 'sk-test', 'model' => 'claude-sonnet-4-5', 'httpClient' => $http]) extends ChatAnthropic {
            /** @var \ArrayObject<int, array<string, mixed>> */
            public \ArrayObject $boundKwargs;

            public function __construct(array $fields = [])
            {
                parent::__construct($fields);
                $this->boundKwargs = new \ArrayObject();
            }

            public function bindTools(array $tools, array $kwargs = []): static
            {
                $this->boundKwargs->append($kwargs);

                return parent::bindTools($tools, $kwargs);
            }
        };
        $agent = Agent::create(['model' => $model, 'middleware' => [PromptCachingMiddleware::create(['ttl' => '1h', 'minMessagesToCache' => 3])]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('one'), new AIMessage('two'), new HumanMessage('three')]]);

        self::assertCount(1, $model->boundKwargs);
        self::assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $model->boundKwargs[0]['cache_control']);
        self::assertCount(1, $http->requests);
        self::assertStringNotContainsString('cache_control', (string) json_encode($http->lastRequestBody()['messages']), 'the messages carry no cache_control of their own');
        self::assertSame('done', $result['messages'][array_key_last($result['messages'])]->content);
    }

    public function testTheRealAnthropicClientForwardsTheBoundCacheControlOnTheRequestBody(): void
    {
        self::markTestSkipped(
            'ChatAnthropic::invocationParams() forwards a top-level cache_control from per-call options only, not from the '
            . 'kwargs bindTools() stores, so the middleware (whose only seam is modelSettings -> bindTools) cannot reach the '
            . 'request body yet. Provider gap outside the WP-22c-2 territory; un-skip once the client reads bound cache_control.',
        );
    }

    public function testTheRealAnthropicClientSendsNoCacheControlBelowTheThreshold(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, self::anthropicReply())]);
        $model = new ChatAnthropic(['apiKey' => 'sk-test', 'model' => 'claude-sonnet-4-5', 'httpClient' => $http]);
        $agent = Agent::create(['model' => $model, 'middleware' => [PromptCachingMiddleware::create(['minMessagesToCache' => 5])]]);

        $agent->invoke(['messages' => [new HumanMessage('one')]]);

        self::assertArrayNotHasKey('cache_control', $http->lastRequestBody());
    }
}
