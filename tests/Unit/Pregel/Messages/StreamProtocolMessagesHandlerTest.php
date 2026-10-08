<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel\Messages;

use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\LLMResult;
use LangChain\Messages\AIMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tracers\Serialized;
use LangGraph\Pregel\Messages\StreamProtocolMessagesHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `pregel/messages-v2.test.ts` (6 tests, all converted).
 *
 * Chat model stream events are plain arrays shaped as upstream's
 * `ChatModelStreamEvent`; `call[0][2][0]` becomes `$chunk[2][0]`.
 */
#[CoversClass(StreamProtocolMessagesHandler::class)]
final class StreamProtocolMessagesHandlerTest extends TestCase
{
    /** @var list<array{0: list<string>, 1: string, 2: array{0: array<string, mixed>, 1: array<string, mixed>}}> */
    private array $streamed = [];

    protected function setUp(): void
    {
        $this->streamed = [];
    }

    private function handler(): StreamProtocolMessagesHandler
    {
        return new StreamProtocolMessagesHandler(function (array $chunk): void {
            $this->streamed[] = $chunk;
        });
    }

    /** @return list<array<string, mixed>> The event of every streamed chunk. */
    private function events(): array
    {
        return array_map(static fn (array $chunk): array => $chunk[2][0], $this->streamed);
    }

    public function testForwardsCoreStreamEventsWithRunMetadata(): void
    {
        $handler = $this->handler();
        $handler->handleChatModelStart(
            new Serialized([]),
            [],
            'run-123',
            null,
            [],
            [],
            ['langgraph_checkpoint_ns' => 'ns1|ns2', 'langgraph_node' => 'node-a'],
            'ModelName',
        );

        $handler->handleChatModelStreamEvent(['event' => 'message-start', 'id' => 'msg-123'], 'run-123');

        self::assertEquals([[
            ['ns1', 'ns2'],
            'messages',
            [
                ['event' => 'message-start', 'id' => 'msg-123'],
                [
                    'langgraph_checkpoint_ns' => 'ns1|ns2',
                    'langgraph_node' => 'node-a',
                    'name' => 'ModelName',
                    'run_id' => 'run-123',
                    'tags' => [],
                ],
            ],
        ]], $this->streamed);
    }

    public function testForwardsCoreDeltaEvents(): void
    {
        $handler = $this->handler();
        $handler->handleChatModelStart(
            new Serialized([]),
            [],
            'run-123',
            null,
            [],
            [],
            ['langgraph_checkpoint_ns' => 'ns1|ns2', 'langgraph_node' => 'node-a'],
            'ModelName',
        );

        $handler->handleChatModelStreamEvent(['event' => 'message-start', 'id' => 'msg-123'], 'run-123');
        $handler->handleChatModelStreamEvent([
            'event' => 'content-block-start',
            'index' => 0,
            'content' => ['type' => 'text', 'text' => ''],
        ], 'run-123');
        $handler->handleChatModelStreamEvent([
            'event' => 'content-block-delta',
            'index' => 0,
            'delta' => ['type' => 'text-delta', 'text' => 'Hello'],
        ], 'run-123');

        self::assertEquals([
            ['event' => 'message-start', 'id' => 'msg-123'],
            ['event' => 'content-block-start', 'index' => 0, 'content' => ['type' => 'text', 'text' => '']],
            ['event' => 'content-block-delta', 'index' => 0, 'delta' => ['type' => 'text-delta', 'text' => 'Hello']],
        ], $this->events());
    }

    public function testForwardsCoreToolCallDeltas(): void
    {
        $handler = $this->handler();
        $handler->handleChatModelStart(
            new Serialized([]),
            [],
            'run-123',
            null,
            [],
            [],
            ['langgraph_checkpoint_ns' => 'ns1'],
            'ModelName',
        );

        $events = [
            ['event' => 'message-start', 'id' => 'msg-123'],
            [
                'event' => 'content-block-start',
                'index' => 0,
                'content' => ['type' => 'tool_call_chunk', 'id' => 'call-1', 'name' => 'search', 'args' => ''],
            ],
            [
                'event' => 'content-block-delta',
                'index' => 0,
                'delta' => [
                    'type' => 'block-delta',
                    'fields' => ['type' => 'tool_call_chunk', 'id' => 'call-1', 'name' => 'search', 'args' => '{"q"'],
                ],
            ],
            [
                'event' => 'content-block-delta',
                'index' => 0,
                'delta' => [
                    'type' => 'block-delta',
                    'fields' => ['type' => 'tool_call_chunk', 'id' => 'call-1', 'name' => 'search', 'args' => '{"q":"hi"}'],
                ],
            ],
        ];
        foreach ($events as $event) {
            $handler->handleChatModelStreamEvent($event, 'run-123');
        }

        self::assertEquals($events, $this->events());
    }

    public function testDoesNotEmitFinalLlmMessagesAfterStreamedEvents(): void
    {
        $handler = $this->handler();
        $handler->handleChatModelStart(
            new Serialized([]),
            [],
            'run-123',
            null,
            [],
            [],
            ['langgraph_checkpoint_ns' => 'ns1'],
            'ModelName',
        );

        $handler->handleChatModelStreamEvent(['event' => 'message-start', 'id' => 'msg-123'], 'run-123');
        $handler->handleLLMEnd(
            new LLMResult([[new ChatGeneration(new AIMessage(['id' => 'msg-123', 'content' => 'Hello']), 'Hello')]]),
            'run-123',
        );

        self::assertCount(1, $this->streamed);
    }

    public function testEmitsProtocolLifecycleEventsForNonStreamingChainOutputs(): void
    {
        $handler = $this->handler();
        $handler->handleChainStart(
            new Serialized([]),
            [],
            'chain-123',
            null,
            [],
            ['langgraph_checkpoint_ns' => 'ns1', 'langgraph_node' => 'NodeName'],
            'NodeName',
        );

        $handler->handleChainEnd(
            new AIMessage([
                'id' => 'msg-456',
                'content' => 'Done',
                'response_metadata' => ['stop_reason' => 'tool_use'],
            ]),
            'chain-123',
        );

        self::assertEquals([
            ['event' => 'message-start', 'id' => 'msg-456'],
            ['event' => 'content-block-start', 'index' => 0, 'content' => ['type' => 'text', 'text' => '']],
            ['event' => 'content-block-delta', 'index' => 0, 'delta' => ['type' => 'text-delta', 'text' => 'Done']],
            ['event' => 'content-block-finish', 'index' => 0, 'content' => ['type' => 'text', 'text' => 'Done']],
            ['event' => 'message-finish', 'responseMetadata' => ['stop_reason' => 'tool_use']],
        ], $this->events());
    }

    public function testDoesNotEmitToolMessageChainOutputsAsChatMessageStreams(): void
    {
        $handler = $this->handler();
        $handler->handleChainStart(
            new Serialized([]),
            [],
            'tool-chain-123',
            null,
            [],
            ['langgraph_checkpoint_ns' => 'ns1', 'langgraph_node' => 'ToolNode'],
            'ToolNode',
        );

        $handler->handleChainEnd(
            new ToolMessage([
                'id' => 'tool-msg-1',
                'content' => '[]',
                'tool_call_id' => 'call_1',
                'name' => 'list_items',
            ]),
            'tool-chain-123',
        );

        self::assertSame([], $this->streamed);
    }
}
