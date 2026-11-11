<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk;

use LangChain\Tests\Unit\Sdk\Support\RecordingTransport;
use LangChain\Tests\Unit\Sdk\Support\StreamingTransport;
use LangChain\Utils\Http\HttpResponse;
use LangGraph\Sdk\ThreadsClient;
use LangGraph\Sdk\Stream\ProtocolSseTransport;
use LangGraph\Sdk\Utils\StreamIdleTimeoutError;
use PHPUnit\Framework\TestCase;

final class ThreadStreamTransportTest extends TestCase
{
    public function testIncrementalReadsDoNotDrainFutureChunksAndCloseReleasesTheBody(): void
    {
        $produced = 0;
        $released = false;
        $chunks = static function () use (&$produced, &$released): \Generator {
            try {
                ++$produced;
                yield ThreadStreamTest::sse([ThreadStreamTest::event('values', 1)])->body;
                ++$produced;
                yield ThreadStreamTest::sse([ThreadStreamTest::event('values', 2, 2)])->body;
            } finally {
                $released = true;
            }
        };
        $http = new StreamingTransport([static fn () => StreamingTransport::sse($chunks())]);
        $thread = (new ThreadsClient(['apiKey' => null], $http))->stream('thread', ['assistantId' => 'agent', 'maxReconnectAttempts' => 0]);
        $cursor = $thread->subscribe('values')->getIterator();
        $cursor->rewind();
        self::assertSame(1, $cursor->current()['params']['data']);
        self::assertSame(1, $produced);
        $thread->close();
        self::assertTrue($released);
        $cursor->next();
        self::assertFalse($cursor->valid());
        self::assertSame(1, $produced);
    }

    public function testLateSubscriptionRotatesSharedFilterAndDedupsExistingConsumersOnly(): void
    {
        $one = ThreadStreamTest::event('values', 1, 1);
        $two = ThreadStreamTest::event('values', 2, 2);
        $http = new RecordingTransport([ThreadStreamTest::sse([$one]), ThreadStreamTest::sse([$one, $two])]);
        $thread = (new ThreadsClient(['apiKey' => null], $http))->stream('thread', ['assistantId' => 'agent', 'maxReconnectAttempts' => 0]);
        $first = $thread->subscribe('values');
        $cursor = $first->getIterator();
        $cursor->rewind();
        self::assertSame($one, $cursor->current());
        $late = $thread->subscribe('values');
        self::assertSame([$one, $two], iterator_to_array($late, false));
        $cursor->next();
        self::assertSame($two, $cursor->current());
        $cursor->next();
        self::assertFalse($cursor->valid());
        self::assertCount(2, $http->requests);
        self::assertSame(2, $thread->ordering['lastSeenSeq']);
        self::assertSame('event-2', $thread->ordering['lastEventId']);
    }

    public function testUnsubscribeWithUnchangedUnionDoesNotRotateTheBody(): void
    {
        $http = new RecordingTransport([ThreadStreamTest::sse([
            ThreadStreamTest::event('values', 1), ThreadStreamTest::event('values', 2, 2),
        ])]);
        $thread = (new ThreadsClient(['apiKey' => null], $http))->stream('thread', ['assistantId' => 'agent', 'maxReconnectAttempts' => 0]);
        $first = $thread->subscribe('values');
        $second = $thread->subscribe('values');
        $cursor = $first->getIterator();
        $cursor->rewind();
        self::assertSame(1, $cursor->current()['params']['data']);
        $second->unsubscribe();
        $cursor->next();
        self::assertSame(2, $cursor->current()['params']['data']);
        self::assertCount(1, $http->requests);
        $thread->close();
    }

    public function testReconnectOmitsSinceAfterReadyAndRetainsItBeforeReady(): void
    {
        $requests = [];
        $connections = [];
        $reconnects = [];
        $responses = [new HttpResponse(503, [], 'unavailable'), ThreadStreamTest::sse([]),
            ThreadStreamTest::sse([ThreadStreamTest::event('values', 3)])];
        $transport = new ProtocolSseTransport('thread', new RecordingTransport(),
            static fn ($path, $options): array => ['https://unit.test' . $path, ['body' => json_encode($options['json'])]],
            static fn () => throw new \LogicException('Unused command fetch'), null, [
            'maxReconnectAttempts' => 2, 'reconnectDelayMs' => static fn () => 0,
            'fetch' => static function ($url, $init) use (&$requests, &$responses): HttpResponse {
                $requests[] = json_decode($init['body'], true);

                return array_shift($responses);
            },
            'onConnected' => static function ($info) use (&$connections): void { $connections[] = $info; },
            'onReconnect' => static function ($info) use (&$reconnects): void { $reconnects[] = $info['attempt']; },
        ]);
        foreach ($transport->events(['channels' => ['values'], 'since' => 7], static fn (): bool => false) as $event) {
            if ($event !== null) {
                self::assertSame(3, $event['params']['data']);
                break;
            }
        }
        $transport->close();
        self::assertSame(7, $requests[0]['since']);
        self::assertSame(7, $requests[1]['since']);
        self::assertArrayNotHasKey('since', $requests[2]);
        self::assertSame([1, 2], $reconnects);
        self::assertSame([['kind' => 'reconnected', 'attempt' => 1], ['kind' => 'reconnected', 'attempt' => 2]], $connections);
    }

    public function testSharedThreadUnionOmitsConnectionLocalSinceAsPinnedSourceDoes(): void
    {
        $http = new RecordingTransport([ThreadStreamTest::sse([])]);
        $thread = (new ThreadsClient(['apiKey' => null], $http))->stream('thread', ['assistantId' => 'agent', 'maxReconnectAttempts' => 0]);
        iterator_to_array($thread->subscribe('values', ['since' => 7]));
        self::assertSame(['channels' => ['values']], json_decode($http->requests[0]['body'], true));
    }

    public function testFailedReplacementKeepsOldBodyAndRejectsOnlyUncoveredSubscriber(): void
    {
        $released = false;
        $chunks = static function () use (&$released): \Generator {
            try {
                yield ThreadStreamTest::sse([ThreadStreamTest::event('values', 1)])->body;
                yield ThreadStreamTest::sse([ThreadStreamTest::event('values', 2, 2)])->body;
            } finally {
                $released = true;
            }
        };
        $http = new StreamingTransport([static fn () => StreamingTransport::sse($chunks()), static fn () => StreamingTransport::sse(['unavailable'], [], 503)]);
        $thread = (new ThreadsClient(['apiKey' => null], $http))->stream('thread', ['assistantId' => 'agent', 'maxReconnectAttempts' => 0]);
        $old = $thread->subscribe('values')->getIterator();
        $old->rewind();
        self::assertSame(1, $old->current()['params']['data']);
        $late = $thread->subscribe('updates');
        try {
            iterator_to_array($late);
            self::fail('Expected pending uncovered subscription to fail.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('503', $error->getMessage());
        }
        self::assertFalse($released);
        $old->next();
        self::assertSame(2, $old->current()['params']['data']);
        $thread->close();
        self::assertTrue($released);
        self::assertCount(2, $http->requests);
    }

    public function testCancellationBetweenChunksDoesNotAdvanceTheBodyOrRetry(): void
    {
        $abort = false;
        $produced = 0;
        $released = false;
        $chunks = static function () use (&$produced, &$released): \Generator {
            try {
                ++$produced;
                yield ThreadStreamTest::sse([ThreadStreamTest::event('values', 1)])->body;
                ++$produced;
                yield ThreadStreamTest::sse([ThreadStreamTest::event('values', 2, 2)])->body;
            } finally {
                $released = true;
            }
        };
        $http = new StreamingTransport([static fn () => StreamingTransport::sse($chunks())]);
        $thread = (new ThreadsClient(['apiKey' => null, 'onRequest' => static function ($url, $init) use (&$abort): array {
            $init['signal'] = static function () use (&$abort): bool { return $abort; };

            return $init;
        }], $http))->stream('thread', ['assistantId' => 'agent']);
        $cursor = $thread->subscribe('values')->getIterator();
        $cursor->rewind();
        self::assertSame(1, $produced);
        $abort = true;
        try {
            $cursor->next();
            self::fail('Expected abort.');
        } catch (\RuntimeException $error) {
            self::assertSame('AbortError', $error->getMessage());
        }
        self::assertSame(1, $produced);
        self::assertTrue($released);
        self::assertCount(1, $http->requests);
    }

    public function testFixedAndHeartbeatAdaptiveIdleWatchdogsObservePollingTicks(): void
    {
        foreach ([10, 'auto'] as $mode) {
            $now = 0;
            $chunks = static function () use (&$now, $mode): \Generator {
                if ($mode === 'auto') {
                    yield ": keepalive\n\n";
                    $now = 100;
                    yield ": keepalive\n\n";
                    $now = 6100;
                } else {
                    $now = 10;
                }
                yield null;
            };
            $http = new StreamingTransport([static fn () => StreamingTransport::sse($chunks())]);
            $thread = (new ThreadsClient(['apiKey' => null], $http))->stream('thread', ['assistantId' => 'agent',
                'maxReconnectAttempts' => 0, 'streamIdleReconnect' => $mode,
                'clock' => static function () use (&$now): int { return $now; }]);
            try {
                iterator_to_array($thread->subscribe('values'));
                self::fail('Expected idle timeout.');
            } catch (StreamIdleTimeoutError $error) {
                self::assertNotSame('', $error->getMessage());
            }
            self::assertCount(1, $http->requests);
        }
    }

    public function testRequestHooksAuthAndDisabledStreamDeadlineReachCustomFetch(): void
    {
        $seen = [];
        $client = new ThreadsClient(['apiUrl' => 'https://unit.test/prefix', 'apiKey' => 'secret-test', 'timeoutMs' => 1,
            'onRequest' => static function ($url, $init): array { $init['headers']['x-hook'] = 'yes'; return $init; }]);
        $thread = $client->stream('thread', ['assistantId' => 'agent', 'maxReconnectAttempts' => 0,
            'fetch' => static function ($url, $init) use (&$seen): HttpResponse {
                $seen[] = [$url, $init];

                return ThreadStreamTest::sse([ThreadStreamTest::event('values', ['ok' => true])]);
            }]);
        self::assertCount(1, iterator_to_array($thread->subscribe('values'), false));
        self::assertSame('https://unit.test/prefix/threads/thread/stream/events', $seen[0][0]);
        self::assertSame('secret-test', $seen[0][1]['headers']['x-api-key']);
        self::assertSame('yes', $seen[0][1]['headers']['x-hook']);
        self::assertSame('text/event-stream', $seen[0][1]['headers']['accept']);
        self::assertNull($seen[0][1]['timeoutMs']);
    }

    public function testAbortedHookSignalStopsBeforeOpeningTheStreamingTransport(): void
    {
        $http = new RecordingTransport();
        $thread = (new ThreadsClient(['apiKey' => null, 'onRequest' => static function ($url, $init): array {
            $init['signal'] = static fn (): bool => true;

            return $init;
        }], $http))->stream('thread', ['assistantId' => 'agent', 'maxReconnectAttempts' => 0]);
        try {
            iterator_to_array($thread->subscribe('values'));
            self::fail('Expected abort.');
        } catch (\RuntimeException $error) {
            self::assertSame('AbortError', $error->getMessage());
        }
        self::assertSame([], $http->requests);
    }

    public function testFatalSharedReadReportsOnErrorAndStillThrowsTheOriginalTransportFailure(): void
    {
        $failure = new \RuntimeException('socket failed');
        $body = static function () use ($failure): \Generator {
            yield ThreadStreamTest::sse([ThreadStreamTest::event('values', 1)])->body;
            throw $failure;
        };
        $http = new StreamingTransport([static fn () => StreamingTransport::sse($body())]);
        $thread = (new ThreadsClient(['apiKey' => null], $http))->stream('thread', ['assistantId' => 'agent', 'maxReconnectAttempts' => 0]);
        $seen = [];
        $thread->onError(static function ($error) use (&$seen): void { $seen[] = $error; });
        $cursor = $thread->subscribe('values')->getIterator();
        $cursor->rewind();
        self::assertSame(1, $cursor->current()['params']['data']);
        self::assertSame([], $seen);
        try {
            $cursor->next();
            self::fail('Expected original transport error.');
        } catch (\RuntimeException $error) {
            self::assertSame($failure, $error);
        }
        self::assertSame([$failure], $seen);
        self::assertSame([$failure], $thread->errors());
        self::assertCount(1, $http->requests);
        $thread->close();
    }

    public function testUnexpectedContentTypeAndMalformedFramesFailTheSubscriber(): void
    {
        foreach ([new HttpResponse(200, ['content-type' => 'application/json'], '{}'),
            new HttpResponse(200, ['content-type' => 'text/event-stream'], "event: message\ndata: {broken\n\n")] as $response) {
            $thread = (new ThreadsClient(['apiKey' => null], new RecordingTransport([$response])))
                ->stream('thread', ['assistantId' => 'agent', 'maxReconnectAttempts' => 0]);
            try {
                iterator_to_array($thread->subscribe('values'));
                self::fail('Expected stream failure.');
            } catch (\RuntimeException|\JsonException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }
}
