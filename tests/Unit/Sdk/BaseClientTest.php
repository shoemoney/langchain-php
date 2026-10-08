<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk;

use LangChain\Tests\Unit\Sdk\Support\ExposedThreadsClient;
use LangChain\Tests\Unit\Sdk\Support\RecordingTransport;
use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Testing\FakeHttpClient;
use LangGraph\Sdk\BaseClient;
use LangGraph\Sdk\Client;
use LangGraph\Sdk\Utils\Env;
use LangGraph\Sdk\Utils\HttpError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `client/base.test.ts`.
 *
 * Skipped: the `describe.each(["global", "mocked"])` axis (which fetch implementation wins) — PHP has
 * no global `fetch` to override, the transport is the constructor argument. Skipped: `createRuns`,
 * which needs `client.runs` (WP-23b). The `Headers`-instance test becomes the tuple/multi-value one.
 */
#[CoversClass(BaseClient::class)]
final class BaseClientTest extends TestCase
{
    private const NO_SLEEP = ['maxRetries' => 0];

    protected function tearDown(): void
    {
        Env::override(null);
    }

    private function threads(RecordingTransport $t, array $config = []): ExposedThreadsClient
    {
        return new ExposedThreadsClient($config + ['apiKey' => 'test-api-key'], $t);
    }

    public function testThreadsUpdateRequestsAMinimalResponseWhenReturnMinimalIsTrue(): void
    {
        $t = new RecordingTransport([new HttpResponse(204, [], '')]);

        $result = $this->threads($t)->update('thread_123', ['metadata' => ['foo' => 'bar'], 'returnMinimal' => true]);

        $this->assertNull($result);
        $this->assertSame('PATCH', $t->requests[0]['method']);
        $this->assertStringContainsString('/threads/thread_123', $t->requests[0]['url']);
        $this->assertSame('return=minimal', $t->requests[0]['headers']['prefer']);
        $this->assertSame(['metadata' => ['foo' => 'bar']], $t->bodyOf());
    }

    public function testHeaderNamesWithConflictingCasingAreMerged(): void
    {
        $t = new RecordingTransport();
        $this->threads($t)->rawFetch('/test', ['headers' => ['X-Api-Key' => 'custom-value']]);

        $this->assertSame('custom-value', $t->requests[0]['headers']['x-api-key']);
        $this->assertCount(1, array_filter(array_keys($t->requests[0]['headers']), static fn ($k) => $k === 'x-api-key'));
    }

    public function testHeadersFromMultipleSourcesAreMergedAndNullDeletes(): void
    {
        $t = new RecordingTransport();
        $client = $this->threads($t, ['defaultHeaders' => ['x-default' => 'default-value', 'x-override' => 'default-value']]);

        $client->rawFetch('/test', ['headers' => ['x-custom' => 'custom-value', 'x-override' => 'custom-value']]);
        $this->assertSame('test-api-key', $t->requests[0]['headers']['x-api-key']);
        $this->assertSame('default-value', $t->requests[0]['headers']['x-default']);
        $this->assertSame('custom-value', $t->requests[0]['headers']['x-custom']);
        $this->assertSame('custom-value', $t->requests[0]['headers']['x-override']);

        $client->rawFetch('/test', ['headers' => ['x-null' => null, 'x-empty' => '']]);
        $second = $t->requests[1]['headers'];
        $this->assertSame('test-api-key', $second['x-api-key']);
        $this->assertSame('default-value', $second['x-default']);
        $this->assertArrayNotHasKey('x-null', $second);
        $this->assertSame('', $second['x-empty']);

        // null is a delete, so it also removes a DEFAULT header.
        $client->rawFetch('/test', ['headers' => ['x-default' => null]]);
        $this->assertArrayNotHasKey('x-default', $t->requests[2]['headers']);
    }

    public function testRepeatedHeaderValuesAreJoined(): void
    {
        $t = new RecordingTransport();
        $this->threads($t)->rawFetch('/test', ['headers' => ['x-custom' => 'custom-value', 'x-multi' => ['value1', 'value2']]]);

        $this->assertSame('value1, value2', $t->requests[0]['headers']['x-multi']);
        $this->assertSame('custom-value', $t->requests[0]['headers']['x-custom']);
    }

    public function testAnArrayOfHeaderTuplesAppendsRepeatedNames(): void
    {
        $t = new RecordingTransport();
        $client = $this->threads($t, ['defaultHeaders' => ['x-custom' => 'custom-value']]);

        $client->rawFetch('/test', ['headers' => [['x-multi', 'value1'], ['x-multi', 'value2']]]);

        $this->assertSame('value1, value2', $t->requests[0]['headers']['x-multi']);
        $this->assertSame('custom-value', $t->requests[0]['headers']['x-custom']);
        $this->assertSame('test-api-key', $t->requests[0]['headers']['x-api-key']);
    }

    public function testConcurrentIdenticalGetStateReadsCoalesceIntoOneRequest(): void
    {
        $t = new RecordingTransport();
        $client = new Client(['apiKey' => 'k'], $t);

        $t->runOverlapping([
            fn () => $client->threads->getState('t-state'),
            fn () => $client->threads->getState('t-state'),
        ]);

        $this->assertSame(1, $t->inFlightSnapshot);
        $this->assertCount(1, $t->requests);
        $this->assertSame(0, BaseClient::inFlightReadCount());
    }

    public function testReadsForDifferentThreadsAreNotCoalesced(): void
    {
        $t = new RecordingTransport();
        $client = new Client(['apiKey' => 'k'], $t);

        $t->runOverlapping([
            fn () => $client->threads->getState('t-a'),
            fn () => $client->threads->getState('t-b'),
        ]);

        $this->assertSame(2, $t->inFlightSnapshot);
    }

    public function testASettledReadIsRefetchedNotCached(): void
    {
        $t = new RecordingTransport();
        $client = new Client(['apiKey' => 'k'], $t);

        $client->threads->getState('t-resettle');
        $this->assertSame(0, BaseClient::inFlightReadCount());
        $client->threads->getState('t-resettle');

        $this->assertCount(2, $t->requests);
    }

    public function testConcurrentIdenticalGetHistoryReadsCoalesceIntoOneRequest(): void
    {
        $t = new RecordingTransport([FakeHttpClient::json(200, [['values' => []]])]);
        $client = new Client(['apiKey' => 'k'], $t);

        $results = $t->runOverlapping([
            fn () => $client->threads->getHistory('t-hist', ['limit' => 20]),
            fn () => $client->threads->getHistory('t-hist', ['limit' => 20]),
        ]);

        $this->assertSame(1, $t->inFlightSnapshot);
        $this->assertSame($results[0], $results[1], 'the waiter receives the leader\'s body');
    }

    public function testASuppliedSignalDisablesCoalescing(): void
    {
        $t = new RecordingTransport();
        $client = new Client(['apiKey' => 'k'], $t);
        $signal = static fn (): bool => false;

        $t->runOverlapping([
            fn () => $client->threads->getState('t-signal', null, ['signal' => $signal]),
            fn () => $client->threads->getState('t-signal', null, ['signal' => $signal]),
        ]);

        $this->assertSame(2, $t->inFlightSnapshot);
    }

    public function testReadsUnderDifferentCredentialsAreNotCoalesced(): void
    {
        // Same URL and thread, different tenant: sharing would hand tenant B a response fetched with
        // tenant A's credentials.
        $t = new RecordingTransport();
        $a = new Client(['apiKey' => 'tenant-a'], $t);
        $b = new Client(['apiKey' => 'tenant-b'], $t);

        $t->runOverlapping([
            fn () => $a->threads->getState('t-shared'),
            fn () => $b->threads->getState('t-shared'),
        ]);

        $this->assertSame(2, $t->inFlightSnapshot);
    }

    public function testReadsAcrossClientsCoalesceOnlyWhenCredentialsMatch(): void
    {
        $t = new RecordingTransport();
        $a = new Client(['apiKey' => 'same'], $t);
        $b = new Client(['apiKey' => 'same'], $t);

        $t->runOverlapping([
            fn () => $a->threads->getState('t-match'),
            fn () => $b->threads->getState('t-match'),
        ]);

        $this->assertSame(1, $t->inFlightSnapshot);
    }

    public function testAnOnRequestHookDisablesCoalescing(): void
    {
        // The hook can inject per-request auth that is invisible when the key is computed.
        $t = new RecordingTransport();
        $client = new Client(['apiKey' => 'k', 'onRequest' => static fn (string $url, array $init): array => $init], $t);

        $t->runOverlapping([
            fn () => $client->threads->getState('t-hook'),
            fn () => $client->threads->getState('t-hook'),
        ]);

        $this->assertSame(2, $t->inFlightSnapshot);
    }

    public function testAFailedSharedReadFailsEveryWaiterAndLeavesNothingInFlight(): void
    {
        $t = new RecordingTransport([new HttpResponse(404, [], 'gone')]);
        $client = new Client(['apiKey' => 'k'], $t);

        $outcomes = $t->runOverlapping([
            static function () use ($client) {
                try {
                    return $client->threads->getState('t-gone');
                } catch (HttpError $e) {
                    return $e->status;
                }
            },
            static function () use ($client) {
                try {
                    return $client->threads->getState('t-gone');
                } catch (HttpError $e) {
                    return $e->status;
                }
            },
        ]);

        $this->assertSame([404, 404], $outcomes);
        $this->assertSame(1, $t->inFlightSnapshot);
        $this->assertSame(0, BaseClient::inFlightReadCount());
    }

    public function testApiKeyIsAutoLoadedFromTheEnvironmentWhenOmitted(): void
    {
        Env::override(static fn (string $n): ?string => $n === 'LANGGRAPH_API_KEY' ? 'env-api-key' : null);
        $t = new RecordingTransport();

        (new ExposedThreadsClient([], $t))->rawFetch('/test');
        (new ExposedThreadsClient(['apiKey' => ''], $t))->rawFetch('/test');

        $this->assertSame('env-api-key', $t->requests[0]['headers']['x-api-key']);
        $this->assertSame('env-api-key', $t->requests[1]['headers']['x-api-key'], 'an empty key falls through to the environment');
    }

    public function testApiKeyAutoLoadIsSkippedWhenApiKeyIsNull(): void
    {
        Env::override(static fn (string $n): ?string => $n === 'LANGGRAPH_API_KEY' ? 'env-api-key' : null);
        $t = new RecordingTransport();

        (new ExposedThreadsClient(['apiKey' => null], $t))->rawFetch('/test');

        $this->assertArrayNotHasKey('x-api-key', $t->requests[0]['headers']);
    }

    public function testAnExplicitApiKeyBeatsTheEnvironment(): void
    {
        Env::override(static fn (string $n): ?string => $n === 'LANGGRAPH_API_KEY' ? 'env-api-key' : null);
        $t = new RecordingTransport();

        (new ExposedThreadsClient(['apiKey' => 'explicit-api-key'], $t))->rawFetch('/test');

        $this->assertSame('explicit-api-key', $t->requests[0]['headers']['x-api-key']);
    }

    public function testApiKeyEnvironmentPrecedenceAndQuoteStripping(): void
    {
        Env::override(static fn (string $n): ?string => match ($n) {
            'LANGSMITH_API_KEY' => '  "smith-key"  ',
            'LANGCHAIN_API_KEY' => 'chain-key',
            default => null,
        });

        $this->assertSame('smith-key', BaseClient::getApiKey());
        Env::override(static fn (string $n): ?string => null);
        $this->assertNull(BaseClient::getApiKey());
    }

    public function testPrepareFetchOptionsBuildsTheUrlQueryAndBody(): void
    {
        $client = new ExposedThreadsClient(['apiUrl' => 'http://example.test:9/', 'apiKey' => null], new RecordingTransport());

        [$url, $init] = $client->rawPrepare('/x', [
            'method' => 'POST',
            'json' => ['a' => 1],
            'params' => ['skip' => null, 'flag' => true, 'ids' => ['a', 'b', null, 3], 'q' => 'a b', 'map' => ['k' => 'v']],
        ]);

        $this->assertSame('http://example.test:9/x?flag=true&ids=a&ids=b&ids=3&q=a+b&map=%7B%22k%22%3A%22v%22%7D', $url);
        $this->assertSame('{"a":1}', $init['body']);
        $this->assertSame('application/json', $init['headers']['content-type']);
    }

    public function testApiUrlDefaultsAndPathPrefixSurvives(): void
    {
        $t = new RecordingTransport();
        (new ExposedThreadsClient(['apiKey' => null], $t))->rawFetch('/ok');
        (new ExposedThreadsClient(['apiKey' => null, 'apiUrl' => 'https://h.test/api/proxy/'], $t))->rawFetch('/ok');

        $this->assertSame('http://localhost:8123/ok', $t->requests[0]['url']);
        $this->assertSame('https://h.test/api/proxy/ok', $t->requests[1]['url']);
    }

    public function testAnEmptyJsonPayloadIsSentAsAnObject(): void
    {
        $t = new RecordingTransport();
        $this->threads($t)->rawFetch('/x', ['method' => 'POST', 'json' => []]);

        $this->assertSame('{}', $t->requests[0]['body']);
    }

    public function testTimeoutComesFromConfigAndAPerRequestNullDisablesIt(): void
    {
        $t = new RecordingTransport();
        $client = $this->threads($t, ['timeoutMs' => 1500]);

        $client->rawFetch('/a');
        $client->rawFetch('/b', ['timeoutMs' => 250]);
        $client->rawFetch('/c', ['timeoutMs' => null]);

        $this->assertSame(1.5, $t->requests[0]['timeout']);
        $this->assertSame(0.25, $t->requests[1]['timeout']);
        $this->assertNull($t->requests[2]['timeout']);
    }

    public function testOnRequestMayReplaceThePreparedRequest(): void
    {
        $t = new RecordingTransport();
        $client = $this->threads($t, ['onRequest' => static function (string $url, array $init): array {
            $init['headers']['authorization'] = 'Bearer fresh';

            return $init;
        }]);

        $client->rawFetch('/hooked');

        $this->assertSame('Bearer fresh', $t->requests[0]['headers']['authorization']);
    }

    public function testWithResponseReturnsTheBodyAndTheResponse(): void
    {
        $t = new RecordingTransport([new HttpResponse(200, ['X-Pagination-Next' => '9'], '[1]')]);

        [$body, $response] = $this->threads($t)->rawFetch('/p', ['withResponse' => true]);

        $this->assertSame([1], $body);
        $this->assertSame('9', $response->header('x-pagination-next'));
    }

    public function testNoContentResponsesDecodeToNull(): void
    {
        $t = new RecordingTransport([new HttpResponse(202, [], ''), new HttpResponse(204, [], '')]);
        $client = $this->threads($t);

        $this->assertNull($client->rawFetch('/a'));
        $this->assertNull($client->rawFetch('/b'));
    }

    public function testANonSuccessStatusRaisesHttpErrorWithBodyAndResponse(): void
    {
        $t = new RecordingTransport([new HttpResponse(404, [], 'no such thread')]);

        try {
            $this->threads($t, ['callerOptions' => self::NO_SLEEP])->rawFetch('/threads/x');
            $this->fail('expected HttpError');
        } catch (HttpError $e) {
            $this->assertSame(404, $e->status);
            $this->assertSame('HTTP 404: no such thread', $e->getMessage());
            $this->assertSame(404, $e->response?->status);
        }
        $this->assertCount(1, $t->requests, '404 is the caller\'s fault and is not retried');
    }

    public function testAVerbOtherThanPostNeedsAMethodCapableTransport(): void
    {
        $client = new ExposedThreadsClient(['apiKey' => null], new FakeHttpClient([FakeHttpClient::json(200, [])]));

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('PATCH');
        $client->update('t', ['metadata' => []]);
    }

    public function testPostWorksOverAPlainHttpClientSeam(): void
    {
        $fake = new FakeHttpClient([new HttpResponse(200, [], '3')]);
        $client = new ExposedThreadsClient(['apiKey' => 'k', 'timeoutMs' => 2000], $fake);

        $this->assertSame(3, $client->count(['status' => 'idle']));

        $this->assertSame('http://localhost:8123/threads/count', $fake->requests[0]['url']);
        $this->assertSame('k', $fake->requests[0]['headers']['x-api-key']);
        $this->assertSame(['status' => 'idle'], $fake->lastRequestBody());
    }

    public function testRunMetadataIsReadFromContentLocation(): void
    {
        $r = static fn (?string $loc): HttpResponse => new HttpResponse(200, $loc === null ? [] : ['Content-Location' => $loc], '');

        $this->assertSame(['run_id' => 'r1', 'thread_id' => 't1'], BaseClient::getRunMetadataFromResponse($r('/threads/t1/runs/r1')));
        $this->assertSame(['run_id' => 'r2', 'thread_id' => null], BaseClient::getRunMetadataFromResponse($r('/runs/r2')));
        $this->assertNull(BaseClient::getRunMetadataFromResponse($r(null)));
        $this->assertNull(BaseClient::getRunMetadataFromResponse($r('/somewhere/else')));
    }
}
