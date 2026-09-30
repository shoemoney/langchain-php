<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat;

use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\Messages\HumanMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tools\{DynamicStructuredTool, Schema};
use LangChain\Utils\Http\{HttpClient, HttpResponse};
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every route to the same logic must produce the same request.
 *
 * This file exists because of a pattern, not a bug report. Seven of the last
 * seven fixes in this port were the same shape: a public entry point with
 * several ways to reach the same core logic, N-1 of them correct and one
 * broken, and a suite that exercised only the correct one. Concretely —
 *
 *   * `Schema::object()` accepted a nested `Schema` instance but not the
 *     equivalent plain array, and every test used the array.
 *   * Tools passed to the OpenAI constructor and to the Anthropic per-call
 *     option skipped conversion; every test used `bindTools()`.
 *   * The eager `post()` retried a 4xx; the streaming path did not, and every
 *     retry test used the streaming path.
 *   * A stalled stream ended silently; every test used a healthy stream.
 *   * A multi-prompt batch leaked a run; every test used one prompt.
 *   * An empty checkpoint map encoded as `[]`; the test used a populated one.
 *   * Task paths past nine sorted wrongly; the test used fewer than ten.
 *
 * None of those were coverage gaps — the broken lines were covered. They were
 * PATH gaps: one variant exercised, the sibling left dark. So the guard here is
 * structural rather than per-defect: every configuration source crossed with
 * every execution mode, asserting the SAME expected request each time.
 *
 * Add a row to `$sources` when a new way of configuring these clients appears,
 * and the new route is held to the identical assertion for free.
 */
#[CoversClass(ChatOpenAI::class)]
#[CoversClass(ChatAnthropic::class)]
final class OptionPathMatrixTest extends TestCase
{
    /**
     * The ways a caller can attach tools, per client.
     *
     * Each returns a ready-to-run model wired to a body-recording transport.
     *
     * @return array<string, array{0: class-string, 1: \Closure(self, HttpClient): object}>
     */
    public static function sources(): array
    {
        return [
            'openai constructor' => [ChatOpenAI::class, static function (self $t, HttpClient $http): object {
                return new ChatOpenAI(['apiKey' => 'k', 'httpClient' => $http, 'tools' => [$t->tool()]]);
            }],
            'openai bindTools' => [ChatOpenAI::class, static function (self $t, HttpClient $http): object {
                return (new ChatOpenAI(['apiKey' => 'k', 'httpClient' => $http]))->bindTools([$t->tool()]);
            }],
            'anthropic constructor' => [ChatAnthropic::class, static function (self $t, HttpClient $http): object {
                return new ChatAnthropic(['apiKey' => 'k', 'httpClient' => $http, 'tools' => [$t->tool()]]);
            }],
            'anthropic bindTools' => [ChatAnthropic::class, static function (self $t, HttpClient $http): object {
                return (new ChatAnthropic(['apiKey' => 'k', 'httpClient' => $http]))->bindTools([$t->tool()]);
            }],
        ];
    }

    /** The two ways a call can be made. */
    public static function modes(): array
    {
        return [
            'eager invoke' => ['eager'],
            'streaming' => ['stream'],
        ];
    }

    /**
     * The cross product of every source and every mode.
     *
     * ONE provider, not two `#[DataProvider]` attributes: PHPUnit applies only
     * the first, and stacking them silently passes each row of the second as
     * the whole argument list. Bit twice in two iterations — the first version
     * of this file died with `ArgumentCountError: 1 passed, exactly 3
     * expected` on every streaming row. `SingleDataProviderTest` now fails the
     * build if it ever happens again.
     *
     * @return array<string, array{0: class-string, 1: \Closure(self, HttpClient): object, 2: string}>
     */
    public static function matrix(): array
    {
        $rows = [];
        foreach (self::sources() as $sourceName => [$class, $make]) {
            foreach (self::modes() as $modeName => [$mode]) {
                $rows["$sourceName / $modeName"] = [$class, $make, $mode];
            }
        }

        return $rows;
    }

    private function tool(): DynamicStructuredTool
    {
        return new DynamicStructuredTool(
            ['name' => 'search', 'description' => 'search things',
             'schema' => Schema::object(['q' => Schema::string()], ['q'])],
            static fn (array $in): string => 'x',
        );
    }

    /** Records every request body, then answers in the client's own dialect. */
    private function recorder(bool $anthropic, ?string $streamBody = null): HttpClient
    {
        return new class ($anthropic, $streamBody) implements HttpClient {
            /** @var list<string> */
            public array $bodies = [];

            public function __construct(private bool $anthropic, private ?string $streamBody)
            {
            }

            private function payload(): string
            {
                return $this->anthropic
                    ? (string) json_encode([
                        'id' => 'x', 'model' => 'm', 'stop_reason' => 'end_turn',
                        'content' => [['type' => 'text', 'text' => 'ok']],
                        'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
                    ])
                    : (string) json_encode([
                        'id' => 'x', 'model' => 'm',
                        'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok']]],
                    ]);
            }

            public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
            {
                $this->bodies[] = $body;

                return new HttpResponse(200, [], $this->payload());
            }

            public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
            {
                $this->bodies[] = $body;
                $sse = $this->streamBody ?? ($this->anthropic
                    ? "event: message_start\ndata: " . json_encode(['type' => 'message_start', 'message' => ['id' => 'x', 'model' => 'm', 'content' => [], 'usage' => ['input_tokens' => 1, 'output_tokens' => 0]]]) . "\n\n"
                      // The block must be OPENED before it is written to. The
                      // fixture emitted a bare content_block_delta, which is a
                      // malformed event, so the parser correctly discarded it and
                      // the stream produced nothing at all — and the
                      // silent-empty branch in BaseChatModel::stream() swallowed
                      // that, letting this row pass while asserting nothing
                      // about streamed output.
                      . "event: content_block_start\ndata: " . json_encode(['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']]) . "\n\n"
                      . "event: content_block_delta\ndata: " . json_encode(['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'ok']]) . "\n\n"
                      . "event: message_delta\ndata: " . json_encode(['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 1]]) . "\n\n"
                      . "event: message_stop\ndata: " . json_encode(['type' => 'message_stop']) . "\n\n"
                    : "data: " . json_encode(['id' => 'x', 'model' => 'm', 'choices' => [['index' => 0, 'delta' => ['content' => 'ok']]]]) . "\n\n"
                      . "data: " . json_encode(['id' => 'x', 'model' => 'm', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]]) . "\n\n"
                      . "data: [DONE]\n\n");

                foreach (explode("\n\n", $sse) as $event) {
                    if (trim($event) === '') {
                        continue;
                    }
                    // Yield the WHOLE event block, not just those whose FIRST
                    // line is `data:`. That filter looked harmless and was
                    // fatal: OpenAI SSE is bare `data:` lines, but Anthropic SSE
                    // leads with `event: message_start`, so EVERY Anthropic
                    // event was dropped and the "streaming" rows streamed
                    // nothing. The test was named stream, called stream(), and
                    // never received a chunk.
                    // Yield the WHOLE event block, not just those whose FIRST
                    // line is `data:`. That filter looked harmless and was
                    // fatal: OpenAI SSE is bare `data:` lines, but Anthropic SSE
                    // leads with `event: message_start`, so EVERY Anthropic
                    // event was dropped and the "streaming" rows streamed
                    // nothing. The test was named stream, called stream(), and
                    // never received a chunk.
                    yield $event . "\n\n";
                }
            }
        };
    }

    /**
     * The tool must reach the wire identically, whichever route configured it
     * and whichever way the call is made.
     */
    /**
     * The regression for the empty-response contract.
     *
     * Asserting the streamed text above is NOT enough: a stream that yields
     * something satisfies it whether or not the empty case throws. This is the
     * test that actually pins the contract, and the matrix could never have
     * supplied it.
     *
     * Upstream throws on BOTH paths — chat_models.ts:750 guards the streaming
     * loop with `if (!sawEvent) throw new Error("Received empty response from
     * chat model call.")`, :825 guards eager aggregation the same way. The
     * port's stream() used to call handleLLMEnd with an empty result and
     * RETURN, so one condition answered cleanly from stream() and threw from
     * invoke(), and a tracer saw an end event on one path and an error on the
     * other.
     */
    #[DataProvider('emptyStreamSources')]
    public function testAStreamThatProducesNothingThrows(string $label, \Closure $make): void
    {
        $empty = new class implements HttpClient {
            public function post(string $u, array $h, string $b, array $q = [], ?float $t = null): HttpResponse
            {
                return new HttpResponse(200, [], '');
            }

            public function postStream(string $u, array $h, string $b, array $q = [], ?float $t = null): \Generator
            {
                // A well-formed stream that simply carries no text, not a
                // malformed one. The path is asked to answer and cannot.
                if (false) {
                    yield '';
                }

                return;
            }
        };

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Received empty response from chat model call.');

        foreach ($make($this, $empty)->stream([new HumanMessage('hi')]) as $ignored) {
            // Consume the stream; the throw is expected before completion.
        }
    }

    public static function emptyStreamSources(): array
    {
        // Derived from the SAME sources() the matrix uses, so every tool route
        // is covered and a source cannot be added without being covered here.
        $rows = [];
        foreach (self::sources() as $name => [$class, $make]) {
            $rows[$name] = [$name, $make];
        }

        return $rows;
    }

    #[DataProvider('matrix')]
    public function testEveryRouteSendsTheSameToolDefinition(
        string $class,
        \Closure $make,
        string $mode,
    ): void {
        $isAnthropic = $class === ChatAnthropic::class;
        $http = $this->recorder($isAnthropic);
        $model = $make($this, $http);

        if ($mode === 'eager') {
            $model->invoke([new HumanMessage('hi')]);
        } else {
            $content = '';
            foreach ($model->stream([new HumanMessage('hi')]) as $pair) {
                $content .= (string) ($pair[1]->content ?? '');
            }
            self::assertSame(
                'ok',
                $content,
                sprintf('%s / %s: streaming produced %d chars, expected the fixture text', $class, $mode, strlen($content)),
            );
        }

        self::assertNotEmpty($http->bodies, 'no request was sent');
        $sent = json_decode($http->bodies[0], true);
        self::assertIsArray($sent);

        $tool = $sent['tools'][0] ?? null;
        self::assertIsArray($tool, sprintf('%s / %s: no tools on the wire', $class, $mode));

        // The provider's own field name differs; everything else must not.
        $name = $isAnthropic ? ($tool['name'] ?? null) : ($tool['function']['name'] ?? null);
        $description = $isAnthropic ? ($tool['description'] ?? null) : ($tool['function']['description'] ?? null);
        $schema = $isAnthropic ? ($tool['input_schema'] ?? null) : ($tool['function']['parameters'] ?? null);

        self::assertSame('search', $name, sprintf('%s / %s: tool name', $class, $mode));
        self::assertSame('search things', $description, sprintf('%s / %s: tool description', $class, $mode));
        self::assertSame(
            ['type' => 'object', 'properties' => ['q' => ['type' => 'string']], 'required' => ['q']],
            $schema,
            sprintf('%s / %s: tool schema', $class, $mode),
        );

        // And nothing that is not a tool leaked into the field.
        self::assertArrayNotHasKey(
            'lc',
            $tool,
            sprintf('%s / %s: a serialised PHP object reached the provider', $class, $mode),
        );
    }

    /**
     * The per-call option is a third route to the same state, and it is the one
     * that was broken for Anthropic. Pinned separately because it takes the
     * option through `RunnableConfig` rather than the constructor.
     */
    public function testPerCallToolsMatchTheConstructorRouteForBothClients(): void
    {
        foreach ([[ChatOpenAI::class, false], [ChatAnthropic::class, true]] as [$class, $isAnthropic]) {
            $viaOption = $this->recorder($isAnthropic);
            (new $class(['apiKey' => 'k', 'httpClient' => $viaOption]))->invoke(
                [new HumanMessage('hi')],
                new RunnableConfig(options: ['tools' => [$this->tool()]]),
            );

            $viaConstructor = $this->recorder($isAnthropic);
            (new $class(['apiKey' => 'k', 'httpClient' => $viaConstructor, 'tools' => [$this->tool()]]))
                ->invoke([new HumanMessage('hi')]);

            self::assertNotEmpty($viaOption->bodies);
            self::assertNotEmpty($viaConstructor->bodies);

            $a = json_decode($viaOption->bodies[0], true)['tools'] ?? null;
            $b = json_decode($viaConstructor->bodies[0], true)['tools'] ?? null;

            self::assertSame(
                $b,
                $a,
                sprintf('%s: the per-call option and the constructor must produce one wire format, not two', $class),
            );
        }
    }
}
