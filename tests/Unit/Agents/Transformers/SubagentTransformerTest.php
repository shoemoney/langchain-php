<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Transformers;

use LangChain\Tests\Unit\Agents\Transformers\Support\RecordingMessagesTransformer;
use LangGraph\Agents\Transformers\SubagentRunStream;
use LangGraph\Agents\Transformers\SubagentTransformer;
use LangGraph\Agents\Transformers\ToolCallStream;
use LangGraph\Agents\Transformers\Types;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `langchain/src/agents/transformers/tests/subagent.test.ts`: `run.subagents`.
 *
 * Upstream runs real nested agents through `streamEvents` v3. This port has no run stream, so each scenario is
 * recast as the protocol events such a run produces (`tasks` with `lc_agent_name` metadata, `tools`, `values`,
 * `lifecycle`, `messages`), fed to the transformer in the order Pregel emits them. Per-subagent messages come from
 * a stand-in messages transformer, as langgraph's has no PHP counterpart.
 */
#[CoversClass(SubagentTransformer::class)]
#[CoversClass(SubagentRunStream::class)]
final class SubagentTransformerTest extends TestCase
{
    private static function transformer(): SubagentTransformer
    {
        return new SubagentTransformer([], static fn (array $ns): RecordingMessagesTransformer => new RecordingMessagesTransformer($ns));
    }

    /**
     * @return list<SubagentRunStream>
     */
    private static function subagentsOf(SubagentTransformer $transformer): array
    {
        return iterator_to_array($transformer->init()['subagents'], false);
    }

    /**
     * @param list<string> $ns
     */
    private static function task(array $ns, string $id, string $node, ?string $agentName): array
    {
        return Types::protocolEvent('tasks', $ns, ['id' => $id, 'name' => $node, 'input' => [], 'metadata' => $agentName === null ? [] : ['lc_agent_name' => $agentName]]);
    }

    /**
     * @param list<string> $ns
     */
    private static function toolStarted(array $ns, string $callId, string $tool): array
    {
        return Types::protocolEvent('tools', $ns, ['event' => 'tool-started', 'tool_call_id' => $callId, 'tool_name' => $tool, 'input' => []]);
    }

    /**
     * @param list<string> $ns
     */
    private static function toolFinished(array $ns, string $callId, string $output): array
    {
        return Types::protocolEvent('tools', $ns, ['event' => 'tool-finished', 'tool_call_id' => $callId, 'output' => $output]);
    }

    /**
     * One AI message streamed at a namespace.
     *
     * @param list<string> $ns
     * @return list<array>
     */
    private static function streamedMessage(array $ns, string $text): array
    {
        return [
            Types::protocolEvent('messages', $ns, ['event' => 'message-start']),
            Types::protocolEvent('messages', $ns, ['event' => 'content-block-delta', 'delta' => ['type' => 'text-delta', 'text' => $text]]),
            Types::protocolEvent('messages', $ns, ['event' => 'message-finish']),
        ];
    }

    /**
     * @param list<string> $ns
     */
    private static function lifecycle(array $ns, string $event): array
    {
        return Types::protocolEvent('lifecycle', $ns, ['event' => $event]);
    }

    /**
     * @param list<array> $events
     */
    private static function feed(SubagentTransformer $transformer, array $events): void
    {
        foreach ($events as $event) {
            $transformer->process($event);
        }
    }

    /**
     * The events of a supervisor dispatching `$agent` from `$tool` (call `$callId`), which itself calls
     * `random_tool_call`, at the tools namespace `$ns`.
     *
     * @param list<string> $ns
     * @return list<array>
     */
    private static function subagentRun(array $ns, string $callId, string $tool, string $agent, string $reply, string $innerCall): array
    {
        $inner = [...$ns, 'tools:' . $innerCall];

        return [
            self::toolStarted($ns, $callId, $tool),
            self::task($ns, 'sub_model_' . $callId, 'model_request', $agent),
            Types::protocolEvent('values', $ns, ['messages' => ['weather in SF?']]),
            self::toolStarted($inner, $innerCall, 'random_tool_call'),
            self::toolFinished($inner, $innerCall, 'A random result'),
            ...self::streamedMessage($ns, $reply),
            Types::protocolEvent('values', $ns, ['messages' => ['weather in SF?', 'A random result', $reply]]),
            self::lifecycle($ns, 'completed'),
            self::toolFinished($ns, $callId, $reply),
        ];
    }

    public function testSurfacesANamedSubagentDispatchedFromAToolWithItsCause(): void
    {
        $transformer = self::transformer();

        self::feed($transformer, [
            self::task([], 'sup_model', 'model_request', 'supervisor'),
            ...self::subagentRun(['tools:abc'], 'call_w', 'call_weather', 'weather_agent', 'It is sunny in SF.', 'inner1'),
        ]);
        $transformer->finalize();

        $handles = self::subagentsOf($transformer);
        self::assertCount(1, $handles);
        $sub = $handles[0];
        self::assertSame('weather_agent', $sub->name);
        self::assertSame(['type' => 'toolCall', 'tool_call_id' => 'call_w'], $sub->cause);

        $messages = iterator_to_array($sub->messages, false);
        self::assertSame(['It is sunny in SF.'], $messages);

        $toolCalls = iterator_to_array($sub->toolCalls, false);
        self::assertCount(1, $toolCalls);
        self::assertInstanceOf(ToolCallStream::class, $toolCalls[0]);
        self::assertSame('random_tool_call', $toolCalls[0]->name);

        self::assertSame([], iterator_to_array($sub->subagents, false));
        self::assertSame(['messages' => ['weather in SF?', 'A random result', 'It is sunny in SF.']], $sub->output->value());
    }

    public function testSurfacesThreeSubagentsDispatchedInParallelFromOneToolsStep(): void
    {
        $transformer = self::transformer();
        $expected = [
            'sf_agent' => ['reply' => 'It is sunny in SF.', 'toolCallId' => 'call_sf'],
            'nyc_agent' => ['reply' => 'It is rainy in NYC.', 'toolCallId' => 'call_nyc'],
            'la_agent' => ['reply' => 'It is warm in LA.', 'toolCallId' => 'call_la'],
        ];

        // The three tools start together, then their subagents interleave.
        self::feed($transformer, [
            self::task([], 'sup_model', 'model_request', 'supervisor'),
            self::toolStarted(['tools:sf'], 'call_sf', 'call_sf'),
            self::toolStarted(['tools:nyc'], 'call_nyc', 'call_nyc'),
            self::toolStarted(['tools:la'], 'call_la', 'call_la'),
            self::task(['tools:sf'], 'm_sf', 'model_request', 'sf_agent'),
            self::task(['tools:nyc'], 'm_nyc', 'model_request', 'nyc_agent'),
            self::task(['tools:la'], 'm_la', 'model_request', 'la_agent'),
            ...self::streamedMessage(['tools:nyc'], $expected['nyc_agent']['reply']),
            ...self::streamedMessage(['tools:la'], $expected['la_agent']['reply']),
            ...self::streamedMessage(['tools:sf'], $expected['sf_agent']['reply']),
            self::lifecycle(['tools:la'], 'completed'),
            self::lifecycle(['tools:sf'], 'completed'),
            self::lifecycle(['tools:nyc'], 'completed'),
        ]);
        $transformer->finalize();

        $seen = [];
        foreach (self::subagentsOf($transformer) as $sub) {
            $seen[$sub->name] = ['messages' => iterator_to_array($sub->messages, false), 'cause' => $sub->cause];
        }

        $names = array_keys($seen);
        sort($names);
        self::assertSame(['la_agent', 'nyc_agent', 'sf_agent'], $names);
        foreach ($seen as $name => $info) {
            // Regression: parallel subagents' per-message streams used to come back empty.
            self::assertSame([$expected[$name]['reply']], $info['messages']);
            self::assertSame(['type' => 'toolCall', 'tool_call_id' => $expected[$name]['toolCallId']], $info['cause']);
        }
    }

    public function testIteratesNestedSubagentsViaSubSubagents(): void
    {
        $transformer = self::transformer();

        $weather = ['tools:w'];
        $geo = ['tools:w', 'tools:g'];
        self::feed($transformer, [
            self::task([], 'sup_model', 'model_request', 'supervisor'),
            self::toolStarted($weather, 'call_w', 'call_weather'),
            self::task($weather, 'w_model', 'model_request', 'weather_agent'),
            // The weather agent's tool dispatches the geo agent.
            self::toolStarted($geo, 'call_g', 'call_geo'),
            self::task($geo, 'g_model', 'model_request', 'geo_agent'),
            Types::protocolEvent('values', $geo, ['messages' => ['coordinates of SF?', 'SF is at 37.77, -122.41.']]),
            self::lifecycle($geo, 'completed'),
            Types::protocolEvent('values', $weather, ['messages' => ['weather in SF?', 'SF is at 37.77, -122.41.', 'It is sunny in SF.']]),
            self::lifecycle($weather, 'completed'),
        ]);
        $transformer->finalize();

        $top = self::subagentsOf($transformer);
        self::assertCount(1, $top);
        self::assertSame('weather_agent', $top[0]->name);

        $nested = iterator_to_array($top[0]->subagents, false);
        self::assertCount(1, $nested);
        self::assertSame('geo_agent', $nested[0]->name);
        self::assertSame(['type' => 'toolCall', 'tool_call_id' => 'call_g'], $nested[0]->cause);
        self::assertSame(['messages' => ['coordinates of SF?', 'SF is at 37.77, -122.41.']], $nested[0]->output->value());
        self::assertSame(['messages' => ['weather in SF?', 'SF is at 37.77, -122.41.', 'It is sunny in SF.']], $top[0]->output->value());
    }

    public function testDoesNotSurfaceSubagentsForAnAgentWithNoNestedNamedAgents(): void
    {
        $transformer = self::transformer();

        self::feed($transformer, [
            self::task([], 'plain_model', 'model_request', 'plain'),
            self::toolStarted(['tools:e'], 'call_e', 'echo'),
            // A plain subgraph (no `lc_agent_name`) is not an agent.
            self::task(['tools:e'], 'sg', 'node', null),
            self::toolFinished(['tools:e'], 'call_e', 'ok'),
        ]);
        $transformer->finalize();

        self::assertSame([], self::subagentsOf($transformer));
    }

    public function testDerivesTheCauseFromTheTaskInputWhenNoToolStartedWasSeen(): void
    {
        $transformer = self::transformer();

        self::feed($transformer, [
            Types::protocolEvent('tasks', [], ['id' => 'task42', 'name' => 'tools', 'input' => ['tool_call' => ['id' => 'call_x', 'name' => 'dispatch', 'args' => []]], 'metadata' => []]),
            self::task(['tools:task42'], 'sub', 'model_request', 'worker'),
        ]);

        $handles = self::subagentsOf($transformer);
        self::assertCount(1, $handles);
        self::assertSame(['type' => 'toolCall', 'tool_call_id' => 'call_x'], $handles[0]->cause);
    }

    public function testAFailedLifecycleRejectsTheSubagentOutput(): void
    {
        $transformer = self::transformer();

        self::feed($transformer, [
            self::task(['tools:a'], 'sub', 'model_request', 'worker'),
            self::lifecycle(['tools:a'], 'failed'),
        ]);

        $sub = self::subagentsOf($transformer)[0];
        self::assertTrue($sub->output->isRejected());
        $this->expectExceptionMessage('Subagent worker failed');
        $sub->output->value();
    }

    public function testFailRejectsOpenSubagentsAndFailsTheChannel(): void
    {
        $transformer = self::transformer();
        self::feed($transformer, [self::task(['tools:a'], 'sub', 'model_request', 'worker')]);
        $sub = self::subagentsOf($transformer)[0];

        $transformer->fail(new \RuntimeException('run failed'));

        self::assertTrue($sub->output->isRejected());
        $this->expectExceptionMessage('run failed');
        self::subagentsOf($transformer);
    }

    public function testWithoutAMessagesFactoryAHandleHasAnEmptyMessagesChannel(): void
    {
        $transformer = new SubagentTransformer();
        $transformer->process(self::task(['tools:a'], 'sub', 'model_request', 'worker'));
        $transformer->finalize();

        $sub = self::subagentsOf($transformer)[0];

        self::assertSame([], iterator_to_array($sub->messages, false));
        self::assertTrue($sub->messages->isClosed());
    }
}
