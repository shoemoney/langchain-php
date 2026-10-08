<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenRouter;

use LangChain\LanguageModels\Chat\OpenRouter\ChatOpenRouter;
use LangChain\LanguageModels\Chat\OpenRouter\Utils\OpenRouterAuthError;
use LangChain\LanguageModels\Chat\OpenRouter\Utils\OpenRouterRateLimitError;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\BaseCallbackHandler;
use LangChain\Utils\AsyncCaller;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `chat_models/tests/index.test.ts` from `@langchain/openrouter`
 * (35 `it`/`test` cases).
 *
 * The seam is {@see FakeHttpClient} where upstream spies on `fetch`. The
 * `withStructuredOutput` cases that feed a mocked `invoke` are driven through a
 * scripted HTTP reply instead, so the request body is asserted too. The four
 * "invalid output throws OutputParserException" cases are NOT converted: they
 * exercise Standard Schema validation of the parsed value, and this port is
 * JSON-Schema-native with no schema-validating parser; they are replaced by
 * {@see self::testStructuredOutputIsNotValidatedAgainstTheSchema()}, which pins
 * that divergence.
 */
#[CoversClass(ChatOpenRouter::class)]
final class ChatOpenRouterTest extends TestCase
{
    private string|false $savedKey;

    private string|false $savedSession;

    protected function setUp(): void
    {
        $this->savedKey = getenv('OPENROUTER_API_KEY');
        $this->savedSession = getenv('OPENROUTER_SESSION_ID');
        putenv('OPENROUTER_API_KEY=test-key');
        putenv('OPENROUTER_SESSION_ID');
        unset($_ENV['OPENROUTER_API_KEY'], $_ENV['OPENROUTER_SESSION_ID']);
    }

    protected function tearDown(): void
    {
        putenv($this->savedKey === false ? 'OPENROUTER_API_KEY' : 'OPENROUTER_API_KEY=' . $this->savedKey);
        putenv($this->savedSession === false ? 'OPENROUTER_SESSION_ID' : 'OPENROUTER_SESSION_ID=' . $this->savedSession);
    }

    /** @param array<string, mixed> $fields */
    private static function model(array $fields = [], ?FakeHttpClient $http = null, string $model = 'openai/gpt-4o'): ChatOpenRouter
    {
        return new ChatOpenRouter(['model' => $model, 'httpClient' => $http] + $fields);
    }

    /** @return array<string, mixed> */
    private static function completion(string $content, ?array $toolCalls = null, ?array $usage = null): array
    {
        $message = ['role' => 'assistant', 'content' => $content];
        if ($toolCalls !== null) {
            $message['tool_calls'] = $toolCalls;
        }

        return [
            'id' => 'chatcmpl-1',
            'model' => 'openai/gpt-4o',
            'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $toolCalls !== null ? 'tool_calls' : 'stop']],
        ] + ($usage !== null ? ['usage' => $usage] : []);
    }

    /** @return list<array<string, mixed>> */
    private static function toolCall(string $name, array $args): array
    {
        return [['id' => 'call_123', 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($args)]]];
    }

    // ─── Constructor ─────────────────────────────────────────────────

    public function testAssignsAllFieldsFromParams(): void
    {
        $model = new ChatOpenRouter([
            'model' => 'anthropic/claude-4-sonnet',
            'apiKey' => 'sk-test',
            'temperature' => 0.5,
            'maxTokens' => 1024,
            'topP' => 0.9,
            'topK' => 40,
            'frequencyPenalty' => 0.1,
            'presencePenalty' => 0.2,
            'repetitionPenalty' => 1.1,
            'minP' => 0.05,
            'topA' => 0.3,
            'seed' => 42,
            'stop' => ["\n"],
            'logitBias' => ['50256' => -100],
            'topLogprobs' => 3,
            'user' => 'user-123',
            'transforms' => ['middle-out'],
            'models' => ['a', 'b'],
            'route' => 'fallback',
            'siteUrl' => 'https://example.com',
            'siteName' => 'TestApp',
            'streamUsage' => false,
        ]);

        self::assertSame('anthropic/claude-4-sonnet', $model->model);
        self::assertSame('sk-test', $model->apiKey);
        self::assertSame(0.5, $model->temperature);
        self::assertSame(1024, $model->maxTokens);
        self::assertSame(0.9, $model->topP);
        self::assertSame(40, $model->topK);
        self::assertSame(0.1, $model->frequencyPenalty);
        self::assertSame(0.2, $model->presencePenalty);
        self::assertSame(1.1, $model->repetitionPenalty);
        self::assertSame(0.05, $model->minP);
        self::assertSame(0.3, $model->topA);
        self::assertSame(42, $model->seed);
        self::assertSame(["\n"], $model->stop);
        self::assertEquals(['50256' => -100], $model->logitBias);
        self::assertSame(3, $model->topLogprobs);
        self::assertSame('user-123', $model->user);
        self::assertSame(['middle-out'], $model->transforms);
        self::assertSame(['a', 'b'], $model->models);
        self::assertSame('fallback', $model->route);
        self::assertSame('https://example.com', $model->siteUrl);
        self::assertSame('TestApp', $model->siteName);
        self::assertFalse($model->streamUsage);
    }

    public function testDefaultsBaseUrlAndStreamUsage(): void
    {
        $model = self::model();
        self::assertSame('https://openrouter.ai/api/v1', $model->baseURL);
        self::assertTrue($model->streamUsage);
    }

    public function testDefaultsSiteUrlAndSiteNameForOpenRouterAttribution(): void
    {
        $model = self::model();
        self::assertSame('https://docs.langchain.com', $model->siteUrl);
        self::assertSame('LangChain', $model->siteName);
    }

    public function testAllowsUserToOverrideSiteUrlAndSiteName(): void
    {
        $model = self::model(['siteUrl' => 'https://my-custom-app.com', 'siteName' => 'My Custom App']);
        self::assertSame('https://my-custom-app.com', $model->siteUrl);
        self::assertSame('My Custom App', $model->siteName);
    }

    public function testStoresAppCategoriesWhenProvided(): void
    {
        $model = self::model(['appCategories' => ['cli-agent', 'programming-app']]);
        self::assertSame(['cli-agent', 'programming-app'], $model->appCategories);
    }

    public function testDefaultsAppCategoriesToNull(): void
    {
        self::assertNull(self::model()->appCategories);
    }

    public function testThrowsOpenRouterAuthErrorWhenNoApiKeyIsAvailable(): void
    {
        putenv('OPENROUTER_API_KEY');

        try {
            new ChatOpenRouter(['model' => 'openai/gpt-4o']);
            self::fail('Expected an OpenRouterAuthError.');
        } catch (\Throwable $e) {
            self::assertInstanceOf(OpenRouterAuthError::class, $e);
        }
    }

    // ─── attribution headers ─────────────────────────────────────────

    /** @return array<string, string> */
    private static function headersOf(ChatOpenRouter $model): array
    {
        return (fn (): array => $this->buildHeaders())->call($model);
    }

    public function testSendsDefaultReferrerAndTitleHeaders(): void
    {
        $headers = self::headersOf(self::model());
        self::assertSame('https://docs.langchain.com', $headers['HTTP-Referer']);
        self::assertSame('LangChain', $headers['X-Title']);
    }

    public function testSendsUserSuppliedSiteUrlAsReferrer(): void
    {
        self::assertSame('https://myapp.com', self::headersOf(self::model(['siteUrl' => 'https://myapp.com']))['HTTP-Referer']);
    }

    public function testSendsUserSuppliedSiteNameAsTitle(): void
    {
        self::assertSame('My App', self::headersOf(self::model(['siteName' => 'My App']))['X-Title']);
    }

    public function testSendsCategoriesHeaderWhenAppCategoriesIsSet(): void
    {
        $headers = self::headersOf(self::model(['appCategories' => ['cli-agent', 'programming-app']]));
        self::assertSame('cli-agent,programming-app', $headers['X-OpenRouter-Categories']);
    }

    public function testOmitsCategoriesHeaderWhenAppCategoriesIsUnset(): void
    {
        self::assertArrayNotHasKey('X-OpenRouter-Categories', self::headersOf(self::model()));
    }

    public function testOmitsCategoriesHeaderWhenAppCategoriesIsEmpty(): void
    {
        self::assertArrayNotHasKey('X-OpenRouter-Categories', self::headersOf(self::model(['appCategories' => []])));
    }

    public function testIncludesAllAttributionHeadersTogether(): void
    {
        $headers = self::headersOf(self::model([
            'siteUrl' => 'https://myapp.com',
            'siteName' => 'My App',
            'appCategories' => ['cli-agent'],
        ]));
        self::assertSame('https://myapp.com', $headers['HTTP-Referer']);
        self::assertSame('My App', $headers['X-Title']);
        self::assertSame('cli-agent', $headers['X-OpenRouter-Categories']);
    }

    // ─── invocationParams ────────────────────────────────────────────

    public function testCallTimeOptionsOverrideConstructorDefaults(): void
    {
        $model = self::model(['temperature' => 0.7, 'maxTokens' => 500]);

        $params = $model->invocationParams(['temperature' => 0.2, 'maxTokens' => 100]);

        self::assertSame(0.2, $params['temperature']);
        self::assertSame(100, $params['max_tokens']);
    }

    public function testFallsBackToConstructorValuesWhenCallOptionsAreAbsent(): void
    {
        $model = self::model(['temperature' => 0.7, 'topK' => 50]);

        $params = $model->invocationParams([]);

        self::assertSame(0.7, $params['temperature']);
        self::assertSame(50, $params['top_k']);
    }

    public function testPassesThroughOpenRouterSpecificFields(): void
    {
        $model = self::model([
            'transforms' => ['middle-out'],
            'models' => ['a', 'b'],
            'route' => 'fallback',
            'provider' => ['order' => ['OpenAI']],
            'sessionId' => 'session-abc',
            'trace' => ['trace_id' => 'trace-1', 'span_name' => 'summarize'],
        ]);

        $params = $model->invocationParams([]);

        self::assertSame(['middle-out'], $params['transforms']);
        self::assertSame(['a', 'b'], $params['models']);
        self::assertSame('fallback', $params['route']);
        self::assertSame(['order' => ['OpenAI']], $params['provider']);
        self::assertSame('session-abc', $params['session_id']);
        self::assertSame(['trace_id' => 'trace-1', 'span_name' => 'summarize'], $params['trace']);
    }

    public function testOmitsSessionIdAndTraceWhenUnset(): void
    {
        $params = self::model()->invocationParams([]);

        self::assertArrayNotHasKey('session_id', $params);
        self::assertArrayNotHasKey('trace', $params);
    }

    public function testLoadsSessionIdFromEnvironment(): void
    {
        putenv('OPENROUTER_SESSION_ID=env-session-xyz');
        $model = self::model();

        $params = $model->invocationParams([]);

        self::assertSame('env-session-xyz', $model->sessionId);
        self::assertSame('env-session-xyz', $params['session_id']);
    }

    public function testPrefersExplicitSessionIdOverEnvironment(): void
    {
        putenv('OPENROUTER_SESSION_ID=env-session');
        $model = self::model(['sessionId' => 'explicit-session']);

        $params = $model->invocationParams([]);

        self::assertSame('explicit-session', $model->sessionId);
        self::assertSame('explicit-session', $params['session_id']);
    }

    public function testAllowsPerCallSessionIdAndTraceOverrides(): void
    {
        $constructorTrace = ['trace_id' => 'constructor-trace'];
        $callTrace = ['trace_id' => 'call-trace', 'span_name' => 'summarize'];
        $model = self::model(['sessionId' => 'constructor-session', 'trace' => $constructorTrace]);

        $params = $model->invocationParams(['sessionId' => 'call-session', 'trace' => $callTrace]);
        $fallback = $model->invocationParams([]);

        self::assertSame('call-session', $params['session_id']);
        self::assertSame($callTrace, $params['trace']);
        self::assertSame('constructor-session', $model->sessionId);
        self::assertSame($constructorTrace, $model->trace);
        self::assertSame('constructor-session', $fallback['session_id']);
        self::assertSame($constructorTrace, $fallback['trace']);
    }

    public function testTreatsEmptySessionIdAsUnset(): void
    {
        $params = self::model(['sessionId' => ''])->invocationParams([]);

        putenv('OPENROUTER_SESSION_ID=');
        $envParams = self::model()->invocationParams([]);

        self::assertArrayNotHasKey('session_id', $params);
        self::assertArrayNotHasKey('session_id', $envParams);
    }

    public function testIncludesPredictionOnlyWhenSet(): void
    {
        $model = self::model();

        self::assertArrayNotHasKey('prediction', $model->invocationParams([]));
        self::assertSame(
            ['type' => 'content', 'content' => 'hello'],
            $model->invocationParams(['prediction' => ['type' => 'content', 'content' => 'hello']])['prediction'],
        );
    }

    // ─── getLsParams ─────────────────────────────────────────────────

    public function testReturnsCorrectLangSmithMetadata(): void
    {
        $model = self::model(['temperature' => 0.3, 'maxTokens' => 256], null, 'anthropic/claude-4-sonnet');

        $ls = $model->getLsParams(['stop' => ['END']]);

        self::assertSame('openrouter', $ls['ls_provider']);
        self::assertSame('anthropic/claude-4-sonnet', $ls['ls_model_name']);
        self::assertSame('chat', $ls['ls_model_type']);
        self::assertSame(0.3, $ls['ls_temperature']);
        self::assertSame(256, $ls['ls_max_tokens']);
        self::assertSame(['END'], $ls['ls_stop']);
    }

    // ─── stream callbacks ────────────────────────────────────────────

    public function testPassesChunkViaHandleLlmNewTokenCallbackFields(): void
    {
        $chunk = ['id' => 'chatcmpl-1', 'choices' => [['index' => 0, 'delta' => ['content' => 'Hi'], 'finish_reason' => null]], 'model' => 'openai/gpt-4o-mini'];
        $http = new FakeHttpClient([], ['data: ' . json_encode($chunk) . "\n\n"]);
        $model = self::model(['streamUsage' => false], $http, 'openai/gpt-4o-mini');

        $handler = new class () extends BaseCallbackHandler {
            /** @var list<string> */
            public array $tokens = [];

            /** @var array<string, mixed>|null */
            public ?array $fields = null;

            public function handleLLMNewToken(string $token, array $idx, string $runId, ?string $parentRunId = null, array $tags = [], array $fields = []): void
            {
                $this->tokens[] = $token;
                $this->fields = $fields;
            }
        };

        foreach ($model->stream('Hello', new RunnableConfig(callbacks: [$handler])) as $_) {
            // consume the stream
        }

        self::assertSame(['Hi'], $handler->tokens);
        self::assertIsArray($handler->fields);
        self::assertSame('Hi', $handler->fields['chunk']->text);
    }

    // ─── retry behaviour ─────────────────────────────────────────────

    public function testRetriesA429WithShortRetryAfterThroughAsyncCaller(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::json(429, ['error' => ['message' => 'Rate limit exceeded', 'code' => 429]], [
                'Content-Type' => 'application/json',
                'retry-after' => '1',
            ]),
            FakeHttpClient::json(200, [
                'id' => 'chatcmpl-1',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Hello back'], 'finish_reason' => 'stop']],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ]),
        ]);
        $waits = [];
        $model = self::model([
            'maxRetries' => 1,
            'sleeper' => static function (int|float $ms) use (&$waits): void {
                $waits[] = $ms;
            },
        ], $http, 'openai/gpt-4o-mini');

        $result = $model->invoke('Hello');

        self::assertSame('Hello back', $result->content);
        self::assertCount(2, $http->requests);
        self::assertCount(1, $waits);
        self::assertGreaterThanOrEqual(1000, $waits[0], 'Retry-After is a floor on the backoff.');
    }

    public function testDoesNotRetryQuotaStyle429WithLongRetryAfter(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::json(429, ['error' => ['message' => 'Quota exhausted', 'code' => 429]], [
                'Content-Type' => 'application/json',
                'retry-after' => '120',
            ]),
        ]);
        $model = self::model(['maxRetries' => 2, 'sleeper' => static function (): void {
        }], $http, 'openai/gpt-4o-mini');

        try {
            $model->invoke('Hello');
            self::fail('Expected a rate limit error.');
        } catch (OpenRouterRateLimitError $e) {
            self::assertSame('OpenRouterRateLimitError', AsyncCaller::errorName($e));
            $meta = AsyncCaller::rateLimitMetadata($e);
            self::assertSame('stop', $meta['rateLimitType']);
            self::assertSame('quota_message', $meta['rateLimitReason']);
        }

        self::assertCount(1, $http->requests);
    }

    // ─── withStructuredOutput ────────────────────────────────────────

    /** @return array<string, mixed> */
    private static function nameSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['name' => ['type' => 'string']],
            'required' => ['name'],
        ];
    }

    public function testFunctionCallingWithValidOutputParsesCorrectly(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, self::completion('', self::toolCall('extract', ['name' => 'Claude'])))]);
        $structured = self::model([], $http)->withStructuredOutput(self::nameSchema(), ['method' => 'functionCalling']);

        self::assertSame(['name' => 'Claude'], $structured->invoke('What is your name?'));

        $body = $http->lastRequestBody();
        self::assertSame('extract', $body['tools'][0]['function']['name']);
        self::assertSame(self::nameSchema(), $body['tools'][0]['function']['parameters']);
        self::assertSame(['type' => 'function', 'function' => ['name' => 'extract']], $body['tool_choice']);
    }

    public function testFunctionCallingWithCustomName(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, self::completion('', self::toolCall('PersonInfo', ['name' => 'Alice'])))]);
        $structured = self::model([], $http)->withStructuredOutput(self::nameSchema(), ['method' => 'functionCalling', 'name' => 'PersonInfo']);

        self::assertSame(['name' => 'Alice'], $structured->invoke('Who is this?'));
        self::assertSame('PersonInfo', $http->lastRequestBody()['tools'][0]['function']['name']);
    }

    public function testFunctionCallingWithIncludeRawReturnsRawAndParsed(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, self::completion('', self::toolCall('extract', ['name' => 'Bob'])))]);
        $structured = self::model([], $http)->withStructuredOutput(self::nameSchema(), ['method' => 'functionCalling', 'includeRaw' => true]);

        $result = $structured->invoke('Tell me a name');

        self::assertArrayHasKey('raw', $result);
        self::assertArrayHasKey('parsed', $result);
        self::assertSame(['name' => 'Bob'], $result['parsed']);
    }

    public function testJsonModeWithValidOutputParsesCorrectly(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, self::completion('{"name": "Alice"}'))]);
        $structured = self::model([], $http)->withStructuredOutput(self::nameSchema(), ['method' => 'jsonMode']);

        self::assertSame(['name' => 'Alice'], $structured->invoke('What is your name?'));
        self::assertSame(['type' => 'json_object'], $http->lastRequestBody()['response_format']);
    }

    public function testJsonSchemaWithValidOutputParsesCorrectly(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, self::completion('{"name": "Eve"}'))]);
        $structured = self::model([], $http, 'openai/gpt-4o-mini')->withStructuredOutput(self::nameSchema(), ['method' => 'jsonSchema']);

        self::assertSame(['name' => 'Eve'], $structured->invoke('What is your name?'));

        $format = $http->lastRequestBody()['response_format'];
        self::assertSame('json_schema', $format['type']);
        self::assertSame('extract', $format['json_schema']['name']);
        self::assertSame(self::nameSchema(), $format['json_schema']['schema']);
    }

    public function testJsonSchemaIsRefusedForAModelWhoseProfileLacksIt(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not supported for model');

        self::model()->withStructuredOutput(self::nameSchema(), ['method' => 'jsonSchema']);
    }

    public function testStructuredOutputIsNotValidatedAgainstTheSchema(): void
    {
        // Upstream throws OutputParserException here (Standard Schema validation). This port has no
        // schema-validating parser, so a well-formed reply of the wrong shape comes back as parsed.
        $http = new FakeHttpClient([FakeHttpClient::json(200, self::completion('{"wrong_field": 123}'))]);
        $structured = self::model([], $http)->withStructuredOutput(self::nameSchema(), ['method' => 'jsonMode']);

        self::assertSame(['wrong_field' => 123], $structured->invoke('What is your name?'));
    }
}
