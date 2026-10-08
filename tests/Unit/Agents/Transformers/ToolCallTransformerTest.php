<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Transformers;

use LangChain\Messages\ToolMessage;
use LangGraph\Agents\Transformers\ToolCallStream;
use LangGraph\Agents\Transformers\ToolCallTransformer;
use LangGraph\Agents\Transformers\Types;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `langchain/src/agents/transformers/tests/tool-call.test.ts`.
 *
 * Upstream drives most of these through `agent.streamEvents(..., { version: "v3" })`, which this port does not have
 * (there is no run stream to host a transformer). The cases that do not depend on the run stream are recast as the
 * protocol events the agent would have produced; the ones about `streamTransformers` registration, `run.output`,
 * `run.interrupted` and `run.messages` test the run stream itself and have no counterpart.
 */
#[CoversClass(ToolCallTransformer::class)]
final class ToolCallTransformerTest extends TestCase
{
    /**
     * @param list<string> $namespace
     * @return array{method: string, params: array{namespace: list<string>, data: mixed}}
     */
    private static function toolEvent(array $data, array $namespace = ['tools']): array
    {
        return Types::protocolEvent('tools', $namespace, $data);
    }

    /**
     * @return list<ToolCallStream>
     */
    private static function callsOf(ToolCallTransformer $transformer): array
    {
        return iterator_to_array($transformer->init()['toolCalls'], false);
    }

    private static function started(string $id, string $name, mixed $input = []): array
    {
        return self::toolEvent(['event' => 'tool-started', 'tool_call_id' => $id, 'tool_name' => $name, 'input' => $input]);
    }

    private static function finished(string $id, mixed $output): array
    {
        return self::toolEvent(['event' => 'tool-finished', 'tool_call_id' => $id, 'output' => $output]);
    }

    public function testShouldEmitToolCallStreamsForEachToolInvocation(): void
    {
        $transformer = new ToolCallTransformer([]);

        foreach ([
            Types::protocolEvent('messages', ['model_request'], ['event' => 'content-block-finish', 'contentBlock' => ['type' => 'tool_call', 'id' => 'call_1', 'name' => 'add', 'args' => ['a' => 3, 'b' => 4]]]),
            Types::protocolEvent('messages', ['model_request'], ['event' => 'content-block-finish', 'contentBlock' => ['type' => 'tool_call', 'id' => 'call_2', 'name' => 'minus', 'args' => ['a' => 3, 'b' => 4]]]),
            self::started('call_1', 'add', ['a' => 3, 'b' => 4]),
            self::finished('call_1', 'The sum is 7'),
            self::started('call_2', 'minus', ['a' => 3, 'b' => 4]),
            self::finished('call_2', 'The difference is -1'),
        ] as $event) {
            $transformer->process($event);
        }
        $transformer->finalize();

        $calls = self::callsOf($transformer);
        self::assertCount(2, $calls);
        self::assertSame('add', $calls[0]->name);
        self::assertSame('call_1', $calls[0]->callId);
        self::assertSame('finished', $calls[0]->status->value());
        self::assertSame(['a' => 3, 'b' => 4], $calls[0]->input);
        self::assertSame('The sum is 7', $calls[0]->output->value());
        self::assertNull($calls[0]->error->value());
    }

    public function testShouldHandleMultipleToolCallsInASingleTurn(): void
    {
        $transformer = new ToolCallTransformer([]);

        foreach ([
            self::started('call_a', 'add', ['a' => 1, 'b' => 2]),
            self::started('call_b', 'add', ['a' => 3, 'b' => 4]),
            self::finished('call_b', '7'),
            self::finished('call_a', '3'),
        ] as $event) {
            $transformer->process($event);
        }
        $transformer->finalize();

        $calls = self::callsOf($transformer);
        self::assertCount(2, $calls);
        $ids = array_map(static fn (ToolCallStream $c): string => $c->callId, $calls);
        sort($ids);
        self::assertSame(['call_a', 'call_b'], $ids);
        self::assertSame('3', $calls[0]->output->value());
        self::assertSame('7', $calls[1]->output->value());
    }

    public function testShouldStreamToolCallsAlongsideOtherEvents(): void
    {
        $transformer = new ToolCallTransformer([]);

        foreach ([
            Types::protocolEvent('values', [], ['messages' => []]),
            self::started('call_s1', 'search', ['query' => 'weather']),
            Types::protocolEvent('messages', ['model_request'], ['event' => 'content-block-delta', 'delta' => ['type' => 'text-delta', 'text' => 'sunny']]),
            self::finished('call_s1', 'Results for: weather'),
        ] as $event) {
            self::assertTrue($transformer->process($event));
        }
        $transformer->finalize();

        $calls = self::callsOf($transformer);
        self::assertSame(['search'], array_map(static fn (ToolCallStream $c): string => $c->name, $calls));
        self::assertSame('Results for: weather', $calls[0]->output->value());
    }

    public function testShouldKeepToolCallStreamsPendingAcrossHeadlessToolInterrupts(): void
    {
        $transformer = new ToolCallTransformer([]);
        $started = self::toolEvent(['event' => 'tool-started', 'tool_call_id' => 'call_1', 'tool_name' => 'memory_list', 'input' => '{"limit":100}'], ['tools:abc']);

        $transformer->process($started);
        $pendingCall = self::callsOf($transformer)[0];

        $transformer->process(self::toolEvent([
            'event' => 'tool-error',
            'tool_call_id' => 'call_1',
            'message' => json_encode([[
                'id' => 'interrupt_1',
                'value' => ['type' => 'tool', 'toolCall' => ['id' => 'call_1', 'name' => 'memory_list', 'args' => ['limit' => 100]]],
            ]]),
        ], ['tools:abc']));
        $transformer->process($started);
        $transformer->process(self::toolEvent(['event' => 'tool-finished', 'tool_call_id' => 'call_1', 'output' => ['count' => 1]], ['tools:abc']));
        $transformer->finalize();

        self::assertSame('call_1', $pendingCall->callId);
        self::assertSame(['limit' => 100], $pendingCall->input);
        self::assertSame('finished', $pendingCall->status->value());
        self::assertSame(['count' => 1], $pendingCall->output->value());
        // The resumed tool-started did not make a second entry.
        self::assertCount(1, self::callsOf($transformer));
        self::assertTrue($transformer->init()['toolCalls']->isClosed());
    }

    public function testShouldKeepToolCallStreamsPendingAcrossRawNonHitlToolInterrupts(): void
    {
        // A tool that calls `interrupt(message)` directly, without the HITL middleware, surfaces a `tool-error`
        // whose message is the serialized interrupt with an arbitrary `value`. It is control flow, not a failure:
        // the call stays pending and its `output` is never rejected.
        $transformer = new ToolCallTransformer([]);
        $started = self::toolEvent(['event' => 'tool-started', 'tool_call_id' => 'call_1', 'tool_name' => 'review_action', 'input' => '{"toolArg":"delete_db"}'], ['tools:abc']);

        $transformer->process($started);
        $pendingCall = self::callsOf($transformer)[0];

        $transformer->process(self::toolEvent([
            'event' => 'tool-error',
            'tool_call_id' => 'call_1',
            'message' => json_encode([[
                'id' => 'interrupt_1',
                'value' => ['type' => 'ai', 'content' => 'Please review the "delete_db" action.', 'response_metadata' => ['cards' => ['action' => 'delete_db']]],
            ]]),
        ], ['tools:abc']));
        self::assertFalse($pendingCall->output->isSettled());

        $transformer->process($started);
        $transformer->process(self::toolEvent(['event' => 'tool-finished', 'tool_call_id' => 'call_1', 'output' => 'Executed "delete_db".'], ['tools:abc']));
        $transformer->finalize();

        self::assertSame('call_1', $pendingCall->callId);
        self::assertSame('finished', $pendingCall->status->value());
        self::assertSame('Executed "delete_db".', $pendingCall->output->value());
        self::assertCount(1, self::callsOf($transformer));
    }

    public function testTreatsAToolErrorShapedLikeAnArrayOfValueRecordsAsARealFailure(): void
    {
        // A genuine tool error whose message happens to be a JSON array of `{ value }` records (a validator) must
        // NOT be mistaken for a graph interrupt: real `Interrupt` entries carry a string `id`.
        $transformer = new ToolCallTransformer([]);
        $transformer->process(self::started('call_1', 'validate'));
        $pendingCall = self::callsOf($transformer)[0];

        $message = json_encode([['value' => 'bad input', 'message' => 'invalid']]);
        $transformer->process(self::toolEvent(['event' => 'tool-error', 'tool_call_id' => 'call_1', 'message' => $message]));
        $transformer->finalize();

        self::assertSame('error', $pendingCall->status->value());
        self::assertSame($message, $pendingCall->error->value());
        self::assertTrue($pendingCall->output->isRejected());
        try {
            $pendingCall->output->value();
            self::fail('output should have rejected');
        } catch (\RuntimeException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }

    public function testUnwrapsToolMessageOutputsInToolCallStreams(): void
    {
        $transformer = new ToolCallTransformer([]);
        $transformer->process(self::started('call_1', 'search'));
        $pendingCall = self::callsOf($transformer)[0];

        $transformer->process(self::finished('call_1', new ToolMessage(['content' => 'raw result', 'tool_call_id' => 'call_1', 'name' => 'search'])));
        $transformer->finalize();

        self::assertSame('raw result', $pendingCall->output->value());
    }

    public function testUnwrapsSerializedToolMessageOutputsInToolCallStreams(): void
    {
        $transformer = new ToolCallTransformer([]);
        $transformer->process(self::started('call_1', 'search'));
        $pendingCall = self::callsOf($transformer)[0];

        $transformer->process(self::finished('call_1', [
            'lc' => 1,
            'type' => 'constructor',
            'id' => ['langchain_core', 'messages', 'ToolMessage'],
            'kwargs' => ['content' => 'serialized result', 'tool_call_id' => 'call_1', 'name' => 'search'],
        ]));
        $transformer->finalize();

        self::assertSame('serialized result', $pendingCall->output->value());
    }

    public function testIgnoresEventsFromSubagentNamespaces(): void
    {
        $transformer = new ToolCallTransformer(['tools:parent']);

        // The agent's own tools node is one level below its path; a subagent's tool is two.
        $transformer->process(self::toolEvent(['event' => 'tool-started', 'tool_call_id' => 'own', 'tool_name' => 'mine', 'input' => []], ['tools:parent', 'tools:x']));
        $transformer->process(self::toolEvent(['event' => 'tool-started', 'tool_call_id' => 'nested', 'tool_name' => 'theirs', 'input' => []], ['tools:parent', 'tools:x', 'tools:y']));
        $transformer->process(self::toolEvent(['event' => 'tool-started', 'tool_call_id' => 'elsewhere', 'tool_name' => 'other', 'input' => []], ['other']));

        self::assertSame(['mine'], array_map(static fn (ToolCallStream $c): string => $c->name, self::callsOf($transformer)));
    }

    public function testFinalizeSettlesCallsThatNeverFinished(): void
    {
        $transformer = new ToolCallTransformer([]);
        $transformer->process(self::started('call_1', 'slow'));

        $transformer->finalize();

        $call = self::callsOf($transformer)[0];
        self::assertSame('finished', $call->status->value());
        self::assertNull($call->output->value());
        self::assertNull($call->error->value());
    }

    public function testFailRejectsPendingCallsAndFailsTheChannel(): void
    {
        $transformer = new ToolCallTransformer([]);
        $transformer->process(self::started('call_1', 'slow'));
        $call = self::callsOf($transformer)[0];

        $transformer->fail(new \RuntimeException('boom'));

        self::assertSame('error', $call->status->value());
        self::assertSame('boom', $call->error->value());
        self::assertTrue($call->output->isRejected());
        $this->expectExceptionMessage('boom');
        iterator_to_array($transformer->init()['toolCalls'], false);
    }

    public function testFactoryMakesAFreshTransformerEachTime(): void
    {
        $factory = ToolCallTransformer::factory(['a']);

        self::assertNotSame($factory(), $factory());
    }
}
