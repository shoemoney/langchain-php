<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\XAI;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAICompletions;
use LangChain\LanguageModels\Chat\XAI\ChatXAI;
use LangChain\LanguageModels\Chat\XAI\Tools\LiveSearch as LiveSearchTool;
use LangChain\Messages\AIMessage;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Ports `chat_models/tests/completions.test.ts` (20 tests).
 *
 * `baseURL configuration` asserts on `clientConfig.baseURL` upstream; the port has no OpenAI client
 * object, so the same fact is asserted on `$baseUrl` and on the URL a request actually goes to.
 */
#[CoversClass(ChatXAI::class)]
final class ChatXAICompletionsTest extends TestCase
{
    private const TEST_MODEL = 'grok-3-fast';

    private string|false $previousKey;

    protected function setUp(): void
    {
        $this->previousKey = getenv('XAI_API_KEY');
        putenv('XAI_API_KEY=foo');
    }

    protected function tearDown(): void
    {
        putenv($this->previousKey === false ? 'XAI_API_KEY' : 'XAI_API_KEY=' . $this->previousKey);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private static function effective(ChatXAI $model, array $options): ?array
    {
        return (new \ReflectionMethod($model, 'getEffectiveSearchParameters'))->invoke($model, $options);
    }

    /**
     * @param list<mixed>|null $tools
     */
    private static function hasBuiltIn(ChatXAI $model, ?array $tools): bool
    {
        return (new \ReflectionMethod($model, 'hasBuiltInTools'))->invoke($model, $tools);
    }

    // ---- baseURL configuration ---------------------------------------------

    public function testShouldUseDefaultBaseUrlWhenNotSpecified(): void
    {
        $model = new ChatXAI(['httpClient' => new FakeHttpClient([FakeHttpClient::json(200, self::completion())])]);

        self::assertSame('https://api.x.ai/v1', $model->baseUrl);

        $model->invoke('hi');
        self::assertSame('https://api.x.ai/v1/chat/completions', $model->httpClient->requests[0]['url']);
    }

    public function testShouldUseCustomBaseUrlWhenProvided(): void
    {
        $model = new ChatXAI([
            'baseURL' => 'https://custom.api.example.com/v1',
            'httpClient' => new FakeHttpClient([FakeHttpClient::json(200, self::completion())]),
        ]);

        self::assertSame('https://custom.api.example.com/v1', $model->baseUrl);

        $model->invoke('hi');
        self::assertSame('https://custom.api.example.com/v1/chat/completions', $model->httpClient->requests[0]['url']);
    }

    // ---- Serialization -----------------------------------------------------

    public function testSerialization(): void
    {
        putenv('XAI_API_KEY');
        $model = new ChatXAI(['model' => self::TEST_MODEL, 'apiKey' => 'bar']);

        self::assertSame(
            '{"lc":1,"type":"constructor","id":["langchain","chat_models","xai","ChatXAI"],"kwargs":{"model":"' . self::TEST_MODEL . '"}}',
            json_encode($model),
        );
    }

    public function testSerializationWithNoParams(): void
    {
        $model = new ChatXAI();

        self::assertSame(
            '{"lc":1,"type":"constructor","id":["langchain","chat_models","xai","ChatXAI"],"kwargs":{"model":"grok-3-fast"}}',
            json_encode($model),
        );
    }

    public function testSerializationWithModelShorthand(): void
    {
        $model = new ChatXAI(self::TEST_MODEL);

        self::assertSame(
            '{"lc":1,"type":"constructor","id":["langchain","chat_models","xai","ChatXAI"],"kwargs":{"model":"' . self::TEST_MODEL . '"}}',
            json_encode($model),
        );
    }

    // ---- Server Tool Calling / isXAIBuiltInTool ----------------------------

    public function testShouldIdentifyLiveSearchAsABuiltInTool(): void
    {
        self::assertTrue(ChatXAI::isXAIBuiltInTool([
            'name' => LiveSearchTool::TOOL_NAME,
            'type' => LiveSearchTool::TOOL_TYPE,
        ]));
    }

    public function testShouldNotIdentifyFunctionToolsAsBuiltIn(): void
    {
        self::assertFalse(ChatXAI::isXAIBuiltInTool([
            'type' => 'function',
            'function' => [
                'name' => 'get_weather',
                'description' => 'Get the weather',
                'parameters' => ['type' => 'object', 'properties' => []],
            ],
        ]));
    }

    public function testShouldNotIdentifyInvalidObjectsAsBuiltIn(): void
    {
        self::assertFalse(ChatXAI::isXAIBuiltInTool(null));
        self::assertFalse(ChatXAI::isXAIBuiltInTool([]));
        self::assertFalse(ChatXAI::isXAIBuiltInTool(['type' => 'other']));
    }

    // ---- ChatXAI with searchParameters -------------------------------------

    public function testShouldStoreSearchParametersFromConstructor(): void
    {
        $searchParams = ['mode' => 'auto', 'max_search_results' => 5];
        $model = new ChatXAI(['searchParameters' => $searchParams]);

        self::assertSame($searchParams, $model->searchParameters);
    }

    public function testShouldHaveNullSearchParametersByDefault(): void
    {
        self::assertNull((new ChatXAI())->searchParameters);
    }

    public function testShouldMergeSearchParametersCorrectly(): void
    {
        $model = new ChatXAI(['searchParameters' => ['mode' => 'auto', 'max_search_results' => 5]]);

        $effective = self::effective($model, ['searchParameters' => ['max_search_results' => 10, 'from_date' => '2024-01-01']]);

        self::assertEquals(['mode' => 'auto', 'max_search_results' => 10, 'from_date' => '2024-01-01'], $effective);
    }

    // ---- invocationParams with server tools --------------------------------

    public function testShouldAddSearchParametersWhenLiveSearchToolIsBound(): void
    {
        $params = (new ChatXAI())->invocationParams([
            'tools' => [['type' => LiveSearchTool::TOOL_TYPE, 'name' => LiveSearchTool::TOOL_NAME]],
        ]);

        self::assertArrayHasKey('search_parameters', $params);
        self::assertSame('auto', $params['search_parameters']['mode']);
    }

    public function testShouldAddSearchParametersFromCallOptions(): void
    {
        $params = (new ChatXAI())->invocationParams([
            'searchParameters' => ['mode' => 'on', 'max_search_results' => 10, 'from_date' => '2024-01-01'],
        ]);

        self::assertSame(['mode' => 'on', 'max_search_results' => 10, 'from_date' => '2024-01-01'], $params['search_parameters']);
    }

    public function testShouldIncludeSourcesInSearchParametersWhenProvided(): void
    {
        $sources = [
            ['type' => 'web', 'allowed_websites' => ['x.ai']],
            ['type' => 'news', 'excluded_websites' => ['bbc.co.uk']],
            ['type' => 'x', 'included_x_handles' => ['xai']],
            ['type' => 'rss', 'links' => ['https://example.com/feed.rss']],
        ];

        $params = (new ChatXAI())->invocationParams(['searchParameters' => ['mode' => 'on', 'sources' => $sources]]);

        self::assertSame($sources, $params['search_parameters']['sources']);
    }

    public function testShouldOmitSourcesFieldWhenNoneAreConfigured(): void
    {
        $params = (new ChatXAI())->invocationParams(['searchParameters' => ['mode' => 'auto']]);

        self::assertSame(['mode' => 'auto'], $params['search_parameters']);
        self::assertArrayNotHasKey('sources', $params['search_parameters']);
    }

    public function testShouldMergeInstanceAndCallOptionSearchParameters(): void
    {
        $model = new ChatXAI(['searchParameters' => ['mode' => 'auto', 'max_search_results' => 5, 'return_citations' => true]]);

        $params = $model->invocationParams(['searchParameters' => ['max_search_results' => 10]]);

        self::assertEquals(['mode' => 'auto', 'max_search_results' => 10, 'return_citations' => true], $params['search_parameters']);
    }

    public function testShouldNotAddSearchParametersWhenNoSearchConfigIsPresent(): void
    {
        self::assertArrayNotHasKey('search_parameters', (new ChatXAI())->invocationParams([]));
    }

    // ---- _hasBuiltInTools --------------------------------------------------

    public function testShouldReturnTrueWhenLiveSearchToolIsPresent(): void
    {
        $result = self::hasBuiltIn(new ChatXAI(), [
            ['type' => LiveSearchTool::TOOL_TYPE, 'name' => LiveSearchTool::TOOL_NAME],
            ['type' => 'function', 'function' => ['name' => 'test', 'parameters' => []]],
        ]);

        self::assertTrue($result);
    }

    public function testShouldReturnFalseWhenNoBuiltInToolsArePresent(): void
    {
        $result = self::hasBuiltIn(new ChatXAI(), [
            ['type' => 'function', 'function' => ['name' => 'test', 'parameters' => []]],
        ]);

        self::assertFalse($result);
    }

    public function testShouldReturnFalseForNullOrEmptyTools(): void
    {
        self::assertFalse(self::hasBuiltIn(new ChatXAI(), null));
        self::assertFalse(self::hasBuiltIn(new ChatXAI(), []));
    }

    // ---- the PHP-side contract of the same class ---------------------------

    public function testItIsAChatCompletionsClientNamedForXai(): void
    {
        $model = new ChatXAI();

        self::assertInstanceOf(ChatOpenAICompletions::class, $model);
        self::assertSame('xai', $model->llmType());
        self::assertSame('ChatXAI', $model->getName());
        self::assertSame(['langchain', 'chat_models', 'xai', 'ChatXAI'], ChatXAI::lcId());
    }

    public function testAMissingApiKeyIsRefusedWithTheUpstreamMessage(): void
    {
        putenv('XAI_API_KEY');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('xAI API key not found. Please set the XAI_API_KEY environment variable');

        new ChatXAI();
    }

    public function testAnEmptyApiKeyFallsBackToTheEnvironment(): void
    {
        $model = new ChatXAI(['apiKey' => '']);

        self::assertSame('foo', $model->apiKey);
    }

    public function testTheApiKeyIsNotSerialized(): void
    {
        self::assertStringNotContainsString('bar-secret', (string) json_encode(new ChatXAI(['apiKey' => 'bar-secret'])));
    }

    public function testTheKeyGoesOutAsABearerToken(): void
    {
        $model = new ChatXAI(['apiKey' => 'xai-123', 'httpClient' => new FakeHttpClient([FakeHttpClient::json(200, self::completion())])]);

        $result = $model->invoke('hi');

        self::assertInstanceOf(AIMessage::class, $result);
        self::assertSame('Bearer xai-123', $model->httpClient->requests[0]['headers']['Authorization']);
    }

    /** @return array<string, mixed> */
    private static function completion(): array
    {
        return [
            'id' => 'chatcmpl-1', 'model' => 'grok-3-fast',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Hi.'], 'finish_reason' => 'stop']],
            'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 1, 'total_tokens' => 4],
        ];
    }
}
