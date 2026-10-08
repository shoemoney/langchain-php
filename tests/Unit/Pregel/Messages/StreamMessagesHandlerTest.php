<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel\Messages;

use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\LanguageModels\Outputs\LLMResult;
use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tracers\Serialized;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Messages\StreamMessagesHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `pregel/messages.test.ts` (22 tests, all converted).
 *
 * `vi.fn()` becomes a recording closure; `vi.spyOn(handler, "_emit")` and
 * `handler._emit = vi.fn()` become a subclass that records `emit()` calls and
 * either forwards them to the real method or swallows them.
 */
#[CoversClass(StreamMessagesHandler::class)]
final class StreamMessagesHandlerTest extends TestCase
{
    /** @var list<mixed> */
    private array $streamed = [];

    protected function setUp(): void
    {
        $this->streamed = [];
    }

    private function handler(): StreamMessagesHandler
    {
        return new StreamMessagesHandler(function (array $chunk): void {
            $this->streamed[] = $chunk;
        });
    }

    /**
     * A handler that records every `emit()` call, optionally without performing it.
     */
    private function spyHandler(bool $callThrough): StreamMessagesHandler
    {
        return new class (function (array $chunk): void {
            $this->streamed[] = $chunk;
        }, $callThrough) extends StreamMessagesHandler {
            /** @var list<array{0: array, 1: BaseMessage, 2: ?string, 3: bool}> */
            public array $emitCalls = [];

            public function __construct(callable $streamFn, private readonly bool $callThrough)
            {
                parent::__construct($streamFn);
            }

            public function emit(array $meta, BaseMessage $message, ?string $runId, bool $dedupe = false): void
            {
                $this->emitCalls[] = [$meta, $message, $runId, $dedupe];
                if ($this->callThrough) {
                    parent::emit($meta, $message, $runId, $dedupe);
                }
            }
        };
    }

    // ---- constructor ------------------------------------------------------

    public function testConstructorInitialisesTheHandler(): void
    {
        $streamFn = static function (array $chunk): void {
        };
        $handler = new StreamMessagesHandler($streamFn);

        self::assertSame('StreamMessagesHandler', $handler->name);
        self::assertSame($streamFn, $handler->streamFn);
        self::assertSame([], $handler->metadatas);
        self::assertSame([], $handler->seen);
        self::assertSame([], $handler->emittedChatModelRunIds);
        self::assertSame([], $handler->stableMessageIdMap);
        self::assertTrue($handler->preferStreaming);
    }

    // ---- emit -------------------------------------------------------------

    public function testEmitStreamsAMessageWithItsMetadata(): void
    {
        $handler = $this->handler();
        $meta = [['ns1', 'ns2'], ['name' => 'test', 'tags' => []]];
        $message = new AIMessage(['content' => 'Hello world']);

        $handler->emit($meta, $message, 'run-123');

        self::assertCount(1, $this->streamed);
        self::assertSame(['ns1', 'ns2'], $this->streamed[0][0]);
        self::assertSame('messages', $this->streamed[0][1]);
        self::assertSame($message, $this->streamed[0][2][0]);
        self::assertSame(['name' => 'test', 'tags' => []], $this->streamed[0][2][1]);

        // A message with an id is remembered as seen.
        $message->id = 'msg-123';
        $handler->emit($meta, $message, 'run-123');
        self::assertSame($message, $handler->seen['msg-123']);
    }

    public function testEmitDeduplicatesASeenMessageWhenAsked(): void
    {
        $handler = $this->handler();
        $meta = [['ns1'], ['name' => 'test']];
        $message = new AIMessage(['content' => 'Hello world', 'id' => 'msg-123']);

        $handler->emit($meta, $message, 'run-123');
        self::assertCount(1, $this->streamed);

        $this->streamed = [];
        $handler->emit($meta, $message, 'run-123', true);
        self::assertSame([], $this->streamed);
    }

    public function testEmitAssignsAToolMessageAnIdFromItsToolCallId(): void
    {
        $handler = $this->handler();
        $meta = [['ns1'], ['name' => 'test']];
        $toolMessage = new ToolMessage(['content' => 'Tool result', 'tool_call_id' => 'tc-123']);

        $handler->emit($meta, $toolMessage, 'run-456');

        self::assertSame('run-456-tool-tc-123', $toolMessage->id);
        self::assertNotSame([], $this->streamed);
    }

    public function testEmitKeepsMessageIdsStableForTheSameRun(): void
    {
        $handler = $this->handler();
        $meta = [['ns1'], ['name' => 'test']];

        $first = new AIMessage(['content' => 'First chunk']);
        $handler->emit($meta, $first, 'run-789');
        $stableId = $first->id;

        $second = new AIMessage(['content' => 'Second chunk']);
        $handler->emit($meta, $second, 'run-789');

        self::assertSame($stableId, $second->id);
        self::assertSame($stableId, $handler->stableMessageIdMap['run-789']);
        // The id is written back to the constructor kwargs too, so a checkpoint agrees.
        self::assertSame($stableId, $second->kwargs()['id']);
    }

    // ---- handleChatModelStart --------------------------------------------

    public function testHandleChatModelStartStoresMetadata(): void
    {
        $handler = $this->handler();
        $metadata = ['langgraph_checkpoint_ns' => 'ns1|ns2', 'other_meta' => 'value'];

        $handler->handleChatModelStart(new Serialized([]), [], 'run-123', null, [], [], $metadata, 'ModelName');

        self::assertEquals(
            [['ns1', 'ns2'], ['tags' => [], 'name' => 'ModelName'] + $metadata],
            $handler->metadatas['run-123'],
        );
    }

    public function testHandleChatModelStartIgnoresARunTaggedNoStream(): void
    {
        $handler = $this->handler();

        $handler->handleChatModelStart(
            new Serialized([]),
            [],
            'run-123',
            null,
            [],
            [Constants::TAG_NOSTREAM],
            ['langgraph_checkpoint_ns' => 'ns1|ns2'],
            'ModelName',
        );

        self::assertNull($handler->metadatas['run-123'] ?? null);
    }

    // ---- handleLLMNewToken -----------------------------------------------

    public function testHandleLLMNewTokenEmitsAMessageChunkWhenMetadataExists(): void
    {
        $handler = $this->spyHandler(true);
        $handler->metadatas['run-123'] = [['ns1', 'ns2'], ['name' => 'test']];

        $handler->handleLLMNewToken('token', ['prompt' => 0, 'completion' => 0], 'run-123');

        self::assertTrue($handler->emittedChatModelRunIds['run-123']);
        self::assertCount(1, $handler->emitCalls);
        [$meta, $message, $runId, $dedupe] = $handler->emitCalls[0];
        self::assertSame($handler->metadatas['run-123'], $meta);
        self::assertInstanceOf(AIMessageChunk::class, $message);
        self::assertSame('token', $message->content);
        self::assertSame('run-123', $runId);
        self::assertFalse($dedupe);
    }

    public function testHandleLLMNewTokenEmitsTheProvidedChunk(): void
    {
        $handler = $this->spyHandler(true);
        $handler->metadatas['run-123'] = [['ns1'], ['name' => 'test']];
        $chunk = new ChatGenerationChunk(new AIMessageChunk(['content' => 'chunk content']), 'chunk content');

        $handler->handleLLMNewToken('token', ['prompt' => 0, 'completion' => 0], 'run-123', null, [], ['chunk' => $chunk]);

        self::assertCount(1, $handler->emitCalls);
        self::assertSame($chunk->message, $handler->emitCalls[0][1]);
    }

    public function testHandleLLMNewTokenDoesNotEmitWithoutMetadata(): void
    {
        $handler = $this->spyHandler(true);

        $handler->handleLLMNewToken('token', ['prompt' => 0, 'completion' => 0], 'run-123');

        // The run is still marked as having streamed, so handleLLMEnd will not re-emit it.
        self::assertTrue($handler->emittedChatModelRunIds['run-123']);
        self::assertSame([], $handler->emitCalls);
    }

    // ---- handleLLMEnd -----------------------------------------------------

    public function testHandleLLMEndEmitsTheMessageOfANonStreamingRun(): void
    {
        $handler = $this->spyHandler(false);
        $handler->metadatas['run-123'] = [['ns1'], ['name' => 'test']];

        $message = new AIMessage(['content' => 'final result']);
        $handler->handleLLMEnd(new LLMResult([[new ChatGeneration($message, 'test output')]]), 'run-123');

        self::assertCount(1, $handler->emitCalls);
        self::assertSame($message, $handler->emitCalls[0][1]);
        self::assertSame('final result', $handler->emitCalls[0][1]->content);
        self::assertSame('run-123', $handler->emitCalls[0][2]);
        self::assertTrue($handler->emitCalls[0][3], 'a final message is emitted with dedupe on');
        self::assertArrayNotHasKey('run-123', $handler->metadatas);
    }

    public function testHandleLLMEndDoesNotEmitForAStreamingRunThatAlreadyEmitted(): void
    {
        $handler = $this->spyHandler(false);
        $handler->metadatas['run-123'] = [['ns1'], ['name' => 'test']];
        $handler->emittedChatModelRunIds['run-123'] = true;

        $handler->handleLLMEnd(
            new LLMResult([[new ChatGeneration(new AIMessage(['content' => 'result']), 'test output')]]),
            'run-123',
        );

        self::assertSame([], $handler->emitCalls);
        self::assertArrayNotHasKey('run-123', $handler->metadatas);
    }

    public function testHandleLLMEndDoesNothingWithoutMetadata(): void
    {
        $handler = $this->spyHandler(true);

        $handler->handleLLMEnd(
            new LLMResult([[new ChatGeneration(new AIMessage(['content' => 'result']), 'test output')]]),
            'run-123',
        );

        self::assertSame([], $handler->emitCalls);
    }

    // ---- handleLLMError ---------------------------------------------------

    public function testHandleLLMErrorCleansUpMetadata(): void
    {
        $handler = $this->handler();
        $handler->metadatas['run-123'] = [['ns1'], ['name' => 'test']];

        $handler->handleLLMError(new \RuntimeException('Test error'), 'run-123');

        self::assertArrayNotHasKey('run-123', $handler->metadatas);
    }

    // ---- handleChainStart -------------------------------------------------

    public function testHandleChainStartStoresMetadataForAMatchingNodeName(): void
    {
        $handler = $this->handler();
        $metadata = ['langgraph_checkpoint_ns' => 'ns1|ns2', 'langgraph_node' => 'NodeName'];

        $handler->handleChainStart(new Serialized([]), [], 'chain-123', null, [], $metadata, 'NodeName');

        self::assertEquals(
            [['ns1', 'ns2'], ['tags' => [], 'name' => 'NodeName'] + $metadata],
            $handler->metadatas['chain-123'],
        );
    }

    public function testHandleChainStartIgnoresARunWhoseNameIsNotTheNode(): void
    {
        $handler = $this->handler();
        $metadata = ['langgraph_checkpoint_ns' => 'ns1|ns2', 'langgraph_node' => 'NodeName'];

        $handler->handleChainStart(new Serialized([]), [], 'chain-123', null, [], $metadata, 'DifferentName');

        self::assertArrayNotHasKey('chain-123', $handler->metadatas);
    }

    public function testHandleChainStartIgnoresAHiddenRun(): void
    {
        $handler = $this->handler();
        $metadata = ['langgraph_checkpoint_ns' => 'ns1|ns2', 'langgraph_node' => 'NodeName'];

        $handler->handleChainStart(new Serialized([]), [], 'chain-123', null, [Constants::TAG_HIDDEN], $metadata, 'NodeName');

        self::assertArrayNotHasKey('chain-123', $handler->metadatas);
    }

    // ---- handleChainEnd ---------------------------------------------------

    public function testHandleChainEndEmitsASingleMessageOutput(): void
    {
        $handler = $this->spyHandler(false);
        $handler->metadatas['chain-123'] = [['ns1'], ['name' => 'test']];

        $message = new AIMessage(['content' => 'chain result']);
        $handler->handleChainEnd($message, 'chain-123');

        self::assertCount(1, $handler->emitCalls);
        self::assertSame('chain result', $handler->emitCalls[0][1]->content);
        self::assertSame('chain-123', $handler->emitCalls[0][2]);
        self::assertTrue($handler->emitCalls[0][3]);
        self::assertArrayNotHasKey('chain-123', $handler->metadatas);
    }

    public function testHandleChainEndEmitsMessagesFromAListOutput(): void
    {
        $handler = $this->spyHandler(false);
        $handler->metadatas['chain-123'] = [['ns1'], ['name' => 'test']];

        $handler->handleChainEnd([
            new AIMessage(['content' => 'result 1']),
            new AIMessage(['content' => 'result 2']),
            'not a message',
        ], 'chain-123');

        self::assertCount(2, $handler->emitCalls);
        $contents = array_map(static fn (array $call): mixed => $call[1]->content, $handler->emitCalls);
        self::assertContains('result 1', $contents);
        self::assertContains('result 2', $contents);
        self::assertArrayNotHasKey('chain-123', $handler->metadatas);
    }

    public function testHandleChainEndEmitsMessagesFromObjectOutputProperties(): void
    {
        $handler = $this->spyHandler(false);
        $handler->metadatas['chain-123'] = [['ns1'], ['name' => 'test']];

        $handler->handleChainEnd([
            'directMessage' => new AIMessage(['content' => 'direct result']),
            'arrayMessages' => [new AIMessage(['content' => 'array result']), 'not a message'],
            'otherProp' => 'something else',
        ], 'chain-123');

        self::assertCount(2, $handler->emitCalls);
        $contents = array_map(static fn (array $call): mixed => $call[1]->content, $handler->emitCalls);
        self::assertContains('direct result', $contents);
        self::assertContains('array result', $contents);
        self::assertArrayNotHasKey('chain-123', $handler->metadatas);
    }

    public function testHandleChainEndDoesNothingWithoutMetadata(): void
    {
        $handler = $this->spyHandler(true);

        $handler->handleChainEnd(new AIMessage(['content' => 'result']), 'chain-123');

        self::assertSame([], $handler->emitCalls);
    }

    // ---- handleChainError -------------------------------------------------

    public function testHandleChainErrorCleansUpMetadata(): void
    {
        $handler = $this->handler();
        $handler->metadatas['chain-123'] = [['ns1'], ['name' => 'test']];

        $handler->handleChainError(new \RuntimeException('Test error'), 'chain-123');

        self::assertArrayNotHasKey('chain-123', $handler->metadatas);
    }
}
