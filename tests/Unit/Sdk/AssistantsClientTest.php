<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk;

use LangChain\Tests\Unit\Sdk\Support\RecordingTransport;
use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Testing\FakeHttpClient;
use LangGraph\Sdk\AssistantsClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `client/assistants/index.test.ts` (the `assistants.search` block), plus the other
 * endpoints of `client/assistants/index.ts`, which upstream leaves untested.
 *
 * The fixture is upstream's own `assistantPayload()`, byte for byte, as the server shapes it.
 */
#[CoversClass(AssistantsClient::class)]
final class AssistantsClientTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function assistantPayload(): array
    {
        return [
            'assistant_id' => 'asst_123',
            'graph_id' => 'graph_123',
            'config' => ['configurable' => ['foo' => 'bar']],
            'context' => ['foo' => 'bar'],
            'created_at' => '2024-01-01T00:00:00Z',
            'metadata' => ['env' => 'test'],
            'version' => 1,
            'name' => 'My Assistant',
            'description' => 'Example',
            'updated_at' => '2024-01-02T00:00:00Z',
        ];
    }

    public function testSearchReturnsTheListByDefault(): void
    {
        $assistant = self::assistantPayload();
        $http = new FakeHttpClient([FakeHttpClient::json(200, [$assistant])]);

        $result = (new AssistantsClient(['apiKey' => 'test-api-key'], $http))->search(['limit' => 3]);

        $this->assertSame([$assistant], $result);
        $this->assertSame('http://localhost:8123/assistants/search', $http->requests[0]['url']);
        $this->assertSame(['limit' => 3, 'offset' => 0], $http->lastRequestBody());
    }

    public function testSearchCanIncludePaginationMetadata(): void
    {
        $assistant = self::assistantPayload();
        $http = new FakeHttpClient([FakeHttpClient::json(200, [$assistant], ['X-Pagination-Next' => '42'])]);

        $result = (new AssistantsClient(['apiKey' => 'test-api-key'], $http))->search(['includePagination' => true]);

        $this->assertSame(['assistants' => [$assistant], 'next' => '42'], $result);
    }

    public function testSearchPaginationNextIsNullWhenTheServerSendsNoCursor(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, [])]);

        $result = (new AssistantsClient(['apiKey' => null], $http))->search(['includePagination' => true]);

        $this->assertSame(['assistants' => [], 'next' => null], $result);
    }

    public function testSearchSendsTheFullFilterAsSnakeCase(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, [])]);

        (new AssistantsClient(['apiKey' => null], $http))->search([
            'graphId' => 'g', 'name' => 'n', 'metadata' => ['a' => 1], 'limit' => 5, 'offset' => 10,
            'sortBy' => 'created_at', 'sortOrder' => 'desc', 'select' => ['assistant_id', 'name'],
        ]);

        $this->assertSame([
            'graph_id' => 'g', 'name' => 'n', 'metadata' => ['a' => 1], 'limit' => 5, 'offset' => 10,
            'sort_by' => 'created_at', 'sort_order' => 'desc', 'select' => ['assistant_id', 'name'],
        ], $http->lastRequestBody());
    }

    public function testGetGraphSendsXrayAsAQueryParameter(): void
    {
        $t = new RecordingTransport([FakeHttpClient::json(200, ['nodes' => [], 'edges' => []])]);
        $client = new AssistantsClient(['apiKey' => null], $t);

        $graph = $client->getGraph('asst_1', ['xray' => 2]);
        $client->getGraph('asst_1');

        $this->assertSame(['nodes' => [], 'edges' => []], $graph);
        $this->assertSame('GET', $t->requests[0]['method']);
        $this->assertSame('http://localhost:8123/assistants/asst_1/graph?xray=2', $t->requests[0]['url']);
        $this->assertSame('http://localhost:8123/assistants/asst_1/graph', $t->requests[1]['url']);
    }

    public function testGetAndGetSchemas(): void
    {
        $t = new RecordingTransport([
            FakeHttpClient::json(200, self::assistantPayload()),
            FakeHttpClient::json(200, ['graph_id' => 'graph_123', 'state_schema' => ['type' => 'object']]),
        ]);
        $client = new AssistantsClient(['apiKey' => null], $t);

        $this->assertSame('asst_123', $client->get('asst_123')['assistant_id']);
        $this->assertSame('object', $client->getSchemas('asst_123')['state_schema']['type']);
        $this->assertSame('http://localhost:8123/assistants/asst_123/schemas', $t->requests[1]['url']);
    }

    public function testGetSubgraphsHonoursNamespaceAndRecurse(): void
    {
        $t = new RecordingTransport();
        $client = new AssistantsClient(['apiKey' => null], $t);

        $client->getSubgraphs('a1');
        $client->getSubgraphs('a1', ['namespace' => 'inner', 'recurse' => true]);

        $this->assertSame('http://localhost:8123/assistants/a1/subgraphs', $t->requests[0]['url']);
        $this->assertSame('http://localhost:8123/assistants/a1/subgraphs/inner?recurse=true', $t->requests[1]['url']);
    }

    public function testCreateMapsCamelCaseToTheWireAndOmitsUnsetFields(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, self::assistantPayload())]);

        $created = (new AssistantsClient(['apiKey' => null], $http))->create([
            'graphId' => 'graph_123', 'assistantId' => 'asst_123', 'ifExists' => 'do_nothing', 'name' => 'My Assistant',
        ]);

        $this->assertSame('asst_123', $created['assistant_id']);
        $this->assertSame([
            'graph_id' => 'graph_123', 'assistant_id' => 'asst_123', 'if_exists' => 'do_nothing', 'name' => 'My Assistant',
        ], $http->lastRequestBody());
    }

    public function testUpdateUsesPatch(): void
    {
        $t = new RecordingTransport([FakeHttpClient::json(200, self::assistantPayload())]);

        (new AssistantsClient(['apiKey' => null], $t))->update('asst_123', ['graphId' => 'g2', 'description' => 'd']);

        $this->assertSame('PATCH', $t->requests[0]['method']);
        $this->assertSame(['graph_id' => 'g2', 'description' => 'd'], $t->bodyOf());
    }

    public function testDeleteSendsTheDeleteThreadsFlagInTheQueryString(): void
    {
        $t = new RecordingTransport([new HttpResponse(204, [], ''), new HttpResponse(204, [], '')]);
        $client = new AssistantsClient(['apiKey' => null], $t);

        $client->delete('a1');
        $client->delete('a1', ['deleteThreads' => true]);

        $this->assertSame('DELETE', $t->requests[0]['method']);
        $this->assertSame('http://localhost:8123/assistants/a1?delete_threads=false', $t->requests[0]['url']);
        $this->assertSame('http://localhost:8123/assistants/a1?delete_threads=true', $t->requests[1]['url']);
    }

    public function testCountVersionsAndSetLatest(): void
    {
        $http = new FakeHttpClient([
            new HttpResponse(200, [], '7'),
            FakeHttpClient::json(200, [self::assistantPayload()]),
            FakeHttpClient::json(200, self::assistantPayload()),
        ]);
        $client = new AssistantsClient(['apiKey' => null], $http);

        $this->assertSame(7, $client->count(['graphId' => 'g', 'metadata' => ['k' => 'v']]));
        $this->assertEquals(['graph_id' => 'g', 'metadata' => ['k' => 'v']], $http->lastRequestBody());

        $this->assertCount(1, $client->getVersions('a1'));
        $this->assertSame(['limit' => 10, 'offset' => 0], $http->lastRequestBody());
        $this->assertSame('http://localhost:8123/assistants/a1/versions', $http->requests[1]['url']);

        $client->setLatest('a1', 3);
        $this->assertSame(['version' => 3], $http->lastRequestBody());
        $this->assertSame('http://localhost:8123/assistants/a1/latest', $http->requests[2]['url']);
    }
}
