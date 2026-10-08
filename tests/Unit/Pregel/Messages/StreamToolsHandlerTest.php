<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel\Messages;

use LangChain\Tracers\Serialized;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Messages\StreamToolsHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `tests/pregel/stream.test.ts` (6 tests, all converted): `StreamToolsHandler`.
 */
#[CoversClass(StreamToolsHandler::class)]
final class StreamToolsHandlerTest extends TestCase
{
    /** @var list<mixed> */
    private array $chunks = [];

    protected function setUp(): void
    {
        $this->chunks = [];
    }

    private function handler(): StreamToolsHandler
    {
        return new StreamToolsHandler(function (array $chunk): void {
            $this->chunks[] = $chunk;
        });
    }

    public function testEmitsOnToolStartWithNamespaceAndToolCallId(): void
    {
        $this->handler()->handleToolStart(
            new Serialized([]),
            '{"query":"SF"}',
            'run-1',
            null,
            [],
            ['langgraph_checkpoint_ns' => 'a|b'],
            'weather',
            'call_1234',
        );

        self::assertCount(1, $this->chunks);
        self::assertSame([
            ['a', 'b'],
            'tools',
            ['event' => 'on_tool_start', 'toolCallId' => 'call_1234', 'name' => 'weather', 'input' => '{"query":"SF"}'],
        ], $this->chunks[0]);
    }

    public function testDoesNotEmitWhenMetadataIsAbsent(): void
    {
        $this->handler()->handleToolStart(new Serialized([]), '{}', 'run-1', null, [], null, 'tool');

        self::assertCount(0, $this->chunks);
    }

    public function testDoesNotEmitWhenTagsIncludeTagHidden(): void
    {
        $this->handler()->handleToolStart(
            new Serialized([]),
            '{}',
            'run-1',
            null,
            [Constants::TAG_HIDDEN],
            [],
            'tool',
            'call_1',
        );

        self::assertCount(0, $this->chunks);
    }

    public function testEmitsOnToolEventWhenTheRunIsKnown(): void
    {
        $handler = $this->handler();
        $handler->handleToolStart(new Serialized([]), '{}', 'run-1', null, [], [], 'my_tool', 'call_1');
        $handler->handleToolEvent(['partial' => 'data'], 'run-1');

        self::assertCount(2, $this->chunks);
        self::assertSame([
            [],
            'tools',
            ['event' => 'on_tool_event', 'toolCallId' => 'call_1', 'name' => 'my_tool', 'data' => ['partial' => 'data']],
        ], $this->chunks[1]);
    }

    public function testEmitsOnToolEndAndClearsTheRun(): void
    {
        $handler = $this->handler();
        $handler->handleToolStart(new Serialized([]), '{}', 'run-1', null, [], [], 'my_tool', 'call_1');
        $handler->handleToolEnd(['result' => 42], 'run-1');

        self::assertCount(2, $this->chunks);
        self::assertSame([
            [],
            'tools',
            ['event' => 'on_tool_end', 'toolCallId' => 'call_1', 'name' => 'my_tool', 'output' => ['result' => 42]],
        ], $this->chunks[1]);

        // The run is forgotten, so a straggling event is dropped.
        $handler->handleToolEvent('no-op', 'run-1');
        self::assertCount(2, $this->chunks);
    }

    public function testEmitsOnToolErrorAndClearsTheRun(): void
    {
        $handler = $this->handler();
        $error = new \RuntimeException('tool failed');
        $handler->handleToolStart(new Serialized([]), '{}', 'run-1', null, [], [], 'my_tool', 'call_1');
        $handler->handleToolError($error, 'run-1');

        self::assertCount(2, $this->chunks);
        self::assertSame([
            [],
            'tools',
            ['event' => 'on_tool_error', 'toolCallId' => 'call_1', 'name' => 'my_tool', 'error' => $error],
        ], $this->chunks[1]);
        self::assertSame([], $handler->runs);
    }
}
