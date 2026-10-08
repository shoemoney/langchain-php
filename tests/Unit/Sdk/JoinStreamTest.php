<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk;

use LangChain\Tests\Unit\Sdk\Support\StreamingTransport;
use LangGraph\Sdk\Client;
use LangGraph\Sdk\RunsClient;
use LangGraph\Sdk\ThreadsClient;
use LangGraph\Sdk\Utils\StreamResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `client/runs/stream.test.ts`, `describe("runs.joinStream()")` and
 * `describe("threads.joinStream()")`.
 */
#[CoversClass(RunsClient::class)]
#[CoversClass(ThreadsClient::class)]
final class JoinStreamTest extends TestCase
{
    /**
     * @param list<array{id?: string, event: string, data: mixed}> $parts
     */
    private static function ok(array $parts): callable
    {
        return static fn (): StreamResponse => StreamingTransport::sse(StreamingTransport::chunksOf($parts));
    }

    private function client(StreamingTransport $t): Client
    {
        return new Client(['apiUrl' => 'http://localhost:8000', 'apiKey' => 'test-key', 'callerOptions' => ['sleep' => static function (): void {
        }]], $t);
    }

    // ---- runs.joinStream()

    public function testJoinsStreamSuccessfullyWithGetMethod(): void
    {
        $t = new StreamingTransport([self::ok([
            ['id' => '1', 'event' => 'values', 'data' => ['state' => 'running']],
            ['id' => '2', 'event' => 'values', 'data' => ['state' => 'complete']],
        ])]);

        $results = iterator_to_array($this->client($t)->runs->joinStream('thread-1', 'run-1'), false);

        $this->assertCount(2, $results);
        $this->assertCount(1, $t->requests);
        $this->assertSame('GET', $t->requests[0]['method']);
        $this->assertStringContainsString('/threads/thread-1/runs/run-1/stream', $t->requests[0]['url']);
        $this->assertNull($t->requests[0]['body'], 'GET requests have no body');
    }

    public function testSendsLastEventIdHeaderWhenProvided(): void
    {
        $t = new StreamingTransport([self::ok([['id' => '5', 'event' => 'values', 'data' => ['resumed' => true]]])]);

        iterator_to_array($this->client($t)->runs->joinStream('thread-1', 'run-1', ['lastEventId' => '4']), false);

        $this->assertSame('4', $t->requests[0]['headers']['last-event-id']);
    }

    public function testRespectsCancelOnDisconnectParameter(): void
    {
        $t = new StreamingTransport([self::ok([['id' => '1', 'event' => 'values', 'data' => []]]), self::ok([])]);
        $client = $this->client($t);

        iterator_to_array($client->runs->joinStream('thread-1', 'run-1', ['cancelOnDisconnect' => true]), false);
        iterator_to_array($client->runs->joinStream('thread-1', 'run-1'), false);

        $this->assertStringContainsString('cancel_on_disconnect=1', $t->requests[0]['url']);
        $this->assertStringContainsString('cancel_on_disconnect=0', $t->requests[1]['url']);
    }

    public function testWithoutAThreadIdItJoinsTheStatelessRunStream(): void
    {
        $t = new StreamingTransport([self::ok([])]);

        iterator_to_array($this->client($t)->runs->joinStream(null, 'run-1', ['streamMode' => ['values', 'updates']]), false);

        $this->assertSame('http://localhost:8000/runs/run-1/stream?cancel_on_disconnect=0&stream_mode=values&stream_mode=updates', $t->requests[0]['url']);
    }

    public function testABareSignalIsAcceptedInPlaceOfTheOptions(): void
    {
        $t = new StreamingTransport([self::ok([['id' => '1', 'event' => 'values', 'data' => []]])]);

        $results = iterator_to_array($this->client($t)->runs->joinStream('thread-1', 'run-1', static fn (): bool => true), false);

        $this->assertSame([], $results);
        $this->assertSame([], $t->requests, 'aborted before the request was made');
    }

    // ---- threads.joinStream()

    public function testJoinsThreadStreamSuccessfullyWithGetMethod(): void
    {
        $t = new StreamingTransport([self::ok([
            ['id' => '1', 'event' => 'values', 'data' => ['messages' => ['msg1']]],
            ['id' => '2', 'event' => 'values', 'data' => ['messages' => ['msg1', 'msg2']]],
        ])]);

        $results = iterator_to_array($this->client($t)->threads->joinStream('thread-1'), false);

        $this->assertCount(2, $results);
        $this->assertCount(1, $t->requests);
        $this->assertSame('GET', $t->requests[0]['method']);
        $this->assertStringContainsString('/threads/thread-1/stream', $t->requests[0]['url']);
        $this->assertNull($t->requests[0]['body']);
    }

    public function testThreadJoinPassesStreamModeParameter(): void
    {
        $t = new StreamingTransport([self::ok([['id' => '1', 'event' => 'messages', 'data' => []]])]);

        iterator_to_array($this->client($t)->threads->joinStream('thread-1', ['streamMode' => ['lifecycle', 'state_update']]), false);

        $query = (string) parse_url($t->requests[0]['url'], \PHP_URL_QUERY);
        $this->assertSame('stream_mode=lifecycle&stream_mode=state_update', $query);
    }

    public function testThreadJoinResumesFromLastEventId(): void
    {
        $t = new StreamingTransport([self::ok([])]);

        iterator_to_array($this->client($t)->threads->joinStream('thread-1', ['lastEventId' => '9']), false);

        $this->assertSame('9', $t->requests[0]['headers']['last-event-id']);
        $this->assertSame('http://localhost:8000/threads/thread-1/stream', $t->requests[0]['url'], 'no streamMode, no query');
    }
}
