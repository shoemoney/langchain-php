<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Universal;

use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\LanguageModels\Chat\Universal\ConfigurableModel;
use LangChain\LanguageModels\Chat\Universal\ConfigurableModelInterface;
use LangChain\LanguageModels\Chat\Universal\InitChatModel;
use LangChain\Messages\AIMessage;
use LangChain\Runnables\RunnableBinding;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\BaseCallbackHandler;
use LangChain\Tracers\Serialized;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of the configuration cases of `universal.int.test.ts`: non-configurable init, partially and fully
 * configurable models, `bindTools`, `withStructuredOutput` and `withConfig` (each with the "does not mutate
 * the original" checks) and `Serialization`. Each provider call is answered by a scripted transport.
 */
#[CoversClass(InitChatModel::class)]
#[CoversClass(ConfigurableModel::class)]
final class UniversalConfigurableTest extends TestCase
{
    private const OPENAI_KEY = 'sk-test';

    // ---- Initialize non-configurable models ------------------------------------------------------

    public function testInitializeNonConfigurableModels(): void
    {
        $gptHttp = UniversalFixtures::openAiHttp('I am GPT.');
        $claudeHttp = UniversalFixtures::anthropicHttp('I am Claude.');

        $gpt4 = InitChatModel::init('gpt-4o-mini', [
            'modelProvider' => 'openai',
            'temperature' => 0.25, // Funky temperature to verify it's being set properly.
            'apiKey' => self::OPENAI_KEY,
            'httpClient' => $gptHttp,
        ]);
        $claude = InitChatModel::init('claude-3-opus-20240229', [
            'modelProvider' => 'anthropic',
            'temperature' => 0.25,
            'apiKey' => 'sk-ant-test',
            'httpClient' => $claudeHttp,
        ]);

        $gpt4Result = $gpt4->invoke("what's your name");
        self::assertNotSame('', $gpt4Result->content);
        self::assertSame(0.25, $gptHttp->lastRequestBody()['temperature']);
        self::assertSame('gpt-4o-mini', $gptHttp->lastRequestBody()['model']);

        $claudeResult = $claude->invoke("what's your name");
        self::assertNotSame('', $claudeResult->content);
        self::assertSame(0.25, $claudeHttp->lastRequestBody()['temperature']);
        self::assertSame('claude-3-opus-20240229', $claudeHttp->lastRequestBody()['model']);
    }

    public function testTheConfigurableModelIsAChatModelThatKnowsItsOwnInterface(): void
    {
        $model = InitChatModel::init('gpt-4o-mini', ['apiKey' => self::OPENAI_KEY]);

        self::assertInstanceOf(\LangChain\LanguageModels\BaseChatModel::class, $model);
        self::assertInstanceOf(ConfigurableModelInterface::class, $model);
        self::assertSame('chat_model', $model->llmType());
        self::assertSame('langchain_init_chat_model', $model->metadata['ls_integration']);
        self::assertSame([], $model->getQueuedMethodOperations());
    }

    // ---- Create a partially configurable model with no default model -----------------------------

    public function testCreateAPartiallyConfigurableModelWithNoDefaultModel(): void
    {
        $configurableModel = InitChatModel::init(null, [
            'temperature' => 0,
            'configurableFields' => ['model', 'apiKey', 'httpClient'],
        ]);
        $openAi = UniversalFixtures::openAiHttp('I am GPT.');
        $anthropic = UniversalFixtures::anthropicHttp('I am Claude.');

        $gpt4Result = $configurableModel->invoke("what's your name", new RunnableConfig(configurable: [
            'model' => 'gpt-4o-mini',
            'apiKey' => self::OPENAI_KEY,
            'httpClient' => $openAi,
        ]));
        self::assertSame('I am GPT.', $gpt4Result->content);
        self::assertSame('gpt-4o-mini', $openAi->lastRequestBody()['model']);

        $claudeResult = $configurableModel->invoke("what's your name", new RunnableConfig(configurable: [
            'model' => 'claude-sonnet-4-5-20250929',
            'apiKey' => 'sk-ant-test',
            'httpClient' => $anthropic,
        ]));
        self::assertSame('I am Claude.', $claudeResult->content);
        self::assertSame('claude-sonnet-4-5-20250929', $anthropic->lastRequestBody()['model']);
        self::assertSame(0, $anthropic->lastRequestBody()['temperature'], 'the default temperature rides along to the overriding provider');
    }

    public function testNothingIsBuiltUntilACallSuppliesAModelWhenThereIsNoDefault(): void
    {
        $model = InitChatModel::init(null, ['configurableFields' => ['model']]);

        self::assertFalse($model->hasDefaultModelInstance());
        self::assertSame([], $model->profile());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to infer model provider for { model: undefined }');
        $model->invoke('hi');
    }

    public function testOnlyTheListedFieldsCanBeOverriddenAtRunTime(): void
    {
        $http = UniversalFixtures::openAiHttp('hi');
        $model = InitChatModel::init('gpt-4o-mini', [
            'apiKey' => self::OPENAI_KEY,
            'httpClient' => $http,
            'temperature' => 0,
            'configurableFields' => ['model'],
        ]);

        $model->invoke('hi', new RunnableConfig(configurable: ['model' => 'gpt-4o', 'temperature' => 1.0, 'apiKey' => 'attacker']));

        self::assertSame('gpt-4o', $http->lastRequestBody()['model']);
        self::assertSame(0, $http->lastRequestBody()['temperature']);
        self::assertSame('Bearer ' . self::OPENAI_KEY, $http->requests[0]['headers']['Authorization']);
    }

    // ---- Create a fully configurable model with a default model and a config prefix -------------

    public function testCreateAFullyConfigurableModelWithADefaultModelAndAConfigPrefix(): void
    {
        $model = InitChatModel::init('gpt-4o-mini', [
            'modelProvider' => 'openai',
            'configurableFields' => 'any',
            'configPrefix' => 'foo',
            'temperature' => 0,
        ]);
        $openAi = UniversalFixtures::openAiHttp('I am GPT.');
        $anthropic = UniversalFixtures::anthropicHttp('I am Claude.');

        $configurableResult = $model->invoke("what's your name", new RunnableConfig(configurable: [
            'foo_apiKey' => self::OPENAI_KEY,
            'foo_httpClient' => $openAi,
        ]));
        self::assertSame('I am GPT.', $configurableResult->content);
        self::assertSame('gpt-4o-mini', $openAi->lastRequestBody()['model']);

        $configurableResult2 = $model->invoke("what's your name", new RunnableConfig(configurable: [
            'foo_model' => 'claude-sonnet-4-5-20250929',
            'foo_modelProvider' => 'anthropic',
            'foo_temperature' => 0.6,
            'foo_apiKey' => 'sk-ant-test',
            'foo_httpClient' => $anthropic,
        ]));
        self::assertSame('I am Claude.', $configurableResult2->content);
        self::assertSame(0.6, $anthropic->lastRequestBody()['temperature']);
        self::assertInstanceOf(ChatAnthropic::class, $model->getModelInstance(new RunnableConfig(configurable: [
            'foo_modelProvider' => 'anthropic',
            'foo_apiKey' => 'x',
        ])));
    }

    public function testKeysWithoutThePrefixAreNotModelParameters(): void
    {
        $model = InitChatModel::init('gpt-4o-mini', ['configurableFields' => 'any', 'configPrefix' => 'foo', 'apiKey' => self::OPENAI_KEY]);

        self::assertSame(
            ['model' => 'gpt-4o', 'temperature' => 0.5],
            $model->modelParams(new RunnableConfig(configurable: ['foo_model' => 'gpt-4o', 'foo_temperature' => 0.5, 'thread_id' => 't1', 'model' => 'ignored'])),
        );
    }

    public function testAConfigPrefixGetsATrailingUnderscore(): void
    {
        self::assertSame('foo_', InitChatModel::init('gpt-4o-mini', ['configurableFields' => 'any', 'configPrefix' => 'foo', 'apiKey' => 'k'])->configPrefix);
        self::assertSame('foo_', InitChatModel::init('gpt-4o-mini', ['configurableFields' => 'any', 'configPrefix' => 'foo_', 'apiKey' => 'k'])->configPrefix);
        self::assertSame('', InitChatModel::init('gpt-4o-mini', ['apiKey' => 'k'])->configPrefix);
    }

    public function testAConfigPrefixWithoutConfigurableFieldsWarns(): void
    {
        $warnings = [];
        set_error_handler(static function (int $errno, string $message) use (&$warnings): bool {
            $warnings[] = $message;

            return true;
        }, E_USER_WARNING);

        try {
            InitChatModel::init('gpt-4o-mini', ['configPrefix' => 'foo', 'apiKey' => 'k']);
        } finally {
            restore_error_handler();
        }

        self::assertCount(1, $warnings);
        self::assertStringContainsString('{ configPrefix: foo } has been set but no fields are configurable.', $warnings[0]);
    }

    // ---- Bind tools to a configurable model -------------------------------------------------------

    public function testBindToolsToAConfigurableModel(): void
    {
        $openAi = new FakeHttpClient([FakeHttpClient::json(200, UniversalFixtures::completion('', UniversalFixtures::openAiToolCall('GetWeather', ['location' => 'LA'])))]);
        $anthropic = new FakeHttpClient([FakeHttpClient::json(200, UniversalFixtures::anthropicMessage('', ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'GetWeather', 'input' => ['location' => 'LA']]))]);

        $configurableModel = InitChatModel::init('gpt-4o-mini', [
            'configurableFields' => ['model', 'modelProvider', 'apiKey', 'httpClient'],
            'temperature' => 0,
            'apiKey' => self::OPENAI_KEY,
            'httpClient' => $openAi,
        ]);

        $withTools = $configurableModel->bindTools([UniversalFixtures::weatherTool(), UniversalFixtures::populationTool()]);

        $configurableToolResult = $withTools->invoke('Which city is hotter today and which is bigger: LA or NY?', new RunnableConfig(configurable: [
            'apiKey' => self::OPENAI_KEY,
        ]));
        self::assertInstanceOf(AIMessage::class, $configurableToolResult);
        self::assertSame('GetWeather', $configurableToolResult->toolCalls[0]['name']);
        self::assertSame(['GetWeather', 'GetPopulation'], array_column(array_column($openAi->lastRequestBody()['tools'], 'function'), 'name'));

        $configurableToolResult2 = $withTools->invoke('Which city is hotter today and which is bigger: LA or NY?', new RunnableConfig(configurable: [
            'model' => 'claude-sonnet-4-5-20250929',
            'apiKey' => 'sk-ant-test',
            'httpClient' => $anthropic,
        ]));
        self::assertSame('GetWeather', $configurableToolResult2->toolCalls[0]['name']);
        self::assertSame(['GetWeather', 'GetPopulation'], array_column($anthropic->lastRequestBody()['tools'], 'name'));
    }

    public function testCanCallBindTools(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, UniversalFixtures::completion('', UniversalFixtures::openAiToolCall('GetWeather', ['location' => 'San Francisco'])))]);
        $gpt4 = InitChatModel::init(null, ['modelProvider' => 'openai', 'temperature' => 0.25, 'apiKey' => self::OPENAI_KEY, 'httpClient' => $http]);

        $result = $gpt4->bindTools([UniversalFixtures::weatherTool()])->invoke("What's the weather in San Francisco?");

        self::assertNotEmpty($result->toolCalls);
        self::assertSame('GetWeather', $result->toolCalls[0]['name']);
        self::assertSame(['location' => 'San Francisco'], $result->toolCalls[0]['args']);
    }

    public function testBindToolsDoesNotMutateTheOriginalModelInstance(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::json(200, UniversalFixtures::completion('', UniversalFixtures::openAiToolCall('GetWeather', ['location' => 'SF']))),
            FakeHttpClient::json(200, UniversalFixtures::completion('2 + 2 = 4')),
        ]);
        $originalModel = InitChatModel::init('gpt-4o-mini', ['modelProvider' => 'openai', 'temperature' => 0, 'apiKey' => self::OPENAI_KEY, 'httpClient' => $http]);

        $modelWithTools = $originalModel->bindTools([UniversalFixtures::weatherTool()]);

        $toolResult = $modelWithTools->invoke("What's the weather in San Francisco?");
        self::assertSame('GetWeather', $toolResult->toolCalls[0]['name']);
        self::assertArrayHasKey('tools', $http->lastRequestBody());

        $originalResult = $originalModel->invoke('What is 2 + 2?');
        self::assertSame('2 + 2 = 4', $originalResult->content);
        self::assertSame([], $originalResult->toolCalls);
        self::assertArrayNotHasKey('tools', $http->lastRequestBody());
        self::assertSame([], $originalModel->getQueuedMethodOperations());
        self::assertNotSame($originalModel, $modelWithTools);
    }

    // ---- withStructuredOutput ---------------------------------------------------------------------

    private static function structuredReply(string $name, array $args): FakeHttpClient
    {
        return new FakeHttpClient([FakeHttpClient::json(200, UniversalFixtures::completion('', UniversalFixtures::openAiToolCall($name, $args)))]);
    }

    public function testCanCallWithStructuredOutput(): void
    {
        $http = self::structuredReply('GetWeather', ['location' => 'San Francisco, CA']);
        $gpt4 = InitChatModel::init(null, ['modelProvider' => 'openai', 'temperature' => 0.25, 'apiKey' => self::OPENAI_KEY, 'httpClient' => $http]);
        $weatherSchema = [
            'type' => 'object',
            'description' => 'Get the current weather in a given location',
            'properties' => ['location' => ['type' => 'string', 'description' => 'The city and state, e.g. San Francisco, CA']],
            'required' => ['location'],
        ];

        $result = $gpt4->withStructuredOutput($weatherSchema, ['name' => 'GetWeather'])->invoke("What's the weather in San Francisco?");

        self::assertSame(['location' => 'San Francisco, CA'], $result);
        self::assertSame('GetWeather', $http->lastRequestBody()['tools'][0]['function']['name']);
    }

    public function testWithStructuredOutputDoesNotMutateTheOriginalModelInstance(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::json(200, UniversalFixtures::completion('', UniversalFixtures::openAiToolCall('extract', ['answer' => '4']))),
            FakeHttpClient::json(200, UniversalFixtures::completion('The answer is 4.')),
        ]);
        $originalModel = InitChatModel::init('gpt-4o-mini', ['modelProvider' => 'openai', 'temperature' => 0, 'apiKey' => self::OPENAI_KEY, 'httpClient' => $http]);
        $schema = ['type' => 'object', 'properties' => ['answer' => ['type' => 'string', 'description' => 'The answer to the question']], 'required' => ['answer']];

        $structuredModel = $originalModel->withStructuredOutput($schema);

        $structuredResult = $structuredModel->invoke('What is 2 + 2?');
        self::assertIsString($structuredResult['answer']);

        $originalResult = $originalModel->invoke('What is 2 + 2?');
        self::assertInstanceOf(AIMessage::class, $originalResult);
        self::assertSame('The answer is 4.', $originalResult->content);
        self::assertArrayNotHasKey('tools', $http->lastRequestBody());
        self::assertSame([], $originalModel->getQueuedMethodOperations());
        self::assertSame(['withStructuredOutput'], array_keys($structuredModel->getQueuedMethodOperations()));
    }

    public function testWithStructuredOutputKeepsEarlierQueuedOperations(): void
    {
        $model = InitChatModel::init('gpt-4o-mini', ['apiKey' => self::OPENAI_KEY]);

        $queued = $model->bindTools([UniversalFixtures::weatherTool()], ['tool_choice' => 'GetWeather'])
            ->withStructuredOutput(['type' => 'object'])
            ->getQueuedMethodOperations();

        self::assertSame(['bindTools', 'withStructuredOutput'], array_keys($queued));
        self::assertSame(['tool_choice' => 'GetWeather'], $queued['bindTools'][1]);
    }

    // ---- can call withConfig with tools -----------------------------------------------------------

    public function testCanCallWithConfigWithTools(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, UniversalFixtures::completion('', UniversalFixtures::openAiToolCall('GetWeather', ['location' => 'x'])))]);
        $weatherTool = UniversalFixtures::weatherTool();
        $openaiModel = InitChatModel::init('gpt-4o-mini', ['temperature' => 0, 'apiKey' => self::OPENAI_KEY, 'httpClient' => $http]);

        $modelWithTools = $openaiModel->bindTools([$weatherTool], ['tool_choice' => 'GetWeather']);
        self::assertArrayHasKey('bindTools', $modelWithTools->getQueuedMethodOperations());
        self::assertSame($weatherTool, $modelWithTools->getQueuedMethodOperations()['bindTools'][0][0]);
        self::assertSame('GetWeather', $modelWithTools->getQueuedMethodOperations()['bindTools'][0][0]->name);

        $modelWithConfig = $modelWithTools->withConfig(['runName' => 'weather']);

        self::assertInstanceOf(RunnableBinding::class, $modelWithConfig);
        self::assertInstanceOf(ConfigurableModel::class, $modelWithConfig->bound);
        self::assertArrayHasKey('bindTools', $modelWithConfig->bound->getQueuedMethodOperations());
        self::assertSame('GetWeather', $modelWithConfig->bound->getQueuedMethodOperations()['bindTools'][0][0]->name);
        self::assertSame('weather', $modelWithConfig->config['runName']);

        $result = $modelWithConfig->invoke("What's 8x8?");
        self::assertSame('GetWeather', $result->toolCalls[0]['name']);
        self::assertSame(['type' => 'function', 'function' => ['name' => 'GetWeather']], $http->lastRequestBody()['tool_choice']);
    }

    public function testWithConfigFoldsTheModelsOwnConfigurableEntriesIntoTheDefaults(): void
    {
        $model = InitChatModel::init('gpt-4o-mini', ['configurableFields' => 'any', 'configPrefix' => 'foo', 'apiKey' => 'k']);

        $binding = $model->withConfig(['configurable' => ['foo_model' => 'gpt-4o', 'thread_id' => 't1']]);

        self::assertSame('gpt-4o', $binding->bound->defaultConfig['model']);
        self::assertArrayNotHasKey('thread_id', $binding->bound->defaultConfig);
        self::assertSame('gpt-4o-mini', $model->defaultConfig['model'], 'the receiver is untouched');
        self::assertSame(['configurable' => ['foo_model' => 'gpt-4o', 'thread_id' => 't1']], $binding->config);
    }

    // ---- caching ----------------------------------------------------------------------------------

    public function testOneClientIsBuiltPerDistinctConfiguration(): void
    {
        $model = InitChatModel::init('gpt-4o-mini', ['configurableFields' => ['model'], 'apiKey' => 'k']);

        $a = $model->getModelInstance();
        $b = $model->getModelInstance(new RunnableConfig(tags: ['x']));
        $c = $model->getModelInstance(new RunnableConfig(configurable: ['model' => 'gpt-4o']));
        $d = $model->getModelInstance(new RunnableConfig(configurable: ['model' => 'gpt-4o', '__pregel_task_id' => 'volatile']));

        self::assertNotSame($a, $b, 'upstream keys the whole config, so tags split the cache');
        self::assertSame($a, $model->getModelInstance(new RunnableConfig()));
        self::assertNotSame($a, $c);
        self::assertSame($c, $d, 'Pregel-injected keys do not split the cache');
    }

    // ---- Serialization ------------------------------------------------------------------------------

    public function testDoesNotContainAdditionalFields(): void
    {
        $http = UniversalFixtures::openAiHttp('I am GPT.');
        $fields = ['model' => 'gpt-4o-mini', 'temperature' => 0.25, 'apiKey' => self::OPENAI_KEY, 'httpClient' => $http];
        $gpt4 = InitChatModel::init('gpt-4o-mini', ['modelProvider' => 'openai'] + $fields);

        $handler = new class () extends BaseCallbackHandler {
            public ?Serialized $serialized = null;

            public function handleChatModelStart(
                Serialized $llm,
                array $messages,
                string $runId,
                ?string $parentRunId = null,
                array $extraParams = [],
                array $tags = [],
                array $metadata = [],
                ?string $runName = null,
            ): void {
                $this->serialized = $llm;
            }
        };

        $res = $gpt4->invoke('foo', new RunnableConfig(callbacks: [$handler], configurable: ['extra' => 'bar']));

        self::assertNotNull($res);
        self::assertNotNull($handler->serialized);
        $expected = (new ChatOpenAI($fields))->toJson();
        self::assertSame($expected['id'], $handler->serialized->id);
        self::assertSame($expected['kwargs'], $handler->serialized->kwargs);
    }

    // ---- Cache key and call options ----------------------------------------------------------------

    public function testCacheKeyStringifiesTheWholeConfigAndSkipsPregelKeys(): void
    {
        $model = new ConfigurableModel(['defaultConfig' => ['model' => 'gpt-4o', 'apiKey' => 'x']]);

        self::assertSame($model->getCacheKey(null), $model->getCacheKey(new RunnableConfig()));
        self::assertNotSame($model->getCacheKey(null), $model->getCacheKey(new RunnableConfig(tags: ['t1'])));
        self::assertNotSame(
            $model->getCacheKey(new RunnableConfig(tags: ['t1'])),
            $model->getCacheKey(new RunnableConfig(tags: ['t2'])),
        );
        self::assertSame(
            $model->getCacheKey(new RunnableConfig(configurable: ['a' => 1])),
            $model->getCacheKey(new RunnableConfig(configurable: ['a' => 1, '__pregel_send' => 'x'])),
        );
        self::assertSame($model->getCacheKey(null), $model->getCacheKey(new RunnableConfig(configurable: ['__pregel_send' => 'x'])));
    }

    public function testCallbacksDoNotDefeatTheInstanceCache(): void
    {
        $model = new ConfigurableModel(['defaultConfig' => ['model' => 'gpt-4o', 'apiKey' => 'x']]);

        self::assertSame(
            $model->getCacheKey(new RunnableConfig(callbacks: [new class () extends BaseCallbackHandler {
            }])),
            $model->getCacheKey(new RunnableConfig(callbacks: [new class () extends BaseCallbackHandler {
            }])),
        );
    }

    public function testGeneratePassesItsOptionsToTheModelInstanceLookup(): void
    {
        $openAi = UniversalFixtures::openAiHttp('I am GPT.');
        $model = InitChatModel::init('gpt-4o-mini', [
            'modelProvider' => 'openai',
            'configurableFields' => 'any',
            'apiKey' => self::OPENAI_KEY,
        ]);

        $generate = new \ReflectionMethod($model, 'generate');
        $result = $generate->invoke($model, [new \LangChain\Messages\HumanMessage('hi')], [
            'configurable' => ['model' => 'gpt-4o', 'httpClient' => $openAi],
        ]);

        self::assertSame('I am GPT.', $result->generations[0]->message->content);
        self::assertSame('gpt-4o', $openAi->lastRequestBody()['model']);
    }
}
