<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Transformers;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware;
use LangGraph\Agents\Middleware\HumanInTheLoopMiddleware;
use LangGraph\Agents\ReactAgent;
use LangGraph\Agents\Transformers\ToolCallStream;
use LangGraph\Agents\Transformers\ToolCallTransformer;
use LangGraph\Agents\Transformers\Types;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Stream\AbstractStreamTransformer;
use LangGraph\Stream\RunStream;
use LangGraph\Stream\StreamChannel;
use LangGraph\Stream\StreamTransformer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * `langchain/src/agents/transformers/tests/tool-call.test.ts`.
 *
 * The nine `streamEvents` cases run a real agent through `streamEvents(..., 'v3')`. The run is pull-driven (no
 * event loop), so a tool call's `output` / `status` settle once the run has been driven past the call:
 * the tests read `$run->output()` (or drain `$run->toolCalls`) before reading them. The other cases feed the
 * transformer protocol events directly.
 */
#[CoversClass(ToolCallTransformer::class)]
#[CoversClass(ReactAgent::class)]
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

    private static function sumTool(string $name = 'add', string $prefix = 'The sum is '): StructuredTool
    {
        return tool(
            static fn (array $in): string => $prefix . ($in['a'] + $in['b']),
            ['name' => $name, 'description' => 'Adds two numbers', 'schema' => Schema::object(['a' => ['type' => 'number'], 'b' => ['type' => 'number']], ['a', 'b'])],
        );
    }

    /**
     * @param array<string, mixed> $args
     */
    private static function toolCallMessage(string $name, array $args, string $id): array
    {
        return ['name' => $name, 'args' => $args, 'id' => $id, 'type' => 'tool_call'];
    }

    /**
     * An AI message calling tools.
     *
     * @param list<array<string, mixed>> $calls
     */
    private static function callsMessage(array $calls): AIMessage
    {
        return new AIMessage(['content' => '', 'tool_calls' => $calls]);
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function agent(array $responses, array $tools, array $options = []): ReactAgent
    {
        return Agent::create(['model' => AgentAssertions::fakeChat($responses), 'tools' => $tools, ...$options]);
    }

    private static function eventCounter(): \Closure
    {
        return static fn (): StreamTransformer => new class extends AbstractStreamTransformer {
            private readonly StreamChannel $eventCount;

            private int $count = 0;

            public function __construct()
            {
                $this->eventCount = StreamChannel::remote('eventCount');
            }

            public function init(): array
            {
                return ['eventCount' => $this->eventCount];
            }

            public function process(array $event): bool
            {
                $this->eventCount->push(++$this->count);

                return true;
            }
        };
    }

    private static function methodTracker(): \Closure
    {
        return static fn (): StreamTransformer => new class extends AbstractStreamTransformer {
            private readonly StreamChannel $methods;

            public function __construct()
            {
                $this->methods = StreamChannel::remote('methods');
            }

            public function init(): array
            {
                return ['methods' => $this->methods];
            }

            public function process(array $event): bool
            {
                $this->methods->push($event['method']);

                return true;
            }
        };
    }

    private static function stream(ReactAgent $agent, string $prompt, array|null $config = null): RunStream
    {
        return $agent->streamEvents(['messages' => [new HumanMessage($prompt)]], $config, 'v3');
    }

    public function testShouldEmitToolCallStreamsForEachToolInvocation(): void
    {
        $agent = self::agent(
            [
                self::callsMessage([self::toolCallMessage('add', ['a' => 3, 'b' => 4], 'call_1'), self::toolCallMessage('minus', ['a' => 3, 'b' => 4], 'call_2')]),
                new AIMessage('The answer is 7.'),
            ],
            [self::sumTool(), tool(static fn (array $in): string => 'The difference is ' . ($in['a'] - $in['b']), ['name' => 'minus', 'description' => 'Subtracts two numbers', 'schema' => Schema::object(['a' => ['type' => 'number'], 'b' => ['type' => 'number']], ['a', 'b'])])],
        );
        $run = self::stream($agent, 'What is 3 + 4?');

        $toolCalls = [];
        foreach ($run->toolCalls as $call) {
            $run->output();
            $toolCalls[] = ['name' => $call->name, 'callId' => $call->callId, 'input' => $call->input, 'output' => $call->output->value(), 'status' => $call->status->value()];
        }

        self::assertCount(2, $toolCalls);
        self::assertSame('add', $toolCalls[0]['name']);
        self::assertSame('call_1', $toolCalls[0]['callId']);
        self::assertSame('finished', $toolCalls[0]['status']);
        self::assertSame(['a' => 3, 'b' => 4], $toolCalls[0]['input']);
        self::assertSame('The sum is 7', $toolCalls[0]['output']);
        self::assertSame('minus', $toolCalls[1]['name']);
        self::assertSame('The difference is -1', $toolCalls[1]['output']);
    }

    public function testDoesNotLeakToolResultsThroughRunMessagesWhileConsumingToolCalls(): void
    {
        $listTool = tool(static fn (array $in): string => '[]', ['name' => 'list_items', 'description' => 'List available items', 'schema' => Schema::object([])]);
        $agent = self::agent(
            [self::callsMessage([self::toolCallMessage('list_items', [], 'call_list')]), new AIMessage('No items found.')],
            [$listTool],
            ['checkpointer' => new MemorySaver()],
        );
        $run = self::stream($agent, 'List items', ['configurable' => ['thread_id' => 'tool-stream-1'], 'recursionLimit' => 50]);

        $messageTexts = [];
        foreach ($run->messages() as $messageStream) {
            $messageTexts[] = $messageStream->text();
        }
        $toolOutputs = [];
        foreach ($run->toolCalls as $call) {
            $toolOutputs[] = $call->output->value();
        }
        $finalState = $run->output();

        // The tool's own result ("[]") is a tool-role message: it belongs to `toolCalls`, never to `messages`.
        self::assertSame(['', 'No items found.'], $messageTexts);
        self::assertSame(['[]'], $toolOutputs);
        self::assertGreaterThanOrEqual(3, \count($finalState['messages']));
        self::assertSame(['human', 'ai', 'tool', 'ai'], array_map(static fn ($m): string => $m->type, $finalState['messages']));
        self::assertSame('call_list', $finalState['messages'][1]->toolCalls[0]['id']);
        self::assertSame('call_list', $finalState['messages'][2]->toolCallId);
    }

    public function testShouldStreamMessagesAlongsideToolCalls(): void
    {
        $searchTool = tool(static fn (array $in): string => 'Results for: ' . $in['query'], ['name' => 'search', 'description' => 'Search the web', 'schema' => Schema::object(['query' => ['type' => 'string']], ['query'])]);
        $agent = self::agent(
            [self::callsMessage([self::toolCallMessage('search', ['query' => 'weather'], 'call_s1')]), new AIMessage('The weather is sunny.')],
            [$searchTool],
        );
        $run = self::stream($agent, 'Search for weather');

        $calls = [];
        foreach ($run->toolCalls as $call) {
            $calls[] = $call->name;
            $run->output();
            self::assertSame('Results for: weather', $call->output->value());
        }
        $finalState = $run->output();

        self::assertContains('search', $calls);
        self::assertGreaterThanOrEqual(3, \count($finalState['messages']));
        $texts = array_map(static fn ($m): string => $m->text(), iterator_to_array($run->messages(), false));
        self::assertContains('The weather is sunny.', $texts);
    }

    public function testShouldResolveOutputWithTheFinalAgentState(): void
    {
        $run = self::stream(self::agent([new AIMessage('hi there')], []), 'hi');

        $state = $run->output();

        self::assertArrayHasKey('messages', $state);
        self::assertGreaterThanOrEqual(2, \count($state['messages']));
        self::assertSame('hi there', $state['messages'][1]->content);
    }

    public function testShouldPassUserDefinedStreamTransformersRegisteredAtCreationTime(): void
    {
        $agent = self::agent([new AIMessage('ok')], [], ['streamTransformers' => [self::eventCounter()]]);

        $counts = iterator_to_array(self::stream($agent, 'hi')->extensions()['eventCount'], false);

        self::assertNotEmpty($counts);
        self::assertSame(\count($counts), $counts[\count($counts) - 1]);
    }

    public function testShouldPassStreamTransformersRegisteredOnMiddleware(): void
    {
        $middleware = Middleware::create(['name' => 'StreamMiddleware', 'streamTransformers' => [self::eventCounter()]]);
        $agent = self::agent([new AIMessage('ok')], [], ['middleware' => [$middleware]]);

        $counts = iterator_to_array(self::stream($agent, 'hi')->extensions()['eventCount'], false);

        self::assertNotEmpty($counts);
        self::assertSame(\count($counts), $counts[\count($counts) - 1]);
    }

    public function testShouldPassCallSiteTransformersViaStreamEventsConfig(): void
    {
        $agent = self::agent([new AIMessage('ok')], []);

        $run = self::stream($agent, 'hi', ['transformers' => [self::methodTracker()]]);
        $seenMethods = iterator_to_array($run->extensions()['methods'], false);

        self::assertNotEmpty($seenMethods);
        self::assertContains('values', $seenMethods);
    }

    public function testCallSiteTransformersAreNotSentToTheGraphAsConfig(): void
    {
        $agent = self::agent([new AIMessage('ok')], []);

        $run = self::stream($agent, 'hi', ['transformers' => [self::methodTracker()], 'tags' => ['t']]);

        self::assertArrayHasKey('methods', $run->extensions());
        self::assertSame('ok', $run->output()['messages'][1]->content);
    }

    public function testShouldHandleMultipleToolCallsInASingleTurn(): void
    {
        $agent = self::agent(
            [
                self::callsMessage([self::toolCallMessage('add', ['a' => 1, 'b' => 2], 'call_a'), self::toolCallMessage('add', ['a' => 3, 'b' => 4], 'call_b')]),
                new AIMessage('Done: 3 and 7'),
            ],
            [self::sumTool('add', '')],
        );
        $run = self::stream($agent, 'Add 1+2 and 3+4');

        $toolCalls = [];
        foreach ($run->toolCalls as $call) {
            $run->output();
            $toolCalls[] = ['name' => $call->name, 'callId' => $call->callId, 'output' => $call->output->value()];
        }

        self::assertCount(2, $toolCalls);
        $ids = array_map(static fn (array $c): string => $c['callId'], $toolCalls);
        sort($ids);
        self::assertSame(['call_a', 'call_b'], $ids);
        $outputs = array_column($toolCalls, 'output', 'callId');
        self::assertSame(['call_a' => '3', 'call_b' => '7'], $outputs);
    }

    public function testShouldExposeInterruptedFlagWhenHitlMiddlewareTriggersAnInterrupt(): void
    {
        $writeFile = tool(
            static fn (array $in): string => 'Wrote ' . \strlen($in['content']) . ' chars to ' . $in['filename'],
            ['name' => 'write_file', 'description' => 'Write content to a file', 'schema' => Schema::object(['filename' => ['type' => 'string'], 'content' => ['type' => 'string']], ['filename', 'content'])],
        );
        $agent = self::agent(
            [self::callsMessage([self::toolCallMessage('write_file', ['filename' => 'test.txt', 'content' => 'hello'], 'call_w1')]), new AIMessage('Done writing.')],
            [$writeFile],
            [
                'middleware' => [HumanInTheLoopMiddleware::create(['interruptOn' => ['write_file' => ['allowedDecisions' => ['approve']]]])],
                'checkpointer' => new MemorySaver(),
            ],
        );

        $run = self::stream($agent, 'Write hello to test.txt', ['configurable' => ['thread_id' => 'hitl-stream-test']]);

        self::assertNotNull($run->output());
        self::assertTrue($run->interrupted());
        self::assertGreaterThanOrEqual(1, \count($run->interrupts()));
        self::assertSame(
            [
                'actionRequests' => [['name' => 'write_file', 'args' => ['filename' => 'test.txt', 'content' => 'hello'], 'description' => $run->interrupts()[0]['payload']['actionRequests'][0]['description']]],
                'reviewConfigs' => [['actionName' => 'write_file', 'allowedDecisions' => ['approve']]],
            ],
            $run->interrupts()[0]['payload'],
        );
        self::assertStringContainsString('write_file', $run->interrupts()[0]['payload']['actionRequests'][0]['description']);
    }

    public function testRunStreamHasNoSubagentsForAPlainToolCall(): void
    {
        $agent = self::agent(
            [self::callsMessage([self::toolCallMessage('add', ['a' => 1, 'b' => 1], 'c1')]), new AIMessage('2')],
            [self::sumTool()],
        );
        $run = self::stream($agent, 'hi');

        self::assertSame([], iterator_to_array($run->subagents, false));
        self::assertSame(['add'], array_map(static fn (ToolCallStream $c): string => $c->name, iterator_to_array($run->toolCalls, false)));
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
        self::assertTrue($transformer->init()['toolCalls']->done());
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
