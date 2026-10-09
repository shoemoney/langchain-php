<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prebuilt;

use LangChain\Messages\AIMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Errors\GraphInterrupt;
use LangGraph\Errors\ParentCommand;
use LangGraph\Graph\MessagesAnnotation;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Send;
use LangGraph\Prebuilt\ToolNode;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;
use function LangGraph\Pregel\interrupt;

/**
 * Port of the `ToolNode with Commands` (3 cases) and `ToolNode should raise GraphInterrupt` (1 case)
 * describe blocks of `langgraph-core/src/tests/prebuilt.test.ts`.
 */
#[CoversClass(ToolNode::class)]
final class ToolNodeCommandsTest extends TestCase
{
    private static function call(string $name, array $args, string $id): array
    {
        return ['name' => $name, 'args' => $args, 'id' => $id];
    }

    private static function ai(array ...$calls): AIMessage
    {
        return new AIMessage(['content' => '', 'tool_calls' => $calls]);
    }

    /** A tool that hands the conversation to `$target`, as a parent-graph Command. */
    private static function transfer(string $name, string $target, bool $arrayUpdate = false): StructuredTool
    {
        return tool(
            static function (array $in, mixed $rm, RunnableConfig $config) use ($name, $target, $arrayUpdate): Command {
                $message = new ToolMessage([
                    'content' => 'Transferred to ' . ucfirst($target),
                    'tool_call_id' => $config->toolCall['id'],
                    'name' => $name,
                ]);

                return new Command(
                    graph: Command::PARENT,
                    update: $arrayUpdate ? [$message] : ['messages' => [$message]],
                    goto: $target,
                );
            },
            ['name' => $name, 'description' => 'Transfer to ' . $target, 'schema' => Schema::object([])],
        );
    }

    private static function add(): StructuredTool
    {
        return tool(
            static fn (array $in): string => (string) ($in['a'] + $in['b']),
            ['name' => 'add', 'description' => 'Add two numbers', 'schema' => Schema::object(['a' => ['type' => 'number'], 'b' => ['type' => 'number']], ['a', 'b'])],
        );
    }

    private function assertTransfer(Command $command, string $callId, string $name, bool $arrayUpdate = false): void
    {
        self::assertSame(Command::PARENT, $command->graph);
        self::assertSame('bob', $command->goto);
        $messages = $arrayUpdate ? $command->update : $command->update['messages'];
        self::assertCount(1, $messages);
        self::assertSame('Transferred to Bob', $messages[0]->content);
        self::assertSame($callId, $messages[0]->toolCallId);
        self::assertSame($name, $messages[0]->name);
    }

    public function testCanHandleToolsReturningCommandsWithDictInput(): void
    {
        $transfer = self::transfer('transfer_to_bob', 'bob');
        $asyncTransfer = self::transfer('async_transfer_to_bob', 'bob');

        $result = (new ToolNode([self::add(), $transfer]))->invoke(['messages' => [
            self::ai(self::call('add', ['a' => 1, 'b' => 2], '1'), self::call('transfer_to_bob', [], '2')),
        ]]);

        self::assertCount(2, $result);
        self::assertSame('3', $result[0]['messages'][0]->content);
        self::assertSame('1', $result[0]['messages'][0]->toolCallId);
        self::assertSame('add', $result[0]['messages'][0]->name);
        self::assertInstanceOf(Command::class, $result[1]);
        $this->assertTransfer($result[1], '2', 'transfer_to_bob');

        $single = (new ToolNode([$transfer]))->invoke(['messages' => [self::ai(self::call('transfer_to_bob', [], '1'))]]);
        self::assertCount(1, $single);
        $this->assertTransfer($single[0], '1', 'transfer_to_bob');

        $second = (new ToolNode([$asyncTransfer]))->invoke(['messages' => [self::ai(self::call('async_transfer_to_bob', [], '1'))]]);
        $this->assertTransfer($second[0], '1', 'async_transfer_to_bob');

        $multiple = (new ToolNode([$transfer, $asyncTransfer]))->invoke(['messages' => [
            self::ai(self::call('transfer_to_bob', [], '1'), self::call('async_transfer_to_bob', [], '2')),
        ]]);
        self::assertCount(2, $multiple);
        $this->assertTransfer($multiple[0], '1', 'transfer_to_bob');
        $this->assertTransfer($multiple[1], '2', 'async_transfer_to_bob');
    }

    public function testCanHandleToolsReturningCommandsWithArrayInput(): void
    {
        $transfer = self::transfer('transfer_to_bob', 'bob', true);
        $asyncTransfer = self::transfer('async_transfer_to_bob', 'bob', true);

        $result = (new ToolNode([self::add(), $transfer]))->invoke([
            self::ai(self::call('add', ['a' => 1, 'b' => 2], '1'), self::call('transfer_to_bob', [], '2')),
        ]);

        self::assertCount(2, $result);
        self::assertCount(1, $result[0]);
        self::assertSame('3', $result[0][0]->content);
        self::assertSame('add', $result[0][0]->name);
        $this->assertTransfer($result[1], '2', 'transfer_to_bob', true);

        foreach ([$transfer, $asyncTransfer] as $one) {
            $single = (new ToolNode([$one]))->invoke([self::ai(self::call($one->name, [], '1'))]);
            self::assertCount(1, $single);
            $this->assertTransfer($single[0], '1', $one->name, true);
        }

        $multiple = (new ToolNode([$transfer, $asyncTransfer]))->invoke([
            self::ai(self::call('transfer_to_bob', [], '1'), self::call('async_transfer_to_bob', [], '2')),
        ]);
        self::assertCount(2, $multiple);
        $this->assertTransfer($multiple[0], '1', 'transfer_to_bob', true);
        $this->assertTransfer($multiple[1], '2', 'async_transfer_to_bob', true);
    }

    public function testShouldHandleParentCommandsWithSend(): void
    {
        $toAlice = self::sendTool('transfer_to_alice', 'alice');
        $toBob = self::sendTool('transfer_to_bob', 'bob');

        $result = (new ToolNode([$toAlice, $toBob]))->invoke([
            self::ai(self::call('transfer_to_alice', [], '1'), self::call('transfer_to_bob', [], '2')),
        ]);

        self::assertCount(1, $result);
        self::assertSame(Command::PARENT, $result[0]->graph);
        $sends = $result[0]->goto;
        self::assertCount(2, $sends);
        self::assertContainsOnlyInstancesOf(Send::class, $sends);
        self::assertSame('alice', $sends[0]->node);
        self::assertSame('Transferred to Alice', $sends[0]->args['messages'][0]->content);
        self::assertSame('1', $sends[0]->args['messages'][0]->toolCallId);
        self::assertSame('bob', $sends[1]->node);
        self::assertSame('Transferred to Bob', $sends[1]->args['messages'][0]->content);
        self::assertSame('2', $sends[1]->args['messages'][0]->toolCallId);
    }

    public function testAToolReturningABareSendIsEncodedIntoASuccessToolMessage(): void
    {
        $fanOut = tool(
            static fn (): Send => new Send('worker', ['n' => 1]),
            ['name' => 'fan_out', 'description' => 'x', 'schema' => Schema::object([])],
        );

        $result = (new ToolNode([$fanOut]))->invoke([self::ai(self::call('fan_out', [], 'f1'))]);

        self::assertInstanceOf(ToolMessage::class, $result[0]);
        self::assertSame('f1', $result[0]->toolCallId);
        self::assertSame('fan_out', $result[0]->name);
        self::assertStringContainsString('worker', (string) $result[0]->content);
        self::assertStringContainsString('"n":1', (string) $result[0]->content);
    }

    public function testAParentCommandFromAToolReachesTheEngineThroughAGraph(): void
    {
        $graph = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('tools', new ToolNode([self::transfer('transfer_to_bob', 'bob')]))
            ->addEdge(Constants::START, 'tools')
            ->compile();

        $this->expectException(ParentCommand::class);

        $graph->invoke(['messages' => [self::ai(self::call('transfer_to_bob', [], '1'))]]);
    }

    private static function sendTool(string $name, string $target): StructuredTool
    {
        return tool(
            static fn (array $in, mixed $rm, RunnableConfig $config): Command => new Command(
                graph: Command::PARENT,
                goto: [new Send($target, ['messages' => [new ToolMessage([
                    'content' => 'Transferred to ' . ucfirst($target),
                    'name' => $name,
                    'tool_call_id' => $config->toolCall['id'],
                ])]])],
            ),
            ['name' => $name, 'description' => 'Transfer to ' . $target, 'schema' => Schema::object([])],
        );
    }

    // ---- ToolNode should raise GraphInterrupt ---------------------------------

    public function testShouldRaiseGraphInterrupt(): void
    {
        $toolWithInterrupt = tool(
            static function (): never {
                throw new GraphInterrupt();
            },
            ['name' => 'tool_with_interrupt', 'description' => 'A tool that returns an interrupt', 'schema' => Schema::object([])],
        );

        $this->expectException(GraphInterrupt::class);

        (new ToolNode([$toolWithInterrupt]))->invoke(['messages' => [self::ai(self::call('tool_with_interrupt', [], 'testid'))]]);
    }

    public function testAParentCommandThrownInsideAToolBecomesAnErrorToolMessage(): void
    {
        // Upstream rethrows only isGraphInterrupt (GraphInterrupt/NodeInterrupt), not every GraphBubbleUp.
        $subgraphCaller = tool(
            static function (): never {
                throw new ParentCommand(new Command(graph: Command::PARENT, goto: 'elsewhere'));
            },
            ['name' => 'call_subgraph', 'description' => 'x', 'schema' => Schema::object([])],
        );

        $result = (new ToolNode([$subgraphCaller]))->invoke([self::ai(self::call('call_subgraph', [], 'p1'))]);

        self::assertInstanceOf(ToolMessage::class, $result[0]);
        self::assertSame('p1', $result[0]->toolCallId);
        self::assertSame('error', $result[0]->additional_kwargs['status']);
        self::assertStringStartsWith('Error: ', (string) $result[0]->content);
        self::assertStringEndsWith('Please fix your mistakes.', (string) $result[0]->content);
    }

    public function testAnInterruptIsRethrownEvenWhenHandleToolErrorsIsACallable(): void
    {
        $interrupting = tool(
            static function (): never {
                throw new GraphInterrupt();
            },
            ['name' => 'tool_with_interrupt', 'description' => 'x', 'schema' => Schema::object([])],
        );
        $handlerCalled = false;
        $node = new ToolNode([$interrupting], ['handleToolErrors' => static function () use (&$handlerCalled): string {
            $handlerCalled = true;

            return 'swallowed';
        }]);

        try {
            $node->invoke([self::ai(self::call('tool_with_interrupt', [], 'x'))]);
            self::fail('The interrupt was swallowed.');
        } catch (GraphInterrupt) {
            self::assertFalse($handlerCalled);
        }
    }

    public function testInterruptInsideAToolPausesTheGraphAndResumesWithTheAnswer(): void
    {
        // End to end: if handleToolErrors swallowed the interrupt this would complete with an
        // "Error: ..." ToolMessage instead of pausing, and the resume value would never reach the tool.
        $ask = tool(
            static fn (): string => 'user said: ' . interrupt('what is your name?'),
            ['name' => 'ask_user', 'description' => 'Ask the user', 'schema' => Schema::object([])],
        );
        $graph = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('tools', new ToolNode([$ask]))
            ->addEdge(Constants::START, 'tools')
            ->compile(['checkpointer' => new MemorySaver()]);
        $config = new RunnableConfig(configurable: ['thread_id' => 't1']);

        $paused = $graph->invoke(['messages' => [self::ai(self::call('ask_user', [], 'q1'))]], $config);

        self::assertCount(1, $paused['messages'], 'The interrupt must pause the run, not become an error message.');
        self::assertNotEmpty($graph->getState($config)->next);

        $done = $graph->invoke(new Command(resume: 'Ada'), $config);

        self::assertSame('user said: Ada', end($done['messages'])->content);
    }

    private static function steerTool(string $format = 'content_and_artifact'): StructuredTool
    {
        return tool(
            static fn (array $in): array => [
                new Command(update: ['messages' => [new ToolMessage(['content' => 'cmd', 'tool_call_id' => 'c1', 'name' => 'steer'])]]),
                ['a' => 1],
            ],
            ['name' => 'steer', 'description' => 'steer', 'schema' => Schema::object([]), 'responseFormat' => $format],
        );
    }

    public function testContentAndArtifactToolReturningACommandTupleYieldsTheCommand(): void
    {
        $result = (new ToolNode([self::steerTool()]))->invoke(['messages' => [self::ai(self::call('steer', [], 'c1'))]]);

        self::assertCount(1, $result);
        self::assertInstanceOf(Command::class, $result[0]);
        self::assertSame('cmd', $result[0]->update['messages'][0]->content);
    }

    public function testPlainContentAndArtifactToolStillBecomesAToolMessageWithArtifact(): void
    {
        $plain = tool(
            static fn (array $in): array => ['hello', ['a' => 1]],
            ['name' => 'plain', 'description' => 'p', 'schema' => Schema::object([]), 'responseFormat' => 'content_and_artifact'],
        );
        $result = (new ToolNode([$plain]))->invoke(['messages' => [self::ai(self::call('plain', [], 'c2'))]]);

        self::assertSame('hello', $result['messages'][0]->content);
        self::assertSame(['a' => 1], $result['messages'][0]->artifact);
    }

    public function testCommandTupleSteersARealGraph(): void
    {
        $graph = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('tools', new ToolNode([self::steerTool()]))
            ->addEdge(Constants::START, 'tools')
            ->compile();

        $out = $graph->invoke(['messages' => [self::ai(self::call('steer', [], 'c1'))]]);

        self::assertSame('cmd', end($out['messages'])->content);
    }
}
