<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk;

use LangChain\Runnables\RunnableConfig;
use LangChain\Tests\Unit\Sdk\Support\RecordingTransport;
use LangChain\Utils\Http\HttpResponse;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\StateSnapshot;
use LangGraph\Sdk\Client;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A real compiled graph behind a wire-level fake of the LangGraph server.
 *
 * Every other SDK test scripts the server's answers, so each can only confirm what the port itself
 * assumed. Here the server side is a genuine `StateGraph` with a checkpointer: the test "starts a
 * run" the way a server would (graph invoke on a thread), and the SDK clients read the thread, its
 * state and its history back over HTTP-shaped requests. The state numbers come from the engine, not
 * from a fixture, so a mismatch between what the clients ask for and what the server can answer
 * fails here.
 */
#[CoversNothing]
final class SdkEndToEndTest extends TestCase
{
    /**
     * @return array{0: Client, 1: RecordingTransport, 2: \LangGraph\Pregel\CompiledStateGraph}
     */
    private function server(): array
    {
        $builder = new StateGraph(['value' => 'int']);
        $builder->addNode('double', static fn (array $s): array => ['value' => $s['value'] * 2]);
        $builder->addNode('increment', static fn (array $s): array => ['value' => $s['value'] + 1]);
        $builder->addEdge(Constants::START, 'double');
        $builder->addEdge('double', 'increment');
        $builder->addEdge('increment', Constants::END);
        $graph = $builder->compile(['checkpointer' => true]);

        /** @var array<string, array<string, mixed>> $threads */
        $threads = [];

        $json = static fn (int $status, mixed $payload, array $headers = []): HttpResponse => new HttpResponse(
            $status,
            $headers + ['Content-Type' => 'application/json'],
            (string) json_encode($payload),
        );

        $checkpointOf = static fn (?array $config): ?array => $config === null ? null : [
            'thread_id' => $config['configurable']['thread_id'],
            'checkpoint_ns' => $config['configurable']['checkpoint_ns'] ?? '',
            'checkpoint_id' => $config['configurable']['checkpoint_id'] ?? null,
            'checkpoint_map' => null,
        ];

        // `getState` yields a StateSnapshot, `getStateHistory` the legacy array shape; the server
        // answers both in the one wire shape `schema.ts` calls ThreadState.
        $toThreadState = static fn (StateSnapshot|array $s): array => [
            'values' => $s instanceof StateSnapshot ? $s->values : $s['values'],
            'next' => $s instanceof StateSnapshot ? $s->next : $s['next'],
            'checkpoint' => $checkpointOf($s instanceof StateSnapshot ? $s->config : $s['config']),
            'metadata' => $s instanceof StateSnapshot ? $s->metadata : $s['metadata'],
            'created_at' => $s instanceof StateSnapshot ? $s->createdAt : null,
            'parent_checkpoint' => $s instanceof StateSnapshot ? $checkpointOf($s->parentConfig) : null,
            'tasks' => [],
        ];

        $handler = function (string $method, string $url, array $headers, ?string $body) use (&$threads, $graph, $json, $toThreadState): HttpResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $payload = $body === null ? [] : (array) json_decode($body, true);

            if ($method === 'POST' && $path === '/threads') {
                $id = $payload['thread_id'] ?? 'thread-' . (count($threads) + 1);
                $threads[$id] = ['thread_id' => $id, 'metadata' => $payload['metadata'] ?? [], 'status' => 'idle'];

                return $json(200, $threads[$id]);
            }
            if ($method === 'POST' && $path === '/threads/search') {
                return $json(200, array_values($threads), ['X-Pagination-Next' => '1']);
            }
            if ($method === 'GET' && preg_match('#^/threads/([^/]+)/state$#', $path, $m) === 1) {
                return $json(200, $toThreadState($graph->getState(['thread_id' => $m[1]])));
            }
            if ($method === 'POST' && preg_match('#^/threads/([^/]+)/history$#', $path, $m) === 1) {
                $snapshots = $graph->getStateHistory(['thread_id' => $m[1]]);

                return $json(200, array_map($toThreadState, array_slice($snapshots, 0, (int) ($payload['limit'] ?? 10))));
            }

            return $json(404, ['detail' => "no route for {$method} {$path}"]);
        };

        $transport = new RecordingTransport(handler: $handler);

        return [new Client(['apiUrl' => 'http://langgraph.test', 'apiKey' => 'key'], $transport), $transport, $graph];
    }

    public function testClientReadsThreadStateAndHistoryOfARealGraphRun(): void
    {
        [$client, $transport, $graph] = $this->server();

        $thread = $client->threads->create(['threadId' => 't-1', 'graphId' => 'calc']);
        $this->assertSame('t-1', $thread['thread_id']);
        $this->assertSame(['graph_id' => 'calc'], $thread['metadata']);

        // The "server" runs the graph on that thread: 5 -> double -> 10 -> increment -> 11.
        $result = $graph->invoke(['value' => 5], new RunnableConfig(configurable: ['thread_id' => $thread['thread_id']]));
        $this->assertSame(11, $result['value']);

        $state = $client->threads->getState('t-1');
        $this->assertSame(['value' => 11], $state['values']);
        $this->assertSame([], $state['next']);
        $this->assertSame('t-1', $state['checkpoint']['thread_id']);
        $this->assertNotNull($state['checkpoint']['checkpoint_id']);

        $history = $client->threads->getHistory('t-1', ['limit' => 20]);
        $this->assertGreaterThanOrEqual(3, count($history));
        $this->assertSame(['value' => 11], $history[0]['values'], 'newest checkpoint first');
        $this->assertSame($state['checkpoint']['checkpoint_id'], $history[0]['checkpoint']['checkpoint_id']);
        $this->assertSame('input', end($history)['metadata']['source'], 'the oldest checkpoint is the input write');

        $found = $client->threads->search(['metadata' => ['graph_id' => 'calc']]);
        $this->assertSame(['t-1'], array_column($found, 'thread_id'));

        // The wire carried the credentials and the verbs the clients are documented to use.
        $this->assertSame(['POST', 'GET', 'POST', 'POST'], array_column($transport->requests, 'method'));
        foreach ($transport->requests as $request) {
            $this->assertSame('key', $request['headers']['x-api-key']);
            $this->assertStringStartsWith('http://langgraph.test/threads', $request['url']);
        }
    }

    public function testASecondThreadHasItsOwnIndependentState(): void
    {
        [$client, , $graph] = $this->server();

        $client->threads->create(['threadId' => 'a']);
        $client->threads->create(['threadId' => 'b']);
        $graph->invoke(['value' => 1], new RunnableConfig(configurable: ['thread_id' => 'a']));
        $graph->invoke(['value' => 100], new RunnableConfig(configurable: ['thread_id' => 'b']));

        $this->assertSame(['value' => 3], $client->threads->getState('a')['values']);
        $this->assertSame(['value' => 201], $client->threads->getState('b')['values']);
    }

    public function testAnUnknownRouteSurfacesAsAnHttpErrorFromTheServer(): void
    {
        [$client] = $this->server();

        $this->expectException(\LangGraph\Sdk\Utils\HttpError::class);
        $this->expectExceptionMessageMatches('/HTTP 404.*no route for GET/');
        $client->assistants->get('nope');
    }
}
