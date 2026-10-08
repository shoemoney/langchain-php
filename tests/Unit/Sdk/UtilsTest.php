<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk;

use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use LangChain\Utils\Http\HttpException;
use LangGraph\Sdk\Schema;
use LangGraph\Sdk\Utils\Env;
use LangGraph\Sdk\Utils\ErrorUtils;
use LangGraph\Sdk\Utils\GuzzleMethodHttpClient;
use LangGraph\Sdk\Utils\Headers;
use LangGraph\Sdk\Utils\HttpError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The small utilities `utils/error.ts`, `utils/env.ts` and `schema.ts` port to, and the Guzzle
 * adapter. Upstream has no tests for them; these pin the behaviour read from the source.
 */
#[CoversClass(ErrorUtils::class)]
#[CoversClass(Env::class)]
#[CoversClass(Headers::class)]
#[CoversClass(Schema::class)]
#[CoversClass(HttpError::class)]
#[CoversClass(GuzzleMethodHttpClient::class)]
final class UtilsTest extends TestCase
{
    protected function tearDown(): void
    {
        Env::override(null);
    }

    public function testIsErrorRecognisesThrowablesOnly(): void
    {
        $this->assertTrue(ErrorUtils::isError(new \RuntimeException('x')));
        $this->assertTrue(ErrorUtils::isError(new \TypeError('x')));
        $this->assertFalse(ErrorUtils::isError('boom'));
        $this->assertFalse(ErrorUtils::isError(['message' => 'boom']));
    }

    public function testIsNetworkErrorMatchesTransportFailureMessages(): void
    {
        foreach (['fetch failed', 'NetworkError when attempting', 'Connection reset', 'error sending request for url', 'Load failed', 'terminated'] as $message) {
            $this->assertTrue(ErrorUtils::isNetworkError(new \TypeError($message)), $message);
        }
        $this->assertTrue(ErrorUtils::isNetworkError(new HttpException('Request to http://x failed: cURL error 7: Connection refused', 0)));
    }

    public function testIsNetworkErrorReadsTheCauseMessage(): void
    {
        $error = new \TypeError('boom', 0, new \RuntimeException('other side closed'));
        $this->assertTrue(ErrorUtils::isNetworkError($error));
        $this->assertTrue(ErrorUtils::isNetworkError(new \TypeError('x', 0, new \RuntimeException('Socket hang up'))));
    }

    public function testIsNetworkErrorRejectsEverythingElse(): void
    {
        $this->assertFalse(ErrorUtils::isNetworkError(new \RuntimeException('fetch failed')), 'wrong class');
        $this->assertFalse(ErrorUtils::isNetworkError(new HttpException('server said no', 500)), 'a response arrived');
        $this->assertFalse(ErrorUtils::isNetworkError(new \TypeError('unrelated')));
        $this->assertFalse(ErrorUtils::isNetworkError('fetch failed'));
    }

    public function testEnvReadsTheRealEnvironmentUnlessOverridden(): void
    {
        putenv('LGSDK_TEST_VAR=hello');
        $this->assertSame('hello', Env::getEnvironmentVariable('LGSDK_TEST_VAR'));
        putenv('LGSDK_TEST_VAR');
        $this->assertNull(Env::getEnvironmentVariable('LGSDK_TEST_VAR'));

        Env::override(static fn (string $n): ?string => 'x-' . $n);
        $this->assertSame('x-A', Env::getEnvironmentVariable('A'));
        Env::override(null);
        $this->assertNull(Env::getEnvironmentVariable('LGSDK_NEVER_SET'));
    }

    public function testHeadersMergeLowercasesSortsAndLaterObjectsReplace(): void
    {
        $merged = Headers::merge(['X-B' => '1', 'X-A' => 'first'], null, ['x-a' => 'second']);

        $this->assertSame(['x-a' => 'second', 'x-b' => '1'], $merged);
    }

    public function testHeadersTuplesAppendWhereObjectsReplace(): void
    {
        $this->assertSame(['x-a' => 'one, two'], Headers::merge([['x-a', 'one'], ['x-a', 'two']]));
        $this->assertSame(['x-a' => 'two'], Headers::merge(['x-a' => 'one'], ['x-a' => 'two']));
        $this->assertSame(['x-a' => 'one, two, three'], Headers::merge(['x-a' => 'one'], [['x-a', 'two'], ['x-a', 'three']]));
    }

    public function testHeadersRejectANonStringName(): void
    {
        $this->expectException(\TypeError::class);
        $this->expectExceptionMessage('Expected header name to be a string, got int');
        Headers::merge(['value-without-a-name']);
    }

    public function testSchemaConstantsMirrorTheClosedStringUnions(): void
    {
        $this->assertSame(['pending', 'running', 'error', 'success', 'timeout', 'interrupted'], Schema::RUN_STATUS);
        $this->assertSame(['idle', 'busy', 'interrupted', 'error'], Schema::THREAD_STATUS);
        $this->assertSame(['asc', 'desc'], Schema::SORT_ORDER);
        $this->assertContains('state_updated_at', Schema::THREAD_SORT_BY);
        $this->assertContains('next_run_date', Schema::CRON_SORT_BY);
        $this->assertContains('on_run_completed', Schema::CRON_SELECT_FIELD);
        $this->assertContains('kwargs', Schema::RUN_SELECT_FIELD);
        $this->assertContains('description', Schema::ASSISTANT_SELECT_FIELD);
        $this->assertContains('interrupts', Schema::THREAD_SELECT_FIELD);
        $this->assertSame(['interrupt', 'rollback'], Schema::CANCEL_ACTION);
        $this->assertSame(['reject', 'interrupt', 'rollback', 'enqueue'], Schema::MULTITASK_STRATEGY);
        $this->assertSame(['assistant_id', 'graph_id', 'name', 'created_at', 'updated_at'], Schema::ASSISTANT_SORT_BY);
    }

    public function testHttpErrorKeepsTheResponseOnlyWhenAsked(): void
    {
        $response = new \LangChain\Utils\Http\HttpResponse(502, ['X-Req' => 'r'], 'bad gateway');

        $bare = HttpError::fromResponse($response);
        $full = HttpError::fromResponse($response, true);

        $this->assertNull($bare->response);
        $this->assertSame('r', $full->response?->header('x-req'));
        $this->assertSame('HTTP 502: bad gateway', $full->getMessage());
        $this->assertSame(502, $full->getCode());
    }

    public function testGuzzleMethodClientSendsAnyVerbWithoutThrowingOnErrorStatuses(): void
    {
        $history = [];
        $stack = HandlerStack::create(new MockHandler([new Response(204), new Response(500, ['X-A' => 'b'], 'oops')]));
        $stack->push(Middleware::history($history));
        $client = new GuzzleMethodHttpClient(['handler' => $stack]);

        $first = $client->request('patch', 'http://h/x', ['content-type' => 'application/json'], '{"a":1}', 5.0);
        $second = $client->request('DELETE', 'http://h/y', []);

        $this->assertSame(204, $first->status);
        $this->assertSame(500, $second->status);
        $this->assertSame('oops', $second->body);
        $this->assertSame('PATCH', $history[0]['request']->getMethod());
        $this->assertSame('{"a":1}', (string) $history[0]['request']->getBody());
        $this->assertSame('DELETE', $history[1]['request']->getMethod());
        $this->assertSame('', (string) $history[1]['request']->getBody());
    }

    public function testGuzzleMethodClientPostDelegatesToTheSharedTransport(): void
    {
        $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{"ok":true}')]));
        $client = new GuzzleMethodHttpClient(['handler' => $stack]);

        $response = $client->post('http://h/p', [], '{}');

        $this->assertSame(['ok' => true], $response->json());
    }

    public function testGuzzleMethodClientWrapsTransportFailuresInHttpException(): void
    {
        $stack = HandlerStack::create(new MockHandler([new \GuzzleHttp\Exception\ConnectException('refused', new \GuzzleHttp\Psr7\Request('GET', 'http://h'))]));
        $client = new GuzzleMethodHttpClient(['handler' => $stack]);

        try {
            $client->request('GET', 'http://h/z', []);
            $this->fail('expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(0, $e->status);
            $this->assertStringContainsString('refused', $e->getMessage());
        }
    }
}
