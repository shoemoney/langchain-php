<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk;

use LangChain\Tests\Unit\Sdk\Support\StreamingTransport;
use LangChain\Utils\Http\HttpResponse;
use LangGraph\Sdk\Utils\MaxReconnectAttemptsError;
use LangGraph\Sdk\Utils\SseDecoder;
use LangGraph\Sdk\Utils\StreamIdleTimeoutError;
use LangGraph\Sdk\Utils\StreamRetry;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `utils/stream-retry.test.ts` (`streamWithRetry`) and `utils/idle-reconnect.test.ts`
 * (`idleReconnectStream`).
 *
 * Differences from the JS tests, all forced by the language:
 *  - `vi.useFakeTimers()` becomes injected `sleep` / `clock` callables, so nothing waits and the
 *    backoff each reconnect asked for is recorded and asserted;
 *  - the idle tests drive a synthetic clock from inside the line generator, which is also how a
 *    quiet socket looks to the watchdog (control returns to it only on a line or a `null` tick);
 *  - a `TypeError` stands in for JS's `TypeError("network error")`: it is what
 *    {@see \LangGraph\Sdk\Utils\ErrorUtils::isNetworkError()} recognises.
 */
#[CoversClass(StreamRetry::class)]
#[CoversClass(MaxReconnectAttemptsError::class)]
#[CoversClass(StreamIdleTimeoutError::class)]
final class StreamRetryTest extends TestCase
{
    private const NEW_PATH = '/reconnect/special-path';

    /** @var list<int> */
    private array $slept = [];

    protected function setUp(): void
    {
        $this->slept = [];
    }

    private function sse(): HttpResponse
    {
        return new HttpResponse(200, ['content-type' => 'text/event-stream', 'location' => self::NEW_PATH]);
    }

    /** @return array<string, mixed> */
    private function options(array $extra = []): array
    {
        return $extra + ['sleep' => function (int $ms): void {
            $this->slept[] = $ms;
        }];
    }

    /**
     * The stream the JS helper builds: SSE text through the byte-line and SSE decoders.
     *
     * @param list<array{id?: string, event: string, data: mixed}> $parts
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private static function sseStream(array $parts): \Generator
    {
        return SseDecoder::decode(StreamingTransport::chunksOf($parts));
    }

    /**
     * Parts, then an error, like a socket that dies mid-body.
     *
     * @param list<array<string, mixed>> $parts
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private static function erroringStream(array $parts, \Throwable $error): \Generator
    {
        yield from $parts;

        throw $error;
    }

    /**
     * @param \Generator<int, array<string, mixed>> $generator
     *
     * @return list<array<string, mixed>>
     */
    private static function gather(\Generator $generator): array
    {
        return iterator_to_array($generator, false);
    }

    // ---- streamWithRetry: successful streaming

    public function testStreamsSuccessfullyWithoutRetries(): void
    {
        $calls = [];
        $makeRequest = function (?array $params) use (&$calls): array {
            $calls[] = $params;

            return ['response' => $this->sse(), 'stream' => self::sseStream([
                ['id' => '1', 'event' => 'message', 'data' => ['content' => 'hello']],
                ['id' => '2', 'event' => 'message', 'data' => ['content' => 'world']],
            ])];
        };

        $results = self::gather(StreamRetry::streamWithRetry($makeRequest, $this->options()));

        $this->assertCount(2, $results);
        $this->assertSame('1', $results[0]['id']);
        $this->assertSame(['content' => 'hello'], $results[0]['data']);
        $this->assertSame('2', $results[1]['id']);
        $this->assertSame(['content' => 'world'], $results[1]['data']);
        $this->assertSame([null], $calls);
        $this->assertSame([], $this->slept);
    }

    // ---- streamWithRetry: retry logic

    public function testUsesLocationHeaderForReconnectionPath(): void
    {
        $calls = [];
        $makeRequest = function (?array $params) use (&$calls): array {
            $calls[] = $params;

            if (count($calls) === 1) {
                return ['response' => $this->sse(), 'stream' => self::erroringStream(
                    [['id' => '1', 'event' => 'msg', 'data' => ['part' => 1]]],
                    new \TypeError('network error'),
                )];
            }

            return ['response' => $this->sse(), 'stream' => self::sseStream([['id' => '2', 'event' => 'msg', 'data' => ['part' => 2]]])];
        };

        $results = self::gather(StreamRetry::streamWithRetry($makeRequest, $this->options()));

        $this->assertCount(2, $results);
        $this->assertSame([null, ['lastEventId' => '1', 'reconnectPath' => self::NEW_PATH]], $calls);
        $this->assertCount(1, $this->slept, 'one reconnect, one backoff');
    }

    public function testDoesNotTryToReconnectWhenNoLocationHeaderIsProvided(): void
    {
        $calls = 0;
        $makeRequest = function (?array $params) use (&$calls): array {
            ++$calls;

            return [
                'response' => new HttpResponse(200, ['content-type' => 'text/event-stream']),
                'stream' => self::erroringStream([['id' => '1', 'event' => 'msg', 'data' => ['part' => 1]]], new \TypeError('non retryable error')),
            ];
        };

        $generator = StreamRetry::streamWithRetry($makeRequest, $this->options());

        $this->assertSame('1', $generator->current()['id']);

        try {
            $generator->next();
            $this->fail('the read error must propagate');
        } catch (\TypeError $e) {
            $this->assertSame('non retryable error', $e->getMessage());
        }
        $this->assertSame(1, $calls);
        $this->assertSame([], $this->slept);
    }

    public function testThrowsMaxReconnectAttemptsErrorAfterMaxRetries(): void
    {
        $calls = [];
        $makeRequest = function (?array $params) use (&$calls): array {
            $calls[] = $params;
            $parts = $params !== null ? [] : [['id' => '1', 'event' => 'msg', 'data' => []]];

            return ['response' => $this->sse(), 'stream' => self::erroringStream($parts, new \TypeError('persistent network error'))];
        };

        try {
            self::gather(StreamRetry::streamWithRetry($makeRequest, $this->options(['maxRetries' => 2])));
            $this->fail('expected MaxReconnectAttemptsError');
        } catch (MaxReconnectAttemptsError $e) {
            $this->assertSame('Exceeded maximum SSE reconnection attempts (2)', $e->getMessage());
            $this->assertSame(2, $e->maxAttempts);
            $this->assertSame('persistent network error', $e->getPrevious()?->getMessage());
        }

        $this->assertCount(3, $calls, 'the initial request plus two reconnects');
        $this->assertCount(2, $this->slept);
        $this->assertGreaterThanOrEqual(1000, $this->slept[0]);
        $this->assertLessThan(2000, $this->slept[0]);
        $this->assertGreaterThanOrEqual(2000, $this->slept[1]);
        $this->assertLessThan(3000, $this->slept[1]);
    }

    public function testPassesLastEventIdToTheReconnectRequestAndDoesNotReplay(): void
    {
        $calls = [];
        $makeRequest = function (?array $params) use (&$calls): array {
            $calls[] = $params;

            if (count($calls) === 1) {
                return ['response' => $this->sse(), 'stream' => self::erroringStream([
                    ['id' => 'event-1', 'event' => 'msg', 'data' => ['text' => 'first']],
                    ['id' => 'event-2', 'event' => 'msg', 'data' => ['text' => 'second']],
                ], new \TypeError('network error'))];
            }

            return ['response' => $this->sse(), 'stream' => self::sseStream([['id' => 'event-3', 'event' => 'msg', 'data' => ['text' => 'third']]])];
        };

        $results = self::gather(StreamRetry::streamWithRetry($makeRequest, $this->options()));

        $this->assertSame(['event-1', 'event-2', 'event-3'], array_column($results, 'id'), 'resumed, not replayed');
        $this->assertCount(2, $calls);
        $this->assertSame(['lastEventId' => 'event-2', 'reconnectPath' => self::NEW_PATH], $calls[1]);
    }

    public function testReportsEachReconnectToOnReconnect(): void
    {
        $seen = [];
        $calls = 0;
        $makeRequest = function (?array $params) use (&$calls): array {
            ++$calls;

            return $calls === 1
                ? ['response' => $this->sse(), 'stream' => self::erroringStream([['id' => '7', 'event' => 'm', 'data' => 1]], new \TypeError('boom'))]
                : ['response' => $this->sse(), 'stream' => []];
        };

        self::gather(StreamRetry::streamWithRetry($makeRequest, $this->options([
            'onReconnect' => static function (array $info) use (&$seen): void {
                $seen[] = $info;
            },
        ])));

        $this->assertCount(1, $seen);
        $this->assertSame(1, $seen[0]['attempt']);
        $this->assertSame('7', $seen[0]['lastEventId']);
        $this->assertSame('boom', $seen[0]['cause']->getMessage());
    }

    // ---- content-type validation

    public function testThrowsOnInvalidContentType(): void
    {
        $makeRequest = static fn (): array => [
            'response' => new HttpResponse(200, ['content-type' => 'application/json']),
            'stream' => self::sseStream([]),
        ];

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Expected response header Content-Type to contain 'text/event-stream'");

        self::gather(StreamRetry::streamWithRetry($makeRequest, $this->options()));
    }

    public function testAcceptsContentTypeWithCharset(): void
    {
        $makeRequest = static fn (): array => [
            'response' => new HttpResponse(200, ['content-type' => 'text/event-stream; charset=utf-8']),
            'stream' => self::sseStream([['id' => '1', 'event' => 'msg', 'data' => ['test' => true]]]),
        ];

        $results = self::gather(StreamRetry::streamWithRetry($makeRequest, $this->options()));

        $this->assertCount(1, $results);
        $this->assertSame(['test' => true], $results[0]['data']);
    }

    // ---- abort signal handling

    public function testStopsStreamingWhenSignalIsAbortedBeforeStart(): void
    {
        $calls = 0;
        $makeRequest = function () use (&$calls): array {
            ++$calls;

            return ['response' => $this->sse(), 'stream' => []];
        };

        $results = self::gather(StreamRetry::streamWithRetry($makeRequest, $this->options(['signal' => static fn (): bool => true])));

        $this->assertSame([], $results);
        $this->assertSame(0, $calls);
    }

    public function testStopsStreamingWhenSignalIsAbortedDuringStream(): void
    {
        $aborted = false;
        $makeRequest = fn (): array => ['response' => $this->sse(), 'stream' => self::sseStream([
            ['id' => '1', 'event' => 'msg', 'data' => ['text' => 'first']],
            ['id' => '2', 'event' => 'msg', 'data' => ['text' => 'second']],
            ['id' => '3', 'event' => 'msg', 'data' => ['text' => 'third']],
        ])];

        $results = [];
        foreach (StreamRetry::streamWithRetry($makeRequest, $this->options(['signal' => static function () use (&$aborted): bool {
            return $aborted;
        }])) as $part) {
            $results[] = $part;
            if ($part['id'] === '1') {
                $aborted = true;
            }
        }

        $this->assertSame(['1'], array_column($results, 'id'));
    }

    public function testDoesNotRetryWhenAborted(): void
    {
        $aborted = false;
        $calls = 0;
        $makeRequest = function () use (&$calls): array {
            ++$calls;

            return ['response' => $this->sse(), 'stream' => self::erroringStream([['id' => '1', 'event' => 'msg', 'data' => []]], new \TypeError('network error'))];
        };

        $results = [];
        foreach (StreamRetry::streamWithRetry($makeRequest, $this->options(['signal' => static function () use (&$aborted): bool {
            return $aborted;
        }])) as $part) {
            $results[] = $part;
            $aborted = true;
        }

        $this->assertCount(1, $results);
        $this->assertSame(1, $calls);
        $this->assertSame([], $this->slept);
    }

    public function testAnAbortedErrorWhileReadingPropagatesInsteadOfRetrying(): void
    {
        $aborted = false;
        $makeRequest = function () use (&$aborted): array {
            return ['response' => $this->sse(), 'stream' => (function () use (&$aborted): \Generator {
                $aborted = true;

                throw new \TypeError('network error');
                yield; // @phpstan-ignore deadCode.unreachable
            })()];
        };

        $this->expectException(\TypeError::class);

        self::gather(StreamRetry::streamWithRetry($makeRequest, $this->options(['signal' => static function () use (&$aborted): bool {
            return $aborted;
        }])));
    }

    // ---- onReconnect callback

    public function testOnReconnectIsNotCalledWhenThereIsNothingToReconnect(): void
    {
        $called = false;
        $makeRequest = fn (): array => ['response' => $this->sse(), 'stream' => self::sseStream([['id' => '1', 'event' => 'msg', 'data' => ['test' => true]]])];

        self::gather(StreamRetry::streamWithRetry($makeRequest, $this->options([
            'maxRetries' => 5,
            'onReconnect' => static function () use (&$called): void {
                $called = true;
            },
        ])));

        $this->assertFalse($called);
    }

    // ---- edge cases

    public function testHandlesAnEmptyStream(): void
    {
        $calls = [];
        $makeRequest = function (?array $params) use (&$calls): array {
            $calls[] = $params;

            return ['response' => $this->sse(), 'stream' => self::sseStream([])];
        };

        $this->assertSame([], self::gather(StreamRetry::streamWithRetry($makeRequest, $this->options())));
        $this->assertSame([null], $calls);
    }

    public function testHandlesEventsWithoutIds(): void
    {
        $makeRequest = fn (): array => ['response' => $this->sse(), 'stream' => self::sseStream([
            ['event' => 'msg', 'data' => ['text' => 'no id']],
            ['id' => '2', 'event' => 'msg', 'data' => ['text' => 'has id']],
        ])];

        $results = self::gather(StreamRetry::streamWithRetry($makeRequest, $this->options()));

        $this->assertCount(2, $results);
        $this->assertNull($results[0]['id']);
        $this->assertSame('2', $results[1]['id']);
    }

    public function testDoesNotRetryOnNonNetworkErrorsThrownByTheRequest(): void
    {
        $calls = 0;
        $makeRequest = function () use (&$calls): array {
            ++$calls;

            throw new \RuntimeException('Non-network error');
        };

        try {
            self::gather(StreamRetry::streamWithRetry($makeRequest, $this->options(['maxRetries' => 5])));
            $this->fail('expected the request error to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('Non-network error', $e->getMessage());
        }
        $this->assertSame(1, $calls);
    }

    public function testDoesNotRetryOnNonNetworkErrorsWhileReading(): void
    {
        $calls = 0;
        $makeRequest = function () use (&$calls): array {
            ++$calls;

            // No Location header: nowhere to reconnect to, so the read error is final.
            return [
                'response' => new HttpResponse(200, ['content-type' => 'text/event-stream']),
                'stream' => self::erroringStream([['id' => '1', 'event' => 'msg', 'data' => []]], new \RuntimeException('Non-network error')),
            ];
        };

        $this->expectExceptionMessage('Non-network error');

        try {
            self::gather(StreamRetry::streamWithRetry($makeRequest, $this->options(['maxRetries' => 5])));
        } finally {
            $this->assertSame(1, $calls);
        }
    }

    public function testRetriesAFailedReconnectRequestThatIsANetworkError(): void
    {
        $calls = [];
        $makeRequest = function (?array $params) use (&$calls): array {
            $calls[] = $params;

            return match (count($calls)) {
                1 => ['response' => $this->sse(), 'stream' => self::erroringStream([['id' => '1', 'event' => 'v', 'data' => ['step' => 1]]], new \TypeError('terminated'))],
                2 => throw new \TypeError('terminated'),
                default => ['response' => $this->sse(), 'stream' => self::sseStream([['id' => '2', 'event' => 'v', 'data' => ['step' => 2]]])],
            };
        };

        $results = self::gather(StreamRetry::streamWithRetry($makeRequest, $this->options()));

        $this->assertSame(['1', '2'], array_column($results, 'id'));
        $this->assertCount(3, $calls);
        $this->assertSame(['lastEventId' => '1', 'reconnectPath' => self::NEW_PATH], $calls[2], 'the failed reconnect keeps the resume point');
    }

    // ---- idleReconnectStream (utils/idle-reconnect.test.ts)

    /**
     * @param callable(): \Generator<int, string|null> $source built after `$now` exists
     *
     * @return array{errored: bool, error: ?\Throwable}
     */
    private function drain(iterable $lines, array $options): array
    {
        try {
            foreach (StreamRetry::idleReconnectStream($lines, $options) as $_) {
            }

            return ['errored' => false, 'error' => null];
        } catch (StreamIdleTimeoutError $e) {
            return ['errored' => true, 'error' => $e];
        }
    }

    public function testAutoArmsAfterTwoHeartbeatsAndFiresAfterThreeTimesTheCadenceOfSilence(): void
    {
        $now = 0;
        $lines = (function () use (&$now): \Generator {
            yield ': heartbeat'; // t=0, first heartbeat (no cadence yet)
            $now += 5_000;
            yield ': heartbeat'; // t=5s -> interval 5s -> window 15s
            $now += 14_000;
            yield null; // 14s of silence: still alive
            $now += 2_000;
            yield null; // crossed the 15s window
        })();

        $res = $this->drain($lines, ['mode' => 'auto', 'clock' => static function () use (&$now): int {
            return $now;
        }]);

        $this->assertTrue($res['errored']);
        $this->assertInstanceOf(StreamIdleTimeoutError::class, $res['error']);
        $this->assertSame(15_000, $res['error']->idleTimeoutMs);
    }

    public function testAutoStaysDormantOnAHeartbeatLessStreamEvenThroughLongSilence(): void
    {
        $now = 0;
        $lines = (function () use (&$now): \Generator {
            yield 'data: {"x":1}';
            // Long silence with no heartbeats ever observed: never arms.
            $now += 120_000;
            yield null;
        })();

        $res = $this->drain($lines, ['mode' => 'auto', 'clock' => static function () use (&$now): int {
            return $now;
        }]);

        $this->assertFalse($res['errored']);
    }

    public function testAutoHeartbeatsEveryFiveSecondsKeepAQuietStreamAliveIndefinitely(): void
    {
        $now = 0;
        $lines = (function () use (&$now): \Generator {
            for ($i = 0; $i < 20; ++$i) {
                yield ': heartbeat';
                $now += 5_000;
            }
        })();

        $res = $this->drain($lines, ['mode' => 'auto', 'clock' => static function () use (&$now): int {
            return $now;
        }]);

        $this->assertFalse($res['errored']);
    }

    public function testFixedArmsFromTheFirstByteAndFiresAfterTheConfiguredWindow(): void
    {
        $now = 0;
        $fired = [];
        $lines = (function () use (&$now): \Generator {
            // No lines at all: just the passage of time.
            $now += 11_000;
            yield null;
        })();

        $res = $this->drain($lines, [
            'mode' => 10_000,
            'clock' => static function () use (&$now): int {
                return $now;
            },
            'onIdle' => static function (array $info) use (&$fired): void {
                $fired[] = $info;
            },
        ]);

        $this->assertTrue($res['errored']);
        $this->assertSame(10_000, $res['error']->idleTimeoutMs);
        $this->assertSame([['timeoutMs' => 10_000, 'source' => 'fixed']], $fired);
    }

    public function testFixedActivityResetsTheWindow(): void
    {
        $now = 0;
        $lines = (function () use (&$now): \Generator {
            // A line every 8s keeps resetting the 10s window.
            for ($i = 0; $i < 5; ++$i) {
                $now += 8_000;
                yield 'data: {"x":1}';
            }
        })();

        $res = $this->drain($lines, ['mode' => 10_000, 'clock' => static function () use (&$now): int {
            return $now;
        }]);

        $this->assertFalse($res['errored']);
    }

    public function testALineThatArrivesAfterTheWindowHasPassedIsTooLate(): void
    {
        $now = 0;
        $lines = (function () use (&$now): \Generator {
            $now += 10_000;
            yield 'data: {"x":1}';
        })();

        $res = $this->drain($lines, ['mode' => 10_000, 'clock' => static function () use (&$now): int {
            return $now;
        }]);

        $this->assertTrue($res['errored'], 'JS would have fired mid-silence; here the late line is how we find out');
    }

    public function testPassesLinesThroughAndHidesTicks(): void
    {
        $out = iterator_to_array(StreamRetry::idleReconnectStream(['a', null, 'b'], ['mode' => 'auto']), false);

        $this->assertSame(['a', 'b'], $out);
    }

    public function testAutoWindowIsClampedToTheMinimumAndMaximum(): void
    {
        foreach ([[1_000, 6_000], [20_000, 30_000]] as [$cadence, $expectedWindow]) {
            $now = 0;
            $lines = (function () use (&$now, $cadence, $expectedWindow): \Generator {
                yield ': hb';
                $now += $cadence;
                yield ': hb';
                $now += $expectedWindow;
                yield null;
            })();

            $res = $this->drain($lines, ['mode' => 'auto', 'clock' => static function () use (&$now): int {
                return $now;
            }]);

            $this->assertSame($expectedWindow, $res['error']->idleTimeoutMs);
        }
    }
}
