<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk;

use LangChain\Runnables\RunnableConfig;
use LangChain\Tests\Unit\Sdk\Support\HandlerStreamingTransport;
use LangChain\Tests\Unit\Sdk\Support\StreamingTransport;
use LangGraph\Pregel\Constants;
use LangGraph\Sdk\Client;
use LangGraph\Sdk\Utils\StreamResponse;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A real compiled graph behind a wire-level fake of the LangGraph server's run-stream endpoint.
 *
 * The "server" runs `StateGraph::stream()` and writes each chunk it yields as an SSE event with an
 * increasing id, lazily, as the client pulls. So the numbers on the client side come out of the
 * engine, and the node execution log shows whether the SDK really consumed the stream incrementally:
 * the first `values` part is in the caller's hands before the second node has run.
 */
#[CoversNothing]
final class RunsClientEndToEndTest extends TestCase
{
    /** @var list<string> */
    private array $log = [];

    /**
     * @return array{0: Client, 1: HandlerStreamingTransport}
     */
    private function server(int $dropAfterEvents = 0): array
    {
        $builder = new StateGraph(['value' => 'int']);
        $builder->addNode('double', function (array $s): array {
            $this->log[] = 'node double';

            return ['value' => $s['value'] * 2];
        });
        $builder->addNode('increment', function (array $s): array {
            $this->log[] = 'node increment';

            return ['value' => $s['value'] + 1];
        });
        $builder->addEdge(Constants::START, 'double');
        $builder->addEdge('double', 'increment');
        $builder->addEdge('increment', Constants::END);
        $graph = $builder->compile();

        /**
         * One SSE body for a run, resumable: events before `$after` were already delivered.
         *
         * @return \Generator<int, string>
         */
        $events = function (int $input, int $after) use ($graph, $dropAfterEvents): \Generator {
            $id = 0;
            $sent = 0;
            foreach ($graph->stream(['value' => $input], new RunnableConfig()) as [$mode, $payload]) {
                if ($mode !== 'values') {
                    continue;
                }
                ++$id;
                if ($id <= $after) {
                    continue;
                }
                yield from StreamingTransport::chunksOf([['id' => (string) $id, 'event' => 'values', 'data' => $payload]]);
                ++$sent;
                if ($dropAfterEvents > 0 && $after === 0 && $sent === $dropAfterEvents) {
                    throw new \TypeError('terminated');
                }
            }
            yield from ["event: end\n", "data: null\n", "\n"];
        };

        $handler = function (string $method, string $url, array $headers, ?string $body) use ($events): StreamResponse {
            if ($method === 'POST' && str_ends_with($url, '/threads/t-1/runs/stream')) {
                $input = (int) (json_decode((string) $body, true)['input']['value'] ?? 0);

                return StreamingTransport::sse($events($input, 0), [
                    'content-location' => '/threads/t-1/runs/run-1',
                    'location' => '/threads/t-1/runs/run-1/stream?input=' . $input,
                ]);
            }
            if ($method === 'GET' && str_contains($url, '/threads/t-1/runs/run-1/stream')) {
                parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);

                return StreamingTransport::sse($events((int) $query['input'], (int) ($headers['last-event-id'] ?? 0)), [
                    'location' => '/threads/t-1/runs/run-1/stream?input=' . $query['input'],
                ]);
            }

            throw new \LogicException("no route for {$method} {$url}");
        };

        $fake = new HandlerStreamingTransport($handler);

        return [new Client(['apiUrl' => 'http://langgraph.test', 'apiKey' => null, 'callerOptions' => ['sleep' => static function (): void {
        }]], $fake), $fake];
    }

    public function testStreamingARealGraphRunDeliversEachSuperstepBeforeTheNextOneRuns(): void
    {
        [$client, $wire] = $this->server();
        $created = [];
        $atReceipt = [];

        $values = [];
        foreach ($client->runs->stream('t-1', 'calc', [
            'input' => ['value' => 5],
            'streamMode' => ['values'],
            'onRunCreated' => static function (array $meta) use (&$created): void {
                $created[] = $meta;
            },
        ]) as $part) {
            if ($part['event'] === 'values') {
                $values[] = $part['data']['value'];
                $atReceipt[] = $this->log;
            }
        }

        // 5 -> double -> 10 -> increment -> 11, straight out of the engine.
        $this->assertSame([5, 10, 11], $values);
        $this->assertSame([[], ['node double'], ['node double', 'node increment']], $atReceipt, 'each snapshot arrives before the following node has run');
        $this->assertSame([['run_id' => 'run-1', 'thread_id' => 't-1']], $created);
        $this->assertCount(1, $wire->requests);
    }

    public function testADroppedConnectionResumesFromTheLastEventIdWithoutReplay(): void
    {
        [$client, $wire] = $this->server(dropAfterEvents: 2);

        $parts = iterator_to_array($client->runs->stream('t-1', 'calc', ['input' => ['value' => 5]]), false);

        $values = [];
        foreach ($parts as $part) {
            if ($part['event'] === 'values') {
                $values[] = [$part['id'], $part['data']['value']];
            }
        }
        $this->assertSame([['1', 5], ['2', 10], ['3', 11]], $values, 'events 1 and 2 are not delivered twice');
        $this->assertSame('end', end($parts)['event']);
        $this->assertSame(['POST', 'GET'], array_column($wire->requests, 'method'));
        $this->assertSame('2', $wire->requests[1]['headers']['last-event-id']);
        $this->assertSame('http://langgraph.test/threads/t-1/runs/run-1/stream?input=5', $wire->requests[1]['url']);
    }
}
