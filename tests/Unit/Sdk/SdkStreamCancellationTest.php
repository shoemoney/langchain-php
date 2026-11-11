<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk;

use LangChain\Tests\Unit\Sdk\Support\RecordingTransport;
use LangChain\Tests\Unit\Sdk\Support\StreamingTransport;
use LangChain\Utils\Http\HttpResponse;
use LangGraph\Sdk\Client;
use LangGraph\Sdk\Utils\MethodHttpClient;
use LangGraph\Sdk\Utils\StreamResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PHP polling regressions for SDK client/base.ts streamWithRetry (timeoutMs:null,
 * final onRequest init passed to fetch), utils/async_caller.ts fetch retry, and
 * utils/stream.ts's pre-request abort check. Blocking IO remains uninterruptible.
 */
final class SdkStreamCancellationTest extends TestCase
{
    /** @return iterable<string, array{string, bool}> */
    public static function streams(): iterable
    {
        foreach (['runs.stream', 'runs.joinStream', 'threads.joinStream'] as $operation) {
            foreach ([false, true] as $incremental) {
                yield $operation . '/' . ($incremental ? 'incremental' : 'buffered') => [$operation, $incremental];
            }
        }
    }

    #[DataProvider('streams')]
    public function testAbortDuringOpeningBackoffPreventsAnotherConnection(string $operation, bool $incremental): void
    {
        $transport = self::transport($incremental);
        $aborted = false;
        $slept = [];
        $client = new Client(['apiUrl' => 'http://localhost:8000', 'apiKey' => null,
            'callerOptions' => ['maxRetries' => 3, 'sleep' => static function (int $ms) use (&$aborted, &$slept): void {
                $slept[] = $ms;
                $aborted = true;
            }]], $transport);
        $signal = static function () use (&$aborted): bool { return $aborted; };

        $caught = null;
        try {
            iterator_to_array(self::stream($client, $operation, $signal), false);
        } catch (\RuntimeException $error) {
            $caught = $error;
        }
        self::assertNotNull($caught, 'Cancellation during opening backoff raises AbortError.');
        self::assertSame('AbortError', $caught->getMessage());
        self::assertCount(1, $transport->requests, 'cancellation prevents the next wire attempt');
        self::assertCount(1, $slept);
    }

    #[DataProvider('streams')]
    public function testAlreadyAbortedCallerReturnsWithoutOpening(string $operation, bool $incremental): void
    {
        $transport = self::transport($incremental);
        $hooks = 0;
        $client = new Client(['apiUrl' => 'http://localhost:8000', 'apiKey' => null,
            'onRequest' => static function (string $url, array $init) use (&$hooks): array { ++$hooks; return $init; }], $transport);

        self::assertSame([], iterator_to_array(self::stream($client, $operation, static fn (): bool => true), false));
        self::assertSame([], $transport->requests);
        self::assertSame(0, $hooks);
    }

    #[DataProvider('streams')]
    public function testFinalHookSignalIsPolledBeforeOpening(string $operation, bool $incremental): void
    {
        $transport = self::transport($incremental);
        $client = new Client(['apiUrl' => 'http://localhost:8000', 'apiKey' => null,
            'callerOptions' => ['sleep' => static function (): void {}],
            'onRequest' => static function (string $url, array $init): array {
                $init['signal'] = static fn (): bool => true;
                return $init;
            }], $transport);
        $caught = null;
        try {
            iterator_to_array(self::stream($client, $operation, static fn (): bool => false), false);
        } catch (\RuntimeException $error) {
            $caught = $error;
        }
        self::assertNotNull($caught, 'The final init signal guards the transport.');
        self::assertSame('AbortError', $caught->getMessage());
        self::assertSame([], $transport->requests);
    }

    #[DataProvider('streams')]
    public function testUnabortedOpeningRetryKeepsStreamingTimeoutDisabled(string $operation, bool $incremental): void
    {
        $transport = self::transport($incremental);
        $now = 0;
        $prepared = [];
        $slept = 0;
        $client = new Client(['apiUrl' => 'http://localhost:8000', 'apiKey' => null, 'timeoutMs' => 1,
            'clock' => static function () use (&$now): int { return $now; },
            'callerOptions' => ['sleep' => static function () use (&$now, &$slept): void { $now += 100000; ++$slept; }],
            'onRequest' => static function (string $url, array $init) use (&$prepared): array { $prepared[] = $init; return $init; }], $transport);

        $parts = iterator_to_array(self::stream($client, $operation, static fn (): bool => false), false);
        self::assertSame(['one'], array_column($parts, 'id'));
        self::assertCount(2, $transport->requests);
        self::assertSame(1, $slept);
        self::assertNull($prepared[0]['timeoutMs']);
        self::assertFalse($prepared[0]['signal'](), 'the configured one millisecond deadline stays disabled');
        if (!$incremental) {
            self::assertNull($transport->requests[0]['timeout']);
            self::assertNull($transport->requests[1]['timeout']);
        }
    }

    /** @param callable(): bool $signal
     *  @return \Generator<int, array{id: string|null, event: string, data: mixed}>
     */
    private static function stream(Client $client, string $operation, callable $signal): \Generator
    {
        return match ($operation) {
            'runs.stream' => $client->runs->stream('thread', 'assistant', ['signal' => $signal]),
            'runs.joinStream' => $client->runs->joinStream('thread', 'run', ['signal' => $signal]),
            'threads.joinStream' => $client->threads->joinStream('thread', ['signal' => $signal]),
        };
    }

    private static function transport(bool $incremental): MethodHttpClient
    {
        $chunks = StreamingTransport::chunksOf([['id' => 'one', 'event' => 'values', 'data' => ['ok' => true]]]);
        if ($incremental) {
            return new StreamingTransport([
                static fn (): StreamResponse => new StreamResponse(new HttpResponse(503), ['busy']),
                static fn (): StreamResponse => StreamingTransport::sse($chunks),
            ]);
        }

        return new RecordingTransport([new HttpResponse(503, [], 'busy'), new HttpResponse(200, ['content-type' => 'text/event-stream'], implode('', $chunks))]);
    }
}
