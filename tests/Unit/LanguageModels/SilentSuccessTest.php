<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Stream;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\RunCollectorCallbackHandler;
use LangChain\Utils\Http\GuzzleHttpClient;
use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Two ways a run or a stream could end while looking healthy.
 *
 * Both were found by a reviewer reading the code, then confirmed by execution
 * rather than by inspection — and both are the shape of bug this project's own
 * history is made of: the failure is silent, every assertion still passes, and
 * the damage only shows up downstream in a trace UI or a truncated answer.
 */
#[CoversClass(GuzzleHttpClient::class)]
#[CoversClass(ChatOpenAI::class)]
final class SilentSuccessTest extends TestCase
{
    /**
     * Emits one SSE event, then goes silent forever without reaching EOF.
     */
    private function stallingStream(): Stream
    {
        return new class extends Stream {
            private bool $sent = false;

            public function __construct()
            {
            }

            public function read($length): string
            {
                if (!$this->sent) {
                    $this->sent = true;

                    return "data: {\"choices\":[{\"delta\":{\"content\":\"hi\"}}]}\n\n";
                }
                usleep(2000);

                return '';
            }

            public function eof(): bool
            {
                return false;
            }

            public function getContents(): string
            {
                return '';
            }

            public function __toString(): string
            {
                return '';
            }

            public function getSize(): ?int
            {
                return null;
            }

            public function tell(): int
            {
                return 0;
            }

            public function isSeekable(): bool
            {
                return false;
            }

            public function seek($offset, $whence = SEEK_SET): void
            {
            }

            public function rewind(): void
            {
            }

            public function isWritable(): bool
            {
                return false;
            }

            public function write($string): int
            {
                return 0;
            }

            public function isReadable(): bool
            {
                return true;
            }

            public function getMetadata($key = null)
            {
                return $key === null ? [] : null;
            }
        };
    }

    /**
     * A dropped connection must raise, not end the generator cleanly.
     *
     * The read loop used to `break` on the silence limit, so the generator
     * returned normally, `BaseChatModel::stream()` called `handleLLMEnd` with a
     * half-built message, and the caller received a truncated completion with
     * no error. A caller cannot tell "the model finished" from "the connection
     * died" if both look like the end of a generator.
     */
    public function testAStalledStreamRaisesRatherThanReturningATruncatedAnswer(): void
    {
        $client = new GuzzleHttpClient(['handler' => HandlerStack::create(
            new MockHandler([new Response(200, ['Content-Type' => 'text/event-stream'], $this->stallingStream())]),
        )]);
        $client->streamSilenceLimit = 0.05;

        $yielded = [];
        $threw = null;
        try {
            foreach ($client->postStream('https://example.test/s', ['Accept' => 'text/event-stream'], '{}') as $chunk) {
                $yielded[] = $chunk;
            }
        } catch (HttpException $e) {
            $threw = $e;
        }

        // The event that DID arrive is still delivered — the caller keeps the
        // partial data — but the truncation is now loud.
        self::assertCount(1, $yielded, 'the data received before the stall is still yielded');
        self::assertNotNull($threw, 'a stalled stream must raise, not end the generator cleanly');
        self::assertStringContainsString('stalled', $threw->getMessage());
        // The URL has to be in the message: "stalled" alone does not say which
        // call, and a provider that stalls on one endpoint and not another is
        // exactly the case this exception exists to make legible.
        self::assertStringContainsString('https://example.test/s', $threw->getMessage());
    }

    /**
     * Every run a batch OPENS must settle, including prompts after the failure.
     *
     * `handleChatModelStart` creates one run per prompt before any of them is
     * attempted, and the loop threw on the first failure. A 3-prompt batch that
     * failed on prompt 2 therefore left 3 runs started and 2 terminated — a span
     * hanging in any trace UI, for a prompt that was never even sent. Upstream
     * catches per-prompt (`allSettled`) and settles every one of them.
     */
    public function testEveryRunABatchOpensSettlesEvenWhenALaterPromptFails(): void
    {
        $ok = static fn (string $c) => FakeHttpClient::json(200, ['id' => 'x', 'model' => 'm', 'choices' => [[
            'index' => 0,
            'message' => ['role' => 'assistant', 'content' => $c],
        ]]]);

        $model = new ChatOpenAI([
            'apiKey' => 'k',
            'maxRetries' => 0,
            'httpClient' => new FakeHttpClient([
                $ok('one'),
                FakeHttpClient::json(500, ['error' => ['message' => 'boom']]),
                $ok('three'),
            ]),
        ]);

        $collector = new RunCollectorCallbackHandler();

        try {
            $model->generateMessages(
                [['a' => 'p1'], ['b' => 'p2'], ['c' => 'p3']],
                [],
                null,
                new RunnableConfig(callbacks: [$collector]),
            );
            self::fail('the second prompt must fail');
        } catch (\Throwable) {
            // expected
        }

        // Three runs are opened up front, so all three must be accounted for.
        self::assertCount(
            3,
            $collector->tracedRuns,
            'every run handleChatModelStart opened must terminate; a run left open hangs in any trace UI',
        );

        $errors = array_filter(
            $collector->tracedRuns,
            static fn (\LangChain\Tracers\Run $r): bool => $r->error !== null,
        );
        // The failure itself, plus the prompt after it that was never attempted.
        self::assertCount(2, $errors, 'the failing prompt and each unattempted one after it settle with an error');
    }
}
