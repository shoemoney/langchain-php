<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenRouter;

use LangChain\LanguageModels\Chat\OpenRouter\ChatOpenRouter;
use LangChain\LanguageModels\Chat\OpenRouter\Profiles;
use LangChain\LanguageModels\Chat\OpenRouter\Utils\OpenRouterAuthError;
use LangChain\LanguageModels\Chat\OpenRouter\Utils\OpenRouterError;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\OutputParsers\StringOutputParser;
use LangChain\Prompts\ChatPromptTemplate;
use LangChain\Tools\Schema;
use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Testing\FakeHttpClient;
use LangGraph\Prebuilt\ReactAgent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * Runs ChatOpenRouter through real chains and a real agent graph over a scripted
 * transport, asserting on what went on the wire as well as what came back.
 */
#[CoversClass(ChatOpenRouter::class)]
#[CoversClass(Profiles::class)]
final class ChatOpenRouterEndToEndTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function reply(string $content, ?array $toolCalls = null, string $id = 'gen-1'): array
    {
        $message = ['role' => 'assistant', 'content' => $content];
        if ($toolCalls !== null) {
            $message['tool_calls'] = $toolCalls;
        }

        return [
            'id' => $id,
            'model' => 'openai/gpt-4o',
            'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $toolCalls !== null ? 'tool_calls' : 'stop']],
            'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 3, 'total_tokens' => 10],
        ];
    }

    /** @param list<HttpResponse> $responses */
    private static function model(FakeHttpClient $http, array $fields = []): ChatOpenRouter
    {
        return new ChatOpenRouter(['model' => 'openai/gpt-4o', 'apiKey' => 'k', 'httpClient' => $http] + $fields);
    }

    public function testPromptModelParserChain(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, self::reply('Paris'))]);
        $chain = ChatPromptTemplate::fromMessages([['system', 'Answer briefly.'], ['human', '{question}']])
            ->pipe(self::model($http, ['temperature' => 0.1]))
            ->pipe(new StringOutputParser());

        self::assertSame('Paris', $chain->invoke(['question' => 'Capital of France?']));

        $body = $http->lastRequestBody();
        self::assertSame('openai/gpt-4o', $body['model']);
        self::assertSame(0.1, $body['temperature']);
        self::assertFalse($body['stream']);
        self::assertSame(
            [['role' => 'system', 'content' => 'Answer briefly.'], ['role' => 'user', 'content' => 'Capital of France?']],
            $body['messages'],
        );
        self::assertSame('Bearer k', $http->requests[0]['headers']['Authorization']);
        self::assertSame('https://openrouter.ai/api/v1/chat/completions', $http->requests[0]['url']);
    }

    public function testUsageAndModelMetadataSurviveTheEagerPath(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, self::reply('hi'))]);

        $message = self::model($http)->invoke('hello');

        self::assertSame(['input_tokens' => 7, 'output_tokens' => 3, 'total_tokens' => 10], $message->response_metadata['usage_metadata']);
        self::assertSame('openrouter', $message->response_metadata['model_provider']);
    }

    public function testReactAgentRunsAToolLoopThroughOpenRouter(): void
    {
        $call = [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'add', 'arguments' => '{"a":2,"b":3}']]];
        $http = new FakeHttpClient([
            FakeHttpClient::json(200, self::reply('', $call)),
            FakeHttpClient::json(200, self::reply('The sum is 5.', null, 'gen-2')),
        ]);
        $add = tool(
            static fn (array $in): string => (string) ($in['a'] + $in['b']),
            ['name' => 'add', 'description' => 'Add two numbers.', 'schema' => Schema::object(['a' => ['type' => 'number'], 'b' => ['type' => 'number']], ['a', 'b'])],
        );

        $agent = ReactAgent::create(['llm' => self::model($http), 'tools' => [$add]]);
        $result = $agent->invoke(['messages' => [new HumanMessage('2 + 3?')]]);

        // Distinct response ids matter: the messages reducer treats equal ids as an update.
        $messages = $result['messages'];
        self::assertCount(4, $messages);
        self::assertInstanceOf(ToolMessage::class, $messages[2]);
        self::assertSame('5', $messages[2]->content);
        self::assertInstanceOf(AIMessage::class, $messages[3]);
        self::assertSame('The sum is 5.', $messages[3]->content);

        self::assertCount(2, $http->requests);
        $first = json_decode($http->requests[0]['body'], true);
        self::assertSame('add', $first['tools'][0]['function']['name']);
        $second = json_decode($http->requests[1]['body'], true);
        self::assertSame('call_1', $second['messages'][2]['tool_call_id']);
        self::assertSame('5', $second['messages'][2]['content']);
    }

    public function testStreamFoldsIntoOneMessageWithToolCallsAndUsage(): void
    {
        $chunks = OpenAiStreamFixtures::toolCallChunks();
        $chunks[] = ['id' => 'chatcmpl-tools', 'choices' => [], 'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 6, 'total_tokens' => 10]];
        $http = new FakeHttpClient([], OpenAiStreamFixtures::sseBody($chunks));

        $folded = null;
        foreach (self::model($http)->stream('search') as [$_, $chunk]) {
            $folded = $folded === null ? $chunk : $folded->concat($chunk);
        }

        [$toolCalls] = $folded->parseToolCalls();
        self::assertSame('web_search', $toolCalls[0]['name']);
        self::assertSame(['query' => 'weather'], $toolCalls[0]['args']);
        self::assertSame('Let me search.', $folded->content);
    }

    public function testStreamingUsageOnlyChunkIsNotLostFromTheRun(): void
    {
        $chunks = OpenAiStreamFixtures::textOnlyChunks();
        $chunks[] = ['id' => 'chatcmpl-text', 'choices' => [], 'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 6, 'total_tokens' => 10]];
        $http = new FakeHttpClient([], OpenAiStreamFixtures::sseBody($chunks));
        $model = self::model($http);

        $method = new \ReflectionMethod($model, 'streamResponseChunks');
        $aggregate = null;
        foreach ($method->invoke($model, [new HumanMessage('hi')], []) as $chunk) {
            $aggregate = $aggregate === null ? $chunk : $aggregate->concat($chunk);
        }

        self::assertSame('Hello world', $aggregate->text);
        self::assertSame(['input_tokens' => 4, 'output_tokens' => 6, 'total_tokens' => 10], $aggregate->message->response_metadata['usage_metadata']);
    }

    public function testStreamUsageFalseDropsUsageFromChunks(): void
    {
        $http = new FakeHttpClient([], OpenAiStreamFixtures::sseBody(OpenAiStreamFixtures::textOnlyChunksWithUsage()));
        $model = self::model($http, ['streamUsage' => false]);

        $method = new \ReflectionMethod($model, 'streamResponseChunks');
        foreach ($method->invoke($model, [new HumanMessage('hi')], []) as $chunk) {
            self::assertArrayNotHasKey('usage_metadata', $chunk->message->response_metadata);
        }
    }

    public function testSseKeepAliveCommentsAndDoneAreIgnored(): void
    {
        $body = ": OPENROUTER PROCESSING\n\n"
            . 'data: ' . json_encode(OpenAiStreamFixtures::textOnlyChunks()[0]) . "\n\n"
            . "data: [DONE]\n\n";
        $http = new FakeHttpClient([], [$body]);

        $text = '';
        foreach (self::model($http)->stream('hi') as [$_, $chunk]) {
            $text .= $chunk->content;
        }

        self::assertSame('Hello', $text);
    }

    public function testHttp401BecomesAnAuthErrorAndIsNotRetried(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(401, ['error' => ['message' => 'No auth credentials found', 'code' => 401]])]);

        try {
            self::model($http, ['maxRetries' => 3, 'sleeper' => static function (): void {
            }])->invoke('hi');
            self::fail('Expected an auth error.');
        } catch (OpenRouterAuthError $e) {
            self::assertSame('No auth credentials found', $e->getMessage());
        }

        self::assertCount(1, $http->requests);
    }

    public function testServerErrorsAreRetriedThenSurfaceAsOpenRouterError(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::json(500, ['error' => ['message' => 'boom']]),
            FakeHttpClient::json(500, ['error' => ['message' => 'boom']]),
        ]);
        $waits = 0;

        try {
            self::model($http, ['maxRetries' => 1, 'sleeper' => static function () use (&$waits): void {
                $waits++;
            }])->invoke('hi');
            self::fail('Expected an error.');
        } catch (OpenRouterError $e) {
            self::assertSame(500, $e->statusCode);
        }

        self::assertCount(2, $http->requests);
        self::assertSame(1, $waits);
    }

    public function testAResponseWithNoChoicesIsAnError(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, ['id' => 'x', 'choices' => []])]);

        $this->expectException(OpenRouterError::class);
        $this->expectExceptionMessage('No choices returned in response.');

        self::model($http)->invoke('hi');
    }

    public function testAStreamingConnectFailureCarriesTheProvidersMessage(): void
    {
        $transport = new class () implements HttpClient {
            public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
            {
                throw new \LogicException('not used');
            }

            public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
            {
                throw new HttpException('HTTP 402', 402, '{"error":{"message":"Insufficient credits","code":402}}');
                yield '';
            }
        };
        $model = new ChatOpenRouter(['model' => 'openai/gpt-4o', 'apiKey' => 'k', 'httpClient' => $transport]);

        try {
            iterator_to_array($model->stream('hi'));
            self::fail('Expected an error.');
        } catch (OpenRouterError $e) {
            self::assertSame('Insufficient credits', $e->getMessage());
            self::assertSame(402, $e->statusCode);
        }
    }

    public function testModelKwargsAreMergedLastAndNullsAreOmitted(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, self::reply('ok'))]);

        self::model($http, ['modelKwargs' => ['top_p' => 0.5, 'custom_flag' => true], 'topP' => 0.9])->invoke('hi');

        $body = $http->lastRequestBody();
        self::assertSame(0.5, $body['top_p']);
        self::assertTrue($body['custom_flag']);
        self::assertArrayNotHasKey('temperature', $body);
        self::assertArrayNotHasKey('tools', $body);
    }

    public function testBindToolsDoesNotMutateTheOriginalAndSendsToolChoiceAndStrict(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, self::reply('ok'))]);
        $model = self::model($http);
        $add = tool(static fn (array $in): string => 'x', ['name' => 'add', 'description' => 'Add.', 'schema' => Schema::object(['a' => ['type' => 'number']], ['a'])]);

        $bound = $model->bindTools([$add], ['tool_choice' => 'any', 'strict' => true]);
        $bound->invoke('hi');

        $body = $http->lastRequestBody();
        self::assertSame('add', $body['tools'][0]['function']['name']);
        self::assertTrue($body['tools'][0]['function']['strict']);
        self::assertSame('required', $body['tool_choice']);
        self::assertArrayNotHasKey('tools', $model->invocationParams([]));
        // Bound tools are already wire-shaped, so the serialized trace payload never holds a tool object.
        self::assertSame('add', $bound->kwargs()['tools'][0]['function']['name']);
    }

    public function testStructuredOutputUsesNativeJsonSchemaForCapableModels(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, self::reply('{"city":"Paris"}'))]);
        $model = new ChatOpenRouter(['model' => 'openai/gpt-4o-mini', 'apiKey' => 'k', 'httpClient' => $http]);

        $result = $model->withStructuredOutput(['type' => 'object', 'properties' => ['city' => ['type' => 'string']]], ['name' => 'Place', 'strict' => true])
            ->invoke('Where?');

        self::assertSame(['city' => 'Paris'], $result);
        $format = $http->lastRequestBody()['response_format'];
        self::assertSame('Place', $format['json_schema']['name']);
        self::assertTrue($format['json_schema']['strict']);
    }

    public function testRoutedModelsForceFunctionCallingEvenForCapableModels(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, self::reply('', [['id' => 'c', 'type' => 'function', 'function' => ['name' => 'extract', 'arguments' => '{"city":"Rome"}']]]))]);
        $model = new ChatOpenRouter(['model' => 'openai/gpt-4o-mini', 'models' => ['a/b', 'c/d'], 'apiKey' => 'k', 'httpClient' => $http]);

        $result = $model->withStructuredOutput(['type' => 'object', 'properties' => ['city' => ['type' => 'string']]])->invoke('Where?');

        self::assertSame(['city' => 'Rome'], $result);
        self::assertArrayHasKey('tools', $http->lastRequestBody());
        self::assertArrayNotHasKey('response_format', $http->lastRequestBody());
    }

    // ─── profiles ────────────────────────────────────────────────────

    public function testProfileTableIsTheGeneratedUpstreamTable(): void
    {
        self::assertCount(183, Profiles::all());
        self::assertSame(
            [
                'maxInputTokens' => 131072,
                'imageInputs' => false,
                'audioInputs' => false,
                'pdfInputs' => false,
                'videoInputs' => false,
                'maxOutputTokens' => 8192,
                'reasoningOutput' => true,
                'imageOutputs' => false,
                'audioOutputs' => false,
                'videoOutputs' => false,
                'toolCalling' => true,
                'structuredOutput' => true,
            ],
            Profiles::for('prime-intellect/intellect-3'),
        );
    }

    public function testUnknownModelHasAnEmptyProfile(): void
    {
        self::assertSame([], Profiles::for('nobody/nothing'));
        self::assertSame([], (new ChatOpenRouter(['model' => 'nobody/nothing', 'apiKey' => 'k']))->profile());
        self::assertTrue((new ChatOpenRouter(['model' => 'openai/gpt-4o-mini', 'apiKey' => 'k']))->profile()['structuredOutput']);
    }

    public function testApiKeyIsNeverWrittenIntoSerializedKwargs(): void
    {
        $model = new ChatOpenRouter(['model' => 'openai/gpt-4o', 'apiKey' => 'sk-secret', 'temperature' => 0.2]);

        self::assertStringNotContainsString('sk-secret', (string) json_encode($model->toSerializedConstructor()));
        self::assertSame(['langchain', 'chat_models', 'openrouter', 'ChatOpenRouter'], $model::lcId());
        self::assertSame(0.2, $model->kwargs()['temperature']);
    }

    public function testRequiresAModelName(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ChatOpenRouter(['apiKey' => 'k']);
    }
}
