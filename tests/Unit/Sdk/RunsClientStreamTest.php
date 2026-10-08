<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk;

use LangChain\Tests\Unit\Sdk\Support\RecordingTransport;
use LangChain\Tests\Unit\Sdk\Support\StreamingTransport;
use LangChain\Utils\Http\HttpResponse;
use LangGraph\Sdk\Client;
use LangGraph\Sdk\RunsClient;
use LangGraph\Sdk\Utils\HttpError;
use LangGraph\Sdk\Utils\MaxReconnectAttemptsError;
use LangGraph\Sdk\Utils\StreamIdleTimeoutError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `client/runs/stream.test.ts`, `describe("runs.stream()")`, `abort signal support` and
 * `error handling` (the `joinStream` blocks are in {@see JoinStreamTest}).
 *
 * Most tests run over {@see StreamingTransport}, whose bodies are generators, so the
 * request/response/parse path is exercised the way the real transport drives it. The last group
 * runs the same client over the buffered {@see RecordingTransport} (a transport that cannot stream),
 * and one test pins incremental delivery with a body that records when each chunk is produced.
 *
 * `vi.useFakeTimers()` becomes `callerOptions.sleep` / `callerOptions.clock`; nothing here waits.
 */
#[CoversClass(RunsClient::class)]
final class RunsClientStreamTest extends TestCase
{
    private const LOCATION = '/threads/thread-1/runs/run-1/stream';

    /** @var list<int> */
    private array $slept = [];

    /** @var array<string, mixed> */
    private array $extraCallerOptions = [];

    protected function setUp(): void
    {
        $this->slept = [];
        $this->extraCallerOptions = [];
    }

    private function client(\LangGraph\Sdk\Utils\MethodHttpClient $transport): Client
    {
        return new Client([
            'apiUrl' => 'http://localhost:8000',
            'apiKey' => 'test-key',
            'callerOptions' => [
                'sleep' => function (int $ms): void {
                    $this->slept[] = $ms;
                },
            ] + $this->extraCallerOptions,
        ], $transport);
    }

    /**
     * @param list<array{id?: string, event: string, data: mixed}> $parts
     * @param array<string, string>                                $headers
     */
    private static function ok(array $parts, array $headers = []): callable
    {
        return static fn (): \LangGraph\Sdk\Utils\StreamResponse => StreamingTransport::sse(StreamingTransport::chunksOf($parts), $headers);
    }

    /**
     * @param \Generator<int, array{id: string|null, event: string, data: mixed}> $stream
     *
     * @return list<array{id: string|null, event: string, data: mixed}>
     */
    private static function gather(\Generator $stream): array
    {
        return iterator_to_array($stream, false);
    }

    // ---- runs.stream()

    public function testStreamsSuccessfullyWithoutRetries(): void
    {
        $t = new StreamingTransport([self::ok([
            ['id' => '1', 'event' => 'values', 'data' => ['messages' => ['hello']]],
            ['id' => '2', 'event' => 'values', 'data' => ['messages' => ['hello', 'world']]],
        ], ['content-location' => '/threads/thread-1/runs/run-1'])]);

        $results = self::gather($this->client($t)->runs->stream('thread-1', 'assistant-1', [
            'input' => ['message' => 'test'],
            'streamMode' => ['values'],
        ]));

        $this->assertCount(2, $results);
        $this->assertSame(['id' => '1', 'event' => 'values', 'data' => ['messages' => ['hello']]], $results[0]);
        $this->assertSame('2', $results[1]['id']);
        $this->assertCount(1, $t->requests);
        $this->assertSame('POST', $t->requests[0]['method']);
        $this->assertSame('http://localhost:8000/threads/thread-1/runs/stream', $t->requests[0]['url']);
    }

    public function testUsesPostWithJsonBodyForInitialStream(): void
    {
        $t = new StreamingTransport([self::ok([['id' => '1', 'event' => 'values', 'data' => []]])]);

        self::gather($this->client($t)->runs->stream('thread-1', 'assistant-1', [
            'input' => ['message' => 'test input'],
            'streamMode' => ['values', 'updates'],
        ]));

        $this->assertSame('POST', $t->requests[0]['method']);
        $body = (array) json_decode((string) $t->requests[0]['body'], true);
        $this->assertSame(['message' => 'test input'], $body['input']);
        $this->assertSame(['values', 'updates'], $body['stream_mode']);
        $this->assertSame('assistant-1', $body['assistant_id']);
    }

    public function testSendsCheckpointIdAsCheckpointIdInTheRequestBody(): void
    {
        $t = new StreamingTransport([self::ok([['id' => '1', 'event' => 'values', 'data' => []]])]);

        self::gather($this->client($t)->runs->stream('thread-1', 'assistant-1', [
            'input' => ['message' => 'test input'],
            'checkpointId' => '1f0aaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ]));

        $this->assertSame('1f0aaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', json_decode((string) $t->requests[0]['body'], true)['checkpoint_id']);
    }

    public function testPassesStreamModeIncludingToolsInRequestBody(): void
    {
        $t = new StreamingTransport([self::ok([['id' => '1', 'event' => 'values', 'data' => []]])]);

        self::gather($this->client($t)->runs->stream('thread-1', 'assistant-1', [
            'input' => ['message' => 'test'],
            'streamMode' => ['values', 'tools'],
        ]));

        $body = (array) json_decode((string) $t->requests[0]['body'], true);
        $this->assertContains('tools', $body['stream_mode']);
        $this->assertContains('values', $body['stream_mode']);
    }

    public function testCallsOnRunCreatedFromTheContentLocationHeader(): void
    {
        $t = new StreamingTransport([self::ok([['id' => '1', 'event' => 'values', 'data' => []]], ['content-location' => '/threads/thread-1/runs/run-123'])]);
        $created = [];

        self::gather($this->client($t)->runs->stream('thread-1', 'assistant-1', [
            'input' => ['message' => 'test'],
            'onRunCreated' => static function (array $meta) use (&$created): void {
                $created[] = $meta;
            },
        ]));

        $this->assertSame([['run_id' => 'run-123', 'thread_id' => 'thread-1']], $created);
    }

    public function testIncludesRequiredHeadersAndUsesTheCorrectMethod(): void
    {
        $t = new StreamingTransport([self::ok([['id' => '1', 'event' => 'values', 'data' => []]])]);

        self::gather($this->client($t)->runs->stream('thread-1', 'assistant-1', [
            'input' => ['message' => 'test'],
            'streamMode' => ['values'],
        ]));

        $request = $t->requests[0];
        $this->assertSame('POST', $request['method']);
        $this->assertSame('application/json', $request['headers']['content-type']);
        $this->assertSame('test-key', $request['headers']['x-api-key']);
        $this->assertEquals(
            ['input' => ['message' => 'test'], 'stream_mode' => ['values'], 'assistant_id' => 'assistant-1'],
            json_decode((string) $request['body'], true),
        );
    }

    public function testAStatelessStreamPostsToRunsStream(): void
    {
        $t = new StreamingTransport([self::ok([])]);

        self::gather($this->client($t)->runs->stream(null, 'assistant-1'));

        $this->assertSame('http://localhost:8000/runs/stream', $t->requests[0]['url']);
        $this->assertSame('{"assistant_id":"assistant-1"}', $t->requests[0]['body']);
    }

    public function testRetriesWhenStreamErrorsBeforeFirstEventIfReconnectPathExists(): void
    {
        $t = new StreamingTransport([
            static fn (): \LangGraph\Sdk\Utils\StreamResponse => StreamingTransport::sse(
                StreamingTransport::chunksThenError([], new \TypeError('terminated')),
                ['location' => self::LOCATION],
            ),
            self::ok([['id' => '1', 'event' => 'values', 'data' => ['ok' => true]]], ['location' => self::LOCATION]),
        ]);

        $results = self::gather($this->client($t)->runs->stream('thread-1', 'assistant-1', [
            'input' => ['message' => 'test'],
            'streamMode' => ['values'],
            'streamResumable' => true,
        ]));

        $this->assertCount(2, $t->requests);
        $this->assertCount(1, $results);
        $this->assertSame('GET', $t->requests[1]['method']);
        $this->assertSame('http://localhost:8000' . self::LOCATION, $t->requests[1]['url']);
        $this->assertNull($t->requests[1]['body'], 'a reconnect carries no body');
        $this->assertArrayNotHasKey('last-event-id', $t->requests[1]['headers'], 'nothing was seen to resume from');
        $this->assertCount(1, $this->slept);
    }

    public function testRetriesWhenReconnectRequestFailsWithTerminatedErrorAndResumesFromTheLastEventId(): void
    {
        $t = new StreamingTransport([
            static fn (): \LangGraph\Sdk\Utils\StreamResponse => StreamingTransport::sse(
                StreamingTransport::chunksThenError([['id' => '1', 'event' => 'values', 'data' => ['step' => 1]]], new \TypeError('terminated')),
                ['location' => self::LOCATION],
            ),
            static fn () => new \TypeError('terminated'),
            self::ok([['id' => '2', 'event' => 'values', 'data' => ['step' => 2]]], ['location' => self::LOCATION]),
        ]);

        $results = self::gather($this->client($t)->runs->stream('thread-1', 'assistant-1', [
            'input' => ['message' => 'test'],
            'streamMode' => ['values'],
            'streamResumable' => true,
        ]));

        $this->assertCount(3, $t->requests);
        $this->assertSame(['1', '2'], array_column($results, 'id'), 'event 1 is delivered once, not replayed');
        $this->assertSame('1', $t->requests[1]['headers']['last-event-id']);
        $this->assertSame('1', $t->requests[2]['headers']['last-event-id'], 'the failed reconnect keeps the resume point');
    }

    public function testGivesUpWithMaxReconnectAttemptsErrorWhenTheStreamKeepsDying(): void
    {
        $dying = static fn (): \LangGraph\Sdk\Utils\StreamResponse => StreamingTransport::sse(
            StreamingTransport::chunksThenError([], new \TypeError('terminated')),
            ['location' => self::LOCATION],
        );
        $t = new StreamingTransport([$dying, $dying, $dying, $dying, $dying, $dying]);

        $this->expectException(MaxReconnectAttemptsError::class);
        $this->expectExceptionMessage('Exceeded maximum SSE reconnection attempts (5)');

        try {
            self::gather($this->client($t)->runs->stream('thread-1', 'assistant-1'));
        } finally {
            $this->assertCount(6, $t->requests);
            $this->assertCount(5, $this->slept);
        }
    }

    public function testAMidStreamErrorWithNoReconnectPathPropagates(): void
    {
        $t = new StreamingTransport([
            static fn (): \LangGraph\Sdk\Utils\StreamResponse => StreamingTransport::sse(
                StreamingTransport::chunksThenError([['id' => '1', 'event' => 'values', 'data' => []]], new \TypeError('terminated')),
            ),
        ]);

        $this->expectException(\TypeError::class);

        self::gather($this->client($t)->runs->stream('thread-1', 'assistant-1'));
    }

    // ---- idle reconnect (utils/stream.ts: wired in by streamWithRetry)

    public function testAFixedIdleWindowTurnsASilentSocketIntoAReconnectFromTheLastEventId(): void
    {
        $now = 0;
        $this->extraCallerOptions['clock'] = static function () use (&$now): int {
            return $now;
        };
        $silent = function () use (&$now): \Generator {
            yield from StreamingTransport::chunksOf([['id' => '1', 'event' => 'values', 'data' => ['n' => 1]]]);
            // The socket goes quiet: the transport polls, hears nothing, and the clock moves on.
            $now += 4_000;
            yield null;
            $now += 4_000;
            yield null;
        };
        $t = new StreamingTransport([
            static fn (): \LangGraph\Sdk\Utils\StreamResponse => StreamingTransport::sse($silent(), ['location' => self::LOCATION]),
            self::ok([['id' => '2', 'event' => 'values', 'data' => ['n' => 2]]], ['location' => self::LOCATION]),
        ]);

        $results = self::gather($this->client($t)->runs->stream('thread-1', 'assistant-1', ['streamIdleReconnect' => 5_000]));

        $this->assertSame(['1', '2'], array_column($results, 'id'));
        $this->assertCount(2, $t->requests);
        $this->assertSame('1', $t->requests[1]['headers']['last-event-id']);
    }

    public function testIdleReconnectZeroDisablesTheWatchdog(): void
    {
        $now = 0;
        $this->extraCallerOptions['clock'] = static function () use (&$now): int {
            return $now;
        };
        $quiet = function () use (&$now): \Generator {
            $now += 1_000_000;
            yield null;
            yield from StreamingTransport::chunksOf([['id' => '1', 'event' => 'values', 'data' => []]]);
        };
        $t = new StreamingTransport([static fn (): \LangGraph\Sdk\Utils\StreamResponse => StreamingTransport::sse($quiet(), ['location' => self::LOCATION])]);

        $results = self::gather($this->client($t)->runs->stream('thread-1', 'assistant-1', ['streamIdleReconnect' => 0]));

        $this->assertCount(1, $results);
        $this->assertCount(1, $t->requests);
    }

    public function testAnIdleTimeoutWithNowhereToReconnectSurfacesTheIdleError(): void
    {
        $now = 0;
        $this->extraCallerOptions['clock'] = static function () use (&$now): int {
            return $now;
        };
        $silent = function () use (&$now): \Generator {
            $now += 9_000;
            yield null;
        };
        $t = new StreamingTransport([static fn (): \LangGraph\Sdk\Utils\StreamResponse => StreamingTransport::sse($silent())]);

        $this->expectException(StreamIdleTimeoutError::class);

        self::gather($this->client($t)->runs->stream('thread-1', 'assistant-1', ['streamIdleReconnect' => 5_000]));
    }

    // ---- incremental delivery

    public function testPartsReachTheCallerBeforeTheTransportHasProducedTheRest(): void
    {
        $log = [];
        $body = static function () use (&$log): \Generator {
            foreach ([['1', 'one'], ['2', 'two'], ['3', 'three']] as [$id, $word]) {
                $log[] = "produce {$id}";
                yield from StreamingTransport::chunksOf([['id' => $id, 'event' => 'values', 'data' => $word]]);
            }
            $log[] = 'produce end';
        };
        $t = new StreamingTransport([static fn (): \LangGraph\Sdk\Utils\StreamResponse => StreamingTransport::sse($body())]);

        foreach ($this->client($t)->runs->stream('thread-1', 'assistant-1') as $part) {
            $log[] = "consume {$part['id']}";
        }

        $this->assertSame(
            ['produce 1', 'consume 1', 'produce 2', 'consume 2', 'produce 3', 'consume 3', 'produce end'],
            $log,
            'each part is handed over as soon as its dispatching blank line arrives',
        );
    }

    public function testNothingHitsTheWireUntilTheGeneratorIsIterated(): void
    {
        $t = new StreamingTransport([self::ok([])]);

        $stream = $this->client($t)->runs->stream('thread-1', 'assistant-1');

        $this->assertSame([], $t->requests);
        self::gather($stream);
        $this->assertCount(1, $t->requests);
    }

    // ---- abort signal support

    public function testStopsStreamingWhenSignalIsAborted(): void
    {
        $t = new StreamingTransport([self::ok([
            ['id' => '1', 'event' => 'values', 'data' => ['step' => 1]],
            ['id' => '2', 'event' => 'values', 'data' => ['step' => 2]],
            ['id' => '3', 'event' => 'values', 'data' => ['step' => 3]],
        ])]);
        $aborted = false;

        $results = [];
        foreach ($this->client($t)->runs->stream('thread-1', 'assistant-1', [
            'input' => ['message' => 'test'],
            'signal' => static function () use (&$aborted): bool {
                return $aborted;
            },
        ]) as $part) {
            $results[] = $part;
            if ($part['id'] === '1') {
                $aborted = true;
            }
        }

        $this->assertLessThan(3, count($results));
    }

    // ---- error handling

    public function testPropagatesHttpErrorsImmediately(): void
    {
        $t = new StreamingTransport([
            static fn (): \LangGraph\Sdk\Utils\StreamResponse => new \LangGraph\Sdk\Utils\StreamResponse(
                new HttpResponse(401, ['content-type' => 'application/json']),
                ['{"error":"Unauthorized"}'],
            ),
        ]);

        try {
            self::gather($this->client($t)->runs->stream('thread-1', 'assistant-1', ['input' => ['message' => 'test']]));
            $this->fail('expected an HttpError');
        } catch (HttpError $e) {
            $this->assertSame(401, $e->status);
            $this->assertSame('{"error":"Unauthorized"}', $e->text);
        }
        $this->assertCount(1, $t->requests, 'a 401 is the caller\'s fault and is not retried');
    }

    public function testRetriesAServerErrorOnOpeningTheStream(): void
    {
        $t = new StreamingTransport([
            static fn (): \LangGraph\Sdk\Utils\StreamResponse => new \LangGraph\Sdk\Utils\StreamResponse(new HttpResponse(503), ['busy']),
            self::ok([['id' => '1', 'event' => 'values', 'data' => []]]),
        ]);

        $results = self::gather($this->client($t)->runs->stream('thread-1', 'assistant-1'));

        $this->assertCount(1, $results);
        $this->assertCount(2, $t->requests);
        $this->assertCount(1, $this->slept, 'AsyncCaller backed off once');
    }

    public function testValidatesContentTypeHeader(): void
    {
        $t = new StreamingTransport([
            static fn (): \LangGraph\Sdk\Utils\StreamResponse => new \LangGraph\Sdk\Utils\StreamResponse(
                new HttpResponse(200, ['content-type' => 'application/json']),
                ['{}'],
            ),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("Expected response header Content-Type to contain 'text/event-stream'");

        self::gather($this->client($t)->runs->stream('thread-1', 'assistant-1', ['input' => ['message' => 'test']]));
    }

    public function testANoContentResponseHasNoStreamBody(): void
    {
        $t = new StreamingTransport([static fn (): \LangGraph\Sdk\Utils\StreamResponse => new \LangGraph\Sdk\Utils\StreamResponse(new HttpResponse(204), [])]);

        $this->expectExceptionMessage('Expected response body from stream endpoint');

        self::gather($this->client($t)->runs->stream('thread-1', 'assistant-1'));
    }

    // ---- a transport that cannot stream (buffered fallback)

    public function testABufferedTransportStillParsesTheWholeBodyAsOneChunk(): void
    {
        $sse = implode('', StreamingTransport::chunksOf([
            ['id' => '1', 'event' => 'values', 'data' => ['a' => 1]],
            ['id' => '2', 'event' => 'end', 'data' => null],
        ]));
        $t = new RecordingTransport([new HttpResponse(200, ['content-type' => 'text/event-stream', 'content-location' => '/threads/thread-1/runs/run-9'], $sse)]);
        $created = [];

        $results = self::gather($this->client($t)->runs->stream('thread-1', 'assistant-1', [
            'onRunCreated' => static function (array $meta) use (&$created): void {
                $created[] = $meta;
            },
        ]));

        $this->assertSame([['id' => '1', 'event' => 'values', 'data' => ['a' => 1]], ['id' => '2', 'event' => 'end', 'data' => null]], $results);
        $this->assertSame([['run_id' => 'run-9', 'thread_id' => 'thread-1']], $created);
        $this->assertSame('POST', $t->requests[0]['method']);
    }

    public function testABufferedTransportReconnectsFromTheLastEventIdToo(): void
    {
        $sse = implode('', StreamingTransport::chunksOf([['id' => '1', 'event' => 'values', 'data' => 1]])) . "id: 2\nevent: values\ndata: {broken\n\n";
        $t = new RecordingTransport([
            new HttpResponse(200, ['content-type' => 'text/event-stream', 'location' => self::LOCATION], $sse),
            new HttpResponse(200, ['content-type' => 'text/event-stream', 'location' => self::LOCATION], implode('', StreamingTransport::chunksOf([['id' => '3', 'event' => 'values', 'data' => 3]]))),
        ]);

        $results = self::gather($this->client($t)->runs->stream('thread-1', 'assistant-1'));

        $this->assertSame(['1', '3'], array_column($results, 'id'));
        $this->assertSame('GET', $t->requests[1]['method']);
        $this->assertSame('1', $t->requests[1]['headers']['last-event-id']);
    }
}
