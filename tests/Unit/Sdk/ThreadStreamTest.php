<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk;

use LangChain\Tests\Unit\Sdk\Support\RecordingTransport;
use LangChain\Utils\Http\HttpResponse;
use LangGraph\Sdk\Stream\ProtocolError;
use LangGraph\Sdk\ThreadsClient;
use PHPUnit\Framework\TestCase;

final class ThreadStreamTest extends TestCase
{
    public static function event(string $method, mixed $data, int $seq = 1, array $ns = [], ?string $id = null): array
    {
        return ['type' => 'event', 'seq' => $seq, 'event_id' => $id ?? 'event-' . $seq, 'method' => $method,
            'params' => ['namespace' => $ns, 'timestamp' => 123, 'data' => $data]];
    }

    public static function sse(array $events): HttpResponse
    {
        return new HttpResponse(200, ['content-type' => 'text/event-stream'], implode('', array_map(
            static fn ($event): string => "event: message\n" . 'data: ' . json_encode($event) . "\n\n", $events)));
    }

    public function testBothOverloadsGenerateIdsAndRequireAssistantWithoutNetworkCalls(): void
    {
        $http = new RecordingTransport();
        $client = new ThreadsClient(['apiUrl' => 'https://unit.test', 'apiKey' => null], $http);
        $first = $client->stream(['assistantId' => 'agent']);
        $second = $client->stream(null, ['assistantId' => 'agent']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $first->threadId);
        self::assertNotSame($first->threadId, $second->threadId);
        self::assertSame('fixed', $client->stream('fixed', ['assistantId' => 'agent'])->threadId);
        self::assertSame([], $http->requests);
        $this->expectException(\InvalidArgumentException::class);
        $client->stream([]);
    }

    public function testRunStartBindsAssistantFoldsForkAndCommitsBeforeAnySubscription(): void
    {
        $http = new RecordingTransport([new HttpResponse(200, [], json_encode(['type' => 'response', 'id' => 10,
            'result' => ['run_id' => 'run'], 'meta' => ['applied_through_seq' => 4]]))]);
        $thread = (new ThreadsClient(['apiUrl' => 'https://unit.test/prefix', 'apiKey' => null], $http))
            ->stream('thread', ['assistantId' => 'agent', 'startingCommandId' => 10]);
        self::assertSame(['run_id' => 'run'], $thread->run->start(['input' => ['x' => 1], 'assistant_id' => 'override',
            'forkFrom' => 'fork', 'config' => ['configurable' => ['checkpoint_id' => 'old', 'keep' => true]], 'langsmith_replica' => 'replica']));
        self::assertCount(1, $http->requests);
        self::assertSame('https://unit.test/prefix/threads/thread/commands', $http->requests[0]['url']);
        $command = $http->bodyOf();
        self::assertSame(10, $command['id']);
        self::assertSame('run.start', $command['method']);
        self::assertSame('agent', $command['params']['assistant_id']);
        self::assertSame(['checkpoint_id' => 'fork', 'keep' => true], $command['params']['config']['configurable']);
        self::assertArrayNotHasKey('forkFrom', $command['params']);
        self::assertSame('replica', $command['params']['langsmith_replica']);
        self::assertSame(4, $thread->ordering['lastAppliedThroughSeq']);
    }

    public function testNamedCustomOverloadsUnwrapButObjectFormKeepsEventEnvelope(): void
    {
        $event = self::event('custom', ['name' => 'activity', 'payload' => ['ok' => true]]);
        $http = new RecordingTransport([self::sse([$event])]);
        $thread = (new ThreadsClient(['apiUrl' => 'https://unit.test', 'apiKey' => null], $http))
            ->stream('thread', ['assistantId' => 'agent', 'maxReconnectAttempts' => 0]);
        $plain = $thread->subscribe('custom:activity');
        $object = $thread->subscribe(['channels' => ['custom:activity']]);
        self::assertSame([['ok' => true]], iterator_to_array($plain, false));
        self::assertSame([$event], iterator_to_array($object, false));
        self::assertSame(['channels' => ['custom:activity']], $http->bodyOf());
        self::assertSame('POST', $http->requests[0]['method']);
        self::assertSame('https://unit.test/threads/thread/stream/events', $http->requests[0]['url']);
    }

    public function testUnsubscribeAndCloseAreIdempotentAndCloseRejectsNewWork(): void
    {
        $http = new RecordingTransport();
        $thread = (new ThreadsClient(['apiKey' => null], $http))->stream('thread', ['assistantId' => 'agent']);
        $sub = $thread->subscribe('values');
        $sub->unsubscribe();
        $sub->unsubscribe();
        self::assertSame([], iterator_to_array($sub));
        $thread->close();
        $thread->close();
        self::assertSame([], $http->requests);
        $this->expectExceptionMessage('ThreadStream closed');
        $thread->run->start();
    }

    public function testProtocolCommandErrorsRetainServerCodeAndMessage(): void
    {
        $response = ['type' => 'error', 'id' => 1, 'error' => 'invalid_request', 'message' => 'bad command'];
        $http = new RecordingTransport([new HttpResponse(200, [], json_encode($response))]);
        $thread = (new ThreadsClient(['apiKey' => null], $http))->stream('thread', ['assistantId' => 'agent']);
        $seen = [];
        $thread->onError(static function ($error) use (&$seen): void { $seen[] = $error; });
        try {
            $thread->run->start();
            self::fail('Expected protocol error.');
        } catch (ProtocolError $error) {
            self::assertSame('invalid_request', $error->protocolCode);
            self::assertSame('bad command', $error->getMessage());
            self::assertSame($response, $error->response);
        }
        self::assertSame([], $seen);
        self::assertSame([], $thread->errors());
    }

    public function testUnsolicitedErrorsNotifyObserversAndKeepValuesStreamingWhileUnmatchedIdsAreIgnored(): void
    {
        $error = ['type' => 'error', 'error' => 'transient', 'message' => 'side-channel'];
        $http = new RecordingTransport([self::sse([
            self::event('values', ['n' => 1]), $error, [...$error, 'id' => 777], [...$error, 'id' => null],
            self::event('values', ['n' => 2], 2), self::event('lifecycle', ['event' => 'completed'], 3),
        ])]);
        $thread = (new ThreadsClient(['apiKey' => null], $http))->stream('thread', ['assistantId' => 'agent', 'maxReconnectAttempts' => 0]);
        $seen = [];
        $thread->onError(static function (): void { throw new \RuntimeException('bad observer'); });
        $observer = static function (\Throwable $error) use (&$seen): void { $seen[] = $error; };
        $off = $thread->onError($observer);
        $thread->onError($observer); // Source Set preserves callback identity.
        $removed = $thread->onError(static function (): void { self::fail('Unsubscribed observer was invoked.'); });
        $removed();
        $removed();
        self::assertSame(['n' => 2], $thread->output());
        self::assertCount(2, $seen);
        self::assertSame($seen, $thread->errors());
        self::assertSame($seen, $thread->errors);
        self::assertInstanceOf(ProtocolError::class, $seen[0]);
        self::assertSame('transient', $seen[0]->protocolCode);
        $off();
        $thread->close();
    }

    public function testConcurrentRawIteratorsConsumeEachQueuedEventExactlyOnce(): void
    {
        $events = array_map(static fn ($n) => self::event('updates', $n, $n), [1, 2, 3, 4]);
        $thread = (new ThreadsClient(['apiKey' => null], new RecordingTransport([self::sse($events)])))
            ->stream('thread', ['assistantId' => 'agent', 'maxReconnectAttempts' => 0]);
        $raw = $thread->subscribe('updates');
        $a = $raw->getIterator();
        $b = $raw->getIterator();
        $a->rewind();
        $b->rewind();
        $seen = [$a->current()['params']['data'], $b->current()['params']['data']];
        $a->next();
        $b->next();
        $seen[] = $a->current()['params']['data'];
        $seen[] = $b->current()['params']['data'];
        self::assertSame([1, 2, 3, 4], $seen);
        $a->next();
        $b->next();
        self::assertFalse($a->valid());
        self::assertFalse($b->valid());
        self::assertSame([], iterator_to_array($raw, false));
    }

    public function testNamespaceFiltersNormalizeDynamicSuffixAndRespectRelativeDepth(): void
    {
        $events = [self::event('values', 1, 1, ['child:uuid']), self::event('values', 2, 2, ['child:uuid', 'deep:0']),
            self::event('values', 3, 3, ['other:0'])];
        $http = new RecordingTransport([self::sse($events)]);
        $thread = (new ThreadsClient(['apiKey' => null], $http))->stream('thread', ['assistantId' => 'agent', 'maxReconnectAttempts' => 0]);
        $sub = $thread->subscribe('values', ['namespaces' => [['child']], 'depth' => 0]);
        self::assertSame([$events[0]], iterator_to_array($sub, false));
        self::assertSame(['channels' => ['values'], 'namespaces' => [['child']], 'depth' => 0], $http->bodyOf());
    }
}
