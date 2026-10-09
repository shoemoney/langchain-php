<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\XAI\Responses;

use LangChain\LanguageModels\Chat\XAI\ChatXAIResponses;
use LangChain\LanguageModels\Chat\XAI\Tools\CodeExecution;
use LangChain\LanguageModels\Chat\XAI\Tools\CollectionsSearch;
use LangChain\LanguageModels\Chat\XAI\Tools\WebSearch;
use LangChain\LanguageModels\Chat\XAI\Tools\XSearch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Ports `chat_models/tests/responses.test.ts` (54 tests).
 *
 * Differences from upstream, all forced by the language:
 *  - the serialized kwargs carry the PHP property names (`topP`, `maxOutputTokens`), and the API key is
 *    absent rather than replaced by a `secret` reference: the PHP serializer has no secret mechanism
 *    (see `ChatXAICompletionsTest`, which asserts the same on `ChatXAI`);
 *  - `lc_secrets` / `lc_aliases` are the static `lcSecrets()` / `lcAliases()`;
 *  - call options are passed as an array, with the wire spelling upstream uses.
 */
#[CoversClass(ChatXAIResponses::class)]
final class ChatXAIResponsesTest extends TestCase
{
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

    /** @return array<string, mixed> */
    private static function serialized(ChatXAIResponses $model): array
    {
        return (array) json_decode((string) json_encode($model), true);
    }

    // ---- serialization -----------------------------------------------------

    public function testSerialization(): void
    {
        putenv('XAI_API_KEY');
        $model = new ChatXAIResponses(['model' => 'grok-3', 'apiKey' => 'bar']);
        $serialized = self::serialized($model);

        self::assertSame(1, $serialized['lc']);
        self::assertSame('constructor', $serialized['type']);
        self::assertSame(['langchain', 'chat_models', 'xai', 'ChatXAIResponses'], $serialized['id']);
        self::assertSame('grok-3', $serialized['kwargs']['model']);
        self::assertArrayNotHasKey('apiKey', $serialized['kwargs']);
        self::assertStringNotContainsString('bar', (string) json_encode($model));
    }

    public function testSerializationWithNoParams(): void
    {
        $serialized = self::serialized(new ChatXAIResponses());

        self::assertSame(1, $serialized['lc']);
        self::assertSame('constructor', $serialized['type']);
        self::assertSame(['langchain', 'chat_models', 'xai', 'ChatXAIResponses'], $serialized['id']);
        self::assertArrayNotHasKey('apiKey', $serialized['kwargs']);
    }

    public function testSerializationWithCustomParams(): void
    {
        putenv('XAI_API_KEY');
        $model = new ChatXAIResponses([
            'model' => 'grok-3-mini', 'apiKey' => 'bar', 'temperature' => 0.5, 'topP' => 0.9, 'maxOutputTokens' => 1024,
        ]);
        $kwargs = self::serialized($model)['kwargs'];

        self::assertSame('grok-3-mini', $kwargs['model']);
        self::assertSame(0.5, $kwargs['temperature']);
        self::assertSame(0.9, $kwargs['topP']);
        self::assertSame(1024, $kwargs['maxOutputTokens']);
        self::assertArrayNotHasKey('apiKey', $kwargs);
    }

    public function testShouldThrowErrorWhenNoApiKeyIsProvided(): void
    {
        putenv('XAI_API_KEY');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/xAI API key not found/');

        new ChatXAIResponses();
    }

    // ---- constructor -------------------------------------------------------

    public function testShouldUseDefaultModelWhenNotSpecified(): void
    {
        self::assertSame('grok-3', (new ChatXAIResponses())->model);
    }

    public function testShouldUseCustomModelWhenSpecified(): void
    {
        self::assertSame('grok-3-mini', (new ChatXAIResponses(['model' => 'grok-3-mini']))->model);
    }

    public function testShouldAcceptModelShorthand(): void
    {
        self::assertSame('grok-3-mini', (new ChatXAIResponses('grok-3-mini'))->model);
    }

    public function testShouldUseDefaultBaseUrlWhenNotSpecified(): void
    {
        self::assertSame('https://api.x.ai/v1', (new ChatXAIResponses())->baseURL);
    }

    public function testShouldUseCustomBaseUrlWhenSpecified(): void
    {
        self::assertSame('https://custom.api.x.ai/v1', (new ChatXAIResponses(['baseURL' => 'https://custom.api.x.ai/v1']))->baseURL);
    }

    public function testShouldStoreTemperature(): void
    {
        self::assertSame(0.7, (new ChatXAIResponses(['temperature' => 0.7]))->temperature);
    }

    public function testShouldStoreTopP(): void
    {
        self::assertSame(0.9, (new ChatXAIResponses(['topP' => 0.9]))->topP);
    }

    public function testShouldStoreMaxOutputTokens(): void
    {
        self::assertSame(2048, (new ChatXAIResponses(['maxOutputTokens' => 2048]))->maxOutputTokens);
    }

    public function testShouldStoreStreamingOption(): void
    {
        self::assertTrue((new ChatXAIResponses(['streaming' => true]))->streaming);
    }

    public function testShouldDefaultStreamingToFalse(): void
    {
        self::assertFalse((new ChatXAIResponses())->streaming);
    }

    public function testShouldStoreUser(): void
    {
        self::assertSame('test-user', (new ChatXAIResponses(['user' => 'test-user']))->user);
    }

    public function testShouldStoreStoreOption(): void
    {
        self::assertTrue((new ChatXAIResponses(['store' => true]))->store);
    }

    // ---- searchParameters --------------------------------------------------

    public function testShouldStoreSearchParametersFromConstructor(): void
    {
        $searchParams = ['mode' => 'auto', 'max_search_results' => 5];

        self::assertSame($searchParams, (new ChatXAIResponses(['searchParameters' => $searchParams]))->searchParameters);
    }

    public function testShouldHaveNullSearchParametersByDefault(): void
    {
        self::assertNull((new ChatXAIResponses())->searchParameters);
    }

    public function testShouldSupportAllSearchModes(): void
    {
        foreach (['auto', 'on', 'off'] as $mode) {
            $model = new ChatXAIResponses(['searchParameters' => ['mode' => $mode]]);
            self::assertSame($mode, $model->searchParameters['mode']);
        }
    }

    // ---- reasoning ---------------------------------------------------------

    public function testShouldStoreReasoningFromConstructor(): void
    {
        $reasoning = ['effort' => 'medium', 'summary' => 'auto'];

        self::assertSame($reasoning, (new ChatXAIResponses(['reasoning' => $reasoning]))->reasoning);
    }

    public function testShouldHaveNullReasoningByDefault(): void
    {
        self::assertNull((new ChatXAIResponses())->reasoning);
    }

    public function testShouldSupportAllReasoningEffortLevels(): void
    {
        foreach (['low', 'medium', 'high'] as $effort) {
            $model = new ChatXAIResponses(['reasoning' => ['effort' => $effort]]);
            self::assertSame($effort, $model->reasoning['effort']);
        }
    }

    // ---- invocationParams --------------------------------------------------

    public function testInvocationParamsShouldReturnBasicParams(): void
    {
        $params = (new ChatXAIResponses([
            'model' => 'grok-3', 'temperature' => 0.5, 'topP' => 0.9, 'maxOutputTokens' => 1024,
        ]))->invocationParams([]);

        self::assertSame('grok-3', $params['model']);
        self::assertSame(0.5, $params['temperature']);
        self::assertSame(0.9, $params['top_p']);
        self::assertSame(1024, $params['max_output_tokens']);
    }

    public function testInvocationParamsShouldIncludeStreamFromInstance(): void
    {
        self::assertTrue((new ChatXAIResponses(['streaming' => true]))->invocationParams([])['stream']);
    }

    public function testInvocationParamsShouldIncludeUserAndStore(): void
    {
        $params = (new ChatXAIResponses(['user' => 'test-user', 'store' => true]))->invocationParams([]);

        self::assertSame('test-user', $params['user']);
        self::assertTrue($params['store']);
    }

    public function testInvocationParamsShouldIncludeSearchParametersFromInstance(): void
    {
        $params = (new ChatXAIResponses(['searchParameters' => ['mode' => 'auto', 'max_search_results' => 5]]))->invocationParams([]);

        self::assertSame(['mode' => 'auto', 'max_search_results' => 5], $params['search_parameters']);
    }

    public function testInvocationParamsShouldOverrideSearchParametersFromCallOptions(): void
    {
        $model = new ChatXAIResponses(['searchParameters' => ['mode' => 'auto', 'max_search_results' => 5]]);

        $params = $model->invocationParams(['search_parameters' => ['mode' => 'on', 'max_search_results' => 10]]);

        self::assertSame(['mode' => 'on', 'max_search_results' => 10], $params['search_parameters']);
    }

    public function testInvocationParamsShouldIncludeReasoningFromInstance(): void
    {
        $params = (new ChatXAIResponses(['reasoning' => ['effort' => 'high', 'summary' => 'auto']]))->invocationParams([]);

        self::assertSame(['effort' => 'high', 'summary' => 'auto'], $params['reasoning']);
    }

    public function testInvocationParamsShouldOverrideReasoningFromCallOptions(): void
    {
        $model = new ChatXAIResponses(['reasoning' => ['effort' => 'medium']]);

        $params = $model->invocationParams(['reasoning' => ['effort' => 'high', 'summary' => 'detailed']]);

        self::assertSame(['effort' => 'high', 'summary' => 'detailed'], $params['reasoning']);
    }

    public function testInvocationParamsShouldIncludePreviousResponseIdFromCallOptions(): void
    {
        self::assertSame('resp_123', (new ChatXAIResponses())->invocationParams(['previous_response_id' => 'resp_123'])['previous_response_id']);
    }

    public function testInvocationParamsShouldIncludeIncludeFromCallOptions(): void
    {
        $params = (new ChatXAIResponses())->invocationParams(['include' => ['reasoning.encrypted_content']]);

        self::assertSame(['reasoning.encrypted_content'], $params['include']);
    }

    public function testInvocationParamsShouldIncludeToolChoiceFromCallOptions(): void
    {
        self::assertSame('auto', (new ChatXAIResponses())->invocationParams(['tool_choice' => 'auto'])['tool_choice']);
    }

    public function testInvocationParamsShouldIncludeParallelToolCallsFromCallOptions(): void
    {
        self::assertFalse((new ChatXAIResponses())->invocationParams(['parallel_tool_calls' => false])['parallel_tool_calls']);
    }

    public function testInvocationParamsShouldIncludeToolsFromCallOptions(): void
    {
        $tools = [['type' => 'web_search'], ['type' => 'x_search']];

        self::assertSame($tools, (new ChatXAIResponses())->invocationParams(['tools' => $tools])['tools']);
    }

    public function testInvocationParamsShouldUseConstructorToolsWhenCallOptionsToolsNotProvided(): void
    {
        $constructorTools = [['type' => 'code_interpreter']];

        self::assertSame($constructorTools, (new ChatXAIResponses(['tools' => $constructorTools]))->invocationParams([])['tools']);
    }

    public function testInvocationParamsShouldOverrideConstructorToolsWithCallOptionsTools(): void
    {
        $model = new ChatXAIResponses(['tools' => [['type' => 'code_interpreter']]]);

        self::assertSame([['type' => 'web_search']], $model->invocationParams(['tools' => [['type' => 'web_search']]])['tools']);
    }

    public function testInvocationParamsOmitsUnsetMembersAndKeepsAnExplicitFalseStream(): void
    {
        self::assertSame(['model' => 'grok-3', 'stream' => false], (new ChatXAIResponses())->invocationParams([]));
    }

    public function testBoundOptionsReachTheRequestAndCallOptionsBeatThem(): void
    {
        $bound = (new ChatXAIResponses())->bindTools([['type' => 'web_search']], ['temperature' => 0.1, 'previous_response_id' => 'resp_bound']);

        $params = $bound->invocationParams(['temperature' => 0.9]);

        self::assertSame(0.9, $params['temperature']);
        self::assertSame('resp_bound', $params['previous_response_id']);
        self::assertSame([['type' => 'web_search']], $params['tools']);
    }

    // ---- tools -------------------------------------------------------------

    public function testShouldStoreToolsFromConstructor(): void
    {
        $tools = [
            ['type' => 'web_search'],
            ['type' => 'x_search', 'allowed_x_handles' => ['elonmusk']],
            ['type' => 'code_interpreter'],
            ['type' => 'file_search', 'vector_store_ids' => ['coll_123']],
        ];

        self::assertSame($tools, (new ChatXAIResponses(['tools' => $tools]))->tools);
    }

    public function testShouldHaveNullToolsByDefault(): void
    {
        self::assertNull((new ChatXAIResponses())->tools);
    }

    public function testShouldSupportWebSearchToolWithOptions(): void
    {
        $tool = ['type' => 'web_search', 'allowed_domains' => ['wikipedia.org'], 'enable_image_understanding' => true];

        self::assertSame($tool, (new ChatXAIResponses(['tools' => [$tool]]))->tools[0]);
    }

    public function testShouldSupportXSearchToolWithOptions(): void
    {
        $tool = [
            'type' => 'x_search', 'allowed_x_handles' => ['elonmusk', 'xai'], 'from_date' => '2024-01-01',
            'to_date' => '2024-12-31', 'enable_video_understanding' => true,
        ];

        self::assertSame($tool, (new ChatXAIResponses(['tools' => [$tool]]))->tools[0]);
    }

    // ---- metadata ----------------------------------------------------------

    public function testLlmTypeShouldReturnXaiResponses(): void
    {
        self::assertSame('xai-responses', (new ChatXAIResponses())->llmType());
    }

    public function testLcNameShouldReturnChatXAIResponses(): void
    {
        self::assertSame('ChatXAIResponses', ChatXAIResponses::lcName());
        self::assertSame('ChatXAIResponses', (new ChatXAIResponses())->getName());
    }

    public function testGetLsParamsShouldReturnCorrectLangSmithParams(): void
    {
        $lsParams = (new ChatXAIResponses(['model' => 'grok-3', 'temperature' => 0.7, 'maxOutputTokens' => 1024]))->getLsParams([]);

        self::assertSame('xai', $lsParams['ls_provider']);
        self::assertSame('grok-3', $lsParams['ls_model_name']);
        self::assertSame('chat', $lsParams['ls_model_type']);
        self::assertSame(0.7, $lsParams['ls_temperature']);
        self::assertSame(1024, $lsParams['ls_max_tokens']);
    }

    public function testToJsonShouldNotIncludeApiKey(): void
    {
        putenv('XAI_API_KEY');
        $json = (new ChatXAIResponses(['model' => 'grok-3', 'apiKey' => 'secret-key']))->toJson();

        self::assertArrayNotHasKey('apiKey', $json['kwargs']);
        self::assertStringNotContainsString('secret-key', (string) json_encode($json));
    }

    public function testLcSecretsShouldMapApiKeyToXaiApiKeyEnv(): void
    {
        self::assertSame(['apiKey' => 'XAI_API_KEY'], ChatXAIResponses::lcSecrets());
    }

    public function testLcAliasesShouldMapApiKeyToXaiApiKey(): void
    {
        self::assertSame(['apiKey' => 'xai_api_key'], ChatXAIResponses::lcAliases());
    }

    // ---- tool factories ----------------------------------------------------

    public function testShouldWorkWithXaiWebSearchFactory(): void
    {
        $model = new ChatXAIResponses(['tools' => [WebSearch::create([
            'allowedDomains' => ['wikipedia.org', 'github.com'], 'enableImageUnderstanding' => true,
        ])]]);

        self::assertCount(1, $model->tools);
        self::assertSame([
            'type' => WebSearch::TOOL_TYPE, 'allowed_domains' => ['wikipedia.org', 'github.com'], 'enable_image_understanding' => true,
        ], $model->tools[0]);
    }

    public function testShouldWorkWithXaiXSearchFactory(): void
    {
        $model = new ChatXAIResponses(['tools' => [XSearch::create([
            'allowedXHandles' => ['elonmusk', 'xai'], 'fromDate' => '2024-01-01', 'toDate' => '2024-12-31', 'enableVideoUnderstanding' => true,
        ])]]);

        self::assertCount(1, $model->tools);
        self::assertSame([
            'type' => XSearch::TOOL_TYPE, 'allowed_x_handles' => ['elonmusk', 'xai'], 'from_date' => '2024-01-01',
            'to_date' => '2024-12-31', 'enable_video_understanding' => true,
        ], $model->tools[0]);
    }

    public function testShouldWorkWithXaiCodeExecutionFactory(): void
    {
        $model = new ChatXAIResponses(['tools' => [CodeExecution::create()]]);

        self::assertSame([['type' => CodeExecution::TOOL_TYPE]], $model->tools);
    }

    public function testShouldWorkWithXaiCollectionsSearchFactory(): void
    {
        $model = new ChatXAIResponses(['tools' => [CollectionsSearch::create(['vectorStoreIds' => ['collection_abc123', 'collection_def456']])]]);

        self::assertSame([
            ['type' => CollectionsSearch::TOOL_TYPE, 'vector_store_ids' => ['collection_abc123', 'collection_def456']],
        ], $model->tools);
    }

    public function testShouldWorkWithMultipleToolFactoriesCombined(): void
    {
        $model = new ChatXAIResponses(['tools' => [
            WebSearch::create(['allowedDomains' => ['example.com']]),
            XSearch::create(['allowedXHandles' => ['xai']]),
            CodeExecution::create(),
            CollectionsSearch::create(['vectorStoreIds' => ['coll_1']]),
        ]]);

        self::assertSame(
            [WebSearch::TOOL_TYPE, XSearch::TOOL_TYPE, CodeExecution::TOOL_TYPE, CollectionsSearch::TOOL_TYPE],
            array_column($model->tools, 'type'),
        );
    }

    public function testInvocationParamsShouldIncludeToolsFromFactory(): void
    {
        $model = new ChatXAIResponses(['tools' => [WebSearch::create(['excludedDomains' => ['spam.com']])]]);

        $params = $model->invocationParams([]);

        self::assertCount(1, $params['tools']);
        self::assertSame(['type' => WebSearch::TOOL_TYPE, 'excluded_domains' => ['spam.com']], $params['tools'][0]);
    }

    public function testCallOptionsToolsShouldOverrideConstructorToolsFromFactories(): void
    {
        $model = new ChatXAIResponses(['tools' => [WebSearch::create()]]);

        $params = $model->invocationParams(['tools' => [XSearch::create(['allowedXHandles' => ['elonmusk']])]]);

        self::assertCount(1, $params['tools']);
        self::assertSame(XSearch::TOOL_TYPE, $params['tools'][0]['type']);
        self::assertSame(['elonmusk'], $params['tools'][0]['allowed_x_handles']);
    }

    public function testShouldWorkWithEmptyOptionsForFactories(): void
    {
        $model = new ChatXAIResponses(['tools' => [WebSearch::create(), XSearch::create(), CollectionsSearch::create()]]);

        self::assertCount(3, $model->tools);
        // Empty options leave only the type member.
        self::assertSame(['type' => WebSearch::TOOL_TYPE], $model->tools[0]);
        self::assertSame(['type' => XSearch::TOOL_TYPE], $model->tools[1]);
        self::assertSame(['type' => CollectionsSearch::TOOL_TYPE], $model->tools[2]);
    }
}
