<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Stream;

use LangChain\Tests\Unit\Stream\Support\StreamHelpers;
use LangGraph\Stream\ChatModelStream;
use LangGraph\Stream\Transformers\MessagesTransformer;
use LangGraph\Stream\Transformers\ValuesTransformer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `langgraph-core/src/stream/transformers.test.ts`: the messages and values transformers.
 *
 * Upstream's `ChatModelStream` is `@langchain/core`'s promise-valued assembler; the PHP stand-in exposes
 * `text()`, `reasoning()`, `usage()` and replayable events instead, which is what these cases read.
 */
#[CoversClass(MessagesTransformer::class)]
#[CoversClass(ValuesTransformer::class)]
#[CoversClass(ChatModelStream::class)]
final class TransformersTest extends TestCase
{
    use StreamHelpers;

    private const AGENT_NS = ['agent'];

    /**
     * @param list<string> $namespace
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function messageEvent(array $data, array $namespace = self::AGENT_NS, ?string $node = null): array
    {
        return self::makeEvent('messages', $namespace, $data, $node);
    }

    /**
     * Root-level transformer tests use path [] and emit events at namespace ["agent"] (depth 1), because
     * the messages transformer only captures events exactly one level below its path.
     */
    public function testCreatesChatModelStreamsFromMessageStartEvents(): void
    {
        $transformer = new MessagesTransformer([]);
        $proj = $transformer->init();

        $transformer->process(self::messageEvent(['event' => 'message-start', 'role' => 'ai']));
        $transformer->process(self::messageEvent(['event' => 'message-finish', 'reason' => 'stop']));
        $transformer->finalize();

        $this->assertCount(1, self::collect($proj['messages']));
    }

    public function testForwardsContentBlockDeltaToActiveStream(): void
    {
        $transformer = new MessagesTransformer([]);
        $proj = $transformer->init();

        $transformer->process(self::messageEvent(['event' => 'message-start', 'role' => 'ai']));
        $transformer->process(self::messageEvent(['event' => 'content-block-delta', 'index' => 0, 'content' => ['type' => 'text', 'text' => 'hello']]));
        $transformer->process(self::messageEvent(['event' => 'message-finish', 'reason' => 'stop']));
        $transformer->finalize();

        $streams = self::collect($proj['messages']);
        $this->assertCount(1, $streams);
        $this->assertSame('hello', $streams[0]->text());
    }

    public function testIgnoresToolRoleMessageLifecycles(): void
    {
        $transformer = new MessagesTransformer([]);
        $proj = $transformer->init();

        $transformer->process(self::messageEvent(['event' => 'message-start', 'role' => 'tool', 'run_id' => 'run-tool']));
        $transformer->process(self::messageEvent([
            'event' => 'content-block-delta', 'index' => 0,
            'delta' => ['type' => 'text-delta', 'text' => '[]'], 'run_id' => 'run-tool',
        ]));
        $transformer->process(self::messageEvent(['event' => 'message-finish', 'run_id' => 'run-tool']));
        $transformer->process(self::messageEvent(['event' => 'message-start', 'role' => 'ai', 'run_id' => 'run-ai']));
        $transformer->process(self::messageEvent([
            'event' => 'content-block-delta', 'index' => 0,
            'delta' => ['type' => 'text-delta', 'text' => 'hello'], 'run_id' => 'run-ai',
        ]));
        $transformer->process(self::messageEvent(['event' => 'message-finish', 'run_id' => 'run-ai']));
        $transformer->finalize();

        $streams = self::collect($proj['messages']);
        $this->assertCount(1, $streams);
        $this->assertSame('hello', $streams[0]->text());
    }

    public function testRoutesInterleavedMessageStreamsByRunId(): void
    {
        $transformer = new MessagesTransformer([]);
        $proj = $transformer->init();

        $transformer->process(self::messageEvent(['event' => 'message-start', 'id' => 'msg-a', 'run_id' => 'run-a']));
        $transformer->process(self::messageEvent(['event' => 'message-start', 'id' => 'msg-b', 'run_id' => 'run-b']));
        $transformer->process(self::messageEvent(['event' => 'content-block-start', 'index' => 0, 'content' => ['type' => 'text', 'text' => ''], 'run_id' => 'run-b']));
        $transformer->process(self::messageEvent(['event' => 'content-block-delta', 'index' => 0, 'delta' => ['type' => 'text-delta', 'text' => 'B'], 'run_id' => 'run-b']));
        $transformer->process(self::messageEvent(['event' => 'content-block-start', 'index' => 0, 'content' => ['type' => 'text', 'text' => ''], 'run_id' => 'run-a']));
        $transformer->process(self::messageEvent(['event' => 'content-block-delta', 'index' => 0, 'delta' => ['type' => 'text-delta', 'text' => 'A'], 'run_id' => 'run-a']));
        $transformer->process(self::messageEvent(['event' => 'message-finish', 'run_id' => 'run-b']));
        $transformer->process(self::messageEvent(['event' => 'message-finish', 'run_id' => 'run-a']));
        $transformer->finalize();

        $streams = self::collect($proj['messages']);
        $this->assertCount(2, $streams);
        $this->assertSame('A', $streams[0]->text());
        $this->assertSame('B', $streams[1]->text());
    }

    public function testClosesStreamOnMessageFinish(): void
    {
        $transformer = new MessagesTransformer([]);
        $proj = $transformer->init();

        $transformer->process(self::messageEvent(['event' => 'message-start', 'role' => 'ai']));
        $transformer->process(self::messageEvent(['event' => 'message-finish', 'reason' => 'stop']));
        $transformer->finalize();

        $streams = self::collect($proj['messages']);
        $this->assertCount(1, $streams);

        $events = self::collect($streams[0]);
        $finishEvents = array_filter($events, static fn (array $e): bool => $e['event'] === 'message-finish');
        $this->assertCount(1, $finishEvents);
    }

    public function testNodeFilterOnlyProcessesEventsFromMatchingNode(): void
    {
        $transformer = new MessagesTransformer([], 'agent');
        $proj = $transformer->init();

        $transformer->process(self::messageEvent(['event' => 'message-start', 'role' => 'ai'], ['other_node'], 'other'));
        $transformer->process(self::messageEvent(['event' => 'message-finish', 'reason' => 'stop'], ['other_node'], 'other'));
        $transformer->process(self::messageEvent(['event' => 'message-start', 'role' => 'ai'], self::AGENT_NS, 'agent'));
        $transformer->process(self::messageEvent(['event' => 'message-finish', 'reason' => 'stop'], self::AGENT_NS, 'agent'));
        $transformer->finalize();

        $streams = self::collect($proj['messages']);
        $this->assertCount(1, $streams);
        $this->assertSame('agent', $streams[0]->node);
    }

    public function testCapturesEventsOnlyAtDepthPlusOneDirectChildNodes(): void
    {
        $transformer = new MessagesTransformer(['root']);
        $proj = $transformer->init();

        $transformer->process(self::messageEvent(['event' => 'message-start', 'role' => 'ai'], ['root', 'node']));
        $transformer->process(self::messageEvent(['event' => 'message-finish', 'reason' => 'stop'], ['root', 'node']));
        $transformer->process(self::messageEvent(['event' => 'message-start', 'role' => 'ai'], ['root']));
        $transformer->process(self::messageEvent(['event' => 'message-finish', 'reason' => 'stop'], ['root']));
        $transformer->finalize();

        $streams = self::collect($proj['messages']);
        $this->assertCount(1, $streams);
        $this->assertSame(['root', 'node'], $streams[0]->namespace);
    }

    public function testIgnoresEventsFromDeeplyNestedNamespacesDepthPlusTwoOrMore(): void
    {
        $transformer = new MessagesTransformer(['root']);
        $proj = $transformer->init();

        $transformer->process(self::messageEvent(['event' => 'message-start', 'role' => 'ai'], ['root', 'sub', 'inner']));
        $transformer->process(self::messageEvent(['event' => 'message-finish', 'reason' => 'stop'], ['root', 'sub', 'inner']));
        $transformer->process(self::messageEvent(['event' => 'message-start', 'role' => 'ai'], ['root', 'node']));
        $transformer->process(self::messageEvent(['event' => 'message-finish', 'reason' => 'stop'], ['root', 'node']));
        $transformer->finalize();

        $streams = self::collect($proj['messages']);
        $this->assertCount(1, $streams);
        $this->assertSame(['root', 'node'], $streams[0]->namespace);
    }

    public function testIgnoresEventsFromUnrelatedNamespaces(): void
    {
        $transformer = new MessagesTransformer(['root']);
        $proj = $transformer->init();

        $transformer->process(self::messageEvent(['event' => 'message-start', 'role' => 'ai'], ['other', 'node']));
        $transformer->process(self::messageEvent(['event' => 'message-finish', 'reason' => 'stop'], ['other', 'node']));
        $transformer->process(self::messageEvent(['event' => 'message-start', 'role' => 'ai'], ['root', 'node']));
        $transformer->process(self::messageEvent(['event' => 'message-finish', 'reason' => 'stop'], ['root', 'node']));
        $transformer->finalize();

        $streams = self::collect($proj['messages']);
        $this->assertCount(1, $streams);
        $this->assertSame(['root', 'node'], $streams[0]->namespace);
    }

    public function testMessagesFinalizeClosesTheLog(): void
    {
        $transformer = new MessagesTransformer([]);
        $proj = $transformer->init();
        $transformer->finalize();

        $this->assertCount(0, self::collect($proj['messages']));
    }

    public function testMessagesFailPropagatesErrorToActiveStreamAndLog(): void
    {
        $transformer = new MessagesTransformer([]);
        $proj = $transformer->init();
        $error = new \RuntimeException('boom');

        $transformer->process(self::messageEvent(['event' => 'message-start', 'role' => 'ai']));

        $iter = $proj['messages']->getIterator();
        $this->assertTrue($iter->valid());
        /** @var ChatModelStream $stream */
        $stream = $iter->current();

        $transformer->fail($error);

        try {
            self::collect($stream);
            $this->fail('the active stream should have failed');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $iter->next();
    }

    public function testMultipleMessageLifecyclesCreateSeparateChatModelStreams(): void
    {
        $transformer = new MessagesTransformer([]);
        $proj = $transformer->init();

        foreach (['first', 'second'] as $text) {
            $transformer->process(self::messageEvent(['event' => 'message-start', 'role' => 'ai']));
            $transformer->process(self::messageEvent(['event' => 'content-block-delta', 'index' => 0, 'content' => ['type' => 'text', 'text' => $text]]));
            $transformer->process(self::messageEvent(['event' => 'message-finish', 'reason' => 'stop']));
        }
        $transformer->finalize();

        $streams = self::collect($proj['messages']);
        $this->assertCount(2, $streams);
        $this->assertSame('first', $streams[0]->text());
        $this->assertSame('second', $streams[1]->text());
    }

    // ---- createValuesTransformer -------------------------------------------------------------

    public function testCapturesValuesEventsAtTheTargetNamespaceDepth(): void
    {
        $transformer = new ValuesTransformer(['root']);
        $proj = $transformer->init();

        $transformer->process(self::makeEvent('values', ['root'], ['count' => 1]));
        $transformer->process(self::makeEvent('values', ['root'], ['count' => 2]));
        $transformer->finalize();

        $this->assertSame([['count' => 1], ['count' => 2]], self::collect($proj['_valuesLog']->toAsyncIterable()));
    }

    public function testValuesIgnoresEventsFromDifferentNamespaces(): void
    {
        $transformer = new ValuesTransformer(['root']);
        $proj = $transformer->init();

        $transformer->process(self::makeEvent('values', ['other'], ['x' => 1]));
        $transformer->process(self::makeEvent('values', ['root', 'child'], ['x' => 2]));
        $transformer->finalize();

        $this->assertCount(0, self::collect($proj['_valuesLog']->toAsyncIterable()));
    }

    public function testValuesIgnoresNonValuesEvents(): void
    {
        $transformer = new ValuesTransformer(['root']);
        $proj = $transformer->init();

        $transformer->process(self::makeEvent('messages', ['root'], ['event' => 'message-start']));
        $transformer->finalize();

        $this->assertCount(0, self::collect($proj['_valuesLog']->toAsyncIterable()));
    }

    public function testValuesFinalizeClosesTheLog(): void
    {
        $transformer = new ValuesTransformer([]);
        $proj = $transformer->init();
        $transformer->finalize();

        $this->assertCount(0, self::collect($proj['_valuesLog']->toAsyncIterable()));
        $this->assertTrue($proj['_valuesLog']->done());
    }

    public function testValuesFailPropagatesError(): void
    {
        $transformer = new ValuesTransformer([]);
        $proj = $transformer->init();

        $transformer->process(self::makeEvent('values', [], ['a' => 1]));
        $transformer->fail(new \RuntimeException('fail'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('fail');
        self::collect($proj['_valuesLog']->toAsyncIterable());
    }

    // ---- ChatModelStream stand-in ------------------------------------------------------------

    public function testChatModelStreamAccumulatesReasoningAndUsage(): void
    {
        $transformer = new MessagesTransformer([]);
        $proj = $transformer->init();

        $transformer->process(self::messageEvent(['event' => 'message-start', 'role' => 'ai']));
        $transformer->process(self::messageEvent(['event' => 'content-block-delta', 'index' => 0, 'delta' => ['type' => 'reasoning-delta', 'reasoning' => 'hmm ']]));
        $transformer->process(self::messageEvent(['event' => 'content-block-delta', 'index' => 0, 'content' => ['type' => 'thinking', 'thinking' => 'ok']]));
        $transformer->process(self::messageEvent(['event' => 'message-finish', 'usage' => ['input_tokens' => 3, 'output_tokens' => 4]]));
        $transformer->finalize();

        $stream = self::collect($proj['messages'])[0];
        $this->assertSame('hmm ok', $stream->reasoning());
        $this->assertSame('', $stream->text());
        $this->assertSame(['input_tokens' => 3, 'output_tokens' => 4], $stream->usage());
    }

    public function testOrphanContentBlockAndErrorEventsForAnUnknownKeyAreDroppedSilently(): void
    {
        $transformer = new MessagesTransformer([]);
        $proj = $transformer->init();

        $warnings = [];
        set_error_handler(static function (int $no, string $str) use (&$warnings): bool {
            $warnings[] = $str;

            return true;
        });
        try {
            $delta = $transformer->process(self::messageEvent(['event' => 'content-block-delta', 'run_id' => 'r1', 'index' => 0, 'content' => ['type' => 'text', 'text' => 'x']]));
            $error = $transformer->process(self::messageEvent(['event' => 'error', 'run_id' => 'r1', 'message' => 'boom']));
            $noKey = $transformer->process(self::messageEvent(['event' => 'content-block-delta', 'index' => 0, 'content' => ['type' => 'text', 'text' => 'y']]));
        } finally {
            restore_error_handler();
        }
        $transformer->finalize();

        $this->assertTrue($delta);
        $this->assertTrue($error);
        $this->assertTrue($noKey);
        $this->assertSame([], $warnings);
        $this->assertCount(0, self::collect($proj['messages']));
    }

    public function testAMessageStillOpenAtFinalizeIsClosedWithASyntheticFinish(): void
    {
        $transformer = new MessagesTransformer([]);
        $proj = $transformer->init();

        $transformer->process(self::messageEvent(['event' => 'message-start', 'role' => 'ai']));
        $transformer->finalize();

        $events = self::collect(self::collect($proj['messages'])[0]);
        $this->assertSame('message-finish', $events[\count($events) - 1]['event']);
    }
}
