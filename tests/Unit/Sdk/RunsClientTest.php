<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk;

use LangChain\Tests\Unit\Sdk\Support\RecordingTransport;
use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Testing\FakeHttpClient;
use LangGraph\Sdk\Client;
use LangGraph\Sdk\RunsClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `client/runs/index.test.ts` (`runs.cancelMany`, `runs.list`), plus the rest of the REST
 * surface of `client/runs/index.ts`, which upstream leaves untested.
 */
#[CoversClass(RunsClient::class)]
final class RunsClientTest extends TestCase
{
    private function client(RecordingTransport $t): Client
    {
        return new Client(['apiKey' => 'test-api-key'], $t);
    }

    /** @return array<string, mixed> */
    private static function runFixture(string $status = 'pending'): array
    {
        return ['run_id' => 'run_1', 'thread_id' => 'th_1', 'assistant_id' => 'as_1', 'created_at' => '2024-01-01T00:00:00Z', 'updated_at' => '2024-01-01T00:00:00Z', 'status' => $status, 'metadata' => [], 'kwargs' => [], 'multitask_strategy' => 'reject'];
    }

    // ---- runs.cancelMany

    public function testCancelsByStatus(): void
    {
        $t = new RecordingTransport([new HttpResponse(204)]);

        $this->client($t)->runs->cancelMany(['status' => 'pending']);

        $this->assertSame('POST', $t->requests[0]['method']);
        $this->assertSame('/runs/cancel', parse_url($t->requests[0]['url'], \PHP_URL_PATH));
        $this->assertSame(['status' => 'pending'], $t->bodyOf());
    }

    public function testCancelsByThreadIdAndRunIds(): void
    {
        $t = new RecordingTransport([new HttpResponse(204)]);

        $this->client($t)->runs->cancelMany(['threadId' => 'thread_abc', 'runIds' => ['run_1', 'run_2']]);

        $this->assertSame('POST', $t->requests[0]['method']);
        $this->assertSame('/runs/cancel', parse_url($t->requests[0]['url'], \PHP_URL_PATH));
        $this->assertSame(['thread_id' => 'thread_abc', 'run_ids' => ['run_1', 'run_2']], $t->bodyOf());
    }

    public function testPassesActionAsQueryParameter(): void
    {
        $t = new RecordingTransport([new HttpResponse(204)]);

        $this->client($t)->runs->cancelMany(['status' => 'all', 'action' => 'rollback']);

        parse_str((string) parse_url($t->requests[0]['url'], \PHP_URL_QUERY), $query);
        $this->assertSame('rollback', $query['action']);
        $this->assertSame(['status' => 'all'], $t->bodyOf());
    }

    public function testOmitsUndefinedFieldsFromRequestBody(): void
    {
        $t = new RecordingTransport([new HttpResponse(204)]);

        $this->client($t)->runs->cancelMany(['status' => 'running']);

        $body = $t->bodyOf();
        $this->assertArrayNotHasKey('thread_id', $body);
        $this->assertArrayNotHasKey('run_ids', $body);
        $this->assertSame(['status' => 'running'], $body);
    }

    // ---- runs.list

    public function testSendsAnArrayValuedParamAsRepeatedQueryEntriesNotOneJsonString(): void
    {
        $t = new RecordingTransport([FakeHttpClient::json(200, [])]);

        $this->client($t)->runs->list('thread_abc', [
            'status' => 'pending',
            'select' => ['run_id', 'kwargs', 'created_at', 'multitask_strategy'],
        ]);

        $query = (string) parse_url($t->requests[0]['url'], \PHP_URL_QUERY);
        $this->assertSame(
            ['run_id', 'kwargs', 'created_at', 'multitask_strategy'],
            array_map(static fn (string $p): string => explode('=', $p)[1], array_values(array_filter(explode('&', $query), static fn (string $p): bool => str_starts_with($p, 'select=')))),
        );
        $this->assertStringContainsString('status=pending', $query);
        $this->assertStringContainsString('limit=10&offset=0', $query, 'defaults are 10 and 0');
    }

    // ---- the rest of the REST surface

    public function testCreateOnAThreadPostsTheWirePayloadAndReportsTheRunViaOnRunCreated(): void
    {
        $t = new RecordingTransport([FakeHttpClient::json(200, self::runFixture(), ['Content-Type' => 'application/json', 'Content-Location' => '/threads/th_1/runs/run_1'])]);
        $created = [];

        $run = $this->client($t)->runs->create('th_1', 'as_1', [
            'input' => ['q' => 'hi'],
            'streamMode' => ['values'],
            'interruptBefore' => ['tools'],
            'checkpointId' => 'cp_1',
            'multitaskStrategy' => 'enqueue',
            'afterSeconds' => 5,
            'metadata' => [],
            '_langsmithTracer' => ['projectName' => 'proj', 'exampleId' => 'ex'],
            'onRunCreated' => static function (array $meta) use (&$created): void {
                $created[] = $meta;
            },
        ]);

        $this->assertSame('run_1', $run['run_id']);
        $this->assertSame('http://localhost:8123/threads/th_1/runs', $t->requests[0]['url']);
        $this->assertEqualsCanonicalizing([
            'input' => ['q' => 'hi'],
            'metadata' => [],
            'stream_mode' => ['values'],
            'interrupt_before' => ['tools'],
            'checkpoint_id' => 'cp_1',
            'multitask_strategy' => 'enqueue',
            'after_seconds' => 5,
            'assistant_id' => 'as_1',
            'langsmith_tracer' => ['project_name' => 'proj', 'example_id' => 'ex'],
        ], $t->bodyOf());
        $this->assertStringContainsString('"metadata":{}', (string) $t->requests[0]['body'], 'an empty metadata is a JSON object');
        $this->assertSame([['run_id' => 'run_1', 'thread_id' => 'th_1']], $created);
    }

    public function testCreateWithoutAThreadIsStatelessAndSendsOnlyWhatWasGiven(): void
    {
        $t = new RecordingTransport([FakeHttpClient::json(200, self::runFixture())]);

        $this->client($t)->runs->create(null, 'as_1');

        $this->assertSame('http://localhost:8123/runs', $t->requests[0]['url']);
        $this->assertSame(['assistant_id' => 'as_1'], $t->bodyOf());
    }

    public function testCreateBatchDropsNullsAndKeepsCallerKeysAsGiven(): void
    {
        $t = new RecordingTransport([FakeHttpClient::json(200, [self::runFixture(), self::runFixture()])]);

        $runs = $this->client($t)->runs->createBatch([
            ['assistantId' => 'a1', 'input' => ['x' => 1], 'webhook' => null],
            ['assistantId' => 'a2'],
        ]);

        $this->assertCount(2, $runs);
        $this->assertSame('http://localhost:8123/runs/batch', $t->requests[0]['url']);
        $this->assertSame([
            ['assistantId' => 'a1', 'input' => ['x' => 1], 'assistant_id' => 'a1'],
            ['assistantId' => 'a2', 'assistant_id' => 'a2'],
        ], (array) json_decode((string) $t->requests[0]['body'], true));
    }

    public function testWaitReturnsTheValuesWithNoRequestTimeout(): void
    {
        $t = new RecordingTransport([FakeHttpClient::json(200, ['count' => 3])]);
        $client = new Client(['apiKey' => null, 'timeoutMs' => 5000], $t);

        $this->assertSame(['count' => 3], $client->runs->wait('th_1', 'as_1', ['input' => ['n' => 1], 'onDisconnect' => 'cancel']));

        $this->assertSame('http://localhost:8123/threads/th_1/runs/wait', $t->requests[0]['url']);
        $this->assertNull($t->requests[0]['timeout'], 'wait overrides the client timeout with none');
        $this->assertSame(['input' => ['n' => 1], 'on_disconnect' => 'cancel', 'assistant_id' => 'as_1'], $t->bodyOf());
    }

    public function testWaitRaisesTheErrorTheServerReportsInTheResult(): void
    {
        $t = new RecordingTransport([FakeHttpClient::json(200, ['__error__' => ['error' => 'ValueError', 'message' => 'bad input']])]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ValueError: bad input');

        $this->client($t)->runs->wait(null, 'as_1');
    }

    public function testWaitReturnsTheErrorResultWhenRaiseErrorIsOff(): void
    {
        $error = ['__error__' => ['error' => 'ValueError', 'message' => 'bad input']];
        $t = new RecordingTransport([FakeHttpClient::json(200, $error)]);

        $this->assertSame($error, $this->client($t)->runs->wait(null, 'as_1', ['raiseError' => false]));
        $this->assertSame('http://localhost:8123/runs/wait', $t->requests[0]['url']);
    }

    public function testGetAndDeleteAddressTheRun(): void
    {
        $t = new RecordingTransport([FakeHttpClient::json(200, self::runFixture('success')), new HttpResponse(204)]);
        $client = $this->client($t);

        $this->assertSame('success', $client->runs->get('th_1', 'run_1')['status']);
        $client->runs->delete('th_1', 'run_1');

        $this->assertSame(['GET', 'DELETE'], array_column($t->requests, 'method'));
        $this->assertSame(['http://localhost:8123/threads/th_1/runs/run_1', 'http://localhost:8123/threads/th_1/runs/run_1'], array_column($t->requests, 'url'));
    }

    public function testCancelSendsWaitAndActionAsQueryParams(): void
    {
        $t = new RecordingTransport([new HttpResponse(204), new HttpResponse(204)]);
        $client = $this->client($t);

        $client->runs->cancel('th_1', 'run_1');
        $client->runs->cancel('th_1', 'run_1', true, 'rollback');

        $this->assertSame('POST', $t->requests[0]['method']);
        $this->assertSame('http://localhost:8123/threads/th_1/runs/run_1/cancel?wait=0&action=interrupt', $t->requests[0]['url']);
        $this->assertSame('http://localhost:8123/threads/th_1/runs/run_1/cancel?wait=1&action=rollback', $t->requests[1]['url']);
    }

    public function testJoinBlocksWithNoTimeoutAndReturnsTheFinalState(): void
    {
        $t = new RecordingTransport([FakeHttpClient::json(200, ['done' => true])]);
        $client = new Client(['apiKey' => null, 'timeoutMs' => 5000], $t);

        $this->assertSame(['done' => true], $client->runs->join('th_1', 'run_1', ['cancelOnDisconnect' => true]));

        $this->assertSame('http://localhost:8123/threads/th_1/runs/run_1/join?cancel_on_disconnect=1', $t->requests[0]['url']);
        $this->assertNull($t->requests[0]['timeout']);
    }

    public function testClientExposesRunsSharingTheTransport(): void
    {
        $t = new RecordingTransport([FakeHttpClient::json(200, self::runFixture())]);
        $client = $this->client($t);

        $this->assertInstanceOf(RunsClient::class, $client->runs);
        $client->runs->get('th_1', 'run_1');
        $this->assertSame('test-api-key', $t->requests[0]['headers']['x-api-key']);
    }
}
