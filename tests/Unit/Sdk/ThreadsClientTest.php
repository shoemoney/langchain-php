<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk;

use LangChain\Tests\Unit\Sdk\Support\RecordingTransport;
use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Testing\FakeHttpClient;
use LangGraph\Sdk\ThreadsClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for `client/threads/index.ts`, which upstream covers only through `base.test.ts`
 * (`threads.update` and the coalescing blocks, ported in BaseClientTest). The rest of the REST
 * surface is checked here against a thread shaped exactly as `schema.ts` declares it.
 *
 * `joinStream` and `stream` are WP-23b and are not ported.
 */
#[CoversClass(ThreadsClient::class)]
final class ThreadsClientTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function thread(): array
    {
        return [
            'thread_id' => 'th_1',
            'created_at' => '2024-01-01T00:00:00Z',
            'updated_at' => '2024-01-02T00:00:00Z',
            'state_updated_at' => '2024-01-02T00:00:00Z',
            'metadata' => ['graph_id' => 'agent'],
            'status' => 'idle',
            'values' => ['messages' => []],
            'interrupts' => [],
        ];
    }

    /** @return array<string, mixed> */
    private static function threadState(): array
    {
        return [
            'values' => ['count' => 3],
            'next' => [],
            'checkpoint' => ['thread_id' => 'th_1', 'checkpoint_ns' => '', 'checkpoint_id' => 'cp_1', 'checkpoint_map' => null],
            'metadata' => ['source' => 'loop', 'step' => 2],
            'created_at' => '2024-01-02T00:00:00Z',
            'parent_checkpoint' => null,
            'tasks' => [],
        ];
    }

    private function client(RecordingTransport $t): ThreadsClient
    {
        return new ThreadsClient(['apiKey' => null], $t);
    }

    public function testGetPassesIncludeAsRepeatedQueryPairs(): void
    {
        $t = new RecordingTransport([FakeHttpClient::json(200, self::thread())]);

        $thread = $this->client($t)->get('th_1', ['include' => ['values', 'interrupts']]);

        $this->assertSame('idle', $thread['status']);
        $this->assertSame('GET', $t->requests[0]['method']);
        $this->assertSame('http://localhost:8123/threads/th_1?include=values&include=interrupts', $t->requests[0]['url']);
    }

    public function testCreateStoresGraphIdInMetadataAndExpandsATtlNumber(): void
    {
        $t = new RecordingTransport([FakeHttpClient::json(200, self::thread())]);

        $this->client($t)->create([
            'graphId' => 'agent',
            'metadata' => ['owner' => 'ada'],
            'threadId' => 'th_1',
            'ifExists' => 'do_nothing',
            'ttl' => 60,
        ]);

        $this->assertSame('POST', $t->requests[0]['method']);
        $this->assertSame([
            'metadata' => ['owner' => 'ada', 'graph_id' => 'agent'],
            'thread_id' => 'th_1',
            'if_exists' => 'do_nothing',
            'ttl' => ['ttl' => 60, 'strategy' => 'delete'],
        ], $t->bodyOf());
    }

    public function testCreateWithNothingSendsAnEmptyMetadataObject(): void
    {
        $t = new RecordingTransport([FakeHttpClient::json(200, self::thread())]);

        $this->client($t)->create();

        $this->assertSame('{"metadata":{}}', $t->requests[0]['body']);
    }

    public function testCreateRenamesSuperstepFieldsAndPassesAnExplicitTtlThrough(): void
    {
        $t = new RecordingTransport([FakeHttpClient::json(200, self::thread())]);

        $this->client($t)->create([
            'supersteps' => [['updates' => [['values' => ['a' => 1], 'asNode' => 'node_a']]]],
            'ttl' => ['ttl' => 5, 'strategy' => 'delete'],
        ]);

        $body = $t->bodyOf();
        $this->assertSame([['updates' => [['values' => ['a' => 1], 'as_node' => 'node_a']]]], $body['supersteps']);
        $this->assertSame(['ttl' => 5, 'strategy' => 'delete'], $body['ttl']);
    }

    public function testCopyPostsWithNoBody(): void
    {
        $t = new RecordingTransport([FakeHttpClient::json(200, self::thread())]);

        $this->client($t)->copy('th_1');

        $this->assertSame('POST', $t->requests[0]['method']);
        $this->assertSame('http://localhost:8123/threads/th_1/copy', $t->requests[0]['url']);
        $this->assertNull($t->requests[0]['body']);
    }

    public function testUpdateReturnsTheThreadUnlessAMinimalResponseWasRequested(): void
    {
        $t = new RecordingTransport([FakeHttpClient::json(200, self::thread())]);

        $thread = $this->client($t)->update('th_1', ['metadata' => ['a' => 1], 'ttl' => ['ttl' => 9]]);

        $this->assertSame('th_1', $thread['thread_id']);
        $this->assertArrayNotHasKey('prefer', $t->requests[0]['headers']);
        $this->assertSame(['metadata' => ['a' => 1], 'ttl' => ['ttl' => 9]], $t->bodyOf());
    }

    public function testDeleteAndPrune(): void
    {
        $t = new RecordingTransport([new HttpResponse(204, [], ''), FakeHttpClient::json(200, ['pruned_count' => 2]), FakeHttpClient::json(200, ['pruned_count' => 1])]);
        $client = $this->client($t);

        $client->delete('th_1');
        $this->assertSame('DELETE', $t->requests[0]['method']);

        $this->assertSame(['pruned_count' => 2], $client->prune(['a', 'b']));
        $this->assertSame(['thread_ids' => ['a', 'b'], 'strategy' => 'delete'], $t->bodyOf(1));

        $client->prune(['a'], ['strategy' => 'keep_latest']);
        $this->assertSame('keep_latest', $t->bodyOf(2)['strategy']);
        $this->assertSame('http://localhost:8123/threads/prune', $t->requests[2]['url']);
    }

    public function testSearchAndCountSendOnlyTheFiltersGiven(): void
    {
        $t = new RecordingTransport([FakeHttpClient::json(200, [self::thread()]), new HttpResponse(200, [], '4')]);
        $client = $this->client($t);

        $found = $client->search(['metadata' => ['graph_id' => 'agent'], 'status' => 'idle', 'sortBy' => 'updated_at', 'sortOrder' => 'desc', 'extract' => ['last' => 'values.messages[-1]']]);
        $this->assertCount(1, $found);
        $this->assertEquals([
            'metadata' => ['graph_id' => 'agent'], 'limit' => 10, 'offset' => 0, 'status' => 'idle',
            'sort_by' => 'updated_at', 'sort_order' => 'desc', 'extract' => ['last' => 'values.messages[-1]'],
        ], $t->bodyOf(0));

        $this->assertSame(4, $client->count(['status' => 'busy']));
        $this->assertSame(['status' => 'busy'], $t->bodyOf(1));
    }

    public function testGetStateWithoutACheckpointReadsTheStateEndpoint(): void
    {
        $t = new RecordingTransport([FakeHttpClient::json(200, self::threadState())]);

        $state = $this->client($t)->getState('th_1', null, ['subgraphs' => true]);

        $this->assertSame(['count' => 3], $state['values']);
        $this->assertSame('GET', $t->requests[0]['method']);
        $this->assertSame('http://localhost:8123/threads/th_1/state?subgraphs=true', $t->requests[0]['url']);
    }

    public function testGetStateWithACheckpointObjectPostsIt(): void
    {
        $checkpoint = ['thread_id' => 'th_1', 'checkpoint_ns' => '', 'checkpoint_id' => 'cp_1', 'checkpoint_map' => null];
        $t = new RecordingTransport([FakeHttpClient::json(200, self::threadState())]);

        $this->client($t)->getState('th_1', $checkpoint, ['subgraphs' => true]);

        $this->assertSame('POST', $t->requests[0]['method']);
        $this->assertSame('http://localhost:8123/threads/th_1/state/checkpoint', $t->requests[0]['url']);
        $this->assertSame(['checkpoint' => $checkpoint, 'subgraphs' => true], $t->bodyOf());
    }

    public function testGetStateWithACheckpointIdUsesTheDeprecatedPathForm(): void
    {
        $t = new RecordingTransport([FakeHttpClient::json(200, self::threadState())]);

        $this->client($t)->getState('th_1', 'cp_1');

        $this->assertSame('GET', $t->requests[0]['method']);
        $this->assertSame('http://localhost:8123/threads/th_1/state/cp_1', $t->requests[0]['url']);
    }

    public function testUpdateStateMapsCheckpointIdAndAsNode(): void
    {
        $t = new RecordingTransport([FakeHttpClient::json(200, ['configurable' => ['thread_id' => 'th_1', 'checkpoint_id' => 'cp_2']])]);

        $config = $this->client($t)->updateState('th_1', ['values' => ['count' => 9], 'checkpointId' => 'cp_1', 'asNode' => 'inc']);

        $this->assertSame('cp_2', $config['configurable']['checkpoint_id']);
        $this->assertSame('POST', $t->requests[0]['method']);
        $this->assertSame(['values' => ['count' => 9], 'checkpoint_id' => 'cp_1', 'as_node' => 'inc'], $t->bodyOf());
    }

    public function testPatchStateAcceptsAThreadIdOrAConfig(): void
    {
        $t = new RecordingTransport([new HttpResponse(204, [], ''), new HttpResponse(204, [], '')]);
        $client = $this->client($t);

        $client->patchState('th_1', ['tag' => 'x']);
        $client->patchState(['configurable' => ['thread_id' => 'th_2']], ['tag' => 'y']);

        $this->assertSame('PATCH', $t->requests[0]['method']);
        $this->assertSame('http://localhost:8123/threads/th_1/state', $t->requests[0]['url']);
        $this->assertSame('http://localhost:8123/threads/th_2/state', $t->requests[1]['url']);
        $this->assertSame(['metadata' => ['tag' => 'y']], $t->bodyOf(1));
    }

    public function testPatchStateRequiresAThreadIdInTheConfig(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Thread ID is required when updating state with a config.');

        $this->client(new RecordingTransport())->patchState(['configurable' => []], ['a' => 1]);
    }

    public function testGetHistoryPostsTheFiltersWithADefaultLimit(): void
    {
        $t = new RecordingTransport([FakeHttpClient::json(200, [self::threadState()])]);

        $history = $this->client($t)->getHistory('th_1', ['metadata' => ['source' => 'loop'], 'before' => ['configurable' => ['checkpoint_id' => 'cp_9']]]);

        $this->assertCount(1, $history);
        $this->assertEquals([
            'limit' => 10,
            'before' => ['configurable' => ['checkpoint_id' => 'cp_9']],
            'metadata' => ['source' => 'loop'],
        ], $t->bodyOf());
    }
}
