<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk;

use LangChain\Messages\AIMessage;
use LangChain\Tests\Unit\Sdk\Support\RecordingTransport;
use LangGraph\Sdk\ThreadsClient;
use PHPUnit\Framework\TestCase;

final class ThreadStreamProjectionsTest extends TestCase
{
    private static function thread(array $events): \LangGraph\Sdk\Stream\ThreadStream
    {
        return (new ThreadsClient(['apiKey' => null], new RecordingTransport([ThreadStreamTest::sse($events)])))
            ->stream('thread', ['assistantId' => 'agent', 'maxReconnectAttempts' => 0]);
    }

    public function testValuesReplayAndOutputCoerceSerializedMessages(): void
    {
        $thread = self::thread([ThreadStreamTest::event('values', ['count' => 1]),
            ThreadStreamTest::event('values', ['count' => 2, 'messages' => [['type' => 'ai', 'content' => 'done']]], 2),
            ThreadStreamTest::event('lifecycle', ['event' => 'completed'], 3)]);
        $values = $thread->values();
        $snapshots = iterator_to_array($values, false);
        self::assertCount(2, $snapshots);
        self::assertSame(2, $thread->output()['count']);
        self::assertInstanceOf(AIMessage::class, $thread->output()['messages'][0]);
        self::assertSame($snapshots, iterator_to_array($values, false));
        self::assertSame($values, $thread->values);
    }

    public function testMessageHandlesDriveDeltasReasoningUsageAndFinalContent(): void
    {
        $data = [['event' => 'message-start', 'id' => 'message', 'run_id' => 'run'],
            ['event' => 'content-block-start', 'index' => 0, 'content' => ['type' => 'text', 'text' => 'H']],
            ['event' => 'content-block-delta', 'index' => 0, 'delta' => ['type' => 'text-delta', 'text' => 'i']],
            ['event' => 'content-block-delta', 'index' => 1, 'content' => ['type' => 'reasoning', 'reasoning' => 'Think']],
            ['event' => 'message-finish', 'usage' => ['total_tokens' => 3], 'metadata' => ['finish_reason' => 'stop']]];
        $events = [];
        foreach ($data as $i => $item) {
            $item['run_id'] = 'run';
            $event = ThreadStreamTest::event('messages', $item, $i + 1, ['model:0']);
            $event['params']['node'] = 'model';
            $events[] = $event;
        }
        $thread = self::thread($events);
        $projection = $thread->messagesFrom('model');
        $cursor = $projection->getIterator();
        $cursor->rewind();
        $message = $cursor->current();
        self::assertSame('message', $message->id);
        self::assertSame('model', $message->node);
        self::assertSame('run', $message->runId);
        self::assertSame('Hi', $message->text());
        self::assertSame('Think', $message->reasoning());
        self::assertSame(3, $message->usage()['total_tokens']);
        self::assertSame('message', $message->output()->id);
        self::assertSame('stop', $message->output()->response_metadata['finish_reason']);
        self::assertCount(5, iterator_to_array($message, false));
        self::assertSame($message, iterator_to_array($projection, false)[0]);
    }

    public function testSourceStartUsageMetadataAndFinishReasonSurviveMessageOutput(): void
    {
        $events = [ThreadStreamTest::event('messages', ['event' => 'message-start', 'id' => 'msg_await', 'run_id' => 'run_await',
            'metadata' => ['provider' => 'openai', 'model' => 'gpt-5'], 'usage' => ['input_tokens' => 3]], 1, ['agent_1']),
            ThreadStreamTest::event('messages', ['event' => 'message-finish', 'reason' => 'stop'], 2, ['agent_1'])];
        foreach ($events as &$event) {
            $event['params']['node'] = 'writer';
        }
        unset($event);
        $thread = self::thread($events);
        $handle = iterator_to_array($thread->messages(), false)[0];
        $message = $handle->output();
        self::assertSame(['input_tokens' => 3, 'output_tokens' => 0, 'total_tokens' => 0], $handle->usage());
        self::assertSame(['namespace' => ['agent_1'], 'node' => 'writer', 'run_id' => 'run_await',
            'metadata' => ['provider' => 'openai', 'model' => 'gpt-5'], 'usage' => ['input_tokens' => 3]], $message->additional_kwargs);
        self::assertSame(['finish_reason' => 'stop', 'output_version' => 'v1'], $message->response_metadata);
    }

    public function testSourceToolChunkNullIdentifiersAndFinalPromotion(): void
    {
        $data = [['event' => 'message-start', 'id' => 'msg_n'],
            ['event' => 'content-block-start', 'index' => 0, 'content' => ['type' => 'tool_call_chunk', 'id' => 'tool_1', 'name' => 'search', 'args' => '']],
            ['event' => 'content-block-delta', 'index' => 0, 'content' => ['type' => 'tool_call_chunk', 'id' => null, 'name' => null, 'args' => '{"q":']],
            ['event' => 'content-block-delta', 'index' => 0, 'content' => ['type' => 'tool_call_chunk', 'id' => null, 'name' => null, 'args' => '"test"}']]];
        $events = array_map(static fn ($item, $i) => ThreadStreamTest::event('messages', $item, $i + 1), $data, array_keys($data));
        $thread = self::thread($events);
        $handle = iterator_to_array($thread->messages(), false)[0];
        self::assertSame([['type' => 'tool_call_chunk', 'id' => 'tool_1', 'name' => 'search', 'args' => '{"q":"test"}']], $handle->blocks());

        $data[] = ['event' => 'content-block-finish', 'index' => 0, 'content' => ['type' => 'tool_call', 'id' => 'tool_1', 'name' => 'search', 'args' => ['q' => 'test']]];
        $data[] = ['event' => 'message-finish', 'reason' => 'tool_use'];
        $events = array_map(static fn ($item, $i) => ThreadStreamTest::event('messages', $item, $i + 1), $data, array_keys($data));
        $thread = self::thread($events);
        $output = iterator_to_array($thread->messages(), false)[0]->output();
        self::assertSame([['name' => 'search', 'args' => ['q' => 'test'], 'id' => 'tool_1', 'type' => 'tool_call']], $output->toolCalls);
        self::assertSame('tool_use', $output->response_metadata['finish_reason']);
    }

    public function testInterleavedMessagesStayIsolatedByRunAndIgnoreUnknownContinuations(): void
    {
        $data = [['event' => 'message-start', 'id' => 'a', 'run_id' => 'run-a'],
            ['event' => 'message-start', 'id' => 'b', 'run_id' => 'run-b'],
            ['event' => 'content-block-delta', 'run_id' => 'unknown', 'index' => 0, 'content' => ['type' => 'text', 'text' => 'wrong']],
            ['event' => 'content-block-delta', 'run_id' => 'run-a', 'index' => 0, 'content' => ['type' => 'text', 'text' => 'A']],
            ['event' => 'content-block-delta', 'run_id' => 'run-b', 'index' => 0, 'content' => ['type' => 'text', 'text' => 'B']],
            ['event' => 'message-finish', 'run_id' => 'run-a'], ['event' => 'message-finish', 'run_id' => 'run-b']];
        $events = array_map(static fn ($item, $i) => ThreadStreamTest::event('messages', $item, $i + 1), $data, array_keys($data));
        $thread = self::thread($events);
        $handles = iterator_to_array($thread->messages(), false);
        self::assertCount(2, $handles);
        self::assertSame('A', $handles[0]->text());
        self::assertSame('B', $handles[1]->text());
    }

    public function testSubgraphDiscoveryScopesValuesAndAttachesToolCause(): void
    {
        $events = [ThreadStreamTest::event('tools', ['event' => 'tool-started', 'tool_call_id' => 'call']),
            ThreadStreamTest::event('lifecycle', ['event' => 'started', 'cause' => ['type' => 'toolCall', 'tool_call_id' => 'call'], 'graph_name' => 'child-graph'], 2, ['worker:3']),
            ThreadStreamTest::event('values', ['child' => true], 3, ['worker:3']),
            ThreadStreamTest::event('values', ['root' => true], 4),
            ThreadStreamTest::event('values', ['nested' => true], 5, ['worker:3', 'nested:0']),
            ThreadStreamTest::event('lifecycle', ['event' => 'completed'], 6)];
        $http = new RecordingTransport([ThreadStreamTest::sse($events), ThreadStreamTest::sse($events)]);
        $thread = (new ThreadsClient(['apiKey' => null], $http))->stream('thread', ['assistantId' => 'agent', 'maxReconnectAttempts' => 0]);
        // Register both views before pulling; the shared filter includes child state.
        $thread->values();
        $subgraphs = $thread->subgraphs();
        $children = iterator_to_array($subgraphs, false);
        self::assertCount(1, $children);
        self::assertSame('worker', $children[0]->name);
        self::assertSame(3, $children[0]->index);
        self::assertSame(['worker:3'], $children[0]->namespace);
        self::assertSame('child-graph', $children[0]->graphName);
        self::assertSame('call', $children[0]->toolStartedEvent['params']['data']['tool_call_id']);
        self::assertSame([['child' => true], ['nested' => true]], iterator_to_array($children[0]->values(), false));
        self::assertSame(['nested' => true], $children[0]->output());
        self::assertCount(2, $http->requests);
    }

    public function testNestedInterruptsAreTrackedOnceAcrossReplayAndLifecycleMarksInterrupted(): void
    {
        $thread = self::thread([ThreadStreamTest::event('input.requested', ['interrupt_id' => 'ask', 'payload' => ['question' => 'approve']], 1, ['child:0']),
            ThreadStreamTest::event('input.requested', ['interrupt_id' => 'ask', 'payload' => ['question' => 'approve']], 1, ['child:0']),
            ThreadStreamTest::event('lifecycle', ['event' => 'interrupted'], 2)]);
        iterator_to_array($thread->subscribe(['channels' => ['input', 'lifecycle']]));
        self::assertTrue($thread->interrupted());
        self::assertSame([['interruptId' => 'ask', 'payload' => ['question' => 'approve'], 'namespace' => ['child:0']]], $thread->interrupts());
    }

    public function testExtensionsShareOneDispatcherAndLateHandlesReplayTheirOwnNamedEvents(): void
    {
        $http = new RecordingTransport([ThreadStreamTest::sse([
            ThreadStreamTest::event('custom', ['name' => 'first', 'payload' => 1]),
            ThreadStreamTest::event('custom', ['name' => 'second', 'payload' => 2], 2),
            ThreadStreamTest::event('lifecycle', ['event' => 'completed'], 3),
        ])]);
        $thread = (new ThreadsClient(['apiKey' => null], $http))->stream('thread', ['assistantId' => 'agent', 'maxReconnectAttempts' => 0]);
        self::assertSame([1], iterator_to_array($thread->extension('first'), false));
        self::assertSame([2], iterator_to_array($thread->extension('second'), false));
        self::assertSame(2, $thread->extension('second')->value());
        self::assertCount(1, $http->requests);
        self::assertSame(['channels' => ['custom', 'lifecycle', 'input']], json_decode($http->requests[0]['body'], true));
    }

    public function testLateRawReplaySkipsPastTerminalPauseAcrossNetworkChunks(): void
    {
        $terminal = ThreadStreamTest::event('lifecycle', ['event' => 'completed'], 10);
        $http = new \LangChain\Tests\Unit\Sdk\Support\StreamingTransport([
            static fn () => \LangChain\Tests\Unit\Sdk\Support\StreamingTransport::sse([ThreadStreamTest::sse([$terminal])->body]),
            static fn () => \LangChain\Tests\Unit\Sdk\Support\StreamingTransport::sse([
                ThreadStreamTest::sse([$terminal])->body,
                ThreadStreamTest::sse([ThreadStreamTest::event('values', 'trailing', 11)])->body,
            ]),
        ]);
        $thread = (new ThreadsClient(['apiKey' => null], $http))->stream('thread', ['assistantId' => 'agent', 'maxReconnectAttempts' => 0]);
        self::assertCount(1, iterator_to_array($thread->subscribe('lifecycle'), false));
        $late = $thread->subscribe(['channels' => ['lifecycle', 'values']]);
        $events = iterator_to_array($late, false);
        self::assertCount(2, $events);
        self::assertSame('trailing', $events[1]['params']['data']);
    }

    public function testMessageErrorClosesAndPreservesPartialViewsAndErrorMetadata(): void
    {
        $data = [['event' => 'message-start', 'id' => 'partial', 'usage' => ['input_tokens' => 3]],
            ['event' => 'content-block-delta', 'index' => 0, 'content' => ['type' => 'text', 'text' => 'partial']],
            ['event' => 'content-block-delta', 'index' => 1, 'content' => ['type' => 'reasoning', 'reasoning' => 'thinking']],
            ['event' => 'error', 'code' => 'model_failure', 'message' => 'model blew up']];
        $events = array_map(static fn ($item, $i) => ThreadStreamTest::event('messages', $item, $i + 1), $data, array_keys($data));
        $events[] = ThreadStreamTest::event('lifecycle', ['event' => 'failed'], 5);
        $thread = self::thread($events);
        $seen = [];
        $thread->onError(static function ($error) use (&$seen): void { $seen[] = $error; });
        $handle = iterator_to_array($thread->messages(), false)[0];
        self::assertSame('partial', $handle->text());
        self::assertSame('thinking', $handle->reasoning());
        self::assertSame(['input_tokens' => 3, 'output_tokens' => 0, 'total_tokens' => 0], $handle->usage());
        self::assertSame('partial', $handle->output()->text());
        self::assertSame(['message' => 'model blew up', 'code' => 'model_failure'], $handle->error);
        self::assertCount(4, iterator_to_array($handle, false));
        self::assertSame([], $seen);
        self::assertSame([], $thread->errors());
    }

    public function testLateMessageProjectionRetainsEachStartHandleWhenItsMutableKeyIsReused(): void
    {
        $events = [];
        foreach (['first', 'second'] as $text) {
            foreach ([['event' => 'message-start', 'id' => 'same'],
                ['event' => 'content-block-delta', 'index' => 0, 'content' => ['type' => 'text', 'text' => $text]],
                ['event' => 'message-finish']] as $data) {
                $event = ThreadStreamTest::event('messages', $data, count($events) + 1);
                $event['params']['node'] = 'model';
                $events[] = $event;
            }
        }
        $events[] = ThreadStreamTest::event('lifecycle', ['event' => 'completed'], count($events) + 1);
        $http = new RecordingTransport([ThreadStreamTest::sse($events), ThreadStreamTest::sse($events)]);
        $thread = (new ThreadsClient(['apiKey' => null], $http))->stream('thread', ['assistantId' => 'agent', 'maxReconnectAttempts' => 0]);
        $projection = $thread->messages();
        $thread->output(); // Drain every start through another consumer before reading messages.
        $handles = iterator_to_array($projection, false);
        self::assertSame(['first', 'second'], array_map(static fn ($m) => $m->text(), $handles));
        self::assertNotSame($handles[0], $handles[1]);
        self::assertSame($handles, iterator_to_array($thread->messagesFrom('model'), false));
        self::assertCount(2, $http->requests);
    }

    public function testAnonymousIdenticalStartsCaptureDistinctHandlesBeforeAnyProjectionReaderRuns(): void
    {
        $events = [];
        foreach (['first', 'second'] as $text) {
            foreach ([['event' => 'message-start', 'id' => 'same'],
                ['event' => 'content-block-delta', 'index' => 0, 'content' => ['type' => 'text', 'text' => $text]],
                ['event' => 'message-finish']] as $data) {
                $event = ThreadStreamTest::event('messages', $data);
                unset($event['event_id'], $event['seq']);
                $events[] = $event;
            }
        }
        $thread = self::thread($events);
        $messages = $thread->messages();
        $thread->output();
        $handles = iterator_to_array($messages, false);
        self::assertCount(2, $handles);
        self::assertNotSame($handles[0], $handles[1]);
        self::assertSame(['first', 'second'], array_map(static fn ($m) => $m->text(), $handles));
    }

    public function testSubgraphWireShapeGuardsKeepObjectLikeCausesAndIgnoreInvalidScalarFields(): void
    {
        foreach ([['cause' => 'bad', 'graph_name' => 12], ['cause' => [], 'graph_name' => 'child'],
            ['cause' => ['future' => ['unknown' => true]], 'graph_name' => null], ['cause' => ['list-cause']],
            ['cause' => ['type' => 'future', 'tool_call_id' => 'call']]] as $fields) {
            $thread = self::thread([ThreadStreamTest::event('tools', ['event' => 'tool-started', 'tool_call_id' => 'call']),
                ThreadStreamTest::event('lifecycle', ['event' => 'started', ...$fields], 2, ['child:0'])]);
            $child = iterator_to_array($thread->subgraphs(), false)[0];
            self::assertSame(is_array($fields['cause']) ? $fields['cause'] : null, $child->cause);
            self::assertSame(is_string($fields['graph_name'] ?? null) ? $fields['graph_name'] : null, $child->graphName);
            self::assertNull($child->toolStartedEvent);
        }
    }

    public function testTrailingEventsResumePausedRawConsumersWhenTerminalHasNoSequence(): void
    {
        $terminal = ThreadStreamTest::event('lifecycle', ['event' => 'completed']);
        unset($terminal['seq']);
        $value = ThreadStreamTest::event('values', 'after-terminal', 2);
        $http = new \LangChain\Tests\Unit\Sdk\Support\StreamingTransport([
            static fn () => \LangChain\Tests\Unit\Sdk\Support\StreamingTransport::sse([
                ThreadStreamTest::sse([$terminal])->body, ThreadStreamTest::sse([$value])->body,
            ]),
        ]);
        $thread = (new ThreadsClient(['apiKey' => null], $http))->stream('thread', ['assistantId' => 'agent', 'maxReconnectAttempts' => 0]);
        $raw = $thread->subscribe(['lifecycle', 'values']);
        self::assertSame([$terminal], iterator_to_array($raw, false));
        self::assertTrue($raw->paused);
        self::assertTrue($thread->pull()); // Another source consumer drives the next network chunk.
        self::assertFalse($raw->paused);
        self::assertSame([$value], iterator_to_array($raw, false));
    }

    public function testLazyExtensionCachesAndIncludesSameChunkTrailingTerminalPayload(): void
    {
        $thread = self::thread([ThreadStreamTest::event('custom', ['name' => 'activity', 'payload' => 1]),
            ThreadStreamTest::event('lifecycle', ['event' => 'completed'], 2),
            ThreadStreamTest::event('custom', ['name' => 'activity', 'payload' => 2], 3)]);
        $extension = $thread->extension('activity');
        self::assertSame($extension, $thread->extension('activity'));
        self::assertSame([1, 2], iterator_to_array($extension, false));
        self::assertSame(2, $extension->value());
        self::assertSame($extension, $thread->extensions['activity']);
    }
}
