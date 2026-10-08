<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Azure;

use LangChain\LanguageModels\Chat\OpenAI\Azure\AzureChatOpenAI;
use LangChain\LanguageModels\Chat\OpenAI\Azure\AzureChatOpenAICompletions;
use LangChain\LanguageModels\Chat\OpenAI\Azure\AzureChatOpenAIResponses;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Utils\Js;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `azure/chat_models/tests/index.test.ts`, plus the wire behaviour the
 * upstream suite leaves to its live tests: which endpoint, headers and query an
 * Azure call carries, and that the facade still routes between the two protocols.
 */
#[CoversClass(AzureChatOpenAI::class)]
#[CoversClass(AzureChatOpenAICompletions::class)]
#[CoversClass(AzureChatOpenAIResponses::class)]
final class AzureChatOpenAITest extends TestCase
{
    private const ENV = [
        'OPENAI_API_KEY', 'AZURE_OPENAI_API_KEY', 'AZURE_OPENAI_API_DEPLOYMENT_NAME', 'AZURE_OPENAI_BASE_PATH',
        'AZURE_OPENAI_API_VERSION', 'AZURE_OPENAI_API_COMPLETIONS_DEPLOYMENT_NAME',
        'AZURE_OPENAI_API_EMBEDDINGS_DEPLOYMENT_NAME', 'AZURE_OPENAI_ENDPOINT', 'AZURE_OPENAI_API_INSTANCE_NAME',
    ];

    /** @var array<string, string|false> */
    private array $saved = [];

    protected function setUp(): void
    {
        foreach (self::ENV as $name) {
            $this->saved[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    private const COMPLETION = [
        'id' => 'chatcmpl-1', 'object' => 'chat.completion', 'model' => 'gpt-4o',
        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'Bonjour.'], 'finish_reason' => 'stop']],
        'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 1, 'total_tokens' => 5],
    ];

    private const SECRET = '{"lc":1,"type":"secret","id":["AZURE_OPENAI_API_KEY"]}';

    private const ID = '"lc":1,"type":"constructor","id":["langchain","chat_models","azure_openai","AzureChatOpenAI"]';

    // ---- index.test.ts -------------------------------------------------------

    public function testSerializationFromAzureEndpoint(): void
    {
        $chat = new AzureChatOpenAI([
            'azureOpenAIEndpoint' => 'https://foobar.openai.azure.com/',
            'azureOpenAIApiDeploymentName' => 'gpt-4o',
            'azureOpenAIApiVersion' => '2024-08-01-preview',
            'azureOpenAIApiKey' => 'foo',
        ]);

        self::assertSame(
            '{' . self::ID . ',"kwargs":{"azure_endpoint":"https://foobar.openai.azure.com/","deployment_name":"gpt-4o",'
            . '"openai_api_version":"2024-08-01-preview","azure_open_ai_api_key":' . self::SECRET . '}}',
            Js::encode($chat),
        );
    }

    public function testSupportsDeploymentNameShorthand(): void
    {
        $chat = new AzureChatOpenAI('gpt-4o', [
            'azureOpenAIEndpoint' => 'https://foobar.openai.azure.com/',
            'azureOpenAIApiVersion' => '2024-08-01-preview',
            'azureOpenAIApiKey' => 'foo',
        ]);

        self::assertSame('gpt-4o', $chat->model);
        self::assertSame(
            '{' . self::ID . ',"kwargs":{"model":"gpt-4o","deployment_name":"gpt-4o",'
            . '"azure_endpoint":"https://foobar.openai.azure.com/","openai_api_version":"2024-08-01-preview",'
            . '"azure_open_ai_api_key":' . self::SECRET . '}}',
            Js::encode($chat),
        );
    }

    public function testSerializationDoesNotPassAlongExtraParams(): void
    {
        $chat = new AzureChatOpenAI([
            'azureOpenAIEndpoint' => 'https://foobar.openai.azure.com/',
            'azureOpenAIApiDeploymentName' => 'gpt-4o',
            'azureOpenAIApiVersion' => '2024-08-01-preview',
            'azureOpenAIApiKey' => 'foo',
            'extraParam' => 'extra',
        ]);

        self::assertSame(
            '{' . self::ID . ',"kwargs":{"azure_endpoint":"https://foobar.openai.azure.com/","deployment_name":"gpt-4o",'
            . '"openai_api_version":"2024-08-01-preview","azure_open_ai_api_key":' . self::SECRET . '}}',
            Js::encode($chat),
        );
    }

    public function testSerializationFromBasePath(): void
    {
        $chat = new AzureChatOpenAI([
            'azureOpenAIBasePath' => 'https://foobar.openai.azure.com/openai/deployments/gpt-4o',
            'azureOpenAIApiVersion' => '2024-08-01-preview',
            'azureOpenAIApiKey' => 'foo',
        ]);

        self::assertSame(
            '{' . self::ID . ',"kwargs":{"openai_api_version":"2024-08-01-preview","azure_open_ai_api_key":' . self::SECRET
            . ',"azure_endpoint":"https://foobar.openai.azure.com","deployment_name":"gpt-4o"}}',
            Js::encode($chat),
        );
    }

    public function testSerializationFromInstanceName(): void
    {
        $chat = new AzureChatOpenAI([
            'azureOpenAIApiInstanceName' => 'foobar',
            'azureOpenAIApiDeploymentName' => 'gpt-4o',
            'azureOpenAIApiVersion' => '2024-08-01-preview',
            'azureOpenAIApiKey' => 'foo',
        ]);

        self::assertSame(
            '{' . self::ID . ',"kwargs":{"azure_open_ai_api_instance_name":"foobar","deployment_name":"gpt-4o",'
            . '"openai_api_version":"2024-08-01-preview","azure_open_ai_api_key":' . self::SECRET
            . ',"azure_endpoint":"https://foobar.openai.azure.com/"}}',
            Js::encode($chat),
        );
    }

    // ---- beyond the upstream unit file ---------------------------------------

    public function testTheSerializedFormNeverContainsTheKey(): void
    {
        $chat = new AzureChatOpenAI('gpt-4o', ['azureOpenAIEndpoint' => 'https://x.openai.azure.com', 'azureOpenAIApiKey' => 'sk-very-secret']);

        self::assertStringNotContainsString('sk-very-secret', Js::encode($chat));
    }

    public function testRequiresAKeyOrATokenProvider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Azure OpenAI API key or Token Provider not found');

        new AzureChatOpenAI('gpt-4o', ['azureOpenAIEndpoint' => 'https://x.openai.azure.com']);
    }

    public function testReadsTheEnvironment(): void
    {
        putenv('AZURE_OPENAI_API_KEY=env-key');
        putenv('AZURE_OPENAI_API_INSTANCE_NAME=envinst');
        putenv('AZURE_OPENAI_API_DEPLOYMENT_NAME=envdep');
        putenv('AZURE_OPENAI_API_VERSION=2024-10-21');

        $chat = new AzureChatOpenAI();

        self::assertSame('env-key', $chat->azureOpenAIApiKey);
        self::assertSame('envinst', $chat->azureOpenAIApiInstanceName);
        self::assertSame('envdep', $chat->azureOpenAIApiDeploymentName);
        self::assertSame('2024-10-21', $chat->azureOpenAIApiVersion);
    }

    public function testInvokeUsesTheDeploymentEndpointKeyHeaderAndApiVersion(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, self::COMPLETION)]);
        $chat = new AzureChatOpenAI('gpt-4o', [
            'azureOpenAIEndpoint' => 'https://foobar.openai.azure.com',
            'azureOpenAIApiVersion' => '2024-08-01-preview',
            'azureOpenAIApiKey' => 'azure-key',
            'httpClient' => $http,
        ]);

        $reply = $chat->invoke([new HumanMessage('Salut')]);

        self::assertInstanceOf(AIMessage::class, $reply);
        self::assertSame('Bonjour.', $reply->content);
        $request = $http->requests[0];
        self::assertSame('https://foobar.openai.azure.com/openai/deployments/gpt-4o/chat/completions', $request['url']);
        self::assertSame(['api-version' => '2024-08-01-preview'], $request['query']);
        self::assertSame('azure-key', $request['headers']['api-key']);
        self::assertArrayNotHasKey('Authorization', $request['headers']);
        self::assertStringStartsWith('langchainjs-azure-openai/2.0.0 (', $request['headers']['User-Agent']);
        self::assertSame('gpt-4o', $http->lastRequestBody()['model']);
    }

    public function testBasePathAndInstanceNameBuildTheSameDeploymentUrl(): void
    {
        $base = new AzureChatOpenAI('dep', ['azureOpenAIBasePath' => 'https://a.openai.azure.com/openai/deployments', 'azureOpenAIApiKey' => 'k']);
        $instance = new AzureChatOpenAI('dep', ['azureOpenAIApiInstanceName' => 'a', 'azureOpenAIApiKey' => 'k']);

        foreach ([$base, $instance] as $chat) {
            $http = new FakeHttpClient([FakeHttpClient::json(200, self::COMPLETION)]);
            $chat->httpClient = $http;
            $chat->invoke('hi');
            self::assertSame('https://a.openai.azure.com/openai/deployments/dep/chat/completions', $http->requests[0]['url']);
        }
    }

    public function testATokenProviderIsCalledPerRequestAndSentAsBearer(): void
    {
        $calls = 0;
        $http = new FakeHttpClient([FakeHttpClient::json(200, self::COMPLETION), FakeHttpClient::json(200, self::COMPLETION)]);
        $chat = new AzureChatOpenAI('gpt-4o', [
            'azureOpenAIEndpoint' => 'https://foobar.openai.azure.com',
            'azureOpenAIApiVersion' => '2024-08-01-preview',
            'azureADTokenProvider' => function () use (&$calls): string {
                return 'token-' . (++$calls);
            },
            'httpClient' => $http,
        ]);

        $chat->invoke('one');
        $chat->invoke('two');

        self::assertSame('Bearer token-1', $http->requests[0]['headers']['Authorization']);
        self::assertSame('Bearer token-2', $http->requests[1]['headers']['Authorization']);
        self::assertArrayNotHasKey('api-key', $http->requests[0]['headers']);
    }

    public function testTheFacadeStillRoutesToTheResponsesApiOnAzureAndNeverReachesOpenAI(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, [
            'id' => 'resp_1', 'object' => 'response', 'model' => 'gpt-4o', 'status' => 'completed',
            'output' => [['type' => 'message', 'id' => 'msg_1', 'role' => 'assistant', 'status' => 'completed',
                'content' => [['type' => 'output_text', 'text' => 'via responses', 'annotations' => []]]]],
            'usage' => ['input_tokens' => 3, 'output_tokens' => 2, 'total_tokens' => 5],
        ])]);
        $chat = new AzureChatOpenAI('gpt-4o', [
            'azureOpenAIEndpoint' => 'https://foobar.openai.azure.com',
            'azureOpenAIApiVersion' => '2025-03-01-preview',
            'azureOpenAIApiKey' => 'azure-key',
            'useResponsesApi' => true,
            'httpClient' => $http,
        ]);

        $reply = $chat->invoke('hello');

        self::assertSame('via responses', $reply->text());
        self::assertSame('https://foobar.openai.azure.com/openai/responses', $http->requests[0]['url']);
        self::assertSame(['api-version' => '2025-03-01-preview'], $http->requests[0]['query']);
        self::assertSame('azure-key', $http->requests[0]['headers']['api-key']);
    }

    public function testAProtocolOnlyOptionRoutesThroughTheInheritedFacadeLogic(): void
    {
        $chat = new AzureChatOpenAI('gpt-4o', ['azureOpenAIApiInstanceName' => 'a', 'azureOpenAIApiKey' => 'k']);

        self::assertFalse($chat->shouldUseResponsesApi());
        self::assertTrue($chat->shouldUseResponsesApi(['previous_response_id' => 'resp_0']));
        self::assertInstanceOf(ChatOpenAI::class, $chat);
    }

    public function testStreamingGoesToTheAzureEndpoint(): void
    {
        $sse = "data: " . Js::encode(['id' => 'c', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hi']]]]) . "\n\n"
            . "data: " . Js::encode(['id' => 'c', 'choices' => [['index' => 0, 'delta' => ['content' => '!'], 'finish_reason' => 'stop']]]) . "\n\n"
            . "data: [DONE]\n\n";
        $http = new FakeHttpClient([], [$sse]);
        $chat = new AzureChatOpenAI('gpt-4o', [
            'azureOpenAIEndpoint' => 'https://foobar.openai.azure.com', 'azureOpenAIApiVersion' => 'v1',
            'azureOpenAIApiKey' => 'k', 'httpClient' => $http,
        ]);

        $text = '';
        foreach ($chat->stream('hi') as [, $chunk]) {
            $text .= $chunk->text();
        }

        self::assertSame('Hi!', $text);
        self::assertSame('https://foobar.openai.azure.com/openai/deployments/gpt-4o/chat/completions', $http->streamRequests[0]['url']);
        self::assertSame(['api-version' => 'v1'], $http->streamRequests[0]['query']);
    }

    public function testAnAzureCallWithoutAnyCredentialsAtRequestTimeFails(): void
    {
        $chat = new AzureChatOpenAI('gpt-4o', ['azureOpenAIApiInstanceName' => 'a', 'azureOpenAIApiKey' => 'k', 'httpClient' => new FakeHttpClient()]);
        $chat->azureOpenAIApiKey = null;

        $this->expectException(OpenAIException::class);
        $chat->invoke('hi');
    }

    public function testTheProfileIsLookedUpByModel(): void
    {
        $chat = new AzureChatOpenAI('gpt-4o', ['azureOpenAIApiInstanceName' => 'a', 'azureOpenAIApiKey' => 'k']);

        self::assertTrue($chat->profile()['toolCalling']);
        self::assertSame([], (new AzureChatOpenAI('nope-model', ['azureOpenAIApiInstanceName' => 'a', 'azureOpenAIApiKey' => 'k']))->profile());
    }

    public function testTheProtocolClientsAreUsableOnTheirOwn(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, self::COMPLETION)]);
        $client = new AzureChatOpenAICompletions('gpt-4o', ['azureOpenAIApiInstanceName' => 'a', 'azureOpenAIApiKey' => 'k', 'httpClient' => $http]);

        $client->invoke('hi');

        self::assertSame('https://a.openai.azure.com/openai/deployments/gpt-4o/chat/completions', $http->requests[0]['url']);
        self::assertSame('azure_openai', $client->llmType());
    }
}
