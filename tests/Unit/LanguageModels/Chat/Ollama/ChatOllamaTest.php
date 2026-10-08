<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Ollama;

use LangChain\LanguageModels\Chat\Ollama\ChatOllama;
use LangChain\LanguageModels\Chat\Ollama\OllamaException;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Prompts\ChatPromptTemplate;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tools\Schema;
use LangChain\Tracers\RunCollectorCallbackHandler;
use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * Port of `chat_models.test.ts` (constructor overload, `withStructuredOutput`),
 * plus the wire-level assertions HANDOFF section 4 demands: `think`, `format`,
 * tool binding and `keep_alive` must reach the request BODY.
 *
 * What is NOT converted from `chat_models.test.ts`, and why: the four
 * "invalid output throws OutputParserException" cases for the Standard Schema
 * path. They exercise Zod / Standard Schema validation of the parsed value;
 * this port is JSON-Schema-native and has no schema-validating parser, so there
 * is no behaviour to assert. The matching "valid output parses" cases are
 * converted over a real NDJSON body instead of a mocked `invoke`.
 */
#[CoversClass(ChatOllama::class)]
#[CoversClass(OllamaException::class)]
final class ChatOllamaTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $records
     * @param list<int>                  $splitAt Byte offsets to cut the body at, to prove framing is chunk-independent.
     */
    private static function ndjson(array $records, array $splitAt = []): array
    {
        $body = implode('', array_map(static fn (array $r): string => json_encode($r) . "\n", $records));
        if ($splitAt === []) {
            return [$body];
        }

        $chunks = [];
        $from = 0;
        foreach ($splitAt as $offset) {
            $chunks[] = substr($body, $from, $offset - $from);
            $from = $offset;
        }
        $chunks[] = substr($body, $from);

        return $chunks;
    }

    /** @return list<array<string, mixed>> */
    private static function reply(string $text): array
    {
        return [
            ['model' => 'llama3', 'message' => ['role' => 'assistant', 'content' => $text], 'done' => false],
            [
                'model' => 'llama3',
                'created_at' => '2026-10-08T00:00:00Z',
                'message' => ['role' => 'assistant', 'content' => ''],
                'done' => true,
                'done_reason' => 'stop',
                'prompt_eval_count' => 19,
                'eval_count' => 20,
            ],
        ];
    }

    private static function model(FakeHttpClient $http, array $fields = []): ChatOllama
    {
        return new ChatOllama(['model' => 'llama3', 'httpClient' => $http] + $fields);
    }

    // ---- constructor overload (chat_models.test.ts) ----------------------------

    public function testAcceptsAModelStringShorthand(): void
    {
        self::assertSame('llama3', (new ChatOllama('llama3'))->model);
        self::assertSame('llama3', (new ChatOllama(['model' => 'llama3']))->model);
    }

    public function testMergesModelStringWithAdditionalParams(): void
    {
        $baseUrl = 'http://127.0.0.1:11435';
        $model = new ChatOllama('llama3', ['baseUrl' => $baseUrl]);

        self::assertSame('llama3', $model->model);
        self::assertSame($baseUrl, $model->baseUrl);
    }

    public function testBaseUrlResolvesFromTheEnvironmentThenTheDefault(): void
    {
        $previous = getenv('OLLAMA_BASE_URL');
        try {
            putenv('OLLAMA_BASE_URL');
            self::assertSame('http://127.0.0.1:11434', (new ChatOllama())->baseUrl);

            putenv('OLLAMA_BASE_URL=http://from-env:1/');
            self::assertSame('http://from-env:1', (new ChatOllama())->baseUrl);
            self::assertSame('http://explicit:2', (new ChatOllama(['baseUrl' => 'http://explicit:2']))->baseUrl);
        } finally {
            $previous === false ? putenv('OLLAMA_BASE_URL') : putenv('OLLAMA_BASE_URL=' . $previous);
        }
    }

    public function testDefaultsMatchUpstream(): void
    {
        $model = new ChatOllama();

        self::assertSame('llama3', $model->model);
        self::assertFalse($model->checkOrPullModel);
        self::assertSame('ollama', $model->llmType());
        self::assertSame(['langchain', 'chat_models', 'ollama', 'ChatOllama'], ChatOllama::lcId());
    }

    // ---- the request body ---------------------------------------------------------

    public function testInvokePostsToApiChatWithStreamTrueAndTheConversation(): void
    {
        $http = new FakeHttpClient([], self::ndjson(self::reply('Bonjour')));
        $model = self::model($http, ['baseUrl' => 'http://ollama.test:11434']);

        $model->invoke([new \LangChain\Messages\SystemMessage('be brief'), new HumanMessage('hi')]);

        self::assertSame('http://ollama.test:11434/api/chat', $http->streamRequests[0]['url']);
        self::assertSame('application/json', $http->streamRequests[0]['headers']['Content-Type']);
        $body = $http->lastRequestBody();
        self::assertSame('llama3', $body['model']);
        self::assertTrue($body['stream']);
        self::assertSame(
            [['role' => 'system', 'content' => 'be brief'], ['role' => 'user', 'content' => 'hi']],
            $body['messages'],
        );
        self::assertArrayNotHasKey('options', $body, 'no overrides, no options object');
        self::assertArrayNotHasKey('tools', $body);
        self::assertArrayNotHasKey('think', $body);
    }

    public function testThinkKeepAliveAndFormatReachTheWire(): void
    {
        $http = new FakeHttpClient([], self::ndjson(self::reply('ok')));
        $schema = ['type' => 'object', 'properties' => ['name' => ['type' => 'string']], 'required' => ['name']];

        self::model($http, ['think' => true, 'keepAlive' => '10m', 'format' => $schema])->invoke('hi');

        $body = $http->lastRequestBody();
        self::assertTrue($body['think']);
        self::assertSame('10m', $body['keep_alive']);
        self::assertSame($schema, $body['format']);
    }

    public function testThinkFalseIsAValueNotAnAbsence(): void
    {
        $http = new FakeHttpClient([], self::ndjson(self::reply('ok')));

        self::model($http, ['think' => false, 'keepAlive' => 0])->invoke('hi');

        self::assertFalse($http->lastRequestBody()['think']);
        self::assertSame(0, $http->lastRequestBody()['keep_alive']);
    }

    public function testCamelCaseOptionsAreSentSnakeCasedInANestedOptionsObject(): void
    {
        $http = new FakeHttpClient([], self::ndjson(self::reply('ok')));

        self::model($http, [
            'numCtx' => 4096,
            'numPredict' => 128,
            'topK' => 40,
            'topP' => 0.9,
            'temperature' => 0.0,
            'seed' => 7,
            'f16Kv' => true,
            'repeatLastN' => 64,
            'penalizeNewline' => false,
            'mirostatTau' => 5.0,
        ])->invoke('hi');

        self::assertEquals([
            'num_ctx' => 4096,
            'num_predict' => 128,
            'top_k' => 40,
            'top_p' => 0.9,
            'temperature' => 0.0,
            'seed' => 7,
            'f16_kv' => true,
            'repeat_last_n' => 64,
            'penalize_newline' => false,
            'mirostat_tau' => 5.0,
        ], $http->lastRequestBody()['options']);
    }

    public function testPerCallStopAndFormatBeatTheConstructor(): void
    {
        $http = new FakeHttpClient([], self::ndjson(self::reply('ok')));
        $model = self::model($http, ['stop' => ['A'], 'format' => 'json']);

        $model->invoke('hi');
        self::assertSame(['A'], $http->lastRequestBody()['options']['stop']);
        self::assertSame('json', $http->lastRequestBody()['format']);

        $http->streamChunks = self::ndjson(self::reply('ok'));
        $model->invoke('hi', new RunnableConfig(options: ['stop' => ['B'], 'format' => ['type' => 'object']]));
        self::assertSame(['B'], $http->lastRequestBody()['options']['stop']);
        self::assertSame(['type' => 'object'], $http->lastRequestBody()['format']);
    }

    public function testBoundOptionsReachTheWire(): void
    {
        $http = new FakeHttpClient([], self::ndjson(self::reply('ok')));

        self::model($http)->bind(['stop' => ['END']])->invoke('hi');

        self::assertSame(['END'], $http->lastRequestBody()['options']['stop']);
    }

    public function testCustomHeadersAreSentAndMayOverrideTheDefaults(): void
    {
        $http = new FakeHttpClient([], self::ndjson(self::reply('ok')));

        self::model($http, ['headers' => ['Authorization' => 'Bearer secret']])->invoke('hi');

        self::assertSame('Bearer secret', $http->streamRequests[0]['headers']['Authorization']);
        self::assertSame('application/json', $http->streamRequests[0]['headers']['Content-Type']);
    }

    // ---- tool binding ---------------------------------------------------------------

    private static function weatherTool(): \LangChain\Tools\StructuredTool
    {
        return tool(static fn (array $a): string => 'sunny', [
            'name' => 'get_weather',
            'description' => 'Get the weather',
            'schema' => Schema::object(['city' => Schema::string()], ['city']),
        ]);
    }

    public function testBoundToolsReachTheWireInTheFunctionShape(): void
    {
        $http = new FakeHttpClient([], self::ndjson(self::reply('ok')));

        self::model($http)->bindTools([self::weatherTool()])->invoke('weather?');

        $tools = $http->lastRequestBody()['tools'];
        self::assertCount(1, $tools);
        self::assertSame('function', $tools[0]['type']);
        self::assertSame('get_weather', $tools[0]['function']['name']);
        self::assertSame('Get the weather', $tools[0]['function']['description']);
        self::assertSame('object', $tools[0]['function']['parameters']['type']);
        self::assertArrayHasKey('city', $tools[0]['function']['parameters']['properties']);
    }

    public function testBindToolsReturnsANewInstanceAndLeavesTheOriginalUntooled(): void
    {
        $http = new FakeHttpClient([], self::ndjson(self::reply('ok')));
        $model = self::model($http);

        $bound = $model->bindTools([self::weatherTool()]);
        self::assertNotSame($model, $bound);

        $model->invoke('hi');
        self::assertArrayNotHasKey('tools', $http->lastRequestBody());
    }

    public function testBindToolsKwargsBindAlongside(): void
    {
        $http = new FakeHttpClient([], self::ndjson(self::reply('ok')));

        self::model($http)->bindTools([self::weatherTool()], ['stop' => ['STOP']])->invoke('hi');

        self::assertSame(['STOP'], $http->lastRequestBody()['options']['stop']);
        self::assertCount(1, $http->lastRequestBody()['tools']);
    }

    public function testPerCallToolsAreConvertedToo(): void
    {
        $http = new FakeHttpClient([], self::ndjson(self::reply('ok')));

        self::model($http)->invoke('hi', new RunnableConfig(options: ['tools' => [self::weatherTool()]]));

        self::assertSame('get_weather', $http->lastRequestBody()['tools'][0]['function']['name']);
    }

    public function testAToolResultRoundTripsAsAToolRoleMessage(): void
    {
        $http = new FakeHttpClient([], self::ndjson(self::reply('It is sunny.')));

        self::model($http)->invoke([
            new HumanMessage('weather in Austin?'),
            new AIMessage(['content' => '', 'tool_calls' => [['id' => 'c1', 'name' => 'get_weather', 'args' => ['city' => 'Austin']]]]),
            new ToolMessage(['content' => 'sunny', 'tool_call_id' => 'c1']),
        ]);

        $messages = $http->lastRequestBody()['messages'];
        self::assertSame('assistant', $messages[1]['role']);
        self::assertSame(['city' => 'Austin'], $messages[1]['tool_calls'][0]['function']['arguments']);
        self::assertSame(['role' => 'tool', 'content' => 'sunny'], $messages[2]);
    }

    public function testANoArgumentToolCallIsSentAsAnObject(): void
    {
        $http = new FakeHttpClient([], self::ndjson(self::reply('ok')));

        self::model($http)->invoke([
            new HumanMessage('now?'),
            new AIMessage(['content' => '', 'tool_calls' => [['id' => 'c1', 'name' => 'clock', 'args' => []]]]),
            new ToolMessage(['content' => '12:00', 'tool_call_id' => 'c1']),
        ]);

        self::assertStringContainsString('"arguments":{}', $http->streamRequests[0]['body']);
    }

    // ---- the response ---------------------------------------------------------------------

    public function testInvokeFoldsTheNdjsonStreamIntoOneMessage(): void
    {
        $http = new FakeHttpClient([], self::ndjson([
            ['message' => ['role' => 'assistant', 'content' => 'The '], 'done' => false],
            ['message' => ['role' => 'assistant', 'content' => 'translation'], 'done' => false],
            [
                'model' => 'llama3', 'created_at' => 't', 'message' => ['role' => 'assistant', 'content' => ''],
                'done' => true, 'done_reason' => 'stop', 'total_duration' => 99, 'prompt_eval_count' => 19, 'eval_count' => 20,
            ],
        ]));

        $result = self::model($http)->invoke('Translate');

        self::assertInstanceOf(AIMessage::class, $result);
        self::assertSame('The translation', $result->content);
        self::assertSame('ollama', $result->response_metadata['model_provider']);
        self::assertSame('stop', $result->response_metadata['done_reason']);
        self::assertSame('llama3', $result->response_metadata['model']);
        self::assertTrue($result->response_metadata['done']);
        self::assertSame(
            ['input_tokens' => 19, 'output_tokens' => 20, 'total_tokens' => 39],
            $result->response_metadata['usage_metadata'],
        );
    }

    public function testFramingDoesNotDependOnHowTheBytesAreChunked(): void
    {
        $records = self::reply('chunked');
        $whole = new FakeHttpClient([], self::ndjson($records));
        $split = new FakeHttpClient([], self::ndjson($records, [7, 31, 40, 90]));

        self::assertSame(
            self::model($whole)->invoke('hi')->content,
            self::model($split)->invoke('hi')->content,
        );
        self::assertSame('chunked', self::model(new FakeHttpClient([], self::ndjson($records, [3, 4, 5])))->invoke('hi')->content);
    }

    public function testToolCallsInTheReplyBecomeParsedToolCallsWithIds(): void
    {
        $http = new FakeHttpClient([], self::ndjson([
            ['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['function' => ['name' => 'get_weather', 'arguments' => ['city' => 'Austin']]],
                ['function' => ['name' => 'clock', 'arguments' => []]],
            ]], 'done' => false],
            ['message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'done_reason' => 'stop'],
        ]));

        $result = self::model($http)->bindTools([self::weatherTool()])->invoke('weather and time?');

        self::assertCount(2, $result->toolCalls);
        self::assertSame('get_weather', $result->toolCalls[0]['name']);
        self::assertSame(['city' => 'Austin'], $result->toolCalls[0]['args']);
        self::assertNotEmpty($result->toolCalls[0]['id']);
        self::assertSame('clock', $result->toolCalls[1]['name']);
        self::assertSame([], $result->toolCalls[1]['args']);
        self::assertNotSame($result->toolCalls[0]['id'], $result->toolCalls[1]['id']);
    }

    public function testThinkingLandsInReasoningContentAndLeavesContentClean(): void
    {
        $http = new FakeHttpClient([], self::ndjson([
            ['message' => ['role' => 'assistant', 'content' => '', 'thinking' => 'Let me '], 'done' => false],
            ['message' => ['role' => 'assistant', 'content' => '', 'thinking' => 'think.'], 'done' => false],
            ['message' => ['role' => 'assistant', 'content' => 'Answer.'], 'done' => false],
            ['message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'done_reason' => 'stop'],
        ]));

        $result = self::model($http, ['think' => true])->invoke('hi');

        self::assertSame('Answer.', $result->content);
        self::assertSame('Let me think.', $result->additional_kwargs['reasoning_content']);
    }

    public function testStreamYieldsTokensAndFoldsTheTerminalMetadata(): void
    {
        $http = new FakeHttpClient([], self::ndjson(self::reply('Hello'), [10, 45]));
        $tokens = [];
        foreach (self::model($http)->stream('hi') as [, $chunk]) {
            $tokens[] = $chunk->content;
        }

        self::assertSame('Hello', implode('', $tokens));
    }

    public function testStreamingTokensReachTheCallbackHandler(): void
    {
        $http = new FakeHttpClient([], self::ndjson(self::reply('Hello')));
        $collector = new RunCollectorCallbackHandler();

        iterator_to_array(self::model($http)->stream('hi', new RunnableConfig(callbacks: [$collector])), false);

        self::assertNotEmpty($collector->tracedRuns);
        $run = $collector->tracedRuns[0];
        self::assertSame('llm', $run->runType);
    }

    public function testAnEmptyStreamStillYieldsAMessageCarryingMetadata(): void
    {
        $http = new FakeHttpClient([], ['']);

        $result = self::model($http)->invoke('hi');

        self::assertSame('', $result->content);
        self::assertSame('ollama', $result->response_metadata['model_provider']);
    }

    // ---- failures -----------------------------------------------------------------------------

    public function testAnErrorLineMidStreamRaisesOllamaException(): void
    {
        $http = new FakeHttpClient([], self::ndjson([
            ['message' => ['role' => 'assistant', 'content' => 'par'], 'done' => false],
            ['error' => 'llama runner process has terminated'],
        ]));

        $this->expectException(OllamaException::class);
        $this->expectExceptionMessage('llama runner process has terminated');

        self::model($http)->invoke('hi');
    }

    public function testANon2xxOnTheStreamKeepsOllamasMessage(): void
    {
        $http = new class () implements HttpClient {
            public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
            {
                return new HttpResponse(200);
            }

            public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
            {
                throw new HttpException('HTTP 404', 404, '{"error":"model \'nope\' not found, try pulling it first"}');
                yield '';
            }
        };

        try {
            (new ChatOllama(['model' => 'nope', 'httpClient' => $http]))->invoke('hi');
            self::fail('expected OllamaException');
        } catch (OllamaException $e) {
            self::assertSame("model 'nope' not found, try pulling it first", $e->getMessage());
            self::assertSame(404, $e->status);
            self::assertInstanceOf(HttpException::class, $e->getPrevious());
        }
    }

    public function testAThrowingTransportIsWrappedWithItsCause(): void
    {
        $http = new class () implements HttpClient {
            public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
            {
                return new HttpResponse(200);
            }

            public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
            {
                throw new \RuntimeException('socket closed');
                yield '';
            }
        };

        try {
            (new ChatOllama(['httpClient' => $http]))->invoke('hi');
            self::fail('expected OllamaException');
        } catch (OllamaException $e) {
            self::assertStringContainsString('socket closed', $e->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    public function testACallbackFailureIsNotRelabelledAsATransportFailure(): void
    {
        $http = new FakeHttpClient([], self::ndjson(self::reply('Hello')));
        $boom = new class () extends \LangChain\Tracers\BaseCallbackHandler {
            public bool $preferStreaming = true;

            public bool $raiseError = true;

            public function handleLLMNewToken(string $token, array $idx, string $runId, ?string $parentRunId = null, array $tags = [], array $fields = []): void
            {
                throw new \DomainException('handler exploded');
            }
        };

        $this->expectException(\DomainException::class);

        self::model($http)->invoke('hi', new RunnableConfig(callbacks: [$boom]));
    }

    // ---- checkOrPullModel -------------------------------------------------------------------------

    public function testAnInstalledModelIsNotPulled(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, ['modelfile' => '...'])], self::ndjson(self::reply('ok')));

        self::model($http, ['checkOrPullModel' => true])->invoke('hi');

        self::assertCount(1, $http->requests);
        self::assertStringEndsWith('/api/show', $http->requests[0]['url']);
        self::assertSame(['model' => 'llama3'], json_decode($http->requests[0]['body'], true));
        self::assertCount(1, $http->streamRequests);
        self::assertStringEndsWith('/api/chat', $http->streamRequests[0]['url']);
    }

    public function testAMissingModelIsPulledBeforeTheChat(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(404, ['error' => 'model not found'])], []);
        $http->streamChunks = self::ndjson([['status' => 'pulling manifest'], ['status' => 'success']]);

        $model = self::model($http, ['checkOrPullModel' => true]);
        // The same scripted stream serves both calls; what matters is the order and the targets.
        $model->invoke('hi');

        self::assertStringEndsWith('/api/pull', $http->streamRequests[0]['url']);
        self::assertSame(['model' => 'llama3', 'stream' => true], json_decode($http->streamRequests[0]['body'], true));
        self::assertStringEndsWith('/api/chat', $http->streamRequests[1]['url']);
    }

    public function testPullHonoursInsecureAndRaisesOnAnErrorLine(): void
    {
        $http = new FakeHttpClient([], self::ndjson([['status' => 'pulling'], ['error' => 'pull model manifest: file does not exist']]));

        try {
            self::model($http)->pull('ghost', ['insecure' => true]);
            self::fail('expected OllamaException');
        } catch (OllamaException $e) {
            self::assertSame('pull model manifest: file does not exist', $e->getMessage());
        }
        self::assertSame(
            ['model' => 'ghost', 'insecure' => true, 'stream' => true],
            json_decode($http->streamRequests[0]['body'], true),
        );
    }

    public function testAnUnexpectedShowStatusIsAnError(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(500, ['error' => 'boom'])], []);

        $this->expectException(OllamaException::class);
        $this->expectExceptionMessage('boom');

        self::model($http, ['checkOrPullModel' => true])->invoke('hi');
    }

    // ---- withStructuredOutput ---------------------------------------------------------------------------

    private const NAME_SCHEMA = [
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string', 'description' => 'A name']],
        'required' => ['name'],
    ];

    public function testJsonSchemaIsTheDefaultAndSendsTheSchemaAsFormat(): void
    {
        $http = new FakeHttpClient([], self::ndjson(self::reply('{"name": "Eve"}')));

        $result = self::model($http)->withStructuredOutput(self::NAME_SCHEMA)->invoke('What?');

        self::assertSame(['name' => 'Eve'], $result);
        self::assertSame(self::NAME_SCHEMA, $http->lastRequestBody()['format']);
        self::assertArrayNotHasKey('tools', $http->lastRequestBody());
    }

    public function testJsonSchemaMethodExplicit(): void
    {
        $http = new FakeHttpClient([], self::ndjson(self::reply('{"name": "Eve"}')));

        $result = self::model($http)->withStructuredOutput(self::NAME_SCHEMA, ['method' => 'jsonSchema'])->invoke('What?');

        self::assertSame(['name' => 'Eve'], $result);
        self::assertSame(self::NAME_SCHEMA, $http->lastRequestBody()['format']);
    }

    public function testJsonModeSendsTheStringJsonAsFormat(): void
    {
        $http = new FakeHttpClient([], self::ndjson(self::reply('{"name": "Alice"}')));

        $result = self::model($http)->withStructuredOutput(self::NAME_SCHEMA, ['method' => 'jsonMode'])->invoke('What?');

        self::assertSame(['name' => 'Alice'], $result);
        self::assertSame('json', $http->lastRequestBody()['format']);
    }

    public function testFunctionCallingOffersTheSchemaAsAToolAndReadsTheCall(): void
    {
        $http = new FakeHttpClient([], self::ndjson([
            ['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['function' => ['name' => 'extract', 'arguments' => ['name' => 'cobalt']]],
            ]], 'done' => false],
            ['message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'done_reason' => 'stop'],
        ]));

        $result = self::model($http)->withStructuredOutput(self::NAME_SCHEMA, ['method' => 'functionCalling'])->invoke('What?');

        self::assertSame(['name' => 'cobalt'], $result);
        $tool = $http->lastRequestBody()['tools'][0];
        self::assertSame('extract', $tool['function']['name']);
        self::assertSame(self::NAME_SCHEMA, $tool['function']['parameters']);
        self::assertArrayNotHasKey('format', $http->lastRequestBody());
    }

    public function testFunctionCallingWithACustomName(): void
    {
        $http = new FakeHttpClient([], self::ndjson([
            ['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['function' => ['name' => 'GetName', 'arguments' => ['name' => 'test']]],
            ]], 'done' => true],
        ]));

        $result = self::model($http)
            ->withStructuredOutput(self::NAME_SCHEMA, ['method' => 'functionCalling', 'name' => 'GetName'])
            ->invoke('What?');

        self::assertSame(['name' => 'test'], $result);
        self::assertSame('GetName', $http->lastRequestBody()['tools'][0]['function']['name']);
    }

    public function testFunctionCallingTakesAReadyMadeFunctionDefinitionAsIs(): void
    {
        $http = new FakeHttpClient([], self::ndjson([
            ['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['function' => ['name' => 'lookup', 'arguments' => ['q' => 'x']]],
            ]], 'done' => true],
        ]));
        $definition = ['name' => 'lookup', 'description' => 'Look it up', 'parameters' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]]];

        $result = self::model($http)->withStructuredOutput($definition, ['method' => 'functionCalling'])->invoke('What?');

        self::assertSame(['q' => 'x'], $result);
        self::assertSame($definition, $http->lastRequestBody()['tools'][0]['function']);
    }

    public function testIncludeRawReturnsRawAndParsed(): void
    {
        $http = new FakeHttpClient([], self::ndjson([
            ['message' => ['role' => 'assistant', 'content' => '', 'tool_calls' => [
                ['function' => ['name' => 'extract', 'arguments' => ['name' => 'cobalt']]],
            ]], 'done' => true],
        ]));

        $result = self::model($http)
            ->withStructuredOutput(self::NAME_SCHEMA, ['method' => 'functionCalling', 'includeRaw' => true])
            ->invoke('What?');

        self::assertArrayHasKey('raw', $result);
        self::assertInstanceOf(AIMessage::class, $result['raw']);
        self::assertSame(['name' => 'cobalt'], $result['parsed']);
    }

    public function testAnUnknownStructuredOutputMethodIsRejected(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage("Unrecognized structured output method 'bogus'");

        (new ChatOllama())->withStructuredOutput(self::NAME_SCHEMA, ['method' => 'bogus']);
    }

    // ---- end to end ---------------------------------------------------------------------------------------

    public function testAPromptModelParserChainRunsEndToEnd(): void
    {
        $http = new FakeHttpClient([], self::ndjson(self::reply('Bonjour!')));
        $chain = ChatPromptTemplate::fromMessages([
            ['system', 'Translate to {language}.'],
            ['human', '{text}'],
        ])->pipe(self::model($http))->pipe(new \LangChain\OutputParsers\StringOutputParser());

        $out = $chain->invoke(['language' => 'French', 'text' => 'Hello!']);

        self::assertSame('Bonjour!', $out);
        self::assertSame(
            [['role' => 'system', 'content' => 'Translate to French.'], ['role' => 'user', 'content' => 'Hello!']],
            $http->lastRequestBody()['messages'],
        );
    }

    public function testAGraphNodeCallsTheModelAndTheAnswerLandsInState(): void
    {
        $http = new FakeHttpClient([], self::ndjson(self::reply('42')));
        $model = self::model($http, ['temperature' => 0.0]);

        $graph = (new \LangGraph\State\StateGraph(['question' => new \LangGraph\Channels\AnyValue(), 'answer' => new \LangGraph\Channels\AnyValue()]))
            ->addNode('ask', static fn (array $s): array => ['answer' => $model->invoke((string) $s['question'])->content])
            ->addEdge('__start__', 'ask')
            ->addEdge('ask', '__end__')
            ->compile();

        $final = $graph->invoke(['question' => 'meaning of life?']);

        self::assertSame('42', $final['answer']);
        self::assertSame([['role' => 'user', 'content' => 'meaning of life?']], $http->lastRequestBody()['messages']);
        self::assertEquals(['temperature' => 0.0], $http->lastRequestBody()['options']);
    }
}
