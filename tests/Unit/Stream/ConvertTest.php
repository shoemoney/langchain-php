<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Stream;

use LangGraph\Stream\Convert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `langgraph-core/src/stream/convert.test.ts`: `convertToProtocolEvent`.
 */
#[CoversClass(Convert::class)]
final class ConvertTest extends TestCase
{
    private const NS = ['agent', 'inner'];

    public function testConvertsMessagesModeEvents(): void
    {
        $payload = ['event' => 'message-start', 'role' => 'assistant'];
        $result = Convert::toProtocolEvent(self::NS, 'messages', $payload, 1);

        $this->assertCount(1, $result);
        $this->assertSame('event', $result[0]['type']);
        $this->assertSame(1, $result[0]['seq']);
        $this->assertSame('messages', $result[0]['method']);
        $this->assertSame(self::NS, $result[0]['params']['namespace']);
        $this->assertSame($payload, $result[0]['params']['data']);
        $this->assertIsInt($result[0]['params']['timestamp']);
    }

    public function testUnwrapsMessagesTuplesAndPreservesRoutingMetadata(): void
    {
        $payload = [
            ['event' => 'message-start', 'id' => 'msg-1'],
            ['langgraph_node' => 'agent', 'run_id' => 'run-1'],
        ];
        $result = Convert::toProtocolEvent(self::NS, 'messages', $payload, 1);

        $this->assertSame('messages', $result[0]['method']);
        $this->assertSame(self::NS, $result[0]['params']['namespace']);
        $this->assertSame('agent', $result[0]['params']['node']);
        $this->assertSame(['event' => 'message-start', 'id' => 'msg-1', 'run_id' => 'run-1'], $result[0]['params']['data']);
    }

    public function testConvertsValuesModeEvents(): void
    {
        $result = Convert::toProtocolEvent(self::NS, 'values', ['count' => 42], 2);

        $this->assertCount(1, $result);
        $this->assertSame('event', $result[0]['type']);
        $this->assertSame(2, $result[0]['seq']);
        $this->assertSame('values', $result[0]['method']);
        $this->assertSame(self::NS, $result[0]['params']['namespace']);
        $this->assertSame(['count' => 42], $result[0]['params']['data']);
        $this->assertArrayNotHasKey('checkpoint', $result[0]['params']);
    }

    public function testUpdatesModeExtractsTheNodeFromNodeNameDeltaShape(): void
    {
        $result = Convert::toProtocolEvent(self::NS, 'updates', ['myNode' => ['foo' => 'bar']], 3);

        $this->assertCount(1, $result);
        $this->assertSame('updates', $result[0]['method']);
        // The completed node is surfaced at the top level of `params` so transformers can attribute the
        // emission to the child namespace without re-parsing `data`.
        $this->assertSame('myNode', $result[0]['params']['node']);
        $this->assertSame(['node' => 'myNode', 'values' => ['foo' => 'bar']], $result[0]['params']['data']);
    }

    public function testUpdatesModeOmitsParamsNodeWhenThePayloadHasNoNamedNode(): void
    {
        $result = Convert::toProtocolEvent(self::NS, 'updates', [], 3);

        $this->assertCount(1, $result);
        $this->assertArrayNotHasKey('node', $result[0]['params']);
    }

    public function testToolsModeOnToolStartBecomesToolStarted(): void
    {
        $result = Convert::toProtocolEvent(self::NS, 'tools', [
            'event' => 'on_tool_start',
            'name' => 'search',
            'toolCallId' => 'tc_1',
            'input' => ['q' => 'hello'],
        ], 4);

        $this->assertCount(1, $result);
        $this->assertEquals([
            'event' => 'tool-started',
            'tool_name' => 'search',
            'tool_call_id' => 'tc_1',
            'input' => ['q' => 'hello'],
        ], $result[0]['params']['data']);
    }

    public function testToolsModeOnToolEndBecomesToolFinished(): void
    {
        $result = Convert::toProtocolEvent(self::NS, 'tools', [
            'event' => 'on_tool_end',
            'output' => 'result',
            'toolCallId' => 'tc_2',
        ], 5);

        $this->assertCount(1, $result);
        $this->assertEquals(['event' => 'tool-finished', 'output' => 'result', 'tool_call_id' => 'tc_2'], $result[0]['params']['data']);
    }

    public function testToolsModeOnToolErrorBecomesToolErrorWithErrorLikeObjectsAndPlainStrings(): void
    {
        $withError = Convert::toProtocolEvent(self::NS, 'tools', ['event' => 'on_tool_error', 'error' => new \RuntimeException('boom')], 6);
        $this->assertSame('tool-error', $withError[0]['params']['data']['event']);
        $this->assertSame('boom', $withError[0]['params']['data']['message']);

        $withString = Convert::toProtocolEvent(self::NS, 'tools', ['event' => 'on_tool_error', 'error' => 'plain failure'], 7);
        $this->assertSame('tool-error', $withString[0]['params']['data']['event']);
        $this->assertSame('plain failure', $withString[0]['params']['data']['message']);
    }

    public function testToolsModeOnToolEventBecomesToolOutputDelta(): void
    {
        $result = Convert::toProtocolEvent(self::NS, 'tools', ['event' => 'on_tool_event', 'data' => 'chunk', 'toolCallId' => 'tc_3'], 8);

        $this->assertEquals(['event' => 'tool-output-delta', 'delta' => 'chunk', 'tool_call_id' => 'tc_3'], $result[0]['params']['data']);
    }

    public function testConvertsCustomModeEvents(): void
    {
        $payload = ['key' => 'value'];
        $result = Convert::toProtocolEvent(self::NS, 'custom', $payload, 9);

        $this->assertSame('custom', $result[0]['method']);
        $this->assertSame(['payload' => $payload], $result[0]['params']['data']);
    }

    public function testConvertsTasksModeEvents(): void
    {
        $payload = ['info' => 'tasks'];
        $result = Convert::toProtocolEvent(self::NS, 'tasks', $payload, 10);

        $this->assertSame('tasks', $result[0]['method']);
        $this->assertSame(self::NS, $result[0]['params']['namespace']);
        $this->assertSame($payload, $result[0]['params']['data']);
    }

    public function testReturnsEmptyForDebugAndFullStateCheckpointsPayloads(): void
    {
        foreach (['debug', 'checkpoints'] as $mode) {
            $result = Convert::toProtocolEvent(self::NS, $mode, ['info' => $mode, 'values' => [], 'config' => []], 10);
            $this->assertSame([], $result, $mode);
        }
    }

    public function testConvertsALightweightCheckpointsEnvelopeChunk(): void
    {
        $envelope = ['id' => 'ckpt-2', 'parent_id' => 'ckpt-1', 'step' => 1, 'source' => 'loop'];
        $result = Convert::toProtocolEvent(self::NS, 'checkpoints', $envelope, 20);

        $this->assertCount(1, $result);
        $this->assertSame(20, $result[0]['seq']);
        $this->assertSame('checkpoints', $result[0]['method']);
        $this->assertSame(self::NS, $result[0]['params']['namespace']);
        $this->assertSame($envelope, $result[0]['params']['data']);
    }

    public function testConvertsAValuesChunkWithoutAnInlineCheckpointField(): void
    {
        $result = Convert::toProtocolEvent(self::NS, 'values', ['count' => 1], 21);

        $this->assertCount(1, $result);
        $this->assertSame(21, $result[0]['seq']);
        $this->assertSame('values', $result[0]['method']);
        $this->assertSame(['count' => 1], $result[0]['params']['data']);
        $this->assertArrayNotHasKey('checkpoint', $result[0]['params']);
    }

    public function testEmitsOnlyAValuesEventWhenNoCheckpointEnvelopeChunkPrecedesIt(): void
    {
        $result = Convert::toProtocolEvent(self::NS, 'values', ['count' => 1], 21);

        $this->assertCount(1, $result);
        $this->assertSame('values', $result[0]['method']);
    }

    public function testReturnsEmptyArrayForUnknownModes(): void
    {
        $this->assertSame([], Convert::toProtocolEvent(self::NS, 'nonexistent', [], 11));
    }

    public function testPreservesNamespaceInParams(): void
    {
        $deep = ['root', 'sub', 'leaf'];
        $result = Convert::toProtocolEvent($deep, 'values', [], 12);

        $this->assertSame($deep, $result[0]['params']['namespace']);
    }

    public function testAssignsCorrectSeqNumbers(): void
    {
        $r1 = Convert::toProtocolEvent(self::NS, 'values', [], 100);
        $r2 = Convert::toProtocolEvent(self::NS, 'values', [], 200);

        $this->assertSame(100, $r1[0]['seq']);
        $this->assertSame(200, $r2[0]['seq']);
    }

    public function testUpdatesPayloadBecomesEmptyValuesWhenNonObject(): void
    {
        $this->assertEquals(['values' => []], Convert::toProtocolEvent(self::NS, 'updates', null, 13)[0]['params']['data']);
        $this->assertEquals(['values' => []], Convert::toProtocolEvent(self::NS, 'updates', 42, 14)[0]['params']['data']);
    }

    public function testToolCallIdIsPreservedWhenPresentAndDefaultsToEmptyStringWhenAbsent(): void
    {
        $withId = Convert::toProtocolEvent(self::NS, 'tools', ['event' => 'on_tool_start', 'name' => 't', 'toolCallId' => 'id_1'], 15);
        $this->assertSame('id_1', $withId[0]['params']['data']['tool_call_id']);

        $withoutId = Convert::toProtocolEvent(self::NS, 'tools', ['event' => 'on_tool_start', 'name' => 't'], 16);
        $this->assertSame('', $withoutId[0]['params']['data']['tool_call_id']);
    }

    // ---- beyond the upstream cases: the branches those 20 leave unpinned ------------------------

    public function testTheV3ModeListExcludesDebugAndCheckpoints(): void
    {
        $this->assertSame(['values', 'updates', 'messages', 'tools', 'custom', 'tasks'], Convert::STREAM_EVENTS_V3_MODES);
    }

    public function testACustomPayloadThatAlreadyCarriesANameIsPassedThrough(): void
    {
        $payload = ['name' => 'progress', 'payload' => 50];

        $this->assertSame($payload, Convert::toProtocolEvent([], 'custom', $payload, 0)[0]['params']['data']);
    }

    public function testNonObjectToolsPayloadsAndUnknownToolEventsBecomeToolErrors(): void
    {
        $bad = Convert::toProtocolEvent([], 'tools', 'oops', 0)[0]['params']['data'];
        $this->assertSame('Unexpected tools payload shape', $bad['message']);

        $unknown = Convert::toProtocolEvent([], 'tools', ['event' => 'on_wat'], 0)[0]['params']['data'];
        $this->assertSame('Unknown tool event: on_wat', $unknown['message']);
        $this->assertSame('', $unknown['tool_call_id']);
    }

    public function testToolOutputDeltaJsonEncodesNonStringData(): void
    {
        $data = Convert::toProtocolEvent([], 'tools', ['event' => 'on_tool_event', 'data' => ['a' => 1]], 0)[0]['params']['data'];

        $this->assertSame('{"a":1}', $data['delta']);
    }

    public function testCheckpointEnvelopeDetection(): void
    {
        $this->assertTrue(Convert::isCheckpointEnvelope(['id' => 'c', 'step' => 0]));
        $this->assertTrue(Convert::isCheckpointEnvelope(['id' => 'c', 'source' => 'input']));
        $this->assertFalse(Convert::isCheckpointEnvelope(['id' => 'c']));
        $this->assertFalse(Convert::isCheckpointEnvelope(['id' => 'c', 'step' => 1, 'values' => []]));
        $this->assertFalse(Convert::isCheckpointEnvelope(['id' => 'c', 'step' => 1, 'config' => []]));
        $this->assertFalse(Convert::isCheckpointEnvelope('nope'));
    }
}
