<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat;

use LangChain\LanguageModels\Chat\Anthropic\AnthropicException;
use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\Anthropic\Utils\MessageInputs;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\LanguageModels\Chat\OpenAI\Utils\Completions;
use LangChain\Messages\FunctionMessage;
use LangChain\Tools\Schema;
use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * Regressions for defects found by adversarial review after the provider clients
 * landed.
 *
 * Every test here failed against the code as it was written, and the shared
 * cause is the same in all of them: the suite asserted on **responses** and on
 * the **fake**, never on the request the real client actually produced. Two of
 * these shipped a malformed request that every unit test passed straight over.
 */
#[CoversClass(Completions::class)]
#[CoversClass(MessageInputs::class)]
final class ProviderClientRegressionTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string']],
        'required' => ['name'],
    ];

    /** @return array<string, mixed> */
    private static function toolResponse(string $functionName, array $args): array
    {
        return ['id' => 'x', 'model' => 'm', 'choices' => [[
            'index' => 0,
            'message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => ['name' => $functionName, 'arguments' => (string) json_encode($args)],
                ]],
            ],
            'finish_reason' => 'tool_calls',
        ]]];
    }

    // ---- 1. the schema reached the provider ------------------------------

    /**
     * The schema must survive `withStructuredOutput()` onto the wire.
     *
     * `withStructuredOutput()` builds an OpenAI-shaped tool and hands it to each
     * provider's `bindTools()`. Anthropic read `parameters` from the *outer*
     * level instead of from inside `function`, so the model was sent
     * `input_schema: {type: object, properties: {}}` — a well-formed tool with
     * no arguments described. Nothing errored; the model simply never learned
     * what the tool takes.
     *
     * This asserts on the **request body**, which is the whole point: the
     * original test scripted a response and never looked at what was sent.
     */
    public function testAnthropicSendsTheSchemaWithStructuredOutput(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::json(200, self::toolResponse('extract', ['name' => 'Ada'])),
        ]);

        $model = new ChatAnthropic([
            'apiKey' => 'k',
            'httpClient' => $http,
        ]);

        $model->withStructuredOutput(self::SCHEMA)->invoke('who?');

        $sent = $http->lastRequestBody()['tools'][0];

        self::assertSame('extract', $sent['name']);
        self::assertSame(
            ['name' => ['type' => 'string']],
            $sent['input_schema']['properties'],
            'the model must be told the argument shape, not an empty schema',
        );
        self::assertSame(['name'], $sent['input_schema']['required']);
    }

    /**
     * The same guarantee for OpenAI, asserted the same way.
     */
    public function testOpenAiSendsTheSchemaWithStructuredOutput(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::json(200, self::toolResponse('extract', ['name' => 'Ada'])),
        ]);

        $model = new ChatOpenAI(['apiKey' => 'k', 'httpClient' => $http]);
        $model->withStructuredOutput(self::SCHEMA)->invoke('who?');

        self::assertSame(self::SCHEMA, $http->lastRequestBody()['tools'][0]['function']['parameters']);
    }

    // ---- 2. FunctionMessage content --------------------------------------

    /**
     * The legacy `function` role carries the function's RETURN VALUE.
     *
     * Omitting it sends the provider a call with no result, which it accepts
     * silently — the model then reasons as though the function returned nothing,
     * with no error anywhere.
     */
    public function testFunctionMessageKeepsItsContent(): void
    {
        $param = Completions::convertMessage(new FunctionMessage([
            'content' => '72F and sunny',
            'name' => 'get_weather',
        ]));

        self::assertSame('function', $param['role']);
        self::assertSame('72F and sunny', $param['content']);
        self::assertSame('get_weather', $param['name']);
    }

    // ---- 3. bound tool_choice goes through the same formatter ------------

    /**
     * A bound `toolChoice` must be formatted exactly like a per-call one.
     *
     * The bound value was passed through raw, so the same string meant two
     * different things depending on how it arrived — and on Anthropic the
     * "must name an offered tool" guard was trivially stepped around by binding
     * the choice instead of passing it as an option.
     */
    public function testBoundToolChoiceIsFormattedLikeAPerCallOne(): void
    {
        $bound = (new ChatOpenAI(['apiKey' => 'k']))->bindTools([], ['toolChoice' => 'f']);
        $perCall = (new ChatOpenAI(['apiKey' => 'k']))->invocationParams(['toolChoice' => 'f']);

        self::assertSame(
            $perCall['tool_choice'],
            $bound->invocationParams()['tool_choice'],
        );
    }

    public function testBoundToolChoiceIsFormattedOnAnthropic(): void
    {
        $real = static fn (mixed $a): string => 'x';
        $offered = static fn (): \LangChain\Tools\StructuredTool => tool($real, [
            'name' => 'f',
            'description' => 'd',
            'schema' => Schema::object([]),
        ]);

        $bound = (new ChatAnthropic(['apiKey' => 'k']))->bindTools([$offered()], ['toolChoice' => 'f']);
        $perCall = (new ChatAnthropic(['apiKey' => 'k']))->bindTools([$offered()])->invocationParams(['toolChoice' => 'f']);

        self::assertSame($perCall['tool_choice'], $bound->invocationParams()['tool_choice']);
    }

    /**
     * The guard cannot be defeated by choosing the binding route.
     */
    public function testAnthropicGuardCannotBeBypassedByBinding(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('not available');

        (new ChatAnthropic(['apiKey' => 'k']))->bindTools([], ['toolChoice' => 'nonexistent'])->invocationParams();
    }

    /**
     * Binding call options without offering a tool must not send `tools: []`,
     * which providers reject.
     */
    public function testBindingNoToolsSendsNoToolsKey(): void
    {
        $params = (new ChatOpenAI(['apiKey' => 'k']))->bindTools([], ['toolChoice' => 'f'])->invocationParams();

        self::assertArrayNotHasKey('tools', $params);
    }

    // ---- 4. streaming failures become provider exceptions ----------------

    /**
     * A streaming request that fails at connect time must surface as the
     * provider's exception, carrying its message.
     *
     * `postStream()` is a generator function: calling it runs none of its body,
     * so a `try` wrapped around the *call* never saw the failure. Every
     * streaming error escaped as a bare `HttpException` with the provider's own
     * message discarded.
     */
    public function testOpenAiStreamingFailureBecomesAnOpenAiException(): void
    {
        $http = new class implements HttpClient {
            public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
            {
                throw new HttpException('unreachable', 0, '');
            }

            public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
            {
                // The `yield` matters: it makes this a real generator, so the
                // failure is raised on ITERATION rather than on the call. That
                // is what `GuzzleHttpClient` does, and it is the whole reason
                // wrapping the call site in a `try` catches nothing. A double
                // with no `yield` throws eagerly, the `try` swallows it, and
                // the test passes against the broken code.
                yield '';

                throw new HttpException(
                    'Streaming request failed with status 401.',
                    401,
                    '{"error":{"message":"invalid api key","code":"invalid_api_key"}}',
                );
            }
        };

        $model = new ChatOpenAI(['apiKey' => 'k', 'httpClient' => $http]);

        try {
            iterator_to_array($model->stream('hi'));
            self::fail('expected the streaming failure to surface');
        } catch (OpenAIException $e) {
            self::assertSame(401, $e->status);
            self::assertStringContainsString('invalid api key', $e->getMessage());
        }
    }

    public function testAnthropicStreamingFailureBecomesAnAnthropicException(): void
    {
        $http = new class implements HttpClient {
            public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
            {
                throw new HttpException('unreachable', 0, '');
            }

            public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
            {
                yield '';

                throw new HttpException(
                    'Streaming request failed with status 429.',
                    429,
                    '{"error":{"type":"rate_limit_error","message":"slow down"}}',
                );
            }
        };

        $model = new ChatAnthropic(['apiKey' => 'k', 'httpClient' => $http]);

        try {
            iterator_to_array($model->stream('hi'));
            self::fail('expected the streaming failure to surface');
        } catch (AnthropicException $e) {
            self::assertSame(429, $e->status);
            self::assertStringContainsString('slow down', $e->getMessage());
        }
    }

    // ---- 5. real header shape --------------------------------------------

    /**
     * PSR-7 hands back `array<string, string[]>` — one entry per header line.
     *
     * `HttpResponse` stored that verbatim, so `header()` returned an array from
     * a method declared `?string`: a `TypeError` on every response the real
     * transport produced. The fake used bare strings, so no test had ever seen
     * the shape that actually arrives.
     */
    public function testHeadersAreFlattenedFromThePsr7Shape(): void
    {
        $response = new HttpResponse(200, [
            'Content-Type' => ['application/json'],
            'Set-Cookie' => ['a=1', 'b=2'],
        ], '{}');

        self::assertSame('application/json', $response->header('content-type'));
        self::assertSame('a=1, b=2', $response->header('set-cookie'));
        self::assertNull($response->header('x-absent'));
    }

    /**
     * A bare-string header still works, so hand-built responses are not broken.
     */
    public function testBareStringHeadersStillWork(): void
    {
        $response = new HttpResponse(200, ['Content-Type' => 'application/json'], '{}');

        self::assertSame('application/json', $response->header('Content-Type'));
    }

    /**
     * The fake must produce the real shape, or this whole class of bug returns.
     */
    public function testTheFakeProducesTheRealHeaderShape(): void
    {
        self::assertSame('application/json', FakeHttpClient::json(200, [])->header('content-type'));
    }
}
