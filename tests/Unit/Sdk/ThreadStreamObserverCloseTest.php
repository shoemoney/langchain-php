<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk;

use LangChain\Tests\Unit\Sdk\Support\RecordingTransport;
use LangChain\Tests\Unit\Sdk\Support\StreamingTransport;
use LangChain\Utils\Http\HttpResponse;
use LangGraph\Sdk\Stream\ProtocolError;
use LangGraph\Sdk\Stream\ThreadStream;
use LangGraph\Sdk\ThreadsClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ThreadStream::class)]
final class ThreadStreamObserverCloseTest extends TestCase
{
    private const FIRST = ['type' => 'event', 'seq' => 1, 'event_id' => 'before-close', 'method' => 'values',
        'params' => ['namespace' => [], 'timestamp' => 123, 'data' => ['n' => 1]]];
    private const NEXT = ['type' => 'event', 'seq' => 2, 'event_id' => 'after-close', 'method' => 'values',
        'params' => ['namespace' => [], 'timestamp' => 123, 'data' => ['n' => 2]]];
    private const SENTINEL = ['type' => 'event', 'seq' => 3, 'event_id' => 'unread-sentinel', 'method' => 'values',
        'params' => ['namespace' => [], 'timestamp' => 123, 'data' => ['n' => 999]]];

    public static function closingRows(): iterable
    {
        foreach (['missing-id', 'null-id'] as $id) {
            foreach (['same-chunk', 'following-chunk'] as $layout) {
                foreach (['raw', 'output', 'direct'] as $driver) {
                    yield "$id/$layout/$driver" => [$id, $layout, $driver];
                }
            }
        }
    }

    public static function firstErrorRows(): iterable
    {
        foreach (['missing-id', 'null-id'] as $id) {
            foreach (['raw', 'output'] as $driver) {
                yield "$id/$driver" => [$id, $driver];
            }
        }
    }

    public static function continuationRows(): iterable
    {
        foreach (['missing-id', 'null-id', 'id-zero', 'id-777'] as $id) {
            foreach (['raw', 'output', 'direct'] as $driver) {
                yield "$id/$driver" => [$id, $driver];
            }
        }
    }

    public static function fatalRows(): iterable
    {
        foreach ([false, true] as $close) {
            foreach (['raw', 'output'] as $driver) {
                yield ($close ? 'closing' : 'nonclosing') . "/$driver" => [$close, $driver];
            }
        }
    }

    #[DataProvider('closingRows')]
    public function testClosingObserverSettlesWithoutAdvancing(string $id, string $layout, string $driver): void
    {
        $error = self::errorFrame($id);
        $chunks = $layout === 'same-chunk'
            ? [self::encode(self::FIRST) . self::encode($error) . self::encode(self::NEXT), self::encode(self::SENTINEL)]
            : [self::encode(self::FIRST), self::encode($error) . self::encode(self::NEXT), self::encode(self::SENTINEL)];
        $produced = $released = 0;
        [$thread, $http] = self::stream($chunks, $produced, $released);
        $observed = [];
        $unsubscribe = $thread->onError(static function (\Throwable $failure) use ($thread, &$observed): void {
            $observed[] = $failure;
            $thread->close();
        });
        if ($driver === 'output') {
            self::assertSame(['n' => 1], $thread->output());
        } else {
            $raw = $thread->subscribe('values');
            if ($driver === 'direct') {
                self::assertTrue($thread->pull());
                self::assertFalse($thread->pull());
                $snapshot = [$produced, $released, $observed, $thread->errors(), $http->requests];
                self::assertFalse($thread->pull());
                self::assertSame($snapshot, [$produced, $released, $observed, $thread->errors(), $http->requests]);
            }
            self::assertSame([self::FIRST], iterator_to_array($raw, false));
        }
        self::assertSame($layout === 'same-chunk' ? 1 : 2, $produced);
        self::assertSame(1, $released);
        self::assertProtocolObservation($thread, $observed, $error);
        self::assertRequest($http, $driver);
        $snapshot = [$produced, $released, $observed, $thread->errors(), $http->requests];
        $thread->close();
        $thread->close();
        $unsubscribe();
        $unsubscribe();
        foreach ([static fn () => $thread->subscribe('values'), static fn () => $thread->run->start(['input' => ['n' => 0]])] as $work) {
            try {
                $work();
                self::fail('Closed work must reject before making another request.');
            } catch (\RuntimeException $failure) {
                self::assertSame('ThreadStream closed', $failure->getMessage());
            }
            self::assertSame($snapshot, [$produced, $released, $observed, $thread->errors(), $http->requests]);
        }
    }

    #[DataProvider('firstErrorRows')]
    public function testFirstErrorCloseDoesNotFabricateAValue(string $id, string $driver): void
    {
        $error = self::errorFrame($id);
        $produced = $released = 0;
        [$thread, $http] = self::stream([self::encode($error) . self::encode(self::NEXT), self::encode(self::SENTINEL)], $produced, $released);
        $observed = [];
        $thread->onError(static function (\Throwable $failure) use ($thread, &$observed): void {
            $observed[] = $failure;
            $thread->close();
        });
        if ($driver === 'output') {
            self::assertNull($thread->output());
        } else {
            self::assertSame([], iterator_to_array($thread->subscribe('values'), false));
        }
        self::assertSame([1, 1], [$produced, $released]);
        self::assertProtocolObservation($thread, $observed, $error);
        self::assertRequest($http, $driver);
    }

    public function testClosingThenThrowingObserverStillSettlesNormally(): void
    {
        $error = self::errorFrame('missing-id');
        $produced = $released = 0;
        [$thread, $http] = self::stream([self::encode(self::FIRST) . self::encode($error) . self::encode(self::NEXT), self::encode(self::SENTINEL)], $produced, $released);
        $observed = [];
        $thread->onError(static function (\Throwable $failure) use ($thread, &$observed): void {
            $observed[] = $failure;
            $thread->close();
            throw new \RuntimeException('observer exception after close');
        });
        self::assertSame([self::FIRST], iterator_to_array($thread->subscribe('values'), false));
        self::assertProtocolObservation($thread, $observed, $error);
        self::assertRequest($http, 'raw');
        self::assertSame([1, 1], [$produced, $released]);
        $snapshot = [$produced, $released, $observed, $thread->errors(), $http->requests];
        self::assertFalse($thread->pull());
        self::assertSame($snapshot, [$produced, $released, $observed, $thread->errors(), $http->requests]);
    }

    #[DataProvider('continuationRows')]
    public function testNonclosingAndUnmatchedErrorsPreserveContinuation(string $id, string $driver): void
    {
        $error = self::errorFrame($id);
        $matched = $id === 'id-zero' || $id === 'id-777';
        $produced = $released = 0;
        [$thread, $http] = self::stream([self::encode(self::FIRST) . self::encode($error) . self::encode(self::NEXT)], $produced, $released);
        $observed = [];
        $thread->onError(static function (\Throwable $failure) use ($thread, &$observed, $matched): void {
            $observed[] = $failure;
            if ($matched) {
                $thread->close();
            }
        });
        if ($driver === 'output') {
            self::assertSame(['n' => 2], $thread->output());
        } else {
            $raw = $thread->subscribe('values');
            if ($driver === 'direct') {
                self::assertTrue($thread->pull());
                self::assertTrue($thread->pull());
                self::assertFalse($thread->pull());
            }
            self::assertSame([self::FIRST, self::NEXT], iterator_to_array($raw, false));
        }
        self::assertSame([1, 1], [$produced, $released]);
        if ($matched) {
            self::assertSame([], $observed);
            self::assertSame([], $thread->errors());
        } else {
            self::assertProtocolObservation($thread, $observed, $error);
        }
        self::assertRequest($http, $driver);
    }

    public function testThrowingNonclosingObserverIsIsolatedAndDeduplicated(): void
    {
        $error = self::errorFrame('missing-id');
        $produced = $released = $throwing = 0;
        [$thread, $http] = self::stream([self::encode(self::FIRST) . self::encode($error) . self::encode(self::NEXT)], $produced, $released);
        $listener = static function () use (&$throwing): void {
            ++$throwing;
            throw new \RuntimeException('ordinary observer exception');
        };
        $unsubscribe = $thread->onError($listener);
        $duplicate = $thread->onError($listener);
        $observed = [];
        $thread->onError(static function (\Throwable $failure) use (&$observed): void { $observed[] = $failure; });
        self::assertSame(['n' => 2], $thread->output());
        self::assertSame(1, $throwing);
        self::assertProtocolObservation($thread, $observed, $error);
        self::assertRequest($http, 'output');
        self::assertSame([1, 1], [$produced, $released]);
        $snapshot = [$throwing, $produced, $released, $thread->errors(), $http->requests];
        $unsubscribe();
        $unsubscribe();
        $duplicate();
        $duplicate();
        self::assertSame($snapshot, [$throwing, $produced, $released, $thread->errors(), $http->requests]);
    }

    public function testCommandErrorRemainsSynchronousAndDoesNotNotifyObservers(): void
    {
        $frame = ['type' => 'error', 'id' => 1, 'error' => 'command_rejected', 'message' => 'literal command rejection'];
        $http = new RecordingTransport([new HttpResponse(200, [], json_encode($frame, JSON_THROW_ON_ERROR))]);
        $thread = (new ThreadsClient(['apiUrl' => 'https://unit.test', 'apiKey' => null], $http))
            ->stream('thread', ['assistantId' => 'agent', 'startingCommandId' => 1, 'maxReconnectAttempts' => 0]);
        $observed = [];
        $thread->onError(static function (\Throwable $failure) use (&$observed): void { $observed[] = $failure; });
        try {
            $thread->run->start(['input' => ['n' => 0]]);
            self::fail('A command error must remain synchronous.');
        } catch (ProtocolError $failure) {
            self::assertSame($frame, $failure->response);
            self::assertSame('command_rejected', $failure->protocolCode);
            self::assertSame('literal command rejection', $failure->getMessage());
        }
        self::assertSame([], $observed);
        self::assertSame([], $thread->errors());
        self::assertCount(1, $http->requests);
        self::assertSame('POST', $http->requests[0]['method']);
        self::assertSame('https://unit.test/threads/thread/commands', $http->requests[0]['url']);
        self::assertSame(['id' => 1, 'method' => 'run.start', 'params' => ['input' => ['n' => 0], 'assistant_id' => 'agent']],
            json_decode($http->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('fatalRows')]
    public function testFatalBodyErrorOverridesEvenObserverClose(bool $close, string $driver): void
    {
        $fatal = new \RuntimeException('literal fatal body read');
        $produced = $released = 0;
        [$thread, $http] = self::stream([self::encode(self::FIRST)], $produced, $released, $fatal);
        $observed = [];
        $thread->onError(static function (\Throwable $failure) use ($thread, &$observed, $close): void {
            $observed[] = $failure;
            if ($close) {
                $thread->close();
            }
        });
        $reader = $driver === 'raw' ? $thread->subscribe('values') : $thread->values();
        $delivered = [];
        try {
            foreach ($reader as $value) {
                $delivered[] = $value;
            }
            self::fail('Fatal reads must rethrow their original instance.');
        } catch (\RuntimeException $failure) {
            self::assertSame($fatal, $failure);
        }
        self::assertSame($driver === 'raw' ? [self::FIRST] : [['n' => 1]], $delivered);
        self::assertSame([$fatal], $observed);
        self::assertSame([$fatal], $thread->errors());
        self::assertSame([1, 1], [$produced, $released]);
        self::assertRequest($http, $driver);
        // The raw queue has already been drained; fail overrides its clean close.
        try {
            iterator_to_array($reader, false);
            self::fail('A drained failed reader must retain the identical fatal error.');
        } catch (\RuntimeException $failure) {
            self::assertSame($fatal, $failure);
        }
        self::assertSame([$fatal], $observed);
        self::assertSame([$fatal], $thread->errors());
        self::assertSame([1, 1], [$produced, $released]);
        self::assertCount(1, $http->requests);
    }

    private static function errorFrame(string $id): array
    {
        $frame = ['type' => 'error', 'error' => 'observer_stop', 'message' => 'close requested by observer'];
        return match ($id) {
            'null-id' => $frame + ['id' => null],
            'id-zero' => $frame + ['id' => 0],
            'id-777' => $frame + ['id' => 777],
            default => $frame,
        };
    }

    private static function encode(array $frame): string
    {
        return "event: message\n" . 'data: ' . json_encode($frame, JSON_THROW_ON_ERROR) . "\n\n";
    }

    private static function stream(array $chunks, int &$produced, int &$released, ?\Throwable $fatal = null): array
    {
        $http = new StreamingTransport([static function () use ($chunks, &$produced, &$released, $fatal) {
            return StreamingTransport::sse((static function () use ($chunks, &$produced, &$released, $fatal): \Generator {
                try {
                    foreach ($chunks as $chunk) {
                        ++$produced;
                        yield $chunk;
                    }
                    if ($fatal !== null) {
                        throw $fatal;
                    }
                } finally {
                    ++$released;
                }
            })());
        }]);
        $thread = (new ThreadsClient(['apiUrl' => 'https://unit.test', 'apiKey' => null], $http))
            ->stream('thread', ['assistantId' => 'agent', 'maxReconnectAttempts' => 0]);
        return [$thread, $http];
    }

    private static function assertProtocolObservation(ThreadStream $thread, array $observed, array $frame): void
    {
        self::assertCount(1, $observed);
        self::assertInstanceOf(ProtocolError::class, $observed[0]);
        self::assertSame($frame, $observed[0]->response);
        self::assertSame('observer_stop', $observed[0]->protocolCode);
        self::assertSame('close requested by observer', $observed[0]->getMessage());
        self::assertSame($observed, $thread->errors());
    }

    private static function assertRequest(StreamingTransport $http, string $driver): void
    {
        self::assertCount(1, $http->requests);
        self::assertSame('POST', $http->requests[0]['method']);
        self::assertSame('https://unit.test/threads/thread/stream/events', $http->requests[0]['url']);
        $body = json_decode($http->requests[0]['body'], true, 512, JSON_THROW_ON_ERROR);
        if ($driver === 'output') {
            self::assertSame(['channels' => ['values', 'lifecycle', 'input']], $body);
        } else {
            self::assertSame(['channels' => ['values']], $body);
        }
    }
}
