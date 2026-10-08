<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk;

use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Http\HttpResponse;
use LangGraph\Sdk\Utils\AsyncCaller;
use LangGraph\Sdk\Utils\ConnectionError;
use LangGraph\Sdk\Utils\HttpError;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `utils/async_caller.test.ts`.
 *
 * The three "message is undefined/null/not an Error" cases cannot exist in PHP (an exception always
 * has a string message, and only Throwables can be thrown), so they become the empty-message case.
 * Backoff is injected as a recorder, so no test sleeps.
 */
#[CoversClass(AsyncCaller::class)]
final class AsyncCallerTest extends TestCase
{
    /** @var list<int> */
    private array $slept = [];

    private function caller(array $params = []): AsyncCaller
    {
        return new AsyncCaller($params + ['sleep' => function (int $ms): void {
            $this->slept[] = $ms;
        }]);
    }

    public function testAnErrorWithAnEmptyMessageStillFailsCleanly(): void
    {
        $calls = 0;
        $caller = $this->caller(['maxRetries' => 0]);

        try {
            $caller->call(function () use (&$calls): never {
                $calls++;
                throw new \RuntimeException();
            });
            $this->fail('expected the error to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('', $e->getMessage());
        }
        $this->assertSame(1, $calls);
    }

    public function testReturnsTheResultOfASuccessfulCall(): void
    {
        $calls = 0;
        $result = $this->caller(['maxRetries' => 3])->call(function () use (&$calls): array {
            $calls++;

            return ['data' => 'success'];
        });

        $this->assertSame(['data' => 'success'], $result);
        $this->assertSame(1, $calls);
    }

    public function testPassesArgumentsToTheCallable(): void
    {
        $result = $this->caller()->call(static fn (int $a, string $b): string => "{$a}-{$b}", 42, 'test');

        $this->assertSame('42-test', $result);
    }

    public function testFetchUsesTheCustomFetch(): void
    {
        $seen = [];
        $caller = $this->caller(['maxRetries' => 0, 'fetch' => function (string $url, array $init) use (&$seen): HttpResponse {
            $seen[] = [$url, $init];

            return new HttpResponse(200, [], '{}');
        }]);

        $caller->fetch('http://example.com/api', ['method' => 'GET']);

        $this->assertSame([['http://example.com/api', ['method' => 'GET']]], $seen);
    }

    public function testFetchRejectsNonOkResponses(): void
    {
        $caller = $this->caller([
            'maxRetries' => 0,
            'fetch' => static fn (): HttpResponse => new HttpResponse(500, [], 'Server error'),
        ]);

        $this->expectException(HttpError::class);
        $this->expectExceptionMessageMatches('/HTTP 500/');
        $caller->fetch('http://example.com/api');
    }

    public function testFetchWithoutATransportIsAConfigurationError(): void
    {
        $this->expectException(\LogicException::class);
        $this->caller()->fetch('http://example.com');
    }

    public function testCallWithOptionsWorksWithoutASignal(): void
    {
        $this->assertSame('success', $this->caller(['maxRetries' => 3])->callWithOptions([], static fn (): string => 'success'));
    }

    public function testCallWithOptionsPassesArgumentsThroughWhenThereIsNoSignal(): void
    {
        $this->assertSame(42, $this->caller()->callWithOptions([], static fn (int $x): int => $x * 2, 21));
    }

    public function testCallWithOptionsRefusesToStartWhenTheSignalHasFired(): void
    {
        $calls = 0;

        try {
            $this->caller()->callWithOptions(['signal' => static fn (): bool => true], function () use (&$calls): void {
                $calls++;
            });
            $this->fail('expected AbortError');
        } catch (\RuntimeException $e) {
            $this->assertSame('AbortError', $e->getMessage());
        }
        $this->assertSame(0, $calls, 'AbortError is never retried');
        $this->assertSame([], $this->slept);
    }

    public function testAFailedResponseBecomesAnHttpErrorWithStatusAndText(): void
    {
        $caller = $this->caller(['maxRetries' => 0]);

        try {
            $caller->call(static function (): never {
                throw HttpError::fromResponse(new HttpResponse(404, [], 'Resource not found'));
            });
            $this->fail('expected HttpError');
        } catch (HttpError $e) {
            $this->assertSame(404, $e->status);
            $this->assertSame('HTTP 404: Resource not found', $e->getMessage());
        }
    }

    public function testErrorsAreHttpPrefixedWithTheBodyText(): void
    {
        $caller = $this->caller([
            'maxRetries' => 0,
            'fetch' => static fn (): HttpResponse => new HttpResponse(400, [], 'Invalid parameters'),
        ]);

        $this->expectExceptionObject(new HttpError(400, 'Invalid parameters'));
        $caller->fetch('http://x');
    }

    public function testTheFailedResponseIsAttachedWithoutAnOnFailedResponseHook(): void
    {
        $caller = $this->caller([
            'maxRetries' => 0,
            'fetch' => static fn (): HttpResponse => new HttpResponse(404, [], 'missing'),
        ]);

        try {
            $caller->fetch('http://x');
            $this->fail('expected HttpError');
        } catch (HttpError $e) {
            $this->assertSame(404, $e->status);
            $this->assertSame('missing', $e->response?->body);
        }
    }

    public function testFailuresThatCarryNoResponseKeepNone(): void
    {
        $caller = $this->caller(['maxRetries' => 0]);

        foreach ([new \RuntimeException('boom'), new HttpException('fetch failed', 0)] as $failure) {
            try {
                $caller->call(static function () use ($failure): never {
                    throw $failure;
                });
                $this->fail('expected a failure');
            } catch (\Throwable $e) {
                $this->assertNotInstanceOf(HttpError::class, $e);
            }
        }
    }

    public function testConnectionFailuresAreRetriedThenBecomeAConnectionError(): void
    {
        $calls = 0;
        $caller = $this->caller(['maxRetries' => 1]);

        try {
            $caller->call(function () use (&$calls): never {
                $calls++;
                throw new \RuntimeException('fetch failed: connect ECONNREFUSED');
            });
            $this->fail('expected ConnectionError');
        } catch (ConnectionError $e) {
            $this->assertStringContainsString('Unable to connect to LangGraph server', $e->getMessage());
            $this->assertStringContainsString('ECONNREFUSED', $e->getMessage());
        }
        $this->assertSame(2, $calls);
        $this->assertCount(1, $this->slept);
    }

    public function testATransportFailureWithNoStatusCountsAsAConnectionFailure(): void
    {
        $caller = $this->caller(['maxRetries' => 0]);

        $this->expectException(ConnectionError::class);
        $caller->call(static function (): never {
            throw new HttpException('Request to http://x failed: cURL error 7', 0);
        });
    }

    public function testCallerErrorStatusesAreNotRetried(): void
    {
        foreach ([400, 401, 402, 403, 404, 405, 406, 407, 408, 409, 422] as $status) {
            $calls = 0;
            $caller = $this->caller(['maxRetries' => 3, 'fetch' => function () use (&$calls, $status): HttpResponse {
                $calls++;

                return new HttpResponse($status, [], 'no');
            }]);

            try {
                $caller->fetch('http://x');
                $this->fail('expected HttpError');
            } catch (HttpError) {
                $this->assertSame(1, $calls, "status {$status} must not be retried");
            }
        }
    }

    public function testServerErrorsAreRetriedAndTheHookSeesEachFailedResponse(): void
    {
        $calls = 0;
        $hooked = [];
        $caller = $this->caller([
            'maxRetries' => 2,
            'onFailedResponseHook' => function (HttpResponse $r) use (&$hooked): bool {
                $hooked[] = $r->status;

                return false;
            },
            'fetch' => function () use (&$calls): HttpResponse {
                $calls++;

                return $calls < 3 ? new HttpResponse(503, [], 'Service down') : new HttpResponse(200, [], '{"ok":true}');
            },
        ]);

        $response = $caller->fetch('http://x');

        $this->assertSame(200, $response->status);
        $this->assertSame(3, $calls);
        $this->assertSame([503, 503], $hooked);
    }

    public function testAnExhaustedServerErrorSurfacesAsHttpError(): void
    {
        $caller = $this->caller([
            'maxRetries' => 0,
            'fetch' => static fn (): HttpResponse => new HttpResponse(503, [], 'Service down'),
        ]);

        $this->expectExceptionMessageMatches('/HTTP 503/');
        $caller->fetch('http://x');
    }

    public function testBackoffGrowsExponentiallyFromTheMinimumTimeout(): void
    {
        $caller = $this->caller(['maxRetries' => 3, 'minTimeoutMs' => 100, 'randomize' => false]);

        try {
            $caller->call(static function (): never {
                throw new \RuntimeException('flaky');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame([100, 200, 400], $this->slept);
    }

    public function testConcurrencyAndRetryDefaultsAreExposed(): void
    {
        $caller = new AsyncCaller([]);

        $this->assertSame(INF, $caller->maxConcurrency());
        $this->assertSame(4, $caller->maxRetries());
        $this->assertSame(1, (new AsyncCaller(['maxConcurrency' => 1]))->maxConcurrency());
    }

    public function testASequentialCallerRunsCallsInOrder(): void
    {
        // JS proves maxConcurrency:1 serialises; PHP is always serial, so this pins the ordering.
        $caller = $this->caller(['maxConcurrency' => 1]);
        $order = [];
        $results = [];
        foreach ([1, 2, 3] as $id) {
            $results[] = $caller->call(static function (int $i) use (&$order): int {
                $order[] = $i;

                return $i;
            }, $id);
        }

        $this->assertSame([1, 2, 3], $results);
        $this->assertSame([1, 2, 3], $order);
    }
}
