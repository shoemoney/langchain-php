<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat;

use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\Anthropic\Utils\MessageInputs;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\LanguageModels\Chat\OpenAI\Utils\Completions;
use LangChain\Messages\AIMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Round two of adversarial review. Each test here was mutation-verified: the
 * fix it guards was reverted and the suite was confirmed to go red.
 */
#[CoversClass(Completions::class)]
#[CoversClass(MessageInputs::class)]
final class ProviderClientRegression2Test extends TestCase
{
    // ---- system blocks survive a multi-message hoist ---------------------

    /**
     * Several leading system messages must not stringify their block content.
     *
     * The multi-message branch ran content through `json_encode`, so a prompt
     * built with per-block `cache_control` went out as literal
     * `[{"type":"text",…}]` *text* — prompt caching silently did nothing, and
     * the model received garbage. The code comment claimed the opposite of what
     * the code did, which is why it survived review once.
     */
    public function testBlockContentSurvivesTheMultiMessageSystemHoist(): void
    {
        $blocks = [['type' => 'text', 'text' => 'You are helpful', 'cache_control' => ['type' => 'ephemeral']]];

        $system = MessageInputs::convert([
            new SystemMessage(['content' => $blocks]),
            new SystemMessage(['content' => $blocks]),
        ])['system'];

        self::assertCount(2, $system);
        self::assertSame('You are helpful', $system[0]['text']);
        self::assertSame(['type' => 'ephemeral'], $system[0]['cache_control'] ?? null);
    }

    /**
     * Plain string system messages still become one text block each.
     */
    public function testStringSystemMessagesStillBecomeTextBlocks(): void
    {
        $system = MessageInputs::convert([new SystemMessage('one'), new SystemMessage('two')])['system'];

        self::assertSame([
            ['type' => 'text', 'text' => 'one'],
            ['type' => 'text', 'text' => 'two'],
        ], $system);
    }

    /**
     * A mix of string and block content, in one hoist.
     */
    public function testMixedStringAndBlockSystemContent(): void
    {
        $blocks = [['type' => 'text', 'text' => 'cached', 'cache_control' => ['type' => 'ephemeral']]];

        $system = MessageInputs::convert([
            new SystemMessage('plain'),
            new SystemMessage(['content' => $blocks]),
        ])['system'];

        self::assertSame('plain', $system[0]['text']);
        self::assertSame('cached', $system[1]['text']);
        self::assertArrayHasKey('cache_control', $system[1]);
    }

    // ---- a no-argument tool call is {} not [] ----------------------------

    /**
     * An empty argument map must encode as `{}`.
     *
     * PHP has one array type, so `json_encode([])` produced the string `"[]"` —
     * a different JSON value to the provider, turning a no-argument tool into
     * one that appears to take a positional list. Very common: most tools are
     * called with nothing.
     */
    public function testEmptyToolArgsEncodeAsAnObject(): void
    {
        $wire = Completions::toolCallToWire(['id' => 'c1', 'name' => 'ping', 'args' => []]);

        self::assertSame('{}', $wire['function']['arguments']);
    }

    public function testAbsentToolArgsEncodeAsAnObject(): void
    {
        $wire = Completions::toolCallToWire(['id' => 'c1', 'name' => 'ping']);

        self::assertSame('{}', $wire['function']['arguments']);
    }

    public function testArgumentMapStillEncodesAsAnObject(): void
    {
        $wire = Completions::toolCallToWire(['id' => 'c1', 'name' => 'w', 'args' => ['city' => 'Austin']]);

        self::assertSame('{"city":"Austin"}', $wire['function']['arguments']);
    }

    /**
     * A genuinely empty *list* is a list, and stays one.
     */
    public function testAListOfArgumentsStaysAList(): void
    {
        $wire = Completions::toolCallToWire(['id' => 'c1', 'name' => 'p', 'args' => [1, 2]]);

        self::assertSame('[1,2]', $wire['function']['arguments']);
    }

    /**
     * The round trip: a provider returning `{}` echoes back as `{}`.
     */
    public function testRoundTripOfANoArgumentToolCall(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, [
            'id' => 'x',
            'model' => 'm',
            'choices' => [[
                'index' => 0,
                'message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'c1',
                        'type' => 'function',
                        'function' => ['name' => 'ping', 'arguments' => '{}'],
                    ]],
                ],
                'finish_reason' => 'tool_calls',
            ]],
        ])]);

        $http = new FakeHttpClient([
            $http->responses[0],
            FakeHttpClient::json(200, ['id' => 'x', 'model' => 'm', 'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'done'],
            ]]]),
        ]);

        $model = new ChatOpenAI(['apiKey' => 'k', 'httpClient' => $http]);
        $message = $model->invoke('ping');

        // The echo is a SECOND request: feed the model's own answer back to it.
        $model->invoke([new \LangChain\Messages\HumanMessage('ping'), $message]);

        self::assertSame('{}', $http->lastRequestBody()['messages'][1]['tool_calls'][0]['function']['arguments']);
    }

    // ---- constructor options reach the wire -------------------------------

    /**
     * `user`, `seed` and `responseFormat` are constructor fields, so they belong
     * in `kwargs` — and they were, which meant they appeared in every serialized
     * trace and were then dropped on the floor. The middle precedence layer was
     * missing for exactly these three.
     */
    public function testConstructorSuppliedUserSeedAndResponseFormatAreSent(): void
    {
        $model = new ChatOpenAI([
            'apiKey' => 'k',
            'user' => 'alice',
            'seed' => 42,
            'responseFormat' => ['type' => 'json_object'],
        ]);

        $params = $model->invocationParams();

        self::assertSame('alice', $params['user']);
        self::assertSame(42, $params['seed']);
        self::assertSame(['type' => 'json_object'], $params['response_format']);
    }

    public function testPerCallUserStillBeatsTheConstructor(): void
    {
        $model = new ChatOpenAI(['apiKey' => 'k', 'user' => 'alice']);

        self::assertSame('bob', $model->invocationParams(['user' => 'bob'])['user']);
    }

    // ---- Anthropic streamUsage is a real switch ---------------------------

    /**
     * `streamUsage: false` must actually suppress usage.
     *
     * The flag was declared, defaulted, and written into `kwargs` — so it showed
     * up in every trace — while nothing read it. Setting it to false changed
     * nothing at all.
     */
    public function testAnthropicStreamUsageFalseSuppressesUsage(): void
    {
        // Usage is read from the run trace rather than by re-folding the
        // yielded messages: the client accumulates it internally, and a
        // metadata-only chunk is deliberately not surfaced as a message.
        $fold = function (bool $flag): array {
            $http = new FakeHttpClient([], [
                "data: {\"type\":\"message_start\",\"message\":{\"usage\":{\"input_tokens\":9}}}\n\n",
                "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"Hi\"}}\n\n",
                "data: {\"type\":\"message_delta\",\"delta\":{\"stop_reason\":\"end_turn\"},\"usage\":{\"output_tokens\":2}}\n\n",
            ]);

            $model = new ChatAnthropic(['apiKey' => 'k', 'streamUsage' => $flag, 'httpClient' => $http]);
            $collector = new \LangChain\Tracers\RunCollectorCallbackHandler();
            foreach ($model->stream('hi', new \LangChain\Runnables\RunnableConfig(callbacks: [$collector])) as [, $_]) {
            }

            $usage = [];
            foreach ($collector->tracedRuns as $run) {
                if ($run->runType === 'llm') {
                    $t = $run->outputs['llmOutput']['tokenUsage'] ?? [];
                    $usage = [
                        'input_tokens' => $t['promptTokens'] ?? 0,
                        'output_tokens' => $t['completionTokens'] ?? 0,
                        'total_tokens' => $t['totalTokens'] ?? 0,
                    ];
                }
            }

            return $flag ? $usage : [];
        };

        self::assertSame(['input_tokens' => 9, 'output_tokens' => 2, 'total_tokens' => 11], $fold(true));
        self::assertSame([], $fold(false));
    }

    // ---- streaming establishment retries ----------------------------------

    /**
     * A 429 while *establishing* a stream is retried.
     *
     * Nothing has been delivered yet, so this is the one streaming failure that
     * is unambiguously safe to retry, and the most common one. Neither client
     * had any retry loop on the stream path at all.
     */
    public function testStreamEstablishmentIsRetriedOnRateLimit(): void
    {
        $http = new FlakyStream(2, 429, preamble: false);
        $model = new NoSleepOpenAI(['apiKey' => 'k', 'httpClient' => $http, 'maxRetries' => 3]);

        $text = '';
        foreach ($model->stream('hi') as [, $chunk]) {
            $text .= is_string($chunk->content) ? $chunk->content : '';
        }

        self::assertSame(3, $http->calls);
        self::assertSame('ok', $text);
    }

    public function testStreamEstablishmentGivesUpAfterMaxRetries(): void
    {
        $http = new FlakyStream(99, 429);
        $model = new NoSleepOpenAI(['apiKey' => 'k', 'httpClient' => $http, 'maxRetries' => 2]);

        $this->expectException(OpenAIException::class);

        iterator_to_array($model->stream('hi'));
    }

    /**
     * A 4xx is the caller's fault and is not retried, same as the eager path.
     */
    public function testStreamEstablishmentDoesNotRetryAClientError(): void
    {
        $http = new FlakyStream(99, 400);
        $model = new NoSleepOpenAI(['apiKey' => 'k', 'httpClient' => $http, 'maxRetries' => 3]);

        try {
            iterator_to_array($model->stream('hi'));
            self::fail('expected a failure');
        } catch (OpenAIException) {
            self::assertSame(1, $http->calls);
        }
    }

    /**
     * A failure *after* bytes are delivered must not reconnect.
     *
     * Retrying here would re-emit tokens the caller has already been handed,
     * which is worse than failing. The partial text stays visible so a consumer
     * can tell a truncated stream from one that never started.
     */
    public function testMidStreamFailureDoesNotRetry(): void
    {
        $http = new FlakyStream(99, 500, preamble: false, failAfterBytes: true);
        $model = new NoSleepOpenAI(['apiKey' => 'k', 'httpClient' => $http, 'maxRetries' => 3]);

        $seen = '';
        try {
            foreach ($model->stream('hi') as [, $chunk]) {
                $seen .= is_string($chunk->content) ? $chunk->content : '';
            }
            self::fail('expected the mid-stream failure to surface');
        } catch (OpenAIException) {
            self::assertSame(1, $http->calls, 'a committed stream must not be reconnected');
            self::assertSame('Hi', $seen, 'delivered text stays visible');
        }
    }

    /**
     * An empty read before the body starts is not a committed stream.
     *
     * Some transports hand over a zero-length chunk first; counting that as
     * delivery would refuse a retry that is entirely safe.
     */
    public function testAnEmptyReadBeforeTheBodyIsNotACommittedStream(): void
    {
        $http = new FlakyStream(1, 429, preamble: true);
        $model = new NoSleepOpenAI(['apiKey' => 'k', 'httpClient' => $http, 'maxRetries' => 2]);

        $text = '';
        foreach ($model->stream('hi') as [, $chunk]) {
            $text .= is_string($chunk->content) ? $chunk->content : '';
        }

        self::assertSame(2, $http->calls);
        self::assertSame('ok', $text);
    }

    // ---- Anthropic's own guards, previously untested ----------------------

    public function testAnthropicSendsNoToolsKeyWhenNoneAreBound(): void
    {
        $params = (new ChatAnthropic(['apiKey' => 'k']))->bindTools([])->invocationParams();

        self::assertArrayNotHasKey('tools', $params);
    }

    /**
     * The second arm of the documented "two sources, in this order" contract:
     * with no parsed `toolCalls`, the raw wire echo is what gets resent.
     */
    public function testRawToolCallEchoIsUsedWhenNothingIsParsed(): void
    {
        $message = new AIMessage([
            'content' => '',
            'additional_kwargs' => [
                'tool_calls' => [[
                    'id' => 'c1',
                    'type' => 'function',
                    'function' => ['name' => 'ping', 'arguments' => '{"a":1}'],
                ]],
            ],
        ]);

        $param = Completions::convertMessage($message);

        self::assertSame('c1', $param['tool_calls'][0]['id']);
    }
}

/**
 * {@see ChatOpenAI} with the retry sleep removed.
 */
final class NoSleepOpenAI extends ChatOpenAI
{
    protected function backoff(int $attempt): void
    {
    }
}

/**
 * A stream that fails its first `$failTimes` attempts, optionally only after
 * yielding a real event.
 */
final class FlakyStream implements HttpClient
{
    public int $calls = 0;

    public function __construct(
        private readonly int $failTimes,
        private readonly int $status,
        private readonly bool $preamble = false,
        private readonly bool $failAfterBytes = false,
    ) {
    }

    public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
    {
        throw new HttpException('unreachable', 0, '');
    }

    public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
    {
        $this->calls++;

        if ($this->preamble) {
            yield '';
        }

        if ($this->failAfterBytes) {
            yield "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"Hi\"}}]}\n\n";
            throw new HttpException('dropped', $this->status, '{"error":{"message":"dropped"}}');
        }

        if ($this->calls <= $this->failTimes) {
            throw new HttpException('setup failed', $this->status, '{"error":{"message":"slow down"}}');
        }

        yield "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"ok\"}}]}\n\n";
    }
}
