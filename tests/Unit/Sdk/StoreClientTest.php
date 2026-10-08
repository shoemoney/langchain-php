<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk;

use LangChain\Tests\Unit\Sdk\Support\RecordingTransport;
use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Testing\FakeHttpClient;
use LangGraph\Sdk\StoreClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `client/store/index.ts` has no upstream test file; these pin its behaviour from the source.
 */
#[CoversClass(StoreClient::class)]
final class StoreClientTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function apiItem(): array
    {
        return [
            'namespace' => ['users', 'ada'],
            'key' => 'prefs',
            'value' => ['theme' => 'dark'],
            'created_at' => '2024-01-01T00:00:00Z',
            'updated_at' => '2024-01-02T00:00:00Z',
        ];
    }

    public function testPutItemSendsIndexAndTtlOnlyWhenGiven(): void
    {
        $t = new RecordingTransport([new HttpResponse(204, [], ''), new HttpResponse(204, [], '')]);
        $client = new StoreClient(['apiKey' => null], $t);

        $client->putItem(['users', 'ada'], 'prefs', ['theme' => 'dark']);
        $client->putItem(['users', 'ada'], 'prefs', ['theme' => 'dark'], ['index' => false, 'ttl' => null]);

        $this->assertSame('PUT', $t->requests[0]['method']);
        $this->assertSame('http://localhost:8123/store/items', $t->requests[0]['url']);
        $this->assertSame(['namespace' => ['users', 'ada'], 'key' => 'prefs', 'value' => ['theme' => 'dark']], $t->bodyOf(0));
        $this->assertSame(false, $t->bodyOf(1)['index']);
        $this->assertArrayHasKey('ttl', $t->bodyOf(1));
        $this->assertNull($t->bodyOf(1)['ttl']);
    }

    public function testNamespaceLabelsWithPeriodsAreRejectedBeforeAnyRequest(): void
    {
        $t = new RecordingTransport();
        $client = new StoreClient(['apiKey' => null], $t);

        foreach ([
            fn () => $client->putItem(['a.b'], 'k', []),
            fn () => $client->getItem(['ok', 'a.b'], 'k'),
            fn () => $client->deleteItem(['a.b'], 'k'),
        ] as $call) {
            try {
                $call();
                $this->fail('expected InvalidArgumentException');
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString("cannot contain periods ('.')", $e->getMessage());
            }
        }
        $this->assertSame([], $t->requests);
    }

    public function testGetItemJoinsTheNamespaceWithPeriodsAndAddsCamelCaseTimestamps(): void
    {
        $t = new RecordingTransport([FakeHttpClient::json(200, self::apiItem())]);

        $item = (new StoreClient(['apiKey' => null], $t))->getItem(['users', 'ada'], 'prefs', ['refreshTtl' => true]);

        $this->assertSame('http://localhost:8123/store/items?namespace=users.ada&key=prefs&refresh_ttl=true', $t->requests[0]['url']);
        $this->assertSame('2024-01-01T00:00:00Z', $item['createdAt']);
        $this->assertSame('2024-01-02T00:00:00Z', $item['updatedAt']);
        $this->assertSame('2024-01-01T00:00:00Z', $item['created_at'], 'the snake_case fields are kept');
    }

    public function testGetItemReturnsNullWhenTheServerSendsNothing(): void
    {
        $t = new RecordingTransport([new HttpResponse(200, [], 'null')]);

        $this->assertNull((new StoreClient(['apiKey' => null], $t))->getItem(['u'], 'missing'));
    }

    public function testDeleteItemSendsTheNamespaceAndKeyAsABody(): void
    {
        $t = new RecordingTransport([new HttpResponse(204, [], '')]);

        (new StoreClient(['apiKey' => null], $t))->deleteItem(['users'], 'prefs');

        $this->assertSame('DELETE', $t->requests[0]['method']);
        $this->assertSame(['namespace' => ['users'], 'key' => 'prefs'], $t->bodyOf());
    }

    public function testSearchItemsMapsTimestampsOnEveryItemAndDefaultsPaging(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, ['items' => [self::apiItem() + ['score' => 0.5]]])]);

        $result = (new StoreClient(['apiKey' => null], $http))->searchItems(['users'], ['query' => 'dark', 'filter' => ['theme' => 'dark']]);

        $this->assertSame('http://localhost:8123/store/items/search', $http->requests[0]['url']);
        $this->assertEquals([
            'namespace_prefix' => ['users'], 'filter' => ['theme' => 'dark'], 'limit' => 10, 'offset' => 0, 'query' => 'dark',
        ], $http->lastRequestBody());
        $this->assertSame('2024-01-01T00:00:00Z', $result['items'][0]['createdAt']);
        $this->assertSame(0.5, $result['items'][0]['score']);
    }

    public function testListNamespacesDefaultsToLimit100(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, ['namespaces' => [['users', 'ada']]])]);

        $result = (new StoreClient(['apiKey' => null], $http))->listNamespaces(['prefix' => ['users'], 'maxDepth' => 2]);

        $this->assertSame([['users', 'ada']], $result['namespaces']);
        $this->assertEquals(['prefix' => ['users'], 'max_depth' => 2, 'limit' => 100, 'offset' => 0], $http->lastRequestBody());
        $this->assertSame('http://localhost:8123/store/namespaces', $http->requests[0]['url']);
    }
}
