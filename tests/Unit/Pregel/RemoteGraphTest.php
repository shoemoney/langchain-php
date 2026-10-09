<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangChain\Tests\Unit\Sdk\Support\RecordingTransport;
use LangChain\Tests\Unit\Sdk\Support\StreamingTransport;
use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Testing\FakeHttpClient;
use LangGraph\Errors\GraphInterrupt;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\PregelTaskDescription;
use LangGraph\Pregel\RemoteGraph;
use LangGraph\Pregel\RemoteRunStream;
use LangGraph\Pregel\Send;
use LangGraph\Pregel\StateSnapshot;
use LangGraph\Sdk\Client;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `tests/remote.test.ts`, run against a wire-level fake of the LangGraph server.
 *
 * Upstream spies on `client.runs.stream` and friends; the SDK clients here are readonly properties of
 * the Client, so the transport underneath them is faked instead and the request bodies are asserted on
 * the wire. That checks the same arguments plus the SDK's own serialisation.
 *
 * Differences from upstream, all forced by the language: no `signal` object (a `callable(): bool`),
 * no BigInt (the circular-reference test uses an object cycle), per-call options live in
 * `RunnableConfig::$options`, and `getGraphAsync`/`getSubgraphsAsync` are `getGraph`/`getSubgraphs`.
 * The v3 tests drive `runs->create` + `runs->joinStream` rather than a `ThreadStream`.
 */
#[CoversClass(RemoteGraph::class)]
#[CoversClass(RemoteRunStream::class)]
final class RemoteGraphTest extends TestCase
{
    private const BASE = 'http://localhost:8000';

    /**
     * @param list<array{id?: string, event: string, data: mixed}> $parts
     */
    private static function sse(array $parts): HttpResponse
    {
        return new HttpResponse(200, ['content-type' => 'text/event-stream'], implode('', StreamingTransport::chunksOf($parts)));
    }

    /**
     * @param \Closure(string, string, array<string, string>, ?string): HttpResponse $handler
     *
     * @return array{0: Client, 1: RecordingTransport}
     */
    private static function client(\Closure $handler): array
    {
        $transport = new RecordingTransport([], $handler);

        return [new Client(['apiUrl' => self::BASE, 'apiKey' => null, 'callerOptions' => ['sleep' => static function (): void {
        }]], $transport), $transport];
    }

    /**
     * @param list<array{event: string, data: mixed}> $parts
     *
     * @return array{0: RemoteGraph, 1: RecordingTransport}
     */
    private static function streaming(array $parts, ?bool $streamResumable = null): array
    {
        [$client, $transport] = self::client(static fn (): HttpResponse => self::sse($parts));

        return [new RemoteGraph(graphId: 'test_graph_id', client: $client, streamResumable: $streamResumable), $transport];
    }

    /**
     * @return array{0: list<mixed>, 1: \Throwable|null}
     */
    private static function collect(\Generator $stream): array
    {
        $parts = [];
        $error = null;
        try {
            foreach ($stream as $chunk) {
                $parts[] = $chunk;
            }
        } catch (\Throwable $e) {
            $error = $e;
        }

        return [$parts, $error];
    }

    private static function threadConfig(array $options = []): RunnableConfig
    {
        return new RunnableConfig(configurable: ['thread_id' => 'thread_1'], options: $options);
    }

    public function testWithConfig(): void
    {
        $remote = new RemoteGraph(
            graphId: 'test_graph_id',
            config: ['configurable' => ['foo' => 'bar', 'threadId' => 'thread_id_1']],
            client: new Client(['apiKey' => null]),
        );

        $copy = $remote->withConfig(['configurable' => ['hello' => 'world']]);

        $this->assertNotSame($remote, $copy);
        $this->assertInstanceOf(RemoteGraph::class, $copy);
        $this->assertSame(
            ['configurable' => ['foo' => 'bar', 'threadId' => 'thread_id_1', 'hello' => 'world']],
            $copy->config,
        );
        $this->assertSame('thread_id_1', $remote->config['configurable']['threadId'], 'the original is untouched');
        $this->assertSame('test_graph_id', $copy->graphId);
    }

    public function testGetGraph(): void
    {
        [$client, $transport] = self::client(static fn (): HttpResponse => FakeHttpClient::json(200, [
            'nodes' => [
                ['id' => '__start__', 'type' => 'schema', 'data' => '__start__'],
                ['id' => '__end__', 'type' => 'schema', 'data' => '__end__'],
                ['id' => 'agent', 'type' => 'runnable', 'data' => ['id' => ['langgraph', 'utils', 'RunnableCallable'], 'name' => 'agent']],
            ],
            'edges' => [
                ['source' => '__start__', 'target' => 'agent'],
                ['source' => 'agent', 'target' => '__end__'],
            ],
        ]));
        $remote = new RemoteGraph(graphId: 'test_graph_id', client: $client);

        $graph = $remote->getGraph(xray: true);

        $this->assertInstanceOf(\LangChain\Runnables\Graph\Graph::class, $graph);
        $this->assertSame(['__start__', '__end__', 'agent'], array_keys($graph->nodes));
        $this->assertSame('__start__', $graph->nodes['__start__']->name);
        $this->assertSame('agent', $graph->nodes['agent']->name);
        $this->assertSame(['id' => ['langgraph', 'utils', 'RunnableCallable'], 'name' => 'agent'], $graph->nodes['agent']->data->schema);
        $this->assertSame([], $graph->nodes['agent']->metadata);
        $this->assertSame(
            [['__start__', 'agent'], ['agent', '__end__']],
            array_map(static fn ($e): array => [$e->source, $e->target], $graph->edges),
        );
        $this->assertStringContainsString('/assistants/test_graph_id/graph', $transport->requests[0]['url']);
        $this->assertStringContainsString('xray', $transport->requests[0]['url']);
    }

    public function testGetSubgraphs(): void
    {
        $schema = static fn (string $id): array => ['graph_id' => $id, 'input_schema' => [], 'output_schema' => [], 'state_schema' => [], 'config_schema' => []];
        [$client] = self::client(static fn (): HttpResponse => FakeHttpClient::json(200, [
            'namespace_1' => $schema('test_graph_id_2'),
            'namespace_2' => $schema('test_graph_id_3'),
        ]));
        $remote = new RemoteGraph(graphId: 'test_graph_id', client: $client);

        $subgraphs = iterator_to_array($remote->getSubgraphs(), false);

        $this->assertCount(2, $subgraphs);
        $this->assertSame('namespace_1', $subgraphs[0][0]);
        $this->assertInstanceOf(RemoteGraph::class, $subgraphs[0][1]);
        $this->assertSame('test_graph_id_2', $subgraphs[0][1]->graphId);
        $this->assertSame('namespace_2', $subgraphs[1][0]);
        $this->assertSame('test_graph_id_3', $subgraphs[1][1]->graphId);
        $this->assertSame('test_graph_id', $remote->graphId);
    }

    public function testGetState(): void
    {
        [$client, $transport] = self::client(static fn (): HttpResponse => FakeHttpClient::json(200, [
            'values' => ['messages' => [['type' => 'human', 'content' => 'hello']]],
            'next' => null,
            'checkpoint' => ['thread_id' => 'thread_1', 'checkpoint_ns' => 'ns', 'checkpoint_id' => 'checkpoint_1', 'checkpoint_map' => []],
            'metadata' => [],
            'created_at' => 'timestamp',
            'parent_checkpoint' => null,
            'tasks' => [],
        ]));
        $remote = new RemoteGraph(graphId: 'test_graph_id', client: $client);

        $snapshot = $remote->getState(['configurable' => ['thread_id' => 'thread1']]);

        $this->assertInstanceOf(StateSnapshot::class, $snapshot);
        $this->assertSame(['messages' => [['type' => 'human', 'content' => 'hello']]], $snapshot->values);
        $this->assertSame([], $snapshot->next);
        $this->assertSame(
            ['configurable' => ['thread_id' => 'thread_1', 'checkpoint_ns' => 'ns', 'checkpoint_id' => 'checkpoint_1', 'checkpoint_map' => []]],
            $snapshot->config,
        );
        $this->assertSame([], $snapshot->metadata);
        $this->assertSame('timestamp', $snapshot->createdAt);
        $this->assertNull($snapshot->parentConfig);
        $this->assertSame([], $snapshot->tasks);
        $this->assertStringContainsString('/threads/thread1/state/checkpoint', $transport->requests[0]['url']);
    }

    public function testGetStateHandlesNullCheckpoint(): void
    {
        [$client] = self::client(static fn (): HttpResponse => FakeHttpClient::json(200, [
            'values' => [], 'next' => [], 'checkpoint' => null, 'metadata' => [], 'created_at' => null, 'parent_checkpoint' => null, 'tasks' => [],
        ]));
        $remote = new RemoteGraph(graphId: 'test_graph_id', client: $client);

        $snapshot = $remote->getState(new RunnableConfig(configurable: ['thread_id' => 'thread1']));

        $this->assertSame([], $snapshot->values);
        $this->assertSame(['configurable' => ['thread_id' => 'thread1']], $snapshot->config, 'falls back to the config that was passed in');
        $this->assertNull($snapshot->createdAt);
        $this->assertNull($snapshot->parentConfig);
    }

    public function testGetStateMapsTasksAndParentCheckpoint(): void
    {
        [$client] = self::client(static fn (): HttpResponse => FakeHttpClient::json(200, [
            'values' => ['n' => 1],
            'next' => ['agent'],
            'checkpoint' => ['thread_id' => 't', 'checkpoint_ns' => '', 'checkpoint_id' => 'c2', 'checkpoint_map' => null],
            'metadata' => ['step' => 1],
            'created_at' => 'ts',
            'parent_checkpoint' => ['thread_id' => 't', 'checkpoint_ns' => '', 'checkpoint_id' => 'c1'],
            'tasks' => [['id' => 'task-1', 'name' => 'agent', 'interrupts' => [['id' => 'i1', 'value' => 'approve?']]]],
        ]));
        $remote = new RemoteGraph(graphId: 'g', client: $client);

        $snapshot = $remote->getState(['configurable' => ['thread_id' => 't']]);

        $this->assertSame(['agent'], $snapshot->next);
        $this->assertSame(['configurable' => ['thread_id' => 't', 'checkpoint_ns' => '', 'checkpoint_id' => 'c1', 'checkpoint_map' => []]], $snapshot->parentConfig);
        $this->assertCount(1, $snapshot->tasks);
        $this->assertInstanceOf(PregelTaskDescription::class, $snapshot->tasks[0]);
        $this->assertSame('task-1', $snapshot->tasks[0]->id);
        $this->assertSame([['id' => 'i1', 'value' => 'approve?']], $snapshot->tasks[0]->interrupts);
    }

    public function testGetStateRequiresAThreadId(): void
    {
        $remote = new RemoteGraph(graphId: 'g', client: new Client(['apiKey' => null]));

        $this->expectException(\InvalidArgumentException::class);
        $remote->getState(new RunnableConfig());
    }

    public function testGetStateHistory(): void
    {
        [$client, $transport] = self::client(static fn (): HttpResponse => FakeHttpClient::json(200, [[
            'values' => ['messages' => [['type' => 'human', 'content' => 'hello']]],
            'next' => null,
            'checkpoint' => ['thread_id' => 'thread_1', 'checkpoint_ns' => 'ns', 'checkpoint_id' => 'checkpoint_1', 'checkpoint_map' => []],
            'metadata' => [],
            'created_at' => 'timestamp',
            'parent_checkpoint' => null,
            'tasks' => [],
        ]]));
        $remote = new RemoteGraph(graphId: 'test_graph_id', client: $client);

        $history = $remote->getStateHistory(['configurable' => ['thread_id' => 'thread1']]);
        $this->assertSame([], $transport->requests, 'nothing is requested until iteration starts');
        $snapshots = iterator_to_array($history, false);

        $this->assertCount(1, $snapshots);
        $this->assertSame(['messages' => [['type' => 'human', 'content' => 'hello']]], $snapshots[0]->values);
        $this->assertSame([], $snapshots[0]->next);
        $this->assertSame('checkpoint_1', $snapshots[0]->config['configurable']['checkpoint_id']);
        $this->assertSame('timestamp', $snapshots[0]->createdAt);
        $this->assertSame(['limit' => 10, 'checkpoint' => ['thread_id' => 'thread1']], $transport->bodyOf());
    }

    public function testGetStateHistoryForwardsLimitBeforeAndFilter(): void
    {
        [$client, $transport] = self::client(static fn (): HttpResponse => FakeHttpClient::json(200, []));
        $remote = new RemoteGraph(graphId: 'g', client: $client);

        iterator_to_array($remote->getStateHistory(['configurable' => ['thread_id' => 't']], [
            'limit' => 3,
            'before' => new RunnableConfig(configurable: ['thread_id' => 't', 'checkpoint_id' => 'c9']),
            'filter' => ['source' => 'loop'],
        ]));

        $this->assertSame([
            'limit' => 3,
            'before' => ['thread_id' => 't', 'checkpoint_id' => 'c9'],
            'metadata' => ['source' => 'loop'],
            'checkpoint' => ['thread_id' => 't'],
        ], $transport->bodyOf());
    }

    public function testGetStateHistoryHandlesNullCheckpoint(): void
    {
        [$client] = self::client(static fn (): HttpResponse => FakeHttpClient::json(200, [[
            'values' => [], 'next' => [], 'checkpoint' => null, 'metadata' => [], 'created_at' => null, 'parent_checkpoint' => null, 'tasks' => [],
        ]]));
        $remote = new RemoteGraph(graphId: 'test_graph_id', client: $client);

        $snapshots = iterator_to_array($remote->getStateHistory(['configurable' => ['thread_id' => 'thread1']]), false);

        $this->assertCount(1, $snapshots);
        $this->assertSame(['configurable' => ['thread_id' => 'thread1']], $snapshots[0]->config);
        $this->assertNull($snapshots[0]->createdAt);
        $this->assertNull($snapshots[0]->parentConfig);
    }

    public function testUpdateState(): void
    {
        [$client, $transport] = self::client(static fn (): HttpResponse => FakeHttpClient::json(200, [
            'checkpoint' => ['thread_id' => 'thread_1', 'checkpoint_ns' => 'ns', 'checkpoint_id' => 'checkpoint_1', 'checkpoint_map' => []],
        ]));
        $remote = new RemoteGraph(graphId: 'test_graph_id', client: $client);

        $response = $remote->updateState(['configurable' => ['thread_id' => 'thread1']], ['key' => 'value'], 'agent');

        $this->assertInstanceOf(RunnableConfig::class, $response);
        $this->assertSame(
            ['thread_id' => 'thread_1', 'checkpoint_ns' => 'ns', 'checkpoint_id' => 'checkpoint_1', 'checkpoint_map' => []],
            $response->configurable,
        );
        $this->assertEquals(['values' => ['key' => 'value'], 'as_node' => 'agent', 'checkpoint' => ['thread_id' => 'thread1']], $transport->bodyOf());
    }

    public function testStreamValuesModeThenInterruptRethrows(): void
    {
        [$remote, $transport] = self::streaming([
            ['event' => 'values', 'data' => ['chunk' => 'data1']],
            ['event' => 'values', 'data' => ['chunk' => 'data2']],
            ['event' => 'values', 'data' => ['chunk' => 'data3']],
            ['event' => 'updates', 'data' => ['chunk' => 'data4']],
            ['event' => 'updates', 'data' => [Constants::INTERRUPT => []]],
        ]);

        [$parts, $error] = self::collect($remote->stream(['input' => 'data'], self::threadConfig(['streamMode' => 'values'])));

        $this->assertInstanceOf(GraphInterrupt::class, $error);
        $this->assertSame([['chunk' => 'data1'], ['chunk' => 'data2'], ['chunk' => 'data3']], $parts);
        $this->assertSame(['values', 'updates'], $transport->bodyOf()['stream_mode']);
    }

    public function testStreamDefaultModeIsUpdates(): void
    {
        $chunks = [
            ['event' => 'updates', 'data' => ['chunk' => 'data3']],
            ['event' => 'updates', 'data' => ['chunk' => 'data4']],
            ['event' => 'updates', 'data' => [Constants::INTERRUPT => []]],
        ];
        [$remote] = self::streaming($chunks);

        [$parts, $error] = self::collect($remote->stream(['input' => 'data'], self::threadConfig()));

        $this->assertSame([['chunk' => 'data3'], ['chunk' => 'data4']], $parts);
        $this->assertInstanceOf(GraphInterrupt::class, $error);
    }

    public function testStreamListOfModesIncludesTheModeName(): void
    {
        [$remote] = self::streaming([
            ['event' => 'updates', 'data' => ['chunk' => 'data3']],
            ['event' => 'updates', 'data' => ['chunk' => 'data4']],
            ['event' => 'updates', 'data' => [Constants::INTERRUPT => []]],
        ]);

        [$parts, $error] = self::collect($remote->stream(['input' => 'data'], self::threadConfig(['streamMode' => ['updates']])));

        $this->assertInstanceOf(GraphInterrupt::class, $error);
        $this->assertSame([['updates', ['chunk' => 'data3']], ['updates', ['chunk' => 'data4']]], $parts);
    }

    public function testStreamSubgraphsWithListAndSingleModes(): void
    {
        $chunks = [
            ['event' => 'updates', 'data' => ['chunk' => 'data3']],
            ['event' => 'updates', 'data' => ['chunk' => 'data4']],
            ['event' => 'updates', 'data' => [Constants::INTERRUPT => []]],
        ];

        [$remote, $transport] = self::streaming($chunks);
        [$parts, $error] = self::collect($remote->stream(['input' => 'data'], self::threadConfig(['streamMode' => ['updates'], 'subgraphs' => true])));
        $this->assertInstanceOf(GraphInterrupt::class, $error);
        $this->assertSame([[[], 'updates', ['chunk' => 'data3']], [[], 'updates', ['chunk' => 'data4']]], $parts);
        $this->assertTrue($transport->bodyOf()['stream_subgraphs']);

        [$remote] = self::streaming($chunks);
        [$parts, $error] = self::collect($remote->stream(['input' => 'data'], self::threadConfig(['subgraphs' => true])));
        $this->assertInstanceOf(GraphInterrupt::class, $error);
        $this->assertSame([[[], ['chunk' => 'data3']], [[], ['chunk' => 'data4']]], $parts);
    }

    public function testStreamParsesTheSubgraphNamespaceOutOfTheEventName(): void
    {
        $chunks = [
            ['event' => 'updates|my|subgraph', 'data' => ['chunk' => 'data3']],
            ['event' => 'updates|hello|subgraph', 'data' => ['chunk' => 'data4']],
            ['event' => 'updates|bye|subgraph', 'data' => [Constants::INTERRUPT => []]],
        ];

        [$remote] = self::streaming($chunks);
        [$parts, $error] = self::collect($remote->stream(['input' => 'data'], self::threadConfig(['subgraphs' => true, 'streamMode' => ['updates']])));
        $this->assertInstanceOf(GraphInterrupt::class, $error);
        $this->assertSame([
            [['my', 'subgraph'], 'updates', ['chunk' => 'data3']],
            [['hello', 'subgraph'], 'updates', ['chunk' => 'data4']],
        ], $parts);

        [$remote] = self::streaming($chunks);
        [$parts, $error] = self::collect($remote->stream(['input' => 'data'], self::threadConfig(['subgraphs' => true])));
        $this->assertInstanceOf(GraphInterrupt::class, $error);
        $this->assertSame([
            [['my', 'subgraph'], ['chunk' => 'data3']],
            [['hello', 'subgraph'], ['chunk' => 'data4']],
        ], $parts);
    }

    public function testStreamPrefixesTheCallersCheckpointNamespace(): void
    {
        [$remote] = self::streaming([['event' => 'updates|child', 'data' => ['x' => 1]]]);

        [$parts] = self::collect($remote->stream([], new RunnableConfig(
            configurable: ['thread_id' => 'thread_1', 'checkpoint_ns' => 'outer:1'],
            options: ['subgraphs' => true],
        )));

        $this->assertSame([[['outer:1', 'child'], ['x' => 1]]], $parts);
    }

    public function testStreamRaisesAnErrorEventAsAnException(): void
    {
        [$remote] = self::streaming([['event' => 'error', 'data' => ['error' => 'ValueError', 'message' => 'boom']]]);

        [, $error] = self::collect($remote->stream([], self::threadConfig()));

        $this->assertInstanceOf(\LangGraph\Errors\RemoteException::class, $error);
        $this->assertStringContainsString('boom', $error->getMessage());
        $this->assertSame(['error' => 'ValueError', 'message' => 'boom'], $error->fields['data']);
    }

    public function testStreamWithoutAThreadRunsStatelessly(): void
    {
        [$remote, $transport] = self::streaming([['event' => 'values', 'data' => ['ok' => true]]]);

        self::collect($remote->stream(['q' => 1], new RunnableConfig(options: ['streamMode' => 'values'])));

        $this->assertSame(self::BASE . '/runs/stream', $transport->requests[0]['url']);
        $this->assertSame('test_graph_id', $transport->bodyOf()['assistant_id']);
    }

    public function testStreamSendsInterruptsAndMessagesAsMessagesTuple(): void
    {
        [$remote, $transport] = self::streaming([]);

        self::collect($remote->stream([], self::threadConfig(['streamMode' => ['messages'], 'interruptBefore' => ['a'], 'interruptAfter' => '*'])));

        $body = $transport->bodyOf();
        $this->assertSame(['messages-tuple', 'updates'], $body['stream_mode']);
        $this->assertSame(['a'], $body['interrupt_before']);
        $this->assertSame('*', $body['interrupt_after']);
        $this->assertSame('create', $body['if_not_exists']);
    }

    public function testStreamFallsBackToTheGraphsInterruptSettings(): void
    {
        [$client, $transport] = self::client(static fn (): HttpResponse => self::sse([]));
        $remote = new RemoteGraph(graphId: 'g', client: $client, interruptBefore: ['x']);

        self::collect($remote->stream([], self::threadConfig()));

        $this->assertSame(['x'], $transport->bodyOf()['interrupt_before']);
        $this->assertArrayNotHasKey('interrupt_after', $transport->bodyOf());
    }

    public function testInvoke(): void
    {
        [$remote, $transport] = self::streaming([
            ['event' => 'values', 'data' => ['chunk' => 'data1']],
            ['event' => 'values', 'data' => ['chunk' => 'data2']],
            ['event' => 'values', 'data' => ['messages' => [['type' => 'human', 'content' => 'world']]]],
        ]);

        $result = $remote->invoke(['messages' => [['type' => 'human', 'content' => 'hello']]], self::threadConfig());

        $this->assertSame(['messages' => [['type' => 'human', 'content' => 'world']]], $result);
        $this->assertSame(self::BASE . '/threads/thread_1/runs/stream', $transport->requests[0]['url']);
        $this->assertSame(['messages' => [['type' => 'human', 'content' => 'hello']]], $transport->bodyOf()['input']);
    }

    public function testInvokeWithACommandSerializesProperly(): void
    {
        [$remote, $transport] = self::streaming([
            ['event' => 'values', 'data' => ['messages' => [['type' => 'human', 'content' => 'world']]]],
        ]);

        $result = $remote->invoke(
            new Command(update: ['foo' => 'bar'], resume: 'bar', goto: ['one', new Send('foo', ['baz' => 'qux'])]),
            self::threadConfig(),
        );

        $this->assertSame(['messages' => [['type' => 'human', 'content' => 'world']]], $result);
        $body = $transport->bodyOf();
        $this->assertSame([
            'lg_name' => 'Command',
            'update' => ['foo' => 'bar'],
            'resume' => 'bar',
            'goto' => ['one', ['lg_name' => 'Send', 'node' => 'foo', 'args' => ['baz' => 'qux']]],
        ], $body['command']);
        $this->assertArrayNotHasKey('input', $body);
        $this->assertSame(['values', 'updates'], $body['stream_mode']);
        $this->assertFalse($body['stream_subgraphs']);
        $this->assertArrayNotHasKey('interrupt_before', $body);
        $this->assertSame('create', $body['if_not_exists']);
        $this->assertArrayHasKey('config', $body);
    }

    public function testInvokePropagatesRecursionLimitAndOtherConfigKeysToTheApi(): void
    {
        [$remote, $transport] = self::streaming([['event' => 'values', 'data' => ['ok' => true]]]);

        $remote->invoke([], new RunnableConfig(
            tags: ['test', 'invoke'],
            metadata: ['source' => 'test', 'version' => '1.0'],
            recursionLimit: 10,
            configurable: ['thread_id' => 'thread_1', 'custom_key' => 'custom_value', '__pregel_send' => 'x', 'checkpoint_ns' => 'ns'],
        ));

        $config = $transport->bodyOf()['config'];
        $this->assertSame(['custom_key' => 'custom_value'], $config['configurable']);
        $this->assertSame(10, $config['recursion_limit']);
        $this->assertSame(['test', 'invoke'], $config['tags']);
        $this->assertSame('thread_1', $config['metadata']['thread_id']);
        $this->assertSame('test', $config['metadata']['source']);
        $this->assertSame(self::BASE . '/threads/thread_1/runs/stream', $transport->requests[0]['url']);
    }

    public function testTheDefaultRecursionLimitIsNotSent(): void
    {
        [$remote, $transport] = self::streaming([['event' => 'values', 'data' => []]]);

        $remote->invoke([], self::threadConfig());

        $this->assertArrayNotHasKey('recursion_limit', $transport->bodyOf()['config']);
    }

    public function testStreamPassesContextSeparatelyFromConfigForStatefulRuns(): void
    {
        [$remote, $transport] = self::streaming([['event' => 'values', 'data' => ['ok' => true]]]);

        $context = ['userId' => 'user-1', 'tenantId' => 'tenant-1'];
        $remote->invoke(
            ['messages' => [['type' => 'human', 'content' => 'hello']]],
            new RunnableConfig(configurable: ['thread_id' => 'thread_1'], context: $context),
        );

        $body = $transport->bodyOf();
        $this->assertSame($context, $body['context']);
        $this->assertSame([], (array) $body['config']['configurable']);
        $this->assertStringContainsString('/threads/thread_1/runs/stream', $transport->requests[0]['url']);
    }

    public function testStandingConfigIsMergedUnderTheCallConfig(): void
    {
        [$client, $transport] = self::client(static fn (): HttpResponse => self::sse([['event' => 'values', 'data' => []]]));
        $remote = (new RemoteGraph(graphId: 'g', client: $client, config: ['tags' => ['a'], 'configurable' => ['k' => 'standing', 'thread_id' => 'T']]))
            ->withConfig(['configurable' => ['extra' => 1]]);

        $remote->invoke([], new RunnableConfig(tags: ['b'], configurable: ['k' => 'call']));

        $config = $transport->bodyOf()['config'];
        $this->assertSame(['k' => 'call', 'extra' => 1], $config['configurable']);
        $this->assertSame(['a', 'b'], $config['tags']);
        $this->assertSame(self::BASE . '/threads/T/runs/stream', $transport->requests[0]['url']);
    }

    public function testMessagesInInputAreSerializedWithARole(): void
    {
        [$remote, $transport] = self::streaming([['event' => 'values', 'data' => []]]);

        $remote->invoke(['messages' => [new \LangChain\Messages\HumanMessage('hello')]], self::threadConfig());

        $message = $transport->bodyOf()['input']['messages'][0];
        $this->assertSame('human', $message['role']);
        $this->assertSame('hello', $message['content']);
    }

    public function testHandlesCircularReferences(): void
    {
        [$remote, $transport] = self::streaming([['event' => 'values', 'data' => ['messages' => [['type' => 'human', 'content' => 'world']]]]]);

        $cycle = new \stdClass();
        $cycle->self = $cycle;
        $cycle->label = 'loop';

        $result = $remote->invoke([], new RunnableConfig(
            tags: [],
            metadata: ['source' => 'test', 'circular' => $cycle],
            configurable: ['thread_id' => 'thread_1', 'circular' => $cycle, 'nan' => NAN],
        ));

        $this->assertSame(['messages' => [['type' => 'human', 'content' => 'world']]], $result);
        $config = $transport->bodyOf()['config'];
        $this->assertSame(['self' => '[Circular]', 'label' => 'loop'], $config['metadata']['circular']);
        $this->assertSame('thread_1', $config['metadata']['thread_id']);
        $this->assertSame(['circular' => '[Circular]', 'nan' => null], $config['configurable']);
    }

    public function testBatchRunsEachInputThroughTheRemoteGraph(): void
    {
        [$remote, $transport] = self::streaming([['event' => 'values', 'data' => ['ok' => true]]]);

        $this->assertSame([['ok' => true], ['ok' => true]], $remote->batch([['a' => 1], ['a' => 2]], self::threadConfig()));
        $this->assertCount(2, $transport->requests);
    }

    public function testStreamEventsV3StartsARemoteRunOnTheThread(): void
    {
        [$client, $transport] = self::client(static fn (string $method, string $url): HttpResponse => FakeHttpClient::json(200, ['run_id' => 'run_1', 'thread_id' => 'thread_1']));
        $remote = new RemoteGraph(graphId: 'test_graph_id', client: $client);

        $run = $remote->streamEventsV3(['input' => 'data'], new RunnableConfig(
            tags: ['remote'],
            metadata: ['source' => 'test'],
            recursionLimit: 10,
            configurable: ['thread_id' => 'thread_1', 'checkpoint_id' => 'checkpoint_1', 'custom_key' => 'custom_value'],
        ));

        $this->assertInstanceOf(RemoteRunStream::class, $run);
        $this->assertSame('thread_1', $run->threadId());
        $this->assertSame('run_1', $run->runId());
        $this->assertCount(1, $transport->requests);
        $this->assertSame(self::BASE . '/threads/thread_1/runs', $transport->requests[0]['url']);
        $body = $transport->bodyOf();
        $this->assertSame('test_graph_id', $body['assistant_id']);
        $this->assertSame(['input' => 'data'], $body['input']);
        $this->assertSame(['custom_key' => 'custom_value'], $body['config']['configurable']);
        $this->assertSame(10, $body['config']['recursion_limit']);
        $this->assertSame(['remote'], $body['config']['tags']);
    }

    public function testStreamEventsV3CreatesAThreadWhenNoThreadIdIsConfigured(): void
    {
        [$client, $transport] = self::client(static fn (string $method, string $url): HttpResponse => str_ends_with($url, '/threads')
            ? FakeHttpClient::json(200, ['thread_id' => 'new-thread'])
            : FakeHttpClient::json(200, ['run_id' => 'run_9']));
        $remote = new RemoteGraph(graphId: 'test_graph_id', client: $client);

        $run = $remote->streamEventsV3(['input' => 'data']);

        $this->assertSame('new-thread', $run->threadId());
        $this->assertSame(self::BASE . '/threads', $transport->requests[0]['url']);
        $this->assertSame(self::BASE . '/threads/new-thread/runs', $transport->requests[1]['url']);
    }

    public function testStreamEventsV3SerializesACommandAsACommand(): void
    {
        [$client, $transport] = self::client(static fn (): HttpResponse => FakeHttpClient::json(200, ['run_id' => 'run_1']));
        $remote = new RemoteGraph(graphId: 'test_graph_id', client: $client);

        $remote->streamEventsV3(new Command(update: ['foo' => 'bar'], resume: 'yes'), self::threadConfig());

        $body = $transport->bodyOf();
        $this->assertSame('Command', $body['command']['lg_name']);
        $this->assertSame(['foo' => 'bar'], $body['command']['update']);
        $this->assertSame('yes', $body['command']['resume']);
        $this->assertArrayNotHasKey('input', $body);
    }

    public function testStreamEventsV3RejectsUnsupportedOptions(): void
    {
        $remote = new RemoteGraph(graphId: 'test_graph_id', client: new Client(['apiKey' => null]));

        foreach (['transformers', 'control', 'interruptBefore', 'interruptAfter'] as $option) {
            try {
                $remote->streamEventsV3(['input' => 'data'], new RunnableConfig(options: [$option => []]));
                $this->fail("{$option} should be rejected");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString($option, $e->getMessage());
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        (new RemoteGraph(graphId: 'g', client: new Client(['apiKey' => null]), interruptAfter: ['n']))->streamEventsV3([]);
    }

    public function testStreamEventsV3IteratesRemoteProtocolEvents(): void
    {
        [$client, $transport] = self::client(static function (string $method, string $url): HttpResponse {
            if ($method === 'POST') {
                return FakeHttpClient::json(200, ['run_id' => 'run_1']);
            }

            return self::sse([
                ['event' => 'metadata', 'data' => ['run_id' => 'run_1']],
                ['event' => 'values', 'data' => ['value' => 1]],
                ['event' => 'updates|child|sub', 'data' => ['__interrupt__' => [['id' => 'i1', 'value' => 'ok?']]]],
                ['event' => 'end', 'data' => null],
            ]);
        });
        $remote = new RemoteGraph(graphId: 'test_graph_id', client: $client);

        $run = $remote->streamEventsV3(['input' => 'data'], self::threadConfig());
        $events = iterator_to_array($run, false);

        $this->assertCount(2, $events);
        $this->assertSame('event', $events[0]['type']);
        $this->assertSame(0, $events[0]['seq']);
        $this->assertSame('values', $events[0]['method']);
        $this->assertSame([], $events[0]['params']['namespace']);
        $this->assertSame(['value' => 1], $events[0]['params']['data']);
        $this->assertSame(1, $events[1]['seq']);
        $this->assertSame(['child', 'sub'], $events[1]['params']['namespace']);
        $this->assertTrue($run->interrupted());
        $this->assertSame([['id' => 'i1', 'value' => 'ok?']], $run->interrupts());
        $this->assertSame(['value' => 1], $run->output());
        $this->assertStringContainsString('/threads/thread_1/runs/run_1/stream', $transport->requests[1]['url']);
    }

    public function testRemoteRunStreamEncodesEventsAsATextEventStream(): void
    {
        [$client] = self::client(static fn (string $method): HttpResponse => $method === 'POST'
            ? FakeHttpClient::json(200, ['run_id' => 'run_1'])
            : self::sse([
                ['event' => 'values', 'data' => ['v' => 1]],
                ['event' => 'updates|a', 'data' => ['u' => 2]],
            ]));
        $remote = new RemoteGraph(graphId: 'g', client: $client);

        $frames = iterator_to_array($remote->streamEventsV3([], self::threadConfig())->toEventStream(), false);

        $this->assertSame(["event: values\ndata: {\"v\":1}\n\n", "event: updates|a\ndata: {\"u\":2}\n\n"], $frames);
    }

    public function testRemoteRunStreamAbortCancelsTheRunOnce(): void
    {
        [$client, $transport] = self::client(static fn (): HttpResponse => FakeHttpClient::json(200, ['run_id' => 'run_1']));
        $remote = new RemoteGraph(graphId: 'g', client: $client);
        $run = $remote->streamEventsV3([], self::threadConfig());

        $run->abort();
        $run->abort();

        $this->assertTrue($run->aborted());
        $this->assertTrue(($run->signal())());
        $cancels = array_filter($transport->requests, static fn (array $r): bool => str_contains($r['url'], '/cancel'));
        $this->assertCount(1, $cancels);
        $this->assertSame([], iterator_to_array(new RemoteRunStream($client, 't', null), false), 'a stream with no run has no events');
    }

    public function testRemoteRunStreamAbortSwallowsACancelFailure(): void
    {
        [$client] = self::client(static fn (string $method, string $url): HttpResponse => str_contains($url, '/cancel')
            ? new HttpResponse(500, [], 'down')
            : FakeHttpClient::json(200, ['run_id' => 'run_1']));
        $run = (new RemoteGraph(graphId: 'g', client: $client))->streamEventsV3([], self::threadConfig());

        $run->abort();

        $this->assertTrue($run->aborted());
    }

    public function testStreamEventsV3AbortsAtOnceWhenTheSignalIsAlreadySet(): void
    {
        [$client, $transport] = self::client(static fn (): HttpResponse => FakeHttpClient::json(200, ['run_id' => 'run_1']));
        $remote = new RemoteGraph(graphId: 'g', client: $client);

        $run = $remote->streamEventsV3([], new RunnableConfig(configurable: ['thread_id' => 't'], signal: static fn (): bool => true));

        $this->assertTrue($run->aborted());
        $this->assertStringContainsString('/cancel', $transport->requests[1]['url']);
    }

    /**
     * A real graph served behind the wire, called by a parent graph through RemoteGraph as a node.
     */
    public function testARemoteGraphRunsAsANodeOfALocalGraphAgainstARealServerGraph(): void
    {
        $served = [];
        $server = new StateGraph(['value' => 'int']);
        $server->addNode('double', static function (array $s) use (&$served): array {
            $served[] = 'double';

            return ['value' => $s['value'] * 2];
        });
        $server->addNode('increment', static function (array $s) use (&$served): array {
            $served[] = 'increment';

            return ['value' => $s['value'] + 1];
        });
        $server->addEdge(Constants::START, 'double');
        $server->addEdge('double', 'increment');
        $server->addEdge('increment', Constants::END);
        $serverGraph = $server->compile();

        [$client, $transport] = self::client(static function (string $method, string $url, array $headers, ?string $body) use ($serverGraph): HttpResponse {
            $parts = [];
            foreach ($serverGraph->stream(json_decode((string) $body, true)['input'], new RunnableConfig()) as [$mode, $payload]) {
                if ($mode === 'values') {
                    $parts[] = ['event' => 'values', 'data' => $payload];
                }
            }

            return self::sse($parts);
        });
        $remote = new RemoteGraph(graphId: 'calc', client: $client);

        $parent = new StateGraph(['value' => 'int']);
        $parent->addNode('remote', $remote);
        $parent->addEdge(Constants::START, 'remote');
        $parent->addEdge('remote', Constants::END);

        $result = $parent->compile()->invoke(['value' => 5]);

        $this->assertSame(11, $result['value'], '5 doubled and incremented by the server graph');
        $this->assertSame(['double', 'increment'], $served);
        $this->assertSame(self::BASE . '/runs/stream', $transport->requests[0]['url']);
        $this->assertSame(['value' => 5], $transport->bodyOf()['input']);
    }

    public function testAnInterruptRaisedByTheServerGraphReachesTheCallerAsGraphInterrupt(): void
    {
        [$remote] = self::streaming([
            ['event' => 'values', 'data' => ['value' => 1]],
            ['event' => 'updates', 'data' => [Constants::INTERRUPT => [['id' => 'abc', 'value' => 'approve?']]]],
        ]);

        try {
            $remote->invoke(['value' => 1], self::threadConfig());
            $this->fail('expected an interrupt');
        } catch (GraphInterrupt $e) {
            $this->assertSame('approve?', $e->interrupts[0]['value']);
            $this->assertSame('abc', $e->interrupts[0]['id']);
        }
    }

    public function testNamingTheGraph(): void
    {
        $remote = new RemoteGraph(graphId: 'g', client: new Client(['apiKey' => null]));

        $this->assertSame('RemoteGraph', $remote->getName());
    }
}
