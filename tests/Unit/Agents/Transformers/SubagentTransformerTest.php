<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Transformers;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangChain\Tools\Schema;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Transformers\SubagentRunStream;
use LangGraph\Agents\Transformers\SubagentTransformer;
use LangGraph\Agents\Transformers\ToolCallStream;
use LangGraph\Agents\Transformers\ToolCallTransformer;
use LangGraph\Agents\Transformers\Types;
use LangGraph\Stream\ChatModelStream;
use LangGraph\Stream\RunStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * `langchain/src/agents/transformers/tests/subagent.test.ts`: `run.subagents`.
 *
 * Upstream builds a supervisor whose tool body invokes a nested `createAgent({ name })` and reads the handles off
 * `streamEvents(..., { version: "v3" })`. This port's engine feed carries neither the nested agent's events nor any
 * `tasks` chunk (see {@see RunStream}), so a nested agent never surfaces on a run created by `streamEvents`. The
 * three scenarios that need one are therefore driven through a REAL run stream built over the chunk source such an
 * engine would produce (`[namespace, mode, payload]`, with `tasks` carrying `lc_agent_name`): the run stream, the
 * protocol conversion, the lifecycle / values / messages transformers and the subagent and tool-call transformers
 * are all real, only the agents' execution is replayed. The case with no nested agent runs through
 * `ReactAgent::streamEvents`.
 */
#[CoversClass(SubagentTransformer::class)]
#[CoversClass(SubagentRunStream::class)]
final class SubagentTransformerTest extends TestCase
{
    private static function transformer(): SubagentTransformer
    {
        return new SubagentTransformer();
    }

    /**
     * @return list<SubagentRunStream>
     */
    private static function subagentsOf(SubagentTransformer $transformer): array
    {
        return iterator_to_array($transformer->init()['subagents'], false);
    }

    /**
     * A real run stream over engine-shaped chunks, with the agent transformers registered the way the agent does.
     *
     * @param list<array{0: list<string>, 1: string, 2: mixed}> $chunks
     */
    private static function runOver(array $chunks): RunStream
    {
        return RunStream::fromSource($chunks, [ToolCallTransformer::factory([]), SubagentTransformer::factory([])]);
    }

    /**
     * @param list<string> $ns
     * @return array{0: list<string>, 1: string, 2: mixed}
     */
    private static function taskChunk(array $ns, string $id, string $node, ?string $agentName): array
    {
        return [$ns, 'tasks', ['id' => $id, 'name' => $node, 'input' => [], 'metadata' => $agentName === null ? [] : ['lc_agent_name' => $agentName]]];
    }

    /**
     * @param list<string> $ns
     * @return array{0: list<string>, 1: string, 2: mixed}
     */
    private static function toolStartedChunk(array $ns, string $callId, string $tool): array
    {
        return [$ns, 'tools', ['event' => 'on_tool_start', 'toolCallId' => $callId, 'name' => $tool, 'input' => '{}']];
    }

    /**
     * @param list<string> $ns
     * @return array{0: list<string>, 1: string, 2: mixed}
     */
    private static function toolFinishedChunk(array $ns, string $callId, string $tool, string $output): array
    {
        return [$ns, 'tools', ['event' => 'on_tool_end', 'toolCallId' => $callId, 'name' => $tool, 'output' => $output]];
    }

    /**
     * @param list<string> $ns
     * @return array{0: list<string>, 1: string, 2: mixed}
     */
    private static function valuesChunk(array $ns, array $values): array
    {
        return [$ns, 'values', $values];
    }

    /**
     * One AI message streamed by the model node of the agent at `$ns` (one namespace level below it).
     *
     * @param list<string> $ns
     * @return list<array{0: list<string>, 1: string, 2: mixed}>
     */
    private static function streamedMessageChunks(array $ns, string $text, string $runId): array
    {
        $at = [...$ns, 'model_request:' . $runId];
        $meta = ['langgraph_node' => 'model_request', 'run_id' => $runId];

        return [
            [$at, 'messages', [['event' => 'message-start', 'id' => $runId], $meta]],
            [$at, 'messages', [['event' => 'content-block-start', 'index' => 0, 'content' => ['type' => 'text', 'text' => '']], $meta]],
            [$at, 'messages', [['event' => 'content-block-delta', 'index' => 0, 'delta' => ['type' => 'text-delta', 'text' => $text]], $meta]],
            [$at, 'messages', [['event' => 'content-block-finish', 'index' => 0, 'content' => ['type' => 'text', 'text' => $text]], $meta]],
            [$at, 'messages', [['event' => 'message-finish'], $meta]],
        ];
    }

    /**
     * The chunks of a supervisor dispatching `$agent` from `$tool` (call `$callId`) at the tools namespace `$ns`;
     * the subagent calls `random_tool_call` itself and replies.
     *
     * @param list<string> $ns
     * @return list<array{0: list<string>, 1: string, 2: mixed}>
     */
    private static function subagentChunks(array $ns, string $callId, string $tool, string $agent, string $reply, string $innerCall): array
    {
        $inner = [...$ns, 'tools:' . $innerCall];

        return [
            self::toolStartedChunk($ns, $callId, $tool),
            self::taskChunk($ns, 'sub_model_' . $callId, 'model_request', $agent),
            self::valuesChunk($ns, ['messages' => ['weather in SF?']]),
            self::toolStartedChunk($inner, $innerCall, 'random_tool_call'),
            self::toolFinishedChunk($inner, $innerCall, 'random_tool_call', 'A random result'),
            ...self::streamedMessageChunks($ns, $reply, 'run_' . $callId),
            self::valuesChunk($ns, ['messages' => ['weather in SF?', 'A random result', $reply]]),
            self::toolFinishedChunk($ns, $callId, $tool, $reply),
        ];
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

    public function testSurfacesANamedSubagentDispatchedFromAToolWithItsCause(): void
    {
        $run = self::runOver([
            self::taskChunk([], 'sup_model', 'model_request', 'supervisor'),
            ...self::subagentChunks(['tools:abc'], 'call_w', 'call_weather', 'weather_agent', 'It is sunny in SF.', 'inner1'),
            self::valuesChunk([], ['messages' => ['Done.']]),
        ]);

        // Draining `run.subagents` drives the underlying stream to completion.
        $handles = [];
        $subHandles = [];
        foreach ($run->subagents as $sub) {
            $handles[] = $sub;
            self::assertSame('weather_agent', $sub->name);
            self::assertSame(['type' => 'toolCall', 'tool_call_id' => 'call_w'], $sub->cause);

            $messages = [];
            $events = [];
            foreach ($sub->messages as $message) {
                self::assertInstanceOf(ChatModelStream::class, $message);
                $messages[] = $message->text();
                foreach ($message as $event) {
                    $events[] = $event;
                }
            }
            self::assertCount(1, $messages);
            self::assertSame('It is sunny in SF.', implode('', $messages));
            self::assertSame(
                ['message-start', 'content-block-start', 'content-block-delta', 'content-block-finish', 'message-finish'],
                array_column($events, 'event'),
            );

            $toolCalls = [];
            foreach ($sub->toolCalls as $toolCall) {
                self::assertInstanceOf(ToolCallStream::class, $toolCall);
                $toolCalls[] = $toolCall->name;
            }
            self::assertSame(['random_tool_call'], $toolCalls);

            foreach ($sub->subagents as $child) {
                $subHandles[] = $child;
            }
        }

        self::assertCount(1, $handles);
        self::assertSame(['Done.'], $run->output()['messages']);
        self::assertSame(['messages' => ['weather in SF?', 'A random result', 'It is sunny in SF.']], $handles[0]->output->value());
        self::assertSame([], $subHandles);
    }

    public function testSurfacesThreeSubagentsDispatchedInParallelFromOneToolsStep(): void
    {
        $expected = [
            'sf_agent' => ['reply' => 'It is sunny in SF.', 'toolCallId' => 'call_sf'],
            'nyc_agent' => ['reply' => 'It is rainy in NYC.', 'toolCallId' => 'call_nyc'],
            'la_agent' => ['reply' => 'It is warm in LA.', 'toolCallId' => 'call_la'],
        ];

        // The three tools start together, then their subagents interleave.
        $run = self::runOver([
            self::taskChunk([], 'sup_model', 'model_request', 'supervisor'),
            self::toolStartedChunk(['tools:sf'], 'call_sf', 'call_sf'),
            self::toolStartedChunk(['tools:nyc'], 'call_nyc', 'call_nyc'),
            self::toolStartedChunk(['tools:la'], 'call_la', 'call_la'),
            self::taskChunk(['tools:sf'], 'm_sf', 'model_request', 'sf_agent'),
            self::taskChunk(['tools:nyc'], 'm_nyc', 'model_request', 'nyc_agent'),
            self::taskChunk(['tools:la'], 'm_la', 'model_request', 'la_agent'),
            ...self::streamedMessageChunks(['tools:nyc'], $expected['nyc_agent']['reply'], 'run_nyc'),
            ...self::streamedMessageChunks(['tools:la'], $expected['la_agent']['reply'], 'run_la'),
            ...self::streamedMessageChunks(['tools:sf'], $expected['sf_agent']['reply'], 'run_sf'),
            self::valuesChunk([], ['messages' => ['Done.']]),
        ]);

        // Handles can arrive in any order: collect them keyed by name.
        $seen = [];
        foreach ($run->subagents as $sub) {
            $messages = [];
            foreach ($sub->messages as $message) {
                $messages[] = $message->text();
            }
            $seen[$sub->name] = ['messages' => $messages, 'cause' => $sub->cause];
        }

        $names = array_keys($seen);
        sort($names);
        self::assertSame(['la_agent', 'nyc_agent', 'sf_agent'], $names);
        foreach ($seen as $name => $info) {
            // Regression: parallel subagents' per-message streams used to come back empty.
            self::assertCount(1, $info['messages']);
            self::assertSame($expected[$name]['reply'], implode('', $info['messages']));
            self::assertSame(['type' => 'toolCall', 'tool_call_id' => $expected[$name]['toolCallId']], $info['cause']);
        }
        self::assertSame(['Done.'], $run->output()['messages']);
    }

    public function testIteratesNestedSubagentsViaSubSubagents(): void
    {
        $weather = ['tools:w'];
        $geo = ['tools:w', 'tools:g'];
        $run = self::runOver([
            self::taskChunk([], 'sup_model', 'model_request', 'supervisor'),
            self::toolStartedChunk($weather, 'call_w', 'call_weather'),
            self::taskChunk($weather, 'w_model', 'model_request', 'weather_agent'),
            self::valuesChunk($weather, ['messages' => ['weather in SF?']]),
            // The weather agent's tool dispatches the geo agent.
            self::toolStartedChunk($geo, 'call_g', 'call_geo'),
            self::taskChunk($geo, 'g_model', 'model_request', 'geo_agent'),
            self::valuesChunk($geo, ['messages' => ['coordinates of SF?']]),
            self::toolStartedChunk([...$geo, 'tools:r'], 'call_r', 'random_tool_call'),
            self::toolFinishedChunk([...$geo, 'tools:r'], 'call_r', 'random_tool_call', 'A random result'),
            self::valuesChunk($geo, ['messages' => ['coordinates of SF?', 'A random result', 'SF is at 37.77, -122.41.']]),
            self::valuesChunk($weather, ['messages' => ['weather in SF?', 'SF is at 37.77, -122.41.', 'It is sunny in SF.']]),
            self::valuesChunk([], ['messages' => ['Done.']]),
        ]);

        // Each handle's nested `subagents` channel is replayable, so grandchildren drain within the same pass.
        $top = [];
        $nested = [];
        foreach ($run->subagents as $sub) {
            $top[] = $sub;
            foreach ($sub->subagents as $child) {
                $nested[] = $child;
            }
            self::assertSame(['messages' => ['weather in SF?', 'SF is at 37.77, -122.41.', 'It is sunny in SF.']], $sub->output->value());
        }

        self::assertCount(1, $top);
        self::assertSame('weather_agent', $top[0]->name);

        self::assertCount(1, $nested);
        self::assertSame('geo_agent', $nested[0]->name);
        self::assertSame(['type' => 'toolCall', 'tool_call_id' => 'call_g'], $nested[0]->cause);
        self::assertSame(['messages' => ['coordinates of SF?', 'A random result', 'SF is at 37.77, -122.41.']], $nested[0]->output->value());

        foreach ($nested[0]->toolCalls as $toolCall) {
            self::assertSame('random_tool_call', $toolCall->name);
        }
        self::assertCount(1, iterator_to_array($nested[0]->toolCalls, false));
    }

    public function testDoesNotSurfaceSubagentsForAnAgentWithNoNestedNamedAgents(): void
    {
        $echo = tool(static fn (array $in): string => 'ok', ['name' => 'echo', 'description' => 'echoes', 'schema' => Schema::object([])]);
        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([
                new AIMessage(['content' => '', 'tool_calls' => [['name' => 'echo', 'args' => [], 'id' => 'call_e', 'type' => 'tool_call']]]),
                new AIMessage('done'),
            ]),
            'tools' => [$echo],
            'name' => 'plain',
        ]);

        $run = $agent->streamEvents(['messages' => [new HumanMessage('hi')]], null, 'v3');

        $handles = [];
        foreach ($run->subagents as $sub) {
            $handles[] = $sub;
        }
        self::assertSame([], $handles);
        self::assertSame(['echo'], array_map(static fn (ToolCallStream $c): string => $c->name, iterator_to_array($run->toolCalls, false)));
    }

    public function testASubagentsChannelsAreDrivenByTheRunWithoutDrainingTheParentFirst(): void
    {
        $run = self::runOver([
            self::taskChunk([], 'sup_model', 'model_request', 'supervisor'),
            ...self::subagentChunks(['tools:abc'], 'call_w', 'call_weather', 'weather_agent', 'It is sunny in SF.', 'inner1'),
        ]);

        // Take the first handle and read its (still empty) channels: asking for more advances the run.
        $sub = $run->subagents->getIterator()->current();

        self::assertSame(['It is sunny in SF.'], array_map(static fn (ChatModelStream $m): string => $m->text(), iterator_to_array($sub->messages, false)));
        self::assertSame(['random_tool_call'], array_map(static fn (ToolCallStream $c): string => $c->name, iterator_to_array($sub->toolCalls, false)));
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

    public function testAHandleCarriesAMessagesProjectionScopedToTheSubagent(): void
    {
        $transformer = self::transformer();
        $transformer->process(self::task(['tools:a'], 'sub', 'model_request', 'worker'));
        $transformer->process(Types::protocolEvent('messages', ['tools:a', 'model_request:1'], ['event' => 'message-start', 'run_id' => 'r']));
        $transformer->process(Types::protocolEvent('messages', ['tools:a', 'model_request:1'], ['event' => 'content-block-delta', 'delta' => ['type' => 'text-delta', 'text' => 'hello'], 'run_id' => 'r']));
        $transformer->process(Types::protocolEvent('messages', ['tools:a', 'model_request:1'], ['event' => 'message-finish', 'run_id' => 'r']));
        // Not the subagent's own model node: two levels below it.
        $transformer->process(Types::protocolEvent('messages', ['tools:a', 'tools:b', 'model_request:2'], ['event' => 'message-start', 'run_id' => 'other']));
        $transformer->finalize();

        $sub = self::subagentsOf($transformer)[0];

        self::assertSame(['hello'], array_map(static fn (ChatModelStream $m): string => $m->text(), iterator_to_array($sub->messages, false)));
    }

    public function testFactoryMakesAFreshTransformerEachTime(): void
    {
        $factory = SubagentTransformer::factory(['a']);

        self::assertNotSame($factory(), $factory());
    }
}
