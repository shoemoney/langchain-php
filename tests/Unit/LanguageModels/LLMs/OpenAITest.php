<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\LLMs;

use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\LanguageModels\LLMs\AzureOpenAI;
use LangChain\LanguageModels\LLMs\OpenAI;
use LangChain\Runnables\RunnableConfig;
use LangChain\Schema\StringPromptValue;
use LangChain\Tracers\CallbackHandler;
use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Js;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `tests/llms.int.test.ts` and `azure/tests/llms.int.test.ts`.
 *
 * The upstream tests call the live API. These run the same calls against a
 * transport that answers like `/completions` (one choice per prompt and
 * completion, text derived from the prompt, usage in tokens-by-words), so what
 * is asserted is the request this client builds and how it reads the reply,
 * including the streamed shape.
 */
#[CoversClass(OpenAI::class)]
#[CoversClass(AzureOpenAI::class)]
final class OpenAITest extends TestCase
{
    private const ENV = [
        'OPENAI_API_KEY', 'OPENAI_ORGANIZATION', 'AZURE_OPENAI_API_KEY', 'AZURE_OPENAI_API_INSTANCE_NAME',
        'AZURE_OPENAI_API_DEPLOYMENT_NAME', 'AZURE_OPENAI_API_COMPLETIONS_DEPLOYMENT_NAME', 'AZURE_OPENAI_API_VERSION',
        'AZURE_OPENAI_BASE_PATH', 'AZURE_OPENAI_ENDPOINT',
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

    /**
     * A transport that behaves like the completions endpoint.
     *
     * @return HttpClient&object{requests: list<array<string, mixed>>, streams: list<array<string, mixed>>}
     */
    private static function endpoint(): HttpClient
    {
        return new class implements HttpClient {
            /** @var list<array{url: string, headers: array<string, string>, body: string, query: array<string, mixed>}> */
            public array $requests = [];

            /** @var list<array{url: string, headers: array<string, string>, body: string, query: array<string, mixed>}> */
            public array $streams = [];

            public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
            {
                $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'query' => $query];
                $request = json_decode($body, true);
                $n = $request['n'] ?? 1;
                $choices = [];
                foreach ($request['prompt'] as $p => $prompt) {
                    for ($c = 0; $c < $n; $c++) {
                        $choices[] = ['index' => $p * $n + $c, 'text' => ' echo:' . $prompt, 'finish_reason' => 'stop', 'logprobs' => null];
                    }
                }
                $words = array_sum(array_map(static fn (string $p): int => str_word_count($p), $request['prompt']));

                return FakeHttpClient::json(200, [
                    'id' => 'cmpl-1', 'object' => 'text_completion', 'model' => $request['model'], 'choices' => $choices,
                    'usage' => ['prompt_tokens' => $words, 'completion_tokens' => count($choices), 'total_tokens' => $words + count($choices)],
                ]);
            }

            public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
            {
                $this->streams[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'query' => $query];
                $request = json_decode($body, true);
                $prompts = (array) $request['prompt'];
                $n = $request['n'] ?? 1;
                foreach (['Hel', 'lo ', 'wor', 'ld'] as $k => $piece) {
                    $choices = [];
                    foreach ($prompts as $p => $_) {
                        for ($c = 0; $c < $n; $c++) {
                            $choices[] = ['index' => $p * $n + $c, 'text' => $piece, 'finish_reason' => $k === 3 ? 'stop' : null, 'logprobs' => null];
                        }
                    }
                    yield 'data: ' . json_encode(['id' => 'cmpl-1', 'object' => 'text_completion', 'created' => 1, 'model' => $request['model'], 'choices' => $choices]) . "\n\n";
                }
                yield "data: [DONE]\n\n";
            }
        };
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function model(array $fields = []): OpenAI
    {
        return new OpenAI($fields + ['apiKey' => 'sk-test', 'model' => 'gpt-3.5-turbo-instruct', 'maxTokens' => 5, 'httpClient' => self::endpoint(), 'maxRetries' => 0]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function azure(array $fields = []): AzureOpenAI
    {
        return new AzureOpenAI($fields + [
            'azureOpenAIApiKey' => 'azure-key', 'azureOpenAIApiInstanceName' => 'inst', 'azureOpenAIApiDeploymentName' => 'dep',
            'azureOpenAIApiVersion' => '2024-10-21', 'modelName' => 'gpt-3.5-turbo-instruct', 'maxTokens' => 5,
            'httpClient' => self::endpoint(), 'maxRetries' => 0,
        ]);
    }

    /** @return array<string, mixed> */
    private static function sent(OpenAI $model, int $index = 0): array
    {
        return json_decode($model->httpClient->requests[$index]['body'], true);
    }

    // ---- llms.int.test.ts ------------------------------------------------------

    public function testOpenAI(): void
    {
        $model = self::model();

        $res = $model->invoke('Print hello world');

        self::assertSame(' echo:Print hello world', $res);
        self::assertSame(
            ['model' => 'gpt-3.5-turbo-instruct', 'max_tokens' => 5, 'n' => 1, 'stream' => false, 'prompt' => ['Print hello world']],
            self::sent($model),
        );
        self::assertSame('https://api.openai.com/v1/completions', $model->httpClient->requests[0]['url']);
        self::assertSame('Bearer sk-test', $model->httpClient->requests[0]['headers']['Authorization']);
    }

    public function testOpenAIWithStop(): void
    {
        $model = self::model();

        $model->invoke('Print hello world', new RunnableConfig(options: ['stop' => ['world']]));

        self::assertSame(['world'], self::sent($model)['stop']);
    }

    public function testOpenAIWithStopInObject(): void
    {
        $model = self::model(['stopSequences' => ['a']]);

        $model->invoke('Print hello world', new RunnableConfig(options: ['stop' => ['world']]));
        $model->invoke('Print hello world');

        self::assertSame(['world'], self::sent($model, 0)['stop'], 'a call option beats the constructor');
        self::assertSame(['a'], self::sent($model, 1)['stop']);
    }

    public function testOpenAIWithTimeoutInCallOptions(): void
    {
        $transport = new class implements HttpClient {
            public ?float $timeoutSeen = null;

            public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
            {
                $this->timeoutSeen = $timeout;
                throw new HttpException('Request timed out.', 0);
            }

            public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
            {
                yield from [];
            }
        };
        $model = new OpenAI(['apiKey' => 'k', 'maxTokens' => 5, 'maxRetries' => 0, 'httpClient' => $transport]);

        try {
            $model->invoke('Print hello world', new RunnableConfig(options: ['timeout' => 10]));
            self::fail('the timeout must surface');
        } catch (OpenAIException $e) {
            self::assertSame(10.0, $transport->timeoutSeen);
        }
    }

    public function testOpenAIWithSignalInCallOptions(): void
    {
        $model = self::model();
        $aborted = false;
        $signal = static function () use (&$aborted): bool {
            return $aborted;
        };
        $aborted = true;

        $this->expectExceptionMessage('AbortError');
        try {
            $model->invoke('Print hello world', new RunnableConfig(options: ['signal' => $signal]));
        } finally {
            self::assertSame([], $model->httpClient->requests, 'an aborted call never reaches the network');
        }
    }

    public function testOpenAIWithConcurrencyOfOne(): void
    {
        $model = self::model(['maxConcurrency' => 1]);

        $res = [$model->invoke('Print hello world'), $model->invoke('Print hello world')];

        self::assertSame([' echo:Print hello world', ' echo:Print hello world'], $res);
        self::assertSame(1, $model->maxConcurrency);
        self::assertCount(2, $model->httpClient->requests);
    }

    public function testOpenAIWithMaxTokensMinusOne(): void
    {
        $model = self::model(['maxTokens' => -1]);

        $model->invoke('Print hello world');

        $sent = self::sent($model)['max_tokens'];
        self::assertGreaterThan(0, $sent);
        self::assertLessThan(4097, $sent);
    }

    public function testMaxTokensMinusOneIsRefusedForMultiplePrompts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('max_tokens set to -1 not supported for multiple inputs');

        self::model(['maxTokens' => -1])->generateStrings(['a', 'b']);
    }

    public function testInstructModelReturnsOpenAI(): void
    {
        $model = self::model(['model' => 'gpt-3.5-turbo-instruct']);

        self::assertInstanceOf(OpenAI::class, $model);
        self::assertIsString($model->invoke('Print hello world'));
    }

    public function testVersionedInstructModelReturnsOpenAI(): void
    {
        $model = self::model(['model' => 'gpt-3.5-turbo-instruct-0914']);

        self::assertInstanceOf(OpenAI::class, $model);
        self::assertIsString($model->invoke('Print hello world'));
        self::assertSame('gpt-3.5-turbo-instruct-0914', self::sent($model)['model']);
    }

    public function testAChatModelNameIsRefused(): void
    {
        foreach (['gpt-3.5-turbo', 'gpt-4o', 'o1-mini'] as $name) {
            try {
                new OpenAI(['apiKey' => 'k', 'model' => $name]);
                self::fail($name . ' is a chat model');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('Please use the "ChatOpenAI" class instead.', $e->getMessage());
            }
        }
    }

    public function testTokenUsage(): void
    {
        $usage = null;
        $model = self::model();

        $model->invoke('Hello', new RunnableConfig(callbacks: [CallbackHandler::fromMethods([
            'handleLLMEnd' => static function ($output) use (&$usage): void {
                $usage = $output->llmOutput['tokenUsage'];
            },
        ])]));

        self::assertSame(['completionTokens' => 1, 'promptTokens' => 1, 'totalTokens' => 2], $usage);
    }

    public function testInStreamingMode(): void
    {
        $tokens = [];
        $model = self::model(['streaming' => true]);

        $res = $model->invoke('Print hello world', new RunnableConfig(callbacks: [CallbackHandler::fromMethods([
            'handleLLMNewToken' => static function (string $token) use (&$tokens): void {
                $tokens[] = $token;
            },
        ])]));

        self::assertGreaterThan(0, count($tokens));
        self::assertSame($res, implode('', $tokens));
        self::assertSame('Hello world', $res);
        self::assertTrue(json_decode($model->httpClient->streams[0]['body'], true)['stream']);
        self::assertSame([], $model->httpClient->requests);
    }

    public function testInStreamingModeWithMultiplePrompts(): void
    {
        $n = 0;
        $completions = [['', ''], ['', '']];
        $model = self::model(['streaming' => true, 'n' => 2]);

        $res = $model->generateStrings(['Print hello world', 'print hello sea'], new RunnableConfig(callbacks: [CallbackHandler::fromMethods([
            'handleLLMNewToken' => static function (string $token, array $idx) use (&$n, &$completions): void {
                $n++;
                $completions[$idx['prompt']][$idx['completion']] .= $token;
            },
        ])]));

        self::assertGreaterThan(0, $n);
        self::assertCount(2, $res->generations);
        self::assertSame($completions, array_map(static fn (array $g): array => array_map(static fn ($gg): string => $gg->text, $g), $res->generations));
        self::assertSame([['Hello world', 'Hello world'], ['Hello world', 'Hello world']], $completions);
        self::assertSame('stop', $res->generations[1][1]->generationInfo['finishReason']);
    }

    public function testInStreamingModeWithMultiplePromptsAndOneCompletion(): void
    {
        $n = 0;
        $completions = [[''], ['']];
        $model = self::model(['streaming' => true, 'n' => 1]);

        $res = $model->generateStrings(['Print hello world', 'print hello sea'], new RunnableConfig(callbacks: [CallbackHandler::fromMethods([
            'handleLLMNewToken' => static function (string $token, array $idx) use (&$n, &$completions): void {
                $n++;
                $completions[$idx['prompt']][$idx['completion']] .= $token;
            },
        ])]));

        self::assertGreaterThan(0, $n);
        self::assertCount(2, $res->generations);
        self::assertSame($completions, array_map(static fn (array $g): array => array_map(static fn ($gg): string => $gg->text, $g), $res->generations));
    }

    public function testPromptValue(): void
    {
        $model = self::model();

        $res = $model->generatePrompt([new StringPromptValue('Print hello world')]);

        self::assertCount(1, $res->generations);
        foreach ($res->generations as $generation) {
            self::assertCount(1, $generation);
        }
    }

    public function testStreamMethod(): void
    {
        $model = self::model(['maxTokens' => 50]);

        $chunks = [];
        foreach ($model->stream('Print hello world.') as [, $chunk]) {
            $chunks[] = $chunk;
        }

        self::assertSame(['Hel', 'lo ', 'wor', 'ld'], $chunks);
        self::assertGreaterThan(1, count($chunks));
        self::assertSame('https://api.openai.com/v1/completions', $model->httpClient->streams[0]['url']);
        self::assertSame('Print hello world.', json_decode($model->httpClient->streams[0]['body'], true)['prompt']);
    }

    public function testStreamMethodWithAbort(): void
    {
        $model = self::model(['maxTokens' => 250]);
        $seen = 0;
        $signal = static function () use (&$seen): bool {
            return $seen >= 2;
        };

        $this->expectExceptionMessage('AbortError');
        foreach ($model->stream('How is your day going? Be extremely verbose.', new RunnableConfig(options: ['signal' => $signal])) as $_) {
            $seen++;
        }
    }

    public function testStreamMethodWithEarlyBreak(): void
    {
        $model = self::model(['maxTokens' => 50]);

        $i = 0;
        foreach ($model->stream('How is your day going? Be extremely verbose.') as $_) {
            $i++;
            if ($i > 1) {
                break;
            }
        }

        self::assertSame(2, $i);
    }

    // ---- behaviour the live tests take for granted ----------------------------

    public function testPromptsAreBatchedByBatchSize(): void
    {
        $model = self::model(['batchSize' => 2]);

        $res = $model->generateStrings(['a', 'b', 'c']);

        self::assertCount(3, $res->generations);
        $sizes = array_map(static fn (array $r): int => count(json_decode($r['body'], true)['prompt']), $model->httpClient->requests);
        self::assertSame([2, 1], $sizes);
        self::assertSame(3, $res->llmOutput['tokenUsage']['promptTokens']);
    }

    public function testModelKwargsAreAppliedLast(): void
    {
        $model = self::model(['modelKwargs' => ['suffix' => '!', 'max_tokens' => 9]]);

        $model->invoke('x');

        self::assertSame('!', self::sent($model)['suffix']);
        self::assertSame(5, self::sent($model)['max_tokens'], 'a field already set keeps its value; modelKwargs only add');
    }

    public function testBestOfCannotStream(): void
    {
        $this->expectExceptionMessage('Cannot stream results when bestOf > 1');

        self::model(['streaming' => true, 'bestOf' => 2]);
    }

    public function testAnErrorStatusIsAnOpenAIException(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(401, ['error' => ['message' => 'bad key', 'code' => 'invalid_api_key']])]);

        try {
            (new OpenAI(['apiKey' => 'sk-x', 'httpClient' => $http, 'maxRetries' => 0]))->invoke('hi');
            self::fail('a 401 must surface');
        } catch (OpenAIException $e) {
            self::assertSame(401, $e->status);
            self::assertSame('invalid_api_key', $e->providerError['code']);
        }
    }

    public function testTheSerializedFormCarriesNoCredential(): void
    {
        $json = Js::encode(self::model(['temperature' => 0.2]));

        self::assertStringNotContainsString('sk-test', $json);
        self::assertStringContainsString('"id":["langchain","llms","openai","OpenAI"]', $json);
        self::assertStringContainsString('"temperature":0.2', $json);
    }

    // ---- azure/tests/llms.int.test.ts ------------------------------------------

    public function testAzureInvokeUsesTheDeploymentCompletionsUrl(): void
    {
        $model = self::azure();

        $res = $model->invoke('Print hello world');

        self::assertSame(' echo:Print hello world', $res);
        $request = $model->httpClient->requests[0];
        self::assertSame('https://inst.openai.azure.com/openai/deployments/dep/completions', $request['url']);
        self::assertSame(['api-version' => '2024-10-21'], $request['query']);
        self::assertSame('azure-key', $request['headers']['api-key']);
        self::assertArrayNotHasKey('Authorization', $request['headers']);
    }

    public function testAzureWithStopInObject(): void
    {
        $model = self::azure();

        $model->invoke('Print hello world', new RunnableConfig(options: ['stop' => ['world']]));

        self::assertSame(['world'], self::sent($model)['stop']);
    }

    public function testAzureWithTimeoutInCallOptions(): void
    {
        $transport = new class implements HttpClient {
            public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
            {
                throw new HttpException('Request timed out.', 0);
            }

            public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
            {
                yield from [];
            }
        };

        $this->expectException(OpenAIException::class);
        self::azure(['httpClient' => $transport])->invoke('Print hello world', new RunnableConfig(options: ['timeout' => 10]));
    }

    public function testAzureWithSignalInCallOptions(): void
    {
        $this->expectExceptionMessage('AbortError');

        self::azure()->invoke('Print hello world', new RunnableConfig(options: ['signal' => static fn (): bool => true]));
    }

    public function testAzureWithConcurrencyOfOne(): void
    {
        $model = self::azure(['maxConcurrency' => 1]);

        self::assertSame(
            [' echo:Print hello world', ' echo:Print hello world'],
            [$model->invoke('Print hello world'), $model->invoke('Print hello world')],
        );
    }

    public function testAzureWithMaxTokensMinusOne(): void
    {
        $model = self::azure(['maxTokens' => -1]);

        $model->invoke('Print hello world');

        self::assertGreaterThan(0, self::sent($model)['max_tokens']);
    }

    public function testAzureWithModelName(): void
    {
        $model = self::azure(['modelName' => 'gpt-3.5-turbo-instruct']);

        self::assertInstanceOf(AzureOpenAI::class, $model);
        self::assertIsString($model->invoke('Print hello world'));
    }

    public function testAzureWithVersionedInstructModel(): void
    {
        $model = self::azure(['modelName' => 'gpt-3.5-turbo-instruct-0914']);

        self::assertInstanceOf(AzureOpenAI::class, $model);
        self::assertIsString($model->invoke('Print hello world'));
    }

    public function testAzureTokenUsage(): void
    {
        $usage = null;
        $model = self::azure();

        $model->invoke('Hello', new RunnableConfig(callbacks: [CallbackHandler::fromMethods([
            'handleLLMEnd' => static function ($output) use (&$usage): void {
                $usage = $output->llmOutput['tokenUsage'];
            },
        ])]));

        self::assertSame(1, $usage['promptTokens']);
    }

    public function testAzureInStreamingMode(): void
    {
        $tokens = [];
        $model = self::azure(['streaming' => true]);

        $res = $model->invoke('Print hello world', new RunnableConfig(callbacks: [CallbackHandler::fromMethods([
            'handleLLMNewToken' => static function (string $token) use (&$tokens): void {
                $tokens[] = $token;
            },
        ])]));

        self::assertSame($res, implode('', $tokens));
        $stream = $model->httpClient->streams[0];
        self::assertSame('https://inst.openai.azure.com/openai/deployments/dep/completions', $stream['url']);
        self::assertSame(['api-version' => '2024-10-21'], $stream['query']);
    }

    public function testAzureInStreamingModeWithMultiplePrompts(): void
    {
        $completions = [['', ''], ['', '']];
        $model = self::azure(['streaming' => true, 'n' => 2]);

        $res = $model->generateStrings(['Print hello world', 'print hello sea'], new RunnableConfig(callbacks: [CallbackHandler::fromMethods([
            'handleLLMNewToken' => static function (string $token, array $idx) use (&$completions): void {
                $completions[$idx['prompt']][$idx['completion']] .= $token;
            },
        ])]));

        self::assertSame($completions, array_map(static fn (array $g): array => array_map(static fn ($gg): string => $gg->text, $g), $res->generations));
    }

    public function testAzurePromptValue(): void
    {
        $res = self::azure()->generatePrompt([new StringPromptValue('Print hello world')]);

        self::assertCount(1, $res->generations);
        self::assertCount(1, $res->generations[0]);
    }

    public function testAzureStreamMethod(): void
    {
        $chunks = [];
        foreach (self::azure(['maxTokens' => 50])->stream('Print hello world.') as [, $chunk]) {
            $chunks[] = $chunk;
        }

        self::assertGreaterThan(1, count($chunks));
    }

    public function testAzureBearerTokenCredentials(): void
    {
        $model = new AzureOpenAI([
            'maxTokens' => 5, 'modelName' => 'davinci-002', 'azureADTokenProvider' => static fn (): string => 'ad-token',
            'azureOpenAIApiInstanceName' => 'inst', 'azureOpenAIApiDeploymentName' => 'dep', 'azureOpenAIApiVersion' => 'v1',
            'httpClient' => self::endpoint(), 'maxRetries' => 0,
        ]);

        $model->invoke('Print hello world');

        self::assertSame('Bearer ad-token', $model->httpClient->requests[0]['headers']['Authorization']);
        self::assertArrayNotHasKey('api-key', $model->httpClient->requests[0]['headers']);
    }

    public function testAzureReadsTheCompletionsDeploymentFromTheEnvironmentAheadOfTheGenericOne(): void
    {
        putenv('AZURE_OPENAI_API_KEY=env-key');
        putenv('AZURE_OPENAI_API_INSTANCE_NAME=envinst');
        putenv('AZURE_OPENAI_API_DEPLOYMENT_NAME=generic');
        putenv('AZURE_OPENAI_API_COMPLETIONS_DEPLOYMENT_NAME=completions-only');
        putenv('AZURE_OPENAI_API_VERSION=2024-10-21');
        $model = new AzureOpenAI(['httpClient' => self::endpoint(), 'maxRetries' => 0]);

        $model->invoke('x');

        self::assertSame(
            'https://envinst.openai.azure.com/openai/deployments/completions-only/completions',
            $model->httpClient->requests[0]['url'],
        );
    }

    public function testAzureRefusesToStartWithoutCredentials(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Azure OpenAI API key or Token Provider not found');

        new AzureOpenAI(['azureOpenAIApiInstanceName' => 'inst', 'azureOpenAIApiDeploymentName' => 'dep']);
    }

    public function testAzureSerializationAliasesFieldsAndHidesTheKey(): void
    {
        $json = Js::encode(self::azure());

        self::assertStringNotContainsString('azure-key', $json);
        self::assertStringContainsString('"azure_open_ai_api_key":{"lc":1,"type":"secret","id":["AZURE_OPENAI_API_KEY"]}', $json);
        self::assertStringContainsString('"deployment_name":"dep"', $json);
        self::assertStringContainsString('"openai_api_version":"2024-10-21"', $json);
    }
}
