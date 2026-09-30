<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat;

use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\Utils\Http\{HttpClient, HttpException, HttpResponse};
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A 4xx must not be retried; a 5xx and a dead connection must be.
 *
 * The streaming path filtered on `status === 0 || 429 || >= 500` and the eager
 * path retried EVERY `HttpException`. `HttpClient` is a public interface, so a
 * transport raising a 400 was retried there — contradicting the class's own
 * docblock, and making one failure four times the latency on one route and not
 * the other.
 *
 * Counting requests is the only honest way to test this. An exception type
 * cannot tell "gave up immediately" from "gave up after three attempts", and
 * that difference is the entire point.
 */
#[CoversClass(ChatOpenAI::class)]
#[CoversClass(ChatAnthropic::class)]
final class RetryPredicateTest extends TestCase
{
    /** Counts requests, then always fails with a fixed status. */
    private function client(int $status, int &$count): HttpClient
    {
        return new class ($status, $count) implements HttpClient {
            public function __construct(private int $status, private int &$count)
            {
            }

            public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
            {
                $this->count++;

                throw new HttpException('{"error":{"message":"nope"}}', $this->status, '');
            }

            public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
            {
                $this->count++;

                throw new HttpException('{"error":{"message":"nope"}}', $this->status, '');

                yield;
            }
        };
    }

    /**
     * The same client with the retry WAIT removed.
     *
     * `backoff()` is `protected` on both clients precisely so a test can
     * override it. Without this the real exponential sleep runs, and 24 cases
     * took 36 seconds of pure `usleep` — not something to leave in the unit
     * suite. The retry DECISION is under test here, not the waiting.
     */
    private function instantOpenAI(int $status, int &$count): ChatOpenAI
    {
        return new class (['apiKey' => 'k', 'maxRetries' => 2, 'httpClient' => $this->client($status, $count)]) extends ChatOpenAI {
            protected function backoff(int $attempt): void
            {
            }
        };
    }

    private function instantAnthropic(int $status, int &$count): ChatAnthropic
    {
        return new class (['apiKey' => 'k', 'maxRetries' => 2, 'httpClient' => $this->client($status, $count)]) extends ChatAnthropic {
            protected function backoff(int $attempt): void
            {
            }
        };
    }

    /** @return array<string, array{0: int, 1: bool}> */
    public static function statuses(): array
    {
        return [
            // A 4xx is the provider saying the REQUEST is wrong. It will be
            // wrong identically next time, so another round trip is pure cost.
            '400 bad request' => [400, false],
            '401 unauthorized' => [401, false],
            '404 not found' => [404, false],
            '422 unprocessable' => [422, false],
            // 429 and 5xx are the provider saying "not now" or "my fault".
            '429 rate limited' => [429, true],
            '500 server error' => [500, true],
            '503 unavailable' => [503, true],
            // Status 0 is the transport reporting the connection never happened
            // — refused, DNS failure, reset. The canonical transient case, and
            // the one the eager path must not start ignoring.
            '0 connection refused' => [0, true],
        ];
    }

    #[DataProvider('statuses')]
    public function testEagerOpenAiRetriesOnlyWhatIsWorthRetrying(int $status, bool $shouldRetry): void
    {
        $count = 0;
        try {
            $this->instantOpenAI($status, $count)->invoke('hi');
        } catch (\Throwable) {
            // every status in the table is a failure
        }

        self::assertSame(
            $shouldRetry ? 3 : 1,
            $count,
            sprintf('eager OpenAI, status %d: expected %d request(s)', $status, $shouldRetry ? 3 : 1),
        );
    }

    #[DataProvider('statuses')]
    public function testEagerAnthropicRetriesOnlyWhatIsWorthRetrying(int $status, bool $shouldRetry): void
    {
        $count = 0;
        try {
            $this->instantAnthropic($status, $count)->invoke('hi');
        } catch (\Throwable) {
        }

        self::assertSame(
            $shouldRetry ? 3 : 1,
            $count,
            sprintf('eager Anthropic, status %d: expected %d request(s)', $status, $shouldRetry ? 3 : 1),
        );
    }

    /**
     * The streaming path already filtered. Pinned so the two routes cannot
     * drift apart again — the whole defect was that they had.
     */
    #[DataProvider('statuses')]
    public function testStreamPathAgreesWithTheEagerPath(int $status, bool $shouldRetry): void
    {
        $count = 0;
        try {
            foreach ($this->instantOpenAI($status, $count)->stream('hi') as $ignored) {
            }
        } catch (\Throwable) {
        }

        self::assertSame(
            $shouldRetry ? 3 : 1,
            $count,
            sprintf('stream, status %d: eager and stream must retry the same set', $status),
        );
    }
}
