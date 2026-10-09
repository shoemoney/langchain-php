<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Universal;

use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\DeepSeek\ChatDeepSeek;
use LangChain\LanguageModels\Chat\Fireworks\ChatFireworks;
use LangChain\LanguageModels\Chat\Ollama\ChatOllama;
use LangChain\LanguageModels\Chat\OpenAI\Azure\AzureChatOpenAI;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\LanguageModels\Chat\TogetherAI\ChatTogetherAI;
use LangChain\LanguageModels\Chat\Universal\InitChatModel;
use LangChain\LanguageModels\Chat\Universal\ModelProviders;
use LangChain\Messages\AIMessage;
use LangChain\Utils\Testing\FakeHttpClient;
use LangChain\LanguageModels\Chat\XAI\ChatXAI;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of the provider cases of `universal.int.test.ts`, run over a scripted transport instead of live APIs:
 * `Works with all model providers`, `Works with model provider in model name`, `Can be initialized without
 * modelProvider` and `Model name parsing with multiple colons preserves full model name`.
 *
 * NOT converted, because this port ships no chat client for them: the `Can invoke` cases for cohere,
 * google-genai (twice), google-vertexai-web, bedrock, groq, mistralai and perplexity, and the `mistralai`
 * row of `Can be initialized without modelProvider`. {@see self::testAnUnportedProviderIsRefusedWithTheListOfSupportedOnes()}
 * pins what they do instead. They are asserted rather than `markTestSkipped()`: a skipped test turns the
 * suite summary into "OK, but some tests were skipped", which the done script reads as not-green.
 */
#[CoversClass(InitChatModel::class)]
#[CoversClass(ModelProviders::class)]
final class UniversalProvidersTest extends TestCase
{
    private const CLASS_FOR = [
        'openai' => ChatOpenAI::class,
        'anthropic' => ChatAnthropic::class,
        'azure_openai' => AzureChatOpenAI::class,
        'ollama' => ChatOllama::class,
        'fireworks' => ChatFireworks::class,
        'together' => ChatTogetherAI::class,
        'deepseek' => ChatDeepSeek::class,
        'xai' => ChatXAI::class,
    ];

    /** @return iterable<string, array{string}> */
    public static function portedProviders(): iterable
    {
        foreach (array_keys(self::CLASS_FOR) as $provider) {
            yield $provider => [$provider];
        }
    }

    #[DataProvider('portedProviders')]
    public function testCanInvoke(string $provider): void
    {
        [$http, $fields] = UniversalFixtures::providers()[$provider];

        $model = InitChatModel::init(null, ['modelProvider' => $provider, 'temperature' => 0, 'httpClient' => $http] + $fields);
        $result = $model->invoke("what's your name");

        self::assertInstanceOf(AIMessage::class, $result);
        self::assertNotSame('', $result->content);
        self::assertStringStartsWith('I am', $result->content);
        self::assertInstanceOf(self::CLASS_FOR[$provider], $model->getModelInstance());
        self::assertSame(1, \count($http->requests) + \count($http->streamRequests), 'exactly one request reached the provider');
    }

    public function testTheProviderChoosesTheWireFormat(): void
    {
        [$http, $fields] = UniversalFixtures::providers()['anthropic'];
        InitChatModel::init('claude-sonnet-4-5', ['httpClient' => $http] + $fields)->invoke('hi');
        self::assertSame('sk-ant-test', $http->requests[0]['headers']['x-api-key'] ?? null);
        self::assertSame('claude-sonnet-4-5', $http->lastRequestBody()['model']);

        [$http, $fields] = UniversalFixtures::providers()['openai'];
        InitChatModel::init('gpt-4o-mini', ['httpClient' => $http] + $fields)->invoke('hi');
        self::assertSame('Bearer sk-test', $http->requests[0]['headers']['Authorization']);
        self::assertSame('gpt-4o-mini', $http->lastRequestBody()['model']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function unportedProviders(): iterable
    {
        yield 'cohere' => ['cohere', 'no ChatCohere'];
        yield 'google-genai' => ['google-genai', 'no ChatGoogleGenerativeAI'];
        yield 'google-vertexai' => ['google-vertexai', 'no ChatVertexAI'];
        yield 'google-vertexai-web' => ['google-vertexai-web', 'no ChatVertexAI'];
        yield 'google' => ['google', 'no ChatGoogle'];
        yield 'bedrock' => ['bedrock', 'no ChatBedrockConverse'];
        yield 'aws' => ['aws', 'no ChatBedrockConverse'];
        yield 'groq' => ['groq', 'no ChatGroq'];
        yield 'mistralai' => ['mistralai', 'no ChatMistralAI'];
        yield 'mistral' => ['mistral', 'no ChatMistralAI'];
        yield 'cerebras' => ['cerebras', 'no ChatCerebras'];
        yield 'perplexity' => ['perplexity', 'no ChatPerplexity'];
    }

    #[DataProvider('unportedProviders')]
    public function testAnUnportedProviderIsRefusedWithTheListOfSupportedOnes(string $provider, string $reason): void
    {
        try {
            InitChatModel::init(null, ['modelProvider' => $provider, 'temperature' => 0]);
            self::fail($provider . ' must be refused: ' . $reason);
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString("Unsupported { modelProvider: {$provider} }.", $e->getMessage());
            self::assertStringContainsString('Supported model providers are: ' . implode(', ', ModelProviders::supportedProviders()), $e->getMessage());
        }
    }

    public function testTheRegistryCarriesExactlyTheShippedProviders(): void
    {
        self::assertSame(
            ['openai', 'anthropic', 'azure_openai', 'langsmith', 'ollama', 'deepseek', 'xai', 'fireworks', 'together'],
            ModelProviders::supportedProviders(),
        );
        self::assertFalse(ModelProviders::isSupported('openrouter'), 'OpenRouter is shipped but is not an upstream registry key');
    }

    public function testWorksWithModelProviderInModelName(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(400, ['error' => [
            'message' => "Unsupported parameter: 'temperature' is not supported with this model.",
            'type' => 'invalid_request_error',
            'param' => 'temperature',
        ]])]);
        $o3Mini = InitChatModel::init('openai:o3-mini', ['temperature' => 0.25, 'apiKey' => 'sk-test', 'httpClient' => $http, 'maxRetries' => 0]);

        $instance = $o3Mini->getModelInstance();
        self::assertInstanceOf(ChatOpenAI::class, $instance);
        self::assertSame('o3-mini', $instance->model);

        try {
            $o3Mini->invoke("what's your name");
            self::fail('the provider refusal must surface');
        } catch (\Throwable $error) {
            self::assertStringContainsString('temperature', $error->getMessage());
        }
    }

    /** @return iterable<string, array{string, class-string}> */
    public static function inferrable(): iterable
    {
        yield 'openai' => ['gpt-4o-mini', ChatOpenAI::class];
        yield 'anthropic' => ['claude-sonnet-4-5-20250929', ChatAnthropic::class];
    }

    /** @param class-string $class */
    #[DataProvider('inferrable')]
    public function testCanBeInitializedWithoutModelProvider(string $modelName, string $class): void
    {
        [$http, $fields] = UniversalFixtures::providers()[$class === ChatOpenAI::class ? 'openai' : 'anthropic'];

        $model = InitChatModel::init($modelName, ['temperature' => 0, 'httpClient' => $http] + $fields);
        $result = $model->invoke("what's your name");

        self::assertInstanceOf($class, $model->getModelInstance());
        self::assertNotSame('', $result->content);
    }

    public function testMistralaiIsInferredButUnsupported(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported { modelProvider: mistralai }.');

        InitChatModel::init('mistral-large-latest', ['temperature' => 0]);
    }

    /** @return iterable<string, array{string, string}> */
    public static function ollamaNames(): iterable
    {
        yield 'qwen2.5:14b' => ['ollama:qwen2.5:14b', 'qwen2.5:14b'];
        yield 'llama3:8b' => ['ollama:llama3:8b', 'llama3:8b'];
        yield 'deepseek-r1:1.5b' => ['ollama:deepseek-r1:1.5b', 'deepseek-r1:1.5b'];
    }

    #[DataProvider('ollamaNames')]
    public function testModelNameParsingWithMultipleColonsPreservesTheFullModelName(string $name, string $expected): void
    {
        $http = new FakeHttpClient([], UniversalFixtures::ollamaStream('I am Llama.'));

        $model = InitChatModel::init($name, ['temperature' => 0, 'httpClient' => $http]);
        $result = $model->invoke("what's your name");

        $instance = $model->getModelInstance();
        self::assertInstanceOf(ChatOllama::class, $instance);
        self::assertSame($expected, $instance->model);
        self::assertSame($expected, $http->lastRequestBody()['model']);
        self::assertNotSame('', $result->content);
    }

    public function testAProviderPrefixOnlyCountsForASupportedProvider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to infer model provider for { model: cohere:command-r }');

        InitChatModel::init('cohere:command-r');
    }

    public function testAnUninferableModelNameNeedsAnExplicitProvider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to infer model provider for { model: unknown-model }, please specify modelProvider directly.');

        InitChatModel::init('unknown-model');
    }

    // ---- _inferModelProvider -----------------------------------------------------------------

    /** @return iterable<string, array{string, string|null}> */
    public static function inferenceTable(): iterable
    {
        foreach (['gpt-3.5-turbo', 'gpt-4', 'gpt-4o-mini', 'gpt-5', 'o1-mini', 'o3-mini', 'o4-mini'] as $name) {
            yield $name => [$name, 'openai'];
        }
        yield 'claude-2' => ['claude-2', 'anthropic'];
        yield 'command-r' => ['command-r', 'cohere'];
        yield 'accounts/fireworks/models/llama' => ['accounts/fireworks/models/llama', 'fireworks'];
        yield 'gemini-1.5-pro' => ['gemini-1.5-pro', 'google-vertexai'];
        yield 'amazon.titan' => ['amazon.titan-text-express-v1', 'bedrock'];
        yield 'mistral-large' => ['mistral-large-latest', 'mistralai'];
        yield 'sonar-pro' => ['sonar-pro', 'perplexity'];
        yield 'pplx-7b' => ['pplx-7b-online', 'perplexity'];
        yield 'deepseek-chat' => ['deepseek-chat', null];
        yield 'unknown-model' => ['unknown-model', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('inferenceTable')]
    public function testInferModelProvider(string $modelName, ?string $expected): void
    {
        self::assertSame($expected, ModelProviders::inferModelProvider($modelName));
    }

    public function testGetChatModelByClassName(): void
    {
        self::assertSame(ChatAnthropic::class, ModelProviders::getChatModelByClassName('ChatAnthropic'));
        self::assertSame(ChatOpenAI::class, ModelProviders::getChatModelByClassName('ChatOpenAI'));
        self::assertSame(ChatOpenAI::class, ModelProviders::getChatModelByClassName('ignored', 'langsmith'));
        self::assertNull(ModelProviders::getChatModelByClassName('ChatCohere'));
        self::assertNull(ModelProviders::getChatModelByClassName('ChatOpenAI', 'cohere'));
    }
}
