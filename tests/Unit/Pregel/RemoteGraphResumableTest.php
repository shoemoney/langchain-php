<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangChain\Tests\Unit\Sdk\Support\StreamingTransport;
use LangGraph\Pregel\RemoteGraph;
use LangGraph\Sdk\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `tests/remote-graph-resumable.test.ts`.
 *
 * The transport is the SDK suite's {@see StreamingTransport}, whose bodies are generators, so a body
 * that fails half way is a real mid-stream failure. Upstream advances fake timers to release the
 * reconnect backoff; here `callerOptions.sleep` is a no-op.
 */
#[CoversClass(RemoteGraph::class)]
final class RemoteGraphResumableTest extends TestCase
{
    private const LOCATION = '/threads/test_thread/runs/run-1/stream';

    /** @var list<int> */
    private array $slept = [];

    /**
     * @return array{0: RemoteGraph, 1: StreamingTransport}
     */
    private function remote(StreamingTransport $transport, bool $streamResumable): array
    {
        $client = new Client([
            'apiUrl' => 'http://localhost:8000',
            'apiKey' => 'test-key',
            'callerOptions' => ['sleep' => function (int $ms): void {
                $this->slept[] = $ms;
            }],
        ], $transport);

        return [new RemoteGraph(graphId: 'test_graph', client: $client, streamResumable: $streamResumable), $transport];
    }

    private static function config(): RunnableConfig
    {
        return new RunnableConfig(configurable: ['thread_id' => 'test_thread'], options: ['streamMode' => ['values', 'metadata']]);
    }

    public function testWorksWithNormalStream(): void
    {
        $chunks = [
            ['id' => '1', 'event' => 'metadata', 'data' => ['run_id' => 'run-1', 'thread_id' => 'test_thread']],
            ['id' => '2', 'event' => 'values', 'data' => ['messages' => ['hello']]],
            ['id' => '3', 'event' => 'values', 'data' => ['messages' => ['hello', 'world']]],
        ];
        [$remote, $transport] = $this->remote(new StreamingTransport([
            static fn () => StreamingTransport::sse(StreamingTransport::chunksOf($chunks), ['location' => '/threads/test_thread/runs/stream']),
        ]), true);

        $results = iterator_to_array($remote->stream(['input' => 'test'], self::config()), false);

        $this->assertCount(3, $results);
        $body = json_decode((string) $transport->requests[0]['body'], true);
        $this->assertTrue($body['stream_resumable']);
    }

    public function testHandlesNetworkFailuresDuringStreamAndRetriesWithLocationHeader(): void
    {
        $partial = [
            ['id' => '1', 'event' => 'metadata', 'data' => ['run_id' => 'run-1', 'thread_id' => 'test_thread']],
            ['id' => '2', 'event' => 'values', 'data' => ['messages' => ['hello']]],
        ];
        [$remote, $transport] = $this->remote(new StreamingTransport([
            static fn () => StreamingTransport::sse(
                StreamingTransport::chunksThenError($partial, new \TypeError('Network connection lost')),
                ['location' => self::LOCATION],
            ),
            static fn () => StreamingTransport::sse(
                StreamingTransport::chunksOf([['id' => '3', 'event' => 'values', 'data' => ['messages' => ['hello', 'world']]]]),
                ['location' => self::LOCATION],
            ),
        ]), true);

        $results = iterator_to_array($remote->stream(['input' => 'test'], self::config()), false);

        $this->assertCount(2, $transport->requests);
        $this->assertCount(3, $results);
        $this->assertSame('GET', $transport->requests[1]['method']);
        $this->assertSame('http://localhost:8000' . self::LOCATION, $transport->requests[1]['url']);
        $this->assertSame('2', $transport->requests[1]['headers']['last-event-id'], 'resumes after the last event seen');
        $this->assertTrue(json_decode((string) $transport->requests[0]['body'], true)['stream_resumable']);
        $this->assertSame(['values', ['messages' => ['hello', 'world']]], $results[2]);
    }

    public function testFailsNormallyWhenStreamResumableIsFalse(): void
    {
        $chunks = [['event' => 'metadata', 'data' => ['run_id' => 'run-1', 'thread_id' => 'test_thread']]];
        [$remote, $transport] = $this->remote(new StreamingTransport([
            static fn () => StreamingTransport::sse(StreamingTransport::chunksThenError($chunks, new \TypeError('network error'))),
        ]), false);

        $stream = $remote->stream(['input' => 'test'], self::config());

        $this->assertSame(['metadata', ['run_id' => 'run-1', 'thread_id' => 'test_thread']], $stream->current());

        try {
            $stream->next();
            $this->fail('the stream should have failed');
        } catch (\TypeError $e) {
            $this->assertSame('network error', $e->getMessage());
        }
        $this->assertCount(1, $transport->requests);
        $this->assertFalse(json_decode((string) $transport->requests[0]['body'], true)['stream_resumable']);
    }
}
