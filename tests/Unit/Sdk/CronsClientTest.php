<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk;

use LangChain\Tests\Unit\Sdk\Support\RecordingTransport;
use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Testing\FakeHttpClient;
use LangGraph\Sdk\CronsClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `client/crons/index.test.ts` (`crons.update`, `crons.search`, `crons.count`) plus the
 * create/delete endpoints. The fixture is upstream's `cronPayload()` verbatim.
 *
 * JS's `undefined` (field omitted) versus `null` (field sent as JSON null) is key-absent versus
 * key-present-with-null here; the "sends null end_time" case pins that.
 */
#[CoversClass(CronsClient::class)]
final class CronsClientTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function cronPayload(): array
    {
        return [
            'cron_id' => 'cron_123',
            'assistant_id' => 'asst_123',
            'thread_id' => 'thread_123',
            'schedule' => '0 0 * * *',
            'created_at' => '2024-01-01T00:00:00Z',
            'updated_at' => '2024-01-02T00:00:00Z',
            'payload' => ['input' => ['message' => 'test'], 'metadata' => ['env' => 'test']],
            'enabled' => true,
        ];
    }

    private function transport(HttpResponse ...$responses): RecordingTransport
    {
        return new RecordingTransport($responses === [] ? [FakeHttpClient::json(200, self::cronPayload())] : array_values($responses));
    }

    public function testUpdateOnlySendsDefinedFieldsToTheApi(): void
    {
        $t = $this->transport();

        (new CronsClient(['apiKey' => 'test-api-key'], $t))->update('cron_123', ['schedule' => '0 10 * * *', 'enabled' => false]);

        $this->assertCount(1, $t->requests);
        $this->assertStringContainsString('/runs/crons/cron_123', $t->requests[0]['url']);
        $this->assertSame('PATCH', $t->requests[0]['method']);
        $this->assertSame(['schedule' => '0 10 * * *', 'enabled' => false], $t->bodyOf());
    }

    public function testUpdateSendsNullEndTimeToClearASetEndTime(): void
    {
        $t = $this->transport();

        (new CronsClient(['apiKey' => 'test-api-key'], $t))->update('cron_123', ['endTime' => null]);

        $this->assertSame('PATCH', $t->requests[0]['method']);
        $this->assertSame('{"end_time":null}', $t->requests[0]['body']);
    }

    public function testUpdateWithNoFieldsSendsAnEmptyObject(): void
    {
        $t = $this->transport();

        (new CronsClient(['apiKey' => null], $t))->update('cron_123');

        $this->assertSame('{}', $t->requests[0]['body']);
    }

    public function testUpdateConvertsCamelCaseToSnakeCase(): void
    {
        $t = $this->transport();

        (new CronsClient(['apiKey' => 'test-api-key'], $t))->update('cron_123', [
            'schedule' => '0 10 * * *',
            'endTime' => '2024-12-31T23:59:59Z',
            'interruptBefore' => ['node1', 'node2'],
            'interruptAfter' => '*',
            'onRunCompleted' => 'delete',
        ]);

        $this->assertSame([
            'schedule' => '0 10 * * *',
            'end_time' => '2024-12-31T23:59:59Z',
            'interrupt_before' => ['node1', 'node2'],
            'interrupt_after' => '*',
            'on_run_completed' => 'delete',
        ], $t->bodyOf());
    }

    public function testUpdateSendsAllUpdatableFieldsWhenProvided(): void
    {
        $t = $this->transport();

        (new CronsClient(['apiKey' => 'test-api-key'], $t))->update('cron_123', [
            'schedule' => '0 10 * * *',
            'endTime' => '2024-12-31T23:59:59Z',
            'input' => ['message' => 'updated'],
            'metadata' => ['env' => 'prod'],
            'config' => ['configurable' => ['foo' => 'bar']],
            'context' => ['user' => 'test'],
            'webhook' => 'https://example.com/webhook',
            'interruptBefore' => ['node1'],
            'interruptAfter' => ['node2'],
            'onRunCompleted' => 'keep',
            'enabled' => false,
        ]);

        $this->assertSame([
            'schedule' => '0 10 * * *',
            'end_time' => '2024-12-31T23:59:59Z',
            'input' => ['message' => 'updated'],
            'metadata' => ['env' => 'prod'],
            'config' => ['configurable' => ['foo' => 'bar']],
            'context' => ['user' => 'test'],
            'webhook' => 'https://example.com/webhook',
            'interrupt_before' => ['node1'],
            'interrupt_after' => ['node2'],
            'on_run_completed' => 'keep',
            'enabled' => false,
        ], $t->bodyOf());
    }

    public function testUpdatePassesTheSignalThroughToTheRequest(): void
    {
        $seen = null;
        $signal = static fn (): bool => false;
        $client = new CronsClient([
            'apiKey' => 'test-api-key',
            'onRequest' => static function (string $url, array $init) use (&$seen): array {
                $seen = $init['signal'] ?? null;

                return $init;
            },
        ], $this->transport());

        $client->update('cron_123', ['schedule' => '0 10 * * *', 'signal' => $signal]);

        $this->assertSame($signal, $seen);
    }

    public function testSearchForwardsTheMetadataFilterToTheApi(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, [self::cronPayload()])]);

        $result = (new CronsClient(['apiKey' => 'test-api-key'], $http))->search([
            'assistantId' => 'asst_123',
            'metadata' => ['owner' => 'alice'],
        ]);

        $this->assertSame([self::cronPayload()], $result);
        $this->assertCount(1, $http->requests);
        $this->assertStringContainsString('/runs/crons/search', $http->requests[0]['url']);
        $this->assertEquals([
            'assistant_id' => 'asst_123',
            'metadata' => ['owner' => 'alice'],
            'limit' => 10,
            'offset' => 0,
        ], $http->lastRequestBody());
    }

    public function testSearchOmitsMetadataWhenNotProvided(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, [])]);

        (new CronsClient(['apiKey' => 'test-api-key'], $http))->search();

        $this->assertArrayNotHasKey('metadata', $http->lastRequestBody());
    }

    public function testCountForwardsTheMetadataFilterToTheApi(): void
    {
        $http = new FakeHttpClient([new HttpResponse(200, [], '2')]);

        $result = (new CronsClient(['apiKey' => 'test-api-key'], $http))->count([
            'assistantId' => 'asst_123',
            'metadata' => ['team' => 'infra'],
        ]);

        $this->assertSame(2, $result);
        $this->assertStringContainsString('/runs/crons/count', $http->requests[0]['url']);
        $this->assertSame(['assistant_id' => 'asst_123', 'metadata' => ['team' => 'infra']], $http->lastRequestBody());
    }

    public function testCountOmitsMetadataWhenNotProvided(): void
    {
        $http = new FakeHttpClient([new HttpResponse(200, [], '0')]);

        (new CronsClient(['apiKey' => 'test-api-key'], $http))->count();

        $this->assertArrayNotHasKey('metadata', $http->lastRequestBody());
    }

    public function testCreateAndCreateForThreadAddTheAssistantId(): void
    {
        $t = $this->transport(FakeHttpClient::json(200, self::cronPayload()), FakeHttpClient::json(200, self::cronPayload()));
        $client = new CronsClient(['apiKey' => null], $t);

        $client->create('asst_123', ['schedule' => '0 0 * * *', 'onRunCompleted' => 'keep', 'enabled' => true]);
        $client->createForThread('thread_123', 'asst_123', ['schedule' => '0 0 * * *', 'multitaskStrategy' => 'reject']);

        $this->assertSame('http://localhost:8123/runs/crons', $t->requests[0]['url']);
        $this->assertSame(
            ['schedule' => '0 0 * * *', 'on_run_completed' => 'keep', 'enabled' => true, 'assistant_id' => 'asst_123'],
            $t->bodyOf(0),
        );
        $this->assertSame('http://localhost:8123/threads/thread_123/runs/crons', $t->requests[1]['url']);
        $this->assertSame(
            ['schedule' => '0 0 * * *', 'multitask_strategy' => 'reject', 'assistant_id' => 'asst_123'],
            $t->bodyOf(1),
        );
    }

    public function testDeleteUsesTheDeleteVerb(): void
    {
        $t = $this->transport(new HttpResponse(204, [], ''));

        (new CronsClient(['apiKey' => null], $t))->delete('cron_123');

        $this->assertSame('DELETE', $t->requests[0]['method']);
        $this->assertSame('http://localhost:8123/runs/crons/cron_123', $t->requests[0]['url']);
        $this->assertNull($t->requests[0]['body']);
    }
}
