<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Load;

use LangChain\Load\ImportConstants;
use LangChain\Load\Load;
use LangChain\Messages;
use LangChain\Prompts;
use LangChain\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Load::class)]
final class LoadTest extends TestCase
{
    private const SECRET = ['lc' => 1, 'type' => 'secret', 'id' => ['TEST_ONLY_KEY']];

    private static function loadValue(mixed $value, array $options = []): mixed
    {
        return Load::load(json_encode($value, JSON_THROW_ON_ERROR), $options);
    }

    public static function coreObjects(): iterable
    {
        yield 'human' => [new Messages\HumanMessage(['content' => 'hello', 'additional_kwargs' => ['plain_key' => 1]])];
        yield 'ai' => [new Messages\AIMessage(['content' => 'hello', 'tool_calls' => [['name' => 'tool', 'id' => 'call', 'args' => ['q' => 'a']]]])];
        yield 'system' => [new Messages\SystemMessage('hello')];
        yield 'tool' => [new Messages\ToolMessage(['content' => 'hello', 'tool_call_id' => 'call', 'artifact' => ['value' => 1]])];
        yield 'function' => [new Messages\FunctionMessage(['content' => 'hello', 'name' => 'tool'])];
        yield 'chat' => [new Messages\ChatMessage(['content' => 'hello', 'role' => 'moderator'])];
        yield 'remove' => [new Messages\RemoveMessage(['id' => 'remove-me'])];
        yield 'human chunk' => [new Messages\HumanMessageChunk('hello')];
        yield 'ai chunk' => [new Messages\AIMessageChunk('hello')];
        yield 'system chunk' => [new Messages\SystemMessageChunk('hello')];
        yield 'tool chunk' => [new Messages\ToolMessageChunk(['content' => 'hello', 'tool_call_id' => 'call'])];
        yield 'function chunk' => [new Messages\FunctionMessageChunk(['content' => 'hello', 'name' => 'tool'])];
        yield 'chat chunk' => [new Messages\ChatMessageChunk(['content' => 'hello', 'role' => 'moderator'])];
        yield 'document' => [new Schema\Document('page', ['plain_key' => 1])];
        yield 'string prompt value' => [new Schema\StringPromptValue('hello')];
        yield 'chat prompt value' => [new Schema\ChatPromptValue([new Messages\HumanMessage('hello'), new Messages\AIMessage('world')])];
        yield 'prompt' => [Prompts\PromptTemplate::fromTemplate('Hello {name}')];
        yield 'image' => [new Prompts\ImagePromptTemplate(['url' => 'https://example.test/{name}'], ['name'])];
        yield 'dict' => [new Prompts\DictPromptTemplate(['value' => '{name}'])];
        yield 'human prompt' => [Prompts\HumanMessagePromptTemplate::fromTemplate('{name}')];
        yield 'ai prompt' => [Prompts\AIMessagePromptTemplate::fromTemplate('{name}')];
        yield 'system prompt' => [Prompts\SystemMessagePromptTemplate::fromTemplate('{name}')];
        yield 'chat message prompt' => [Prompts\ChatMessagePromptTemplate::fromTemplate('{name}', 'moderator')];
        yield 'placeholder' => [new Prompts\MessagesPlaceholder('history', true)];
        yield 'chat prompt' => [Prompts\ChatPromptTemplate::fromMessages([['system', 'hi'], ['human', '{name}']])];
        yield 'few-shot chat' => [new Prompts\FewShotChatMessagePromptTemplate(Prompts\ChatPromptTemplate::fromMessages([['human', '{name}']]), [['name' => 'example']])];
    }

    #[DataProvider('coreObjects')]
    public function testSupportedCoreConstructorRoundTrip(object $object): void
    {
        $restored = self::loadValue($object);
        self::assertSame($object::class, $restored::class);
        self::assertSame(json_decode(json_encode($object), true), json_decode(json_encode($restored), true));
    }

    public static function providers(): iterable
    {
        foreach (ImportConstants::inventory() as $class => $entry) {
            if ($entry['status'] === 'supported_constructor' && str_starts_with($class, 'LangChain\\LanguageModels\\')) {
                yield $class => [$class, $entry['id']];
            }
        }
    }

    #[DataProvider('providers')]
    public function testFixedProviderFactoriesAndFakeSecretRoundTrips(string $class, array $id): void
    {
        $fields = [
            'model' => 'test-model', 'apiKey' => self::SECRET, 'temperature' => 0.3,
            'baseUrl' => 'https://example.test', 'baseURL' => 'https://example.test',
            'organization' => 'test-org',
        ];
        if (str_contains($class, 'Azure')) {
            $fields += [
                'azureOpenAIApiKey' => self::SECRET, 'azureOpenAIEndpoint' => 'https://example.test',
                'azureOpenAIApiInstanceName' => 'test-instance', 'azureOpenAIApiDeploymentName' => 'test-deployment',
                'azureOpenAIApiCompletionsDeploymentName' => 'test-deployment', 'azureOpenAIApiVersion' => 'test-version',
                'azureOpenAIBasePath' => 'https://example.test/openai/deployments/test-deployment',
            ];
        }
        $payload = ['lc' => 1, 'type' => 'constructor', 'id' => $id, 'kwargs' => $fields];
        $object = self::loadValue($payload, ['secretsMap' => ['TEST_ONLY_KEY' => 'fake-key']]);
        self::assertInstanceOf($class, $object);
        self::assertSame('test-model', $object->model);
        if (!str_contains($class, 'Ollama')) {
            self::assertSame('fake-key', (new \ReflectionProperty($object, 'apiKey'))->getValue($object));
        }
        $json = json_encode($object, JSON_THROW_ON_ERROR);
        $serialized = json_decode($json, true);
        // Source lc_secrets of the providers that emit credential markers; the
        // other provider serializers still omit API keys (N19b S2).
        $markers = [
            \LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI::class => ['openai_api_key' => 'OPENAI_API_KEY', 'organization' => 'OPENAI_ORGANIZATION'],
            \LangChain\LanguageModels\Chat\OpenAI\ChatOpenAICompletions::class => ['openai_api_key' => 'OPENAI_API_KEY', 'organization' => 'OPENAI_ORGANIZATION'],
            \LangChain\LanguageModels\Chat\OpenAI\ChatOpenAIResponses::class => ['openai_api_key' => 'OPENAI_API_KEY', 'organization' => 'OPENAI_ORGANIZATION'],
            \LangChain\LanguageModels\LLMs\OpenAI::class => ['openai_api_key' => 'OPENAI_API_KEY', 'organization' => 'OPENAI_ORGANIZATION'],
            \LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic::class => ['anthropic_api_key' => 'ANTHROPIC_API_KEY', 'api_key' => 'ANTHROPIC_API_KEY'],
            \LangChain\LanguageModels\Chat\OpenRouter\ChatOpenRouter::class => ['api_key' => 'OPENROUTER_API_KEY'],
        ];
        foreach ($markers[$class] ?? [] as $key => $secret) {
            self::assertSame(['lc' => 1, 'type' => 'secret', 'id' => [$secret]], $serialized['kwargs'][$key] ?? null, $key);
        }
        if (isset($markers[$class])) {
            self::assertStringNotContainsString('fake-key', $json);
            self::assertStringNotContainsString('test-org', $json);
        }
        $serialized['kwargs']['apiKey'] = self::SECRET;
        if (str_contains($class, 'Azure')) {
            $serialized['kwargs'] += $fields;
        }
        $restored = self::loadValue($serialized, ['secretsMap' => [
            'TEST_ONLY_KEY' => 'fake-key', 'OPENAI_API_KEY' => 'fake-key', 'AZURE_OPENAI_API_KEY' => 'fake-key',
            'ANTHROPIC_API_KEY' => 'fake-key', 'OPENROUTER_API_KEY' => 'fake-key', 'OPENAI_ORGANIZATION' => 'test-org',
        ]]);
        self::assertInstanceOf($class, $restored);
        self::assertSame($object->model, $restored->model);
        self::assertSame($object->temperature, $restored->temperature);
        if (isset($markers[$class])) {
            self::assertSame('fake-key', (new \ReflectionProperty($restored, 'apiKey'))->getValue($restored));
            self::assertSame($json, json_encode($restored, JSON_THROW_ON_ERROR));
        }
    }

    public function testFewShotChatRendersAfterNestedConstructorLoad(): void
    {
        $example = Prompts\ChatPromptTemplate::fromMessages([['human', '{input}'], ['ai', '{output}']]);
        $fewShot = new Prompts\FewShotChatMessagePromptTemplate($example, [['input' => 'hello', 'output' => 'bonjour']]);
        $chat = Prompts\ChatPromptTemplate::fromMessages([$fewShot, ['human', '{question}']]);
        $restored = self::loadValue($chat);
        $messages = $restored->formatMessages(['question' => 'hi']);
        self::assertSame(['hello', 'bonjour', 'hi'], array_map(static fn (Messages\BaseMessage $m): mixed => $m->content, $messages));
    }

    public function testAzureEmittedSecretMarkersAndAliasesRoundTripWithoutReinsertingFields(): void
    {
        $original = self::loadValue([
            'lc' => 1, 'type' => 'constructor', 'id' => ['langchain', 'chat_models', 'azure_openai', 'AzureChatOpenAI'],
            'kwargs' => [
                'model' => 'test-model', 'apiKey' => self::SECRET, 'azureOpenAIApiKey' => self::SECRET,
                'azureOpenAIEndpoint' => 'https://example.test', 'azureOpenAIApiInstanceName' => 'test-instance',
                'azureOpenAIApiDeploymentName' => 'test-deployment', 'azureOpenAIApiVersion' => 'test-version',
                'azureOpenAIBasePath' => 'https://example.test/openai/deployments/test-deployment',
            ],
        ], ['secretsMap' => ['TEST_ONLY_KEY' => 'fake-key']]);
        $json = json_encode($original, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('fake-key', $json);
        $restored = Load::load($json, ['secretsMap' => ['OPENAI_API_KEY' => 'fake-key', 'AZURE_OPENAI_API_KEY' => 'fake-key']]);
        self::assertSame('fake-key', $restored->apiKey);
        self::assertSame('fake-key', $restored->azureOpenAIApiKey);
        self::assertSame('https://example.test', $restored->azureOpenAIEndpoint);
        self::assertSame('test-instance', $restored->azureOpenAIApiInstanceName);
        self::assertSame('test-deployment', $restored->azureOpenAIApiDeploymentName);
        self::assertSame('test-version', $restored->azureOpenAIApiVersion);
        // Azure serialization replaces the redundant base path with endpoint + deployment.
        self::assertArrayNotHasKey('openai_api_base', json_decode($json, true)['kwargs']);
        self::assertNull($restored->azureOpenAIBasePath);
    }

    public function testStringFewShotNotImplementedIsRespected(): void
    {
        $prompt = new Prompts\FewShotPromptTemplate(Prompts\PromptTemplate::fromTemplate('{name}'), [['name' => 'example']]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('does not implement serialization');
        self::loadValue($prompt);
    }

    public function testSecretsAndPlainCollections(): void
    {
        self::assertSame(['list' => ['fake', null], 'n' => 1], self::loadValue(['list' => [self::SECRET, null], 'n' => 1], ['secretsMap' => ['TEST_ONLY_KEY' => 'fake']]));
        self::assertNull(self::loadValue(self::SECRET, ['secretsMap' => ['TEST_ONLY_KEY' => null]]));
        self::assertSame('', self::loadValue(self::SECRET, ['secretsMap' => ['TEST_ONLY_KEY' => '']]));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Missing secret "TEST_ONLY_KEY" at $.value');
        self::loadValue(['value' => self::SECRET]);
    }

    public function testEscapedConstructorAndSecretObjectsRemainLiteral(): void
    {
        $literal = ['lc' => 1, 'type' => 'constructor', 'id' => ['arbitrary', 'NotAClass'], 'kwargs' => ['secret' => self::SECRET, 'nested' => ['__lc_escaped__' => ['lc' => 1]]]];
        self::assertSame($literal, self::loadValue(['__lc_escaped__' => $literal]));
        $message = ['lc' => 1, 'type' => 'constructor', 'id' => ['langchain_core', 'messages', 'HumanMessage'], 'kwargs' => ['content' => 'hi', 'additional_kwargs' => ['literal' => ['__lc_escaped__' => self::SECRET]]]];
        self::assertSame(self::SECRET, self::loadValue($message)->additional_kwargs['literal']);
    }

    public function testProviderAliasesAndNestedUserKeysArePreserved(): void
    {
        $model = self::loadValue(['lc' => 1, 'type' => 'constructor', 'id' => ['langchain', 'chat_models', 'xai', 'ChatXAIResponses'], 'kwargs' => ['model' => 'test-model', 'xai_api_key' => self::SECRET, 'metadata' => ['keep_snake_key' => 1]]], ['secretsMap' => ['TEST_ONLY_KEY' => 'fake']]);
        self::assertSame('fake', $model->apiKey);
        self::assertSame(['keep_snake_key' => 1], $model->metadata);
    }

    public static function unsupported(): iterable
    {
        yield 'arbitrary FQCN' => [['lc' => 1, 'type' => 'constructor', 'id' => ['LangChain', 'Messages', 'HumanMessage'], 'kwargs' => []], [], 'Invalid namespace'];
        yield 'identifier FQCN' => [['lc' => 1, 'type' => 'constructor', 'id' => ['langchain_core', 'messages', 'LangChain\\Messages\\HumanMessage'], 'kwargs' => []], [], 'Invalid identifier'];
        yield 'unknown class' => [['lc' => 1, 'type' => 'constructor', 'id' => ['langchain_core', 'messages', 'MissingClass'], 'kwargs' => []], [], 'Invalid identifier'];
        yield 'excluded classic' => [['lc' => 1, 'type' => 'constructor', 'id' => ['langchain', 'chains', 'LLMChain'], 'kwargs' => []], [], 'Invalid namespace'];
        yield 'excluded provider' => [['lc' => 1, 'type' => 'constructor', 'id' => ['langchain_community', 'llms', 'SomeProvider'], 'kwargs' => []], [], 'Invalid namespace'];
        yield 'missing secret' => [self::SECRET, [], 'Missing secret'];
        yield 'bad secret id' => [['lc' => 1, 'type' => 'secret', 'id' => ['a', 'b']], [], 'Malformed secret'];
        yield 'bad constructor id' => [['lc' => 1, 'type' => 'constructor', 'id' => 'HumanMessage', 'kwargs' => []], [], 'Malformed serialized identifier'];
        yield 'missing kwargs' => [['lc' => 1, 'type' => 'constructor', 'id' => ['langchain_core', 'messages', 'HumanMessage']], [], 'Malformed constructor kwargs'];
        yield 'environment option' => [self::SECRET, ['secretsFromEnv' => true], 'Environment secret resolution is not supported'];
        yield 'dynamic imports' => [null, ['importMap' => ['messages' => ['HumanMessage' => 'LangChain\\Messages\\HumanMessage']]], 'Dynamic importMap is not supported'];
        yield 'negative depth' => [null, ['maxDepth' => -1], 'maxDepth must be'];
        yield 'missing provider credential' => [['lc' => 1, 'type' => 'constructor', 'id' => ['langchain', 'chat_models', 'openai', 'ChatOpenAI'], 'kwargs' => ['model' => 'test-model']], [], 'requires an explicit API key'];
    }

    #[DataProvider('unsupported')]
    public function testUnsupportedPayloadsFailHonestly(mixed $value, array $options, string $message): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage($message);
        self::loadValue($value, $options);
    }

    public function testDepthLimitCoversListsAndNestedConstructorKwargs(): void
    {
        self::assertSame(['a' => ['b' => 1]], self::loadValue(['a' => ['b' => 1]], ['maxDepth' => 2]));
        foreach ([[[[[1]]]], ['lc' => 1, 'type' => 'constructor', 'id' => ['langchain_core', 'messages', 'HumanMessage'], 'kwargs' => ['content' => 'hi']]] as $value) {
            try {
                self::loadValue($value, ['maxDepth' => 1]);
                self::fail('Expected bounded recursion to fail.');
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('Maximum recursion depth (1)', $exception->getMessage());
            }
        }
        self::assertSame([[[[1]]]], self::loadValue([[[[1]]]], ['maxDepth' => 5]));
    }

    public function testInvalidJsonThrows(): void
    {
        $this->expectException(\JsonException::class);
        Load::load('{broken');
    }

    public static function runtimeProviderFields(): iterable
    {
        foreach (self::providers() as $class => [$provider, $id]) {
            foreach (['sleeper', 'azureADTokenProvider', 'azure_ad_token_provider', 'backoffHandler', 'backoff_handler', 'httpClient', 'http_client', 'asyncCaller', 'async_caller', 'callbacks'] as $field) {
                foreach (['function' => 'phpversion', 'static method' => [ImportConstants::class, 'inventory']] as $gadget => $value) {
                    yield $class . ' ' . $field . ' ' . $gadget => [$id, $field, $value];
                }
            }
        }
    }

    #[DataProvider('runtimeProviderFields')]
    public function testProviderManifestsCannotSupplyRuntimeHooks(array $id, string $field, mixed $gadget): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Runtime-only constructor field');
        self::loadValue(['lc' => 1, 'type' => 'constructor', 'id' => $id, 'kwargs' => ['apiKey' => 'fake-key', $field => $gadget]]);
    }

    public static function nonStringPartials(): iterable
    {
        foreach ([Prompts\PromptTemplate::class, Prompts\ImagePromptTemplate::class, Prompts\ChatPromptTemplate::class, Prompts\FewShotChatMessagePromptTemplate::class] as $class) {
            foreach (['partialVariables', 'partial_variables'] as $field) {
                foreach ([[ImportConstants::class, 'inventory'], [ImportConstants::class . '::inventory'], null, 42, ['literal' => 'array']] as $value) {
                    yield [$class::lcId(), $field, $value];
                }
            }
        }
    }

    #[DataProvider('nonStringPartials')]
    public function testPromptManifestsCannotSupplyCallableArraysOrOtherNonStringPartials(array $id, string $field, mixed $value): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Serialized partialVariables values must be strings');
        self::loadValue(['lc' => 1, 'type' => 'constructor', 'id' => $id, 'kwargs' => [$field => ['context' => $value]]]);
    }

    public function testFunctionNamesInStringPartialsRemainLiteral(): void
    {
        foreach (['phpversion', ImportConstants::class . '::inventory'] as $value) {
            $prompt = self::loadValue(['lc' => 1, 'type' => 'constructor', 'id' => Prompts\PromptTemplate::lcId(), 'kwargs' => ['template' => '{context}', 'partial_variables' => ['context' => $value]]]);
            self::assertSame($value, $prompt->format([]));
        }
    }
}
