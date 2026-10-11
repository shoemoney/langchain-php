<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Azure;

use LangChain\LanguageModels\Chat\OpenAI\Azure\AzureChatOpenAI;
use LangChain\LanguageModels\Chat\OpenAI\Azure\AzureChatOpenAICompletions;
use LangChain\LanguageModels\Chat\OpenAI\Azure\AzureChatOpenAIResponses;
use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\Messages\HumanMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Utils\Js;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Request-boundary oracle: upstream azure/chat_models/common.ts and utils/azure.ts,
 * plus openai-node v7.10.0 src/azure.ts (buildRequest) and src/client.ts (buildURL).
 * SDK URLs were independently checked with the real 7.10.0 client and fake fetch.
 * Generated Responses URLs retain the established resource-level REST route as
 * a provider-compatible adaptation; current Azure docs have moved to the v1 API.
 * With credentials, getEndpoint overrides configuration.baseURL; the SDK also
 * ignores a baseURL supplied in per-request options.
 */
final class AzureRequestMatrixTest extends TestCase
{
    private const VERSION = '2025-04-01-preview';

    private const ENV = ['OPENAI_API_KEY', 'OPENAI_API_VERSION', 'AZURE_OPENAI_API_KEY', 'AZURE_OPENAI_API_VERSION',
        'AZURE_OPENAI_API_INSTANCE_NAME', 'AZURE_OPENAI_API_DEPLOYMENT_NAME', 'AZURE_OPENAI_ENDPOINT', 'AZURE_OPENAI_BASE_PATH'];

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

    /** @return iterable<string, array{string, bool, bool, array<string, mixed>, array<string, mixed>, string}> */
    public static function endpointCases(): iterable
    {
        // Expected roots are literals taken from the source/SDK oracle, not
        // computed with the PHP endpoint helper under test.
        $roots = [
            'endpoint' => [['azureOpenAIEndpoint' => 'https://a.openai.azure.com'], [],
                'https://a.openai.azure.com/openai/deployments/dep/chat/completions', 'https://a.openai.azure.com/openai/responses'],
            'instance' => [['azureOpenAIApiInstanceName' => 'a'], [],
                'https://a.openai.azure.com/openai/deployments/dep/chat/completions', 'https://a.openai.azure.com/openai/responses'],
            'base path precedes endpoint' => [['azureOpenAIBasePath' => 'https://proxy.example/prefix/openai/deployments',
                'azureOpenAIEndpoint' => 'https://ignored.example'], [],
                'https://proxy.example/prefix/openai/deployments/dep/chat/completions', 'https://proxy.example/prefix/openai/responses'],
            'custom base path deployment injection' => [['azureOpenAIBasePath' => 'https://proxy.example/custom'], [],
                'https://proxy.example/custom/dep/deployments/dep/chat/completions', 'https://proxy.example/custom/dep/responses'],
            'configuration resource base URL ignored by getEndpoint' => [['azureOpenAIEndpoint' => 'https://a.openai.azure.com',
                'configuration' => ['baseURL' => 'https://proxy.example/openai/v1/']], [],
                'https://a.openai.azure.com/openai/deployments/dep/chat/completions', 'https://a.openai.azure.com/openai/responses'],
            'configuration deployment base URL ignored by getEndpoint' => [['azureOpenAIEndpoint' => 'https://a.openai.azure.com',
                'configuration' => ['baseURL' => 'https://proxy.example/prefix/deployments/custom/']], [],
                'https://a.openai.azure.com/openai/deployments/dep/chat/completions', 'https://a.openai.azure.com/openai/responses'],
            'per-call base URL ignored by SDK' => [['azureOpenAIEndpoint' => 'https://a.openai.azure.com',
                'configuration' => ['baseURL' => 'https://ignored.example/openai']], ['baseURL' => 'https://call.example/custom/'],
                'https://a.openai.azure.com/openai/deployments/dep/chat/completions', 'https://a.openai.azure.com/openai/responses'],
        ];
        foreach ([false, true] as $responses) {
            foreach ([false, true] as $stream) {
                foreach ([false, true] as $facade) {
                    $class = $facade ? AzureChatOpenAI::class : ($responses ? AzureChatOpenAIResponses::class : AzureChatOpenAICompletions::class);
                    foreach ($roots as $name => [$fields, $options, $completionUrl, $responsesUrl]) {
                        yield $name . '/' . ($responses ? 'responses' : 'completions') . '/' . ($stream ? 'stream' : 'invoke') . '/' . ($facade ? 'facade' : 'direct')
                            => [$class, $responses, $stream, $fields, $options, $responses ? $responsesUrl : $completionUrl];
                    }
                }
            }
        }
    }

    /** @param class-string<AzureChatOpenAI|AzureChatOpenAICompletions|AzureChatOpenAIResponses> $class
     *  @param array<string, mixed> $fields
     *  @param array<string, mixed> $options
     */
    #[DataProvider('endpointCases')]
    public function testEndpointMatrix(string $class, bool $responses, bool $stream, array $fields, array $options, string $url): void
    {
        $http = self::http($responses);
        $chat = new $class(['model' => 'body-model', 'azureOpenAIApiDeploymentName' => 'dep',
            'azureOpenAIApiKey' => 'fake-key', 'azureOpenAIApiVersion' => self::VERSION,
            'httpClient' => $http, 'useResponsesApi' => $responses, ...$fields]);
        self::call($chat, $stream, $options);
        $request = $stream ? $http->streamRequests[0] : $http->requests[0];
        self::assertSame($url, $request['url']);
        self::assertSame(['api-version' => self::VERSION], $request['query']);
        self::assertSame('fake-key', $request['headers']['api-key']);
        self::assertSame('body-model', json_decode($request['body'], true)['model']);
        self::assertCount(1, $stream ? $http->streamRequests : $http->requests);
    }

    /** @return iterable<string, array{bool, bool, bool}> */
    public static function transportModes(): iterable
    {
        foreach ([false, true] as $responses) {
            foreach ([false, true] as $stream) {
                foreach ([false, true] as $token) {
                    yield ($responses ? 'responses' : 'completions') . '/' . ($stream ? 'stream' : 'invoke') . '/' . ($token ? 'token' : 'key')
                        => [$responses, $stream, $token];
                }
            }
        }
    }

    #[DataProvider('transportModes')]
    public function testHeaderAndQueryPrecedenceAndNoPerCallLeak(bool $responses, bool $stream, bool $token): void
    {
        $http = self::http($responses, 2);
        $calls = 0;
        $credentials = $token ? ['azureADTokenProvider' => function () use (&$calls): string { return 'token-' . ++$calls; }]
            : ['azureOpenAIApiKey' => 'key'];
        $chat = new AzureChatOpenAI('dep', [
            'azureOpenAIEndpoint' => 'https://a.openai.azure.com', 'azureOpenAIApiVersion' => self::VERSION,
            'configuration' => ['defaultHeaders' => ['X-Default' => 'default'],
                'headers' => ['X-Constructor' => 'constructor'],
                'defaultQuery' => ['region' => 'default', 'api-version' => 'ignored-default'],
                'query' => ['api-version' => 'constructor-version', 'tenant' => 'constructor']],
            'httpClient' => $http, 'useResponsesApi' => $responses, ...$credentials,
        ]);
        self::call($chat, $stream, ['headers' => ['API-KEY' => 'request-key', 'X-Call' => 'call'],
            'query' => ['api-version' => 'request-version', 'tenant' => 'call']]);
        self::call($chat, $stream);
        $requests = $stream ? $http->streamRequests : $http->requests;
        self::assertSame(['region' => 'default', 'api-version' => 'request-version', 'tenant' => 'call'], $requests[0]['query']);
        self::assertSame(['region' => 'default', 'api-version' => 'constructor-version', 'tenant' => 'constructor'], $requests[1]['query']);
        self::assertSame('request-key', $requests[0]['headers']['API-KEY']);
        self::assertArrayNotHasKey('api-key', $requests[0]['headers']);
        self::assertSame('default', $requests[0]['headers']['x-default']);
        self::assertSame('call', $requests[0]['headers']['X-Call']);
        self::assertArrayNotHasKey('X-Constructor', $requests[0]['headers']);
        self::assertSame('constructor', $requests[1]['headers']['X-Constructor']);
        self::assertArrayNotHasKey('X-Call', $requests[1]['headers']);
        if ($token) {
            self::assertSame(2, $calls);
            self::assertSame('Bearer token-1', $requests[0]['headers']['Authorization']);
            self::assertSame('Bearer token-2', $requests[1]['headers']['Authorization']);
            self::assertArrayNotHasKey('api-key', $requests[1]['headers']);
        } else {
            self::assertSame('key', $requests[1]['headers']['api-key']);
        }
    }

    #[DataProvider('transportModes')]
    public function testEnvironmentDefaultDeploymentVersionAndAuth(bool $responses, bool $stream, bool $token): void
    {
        putenv('AZURE_OPENAI_API_DEPLOYMENT_NAME=env-deployment');
        putenv('AZURE_OPENAI_API_VERSION=env-version');
        putenv('AZURE_OPENAI_ENDPOINT=https://env.openai.azure.com');
        $http = self::http($responses);
        $credentials = $token ? ['azureADTokenProvider' => fn (): string => 'env-token'] : [];
        if (!$token) {
            putenv('AZURE_OPENAI_API_KEY=env-key');
        }
        $chat = new AzureChatOpenAI(['model' => 'body-model', 'httpClient' => $http, 'useResponsesApi' => $responses, ...$credentials]);
        self::call($chat, $stream);
        $request = $stream ? $http->streamRequests[0] : $http->requests[0];
        self::assertSame($responses ? 'https://env.openai.azure.com/openai/responses'
            : 'https://env.openai.azure.com/openai/deployments/env-deployment/chat/completions', $request['url']);
        self::assertSame(['api-version' => 'env-version'], $request['query']);
        self::assertSame($token ? 'Bearer env-token' : 'env-key', $request['headers'][$token ? 'Authorization' : 'api-key']);
    }

    #[DataProvider('transportModes')]
    public function testConfiguredSdkVersionAndHeaderOverrides(bool $responses, bool $stream, bool $token): void
    {
        $http = self::http($responses);
        $credentials = $token ? ['azureADTokenProvider' => fn (): string => 'generated-token'] : ['azureOpenAIApiKey' => 'generated-key'];
        $chat = new AzureChatOpenAI('dep', ['azureOpenAIEndpoint' => 'https://a.openai.azure.com',
            'azureOpenAIApiVersion' => self::VERSION,
            'configuration' => ['apiVersion' => 'sdk-version', 'defaultHeaders' => ['X-Delete' => 'default'],
                'headers' => ['X-Delete' => null, 'Authorization' => 'Bearer explicit']],
            'httpClient' => $http, 'useResponsesApi' => $responses, ...$credentials]);
        self::call($chat, $stream);
        $request = $stream ? $http->streamRequests[0] : $http->requests[0];
        // With a key, common.ts forces the LangChain version into request query.
        // Token auth leaves the SDK's configured apiVersion in default query.
        self::assertSame(['api-version' => $token ? 'sdk-version' : self::VERSION], $request['query']);
        self::assertSame('Bearer explicit', $request['headers']['Authorization']);
        self::assertArrayNotHasKey('x-delete', $request['headers']);
        self::assertArrayNotHasKey('X-Delete', $request['headers']);
    }

    public function testSdkOpenAiVersionEnvironmentFallback(): void
    {
        putenv('OPENAI_API_VERSION=sdk-env-version');
        $http = self::http(false);
        $chat = new AzureChatOpenAICompletions('dep', ['azureOpenAIEndpoint' => 'https://a.openai.azure.com',
            'azureADTokenProvider' => fn (): string => 'token', 'httpClient' => $http]);
        $chat->invoke('hello');
        self::assertSame(['api-version' => 'sdk-env-version'], $http->requests[0]['query']);
    }

    public function testTypedResponsesEventsUseTheSameRequestOptions(): void
    {
        $http = self::http(true);
        $chat = new AzureChatOpenAI('dep', ['azureOpenAIEndpoint' => 'https://a.openai.azure.com',
            'azureOpenAIApiKey' => 'key', 'azureOpenAIApiVersion' => self::VERSION, 'httpClient' => $http, 'useResponsesApi' => true]);
        $events = iterator_to_array($chat->streamChatModelEvents([new HumanMessage('hello')], ['options' => [
            'baseURL' => 'https://proxy.example/openai/v1/', 'headers' => ['X-Call' => 'typed'], 'query' => ['api-version' => 'typed-version'],
        ]]));
        self::assertNotEmpty($events);
        self::assertSame('https://a.openai.azure.com/openai/responses', $http->streamRequests[0]['url']);
        self::assertSame('typed', $http->streamRequests[0]['headers']['X-Call']);
        self::assertSame(['api-version' => 'typed-version'], $http->streamRequests[0]['query']);
    }

    public function testExplicitFieldsOverrideDeploymentShorthand(): void
    {
        $fields = AzureChatOpenAI::getAzureChatOpenAIParams('default', ['model' => 'model', 'azureOpenAIApiDeploymentName' => 'explicit']);
        self::assertSame('model', $fields['model']);
        self::assertSame('explicit', $fields['azureOpenAIApiDeploymentName']);
    }

    public function testTokenProviderAndExplicitKeyKeepBothUpstreamRequestCredentials(): void
    {
        $http = self::http(true);
        $chat = new AzureChatOpenAIResponses('dep', ['azureOpenAIEndpoint' => 'https://a.openai.azure.com',
            'azureOpenAIApiKey' => 'key', 'azureADTokenProvider' => fn (): string => 'token',
            'azureOpenAIApiVersion' => self::VERSION, 'httpClient' => $http]);
        $chat->invoke('hello');
        self::assertSame('key', $http->requests[0]['headers']['api-key']);
        self::assertSame('Bearer token', $http->requests[0]['headers']['Authorization']);
    }

    public function testEmptyTokenFailsBeforeTransport(): void
    {
        $http = self::http(false);
        $chat = new AzureChatOpenAICompletions('dep', ['azureOpenAIEndpoint' => 'https://a.openai.azure.com',
            'azureADTokenProvider' => fn (): string => '', 'azureOpenAIApiVersion' => self::VERSION, 'httpClient' => $http]);
        try {
            $chat->invoke('hello');
            self::fail('The SDK rejects empty provider tokens.');
        } catch (OpenAIException $e) {
            self::assertStringContainsString('non-empty string', $e->getMessage());
            self::assertSame([], $http->requests);
        }
    }

    /** @param array<string, mixed> $options */
    private static function call(AzureChatOpenAI|AzureChatOpenAICompletions|AzureChatOpenAIResponses $chat, bool $stream, array $options = []): void
    {
        $config = new RunnableConfig(options: ['options' => $options]);
        if ($stream) {
            $text = '';
            foreach ($chat->stream('hello', $config) as [, $chunk]) {
                $text .= $chunk->text();
            }
            self::assertSame('OK', $text);
        } else {
            self::assertSame('OK', $chat->invoke('hello', $config)->text());
        }
    }

    private static function http(bool $responses, int $count = 1): FakeHttpClient
    {
        $payload = $responses ? ['id' => 'resp_fake', 'object' => 'response', 'status' => 'completed', 'model' => 'body-model',
            'output' => [['type' => 'message', 'id' => 'msg_fake', 'role' => 'assistant', 'status' => 'completed',
                'content' => [['type' => 'output_text', 'text' => 'OK', 'annotations' => []]]]]]
            : ['id' => 'chat_fake', 'object' => 'chat.completion', 'model' => 'body-model',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'OK'], 'finish_reason' => 'stop']]];
        $events = $responses ? [
            ['type' => 'response.created', 'response' => ['id' => 'resp_fake', 'model' => 'body-model']],
            ['type' => 'response.output_text.delta', 'delta' => 'OK', 'output_index' => 0, 'content_index' => 0],
            ['type' => 'response.completed', 'response' => $payload],
        ] : [['id' => 'chat_fake', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'OK'], 'finish_reason' => 'stop']]]];
        $sse = '';
        foreach ($events as $event) {
            $sse .= 'data: ' . Js::encode($event) . "\n\n";
        }
        $sse .= "data: [DONE]\n\n";

        return new FakeHttpClient(array_fill(0, $count, FakeHttpClient::json(200, $payload)), [$sse]);
    }
}
