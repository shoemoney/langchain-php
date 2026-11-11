<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Tools;

use LangChain\Messages\AIMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tools\DynamicTool;
use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;
use LangChain\Tracers\CallbackHandler;
use LangGraph\Agents\Nodes\ToolNode as AgentToolNode;
use LangGraph\Graph\MessagesAnnotation;
use LangGraph\Pregel\Constants;
use LangGraph\Prebuilt\ToolNode;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * Regression coverage for core/tools/index.ts DynamicTool/DynamicStructuredTool.call()
 * naming and runnables/config.ts patchConfig() child-run identity clearing.
 * Agent transformer identity is also pinned end to end in ReactAgentStreamV3Test.
 */
final class StructuredToolRunNameTest extends TestCase
{
    private static function probe(array $fields = [], bool $throw = false): StructuredTool
    {
        return tool(static function () use ($throw): string {
            if ($throw) {
                throw new \RuntimeException('probe failed');
            }

            return 'ok';
        }, ['name' => 'probe', 'description' => 'Name probe', 'schema' => Schema::object([]), ...$fields]);
    }

    private static function input(): array
    {
        return ['messages' => [new AIMessage(['content' => '', 'tool_calls' => [
            ['name' => 'probe', 'args' => [], 'id' => 'call_probe', 'type' => 'tool_call'],
        ]])]];
    }

    private static function config(array &$starts, ?string $name = null): RunnableConfig
    {
        return new RunnableConfig(runName: $name, callbacks: [CallbackHandler::fromMethods([
            'handleToolStart' => static function (mixed $tool, mixed $input, string $id, ?string $parent, array $tags, array $metadata, ?string $runName, ?string $callId) use (&$starts): void {
                $starts[] = [$runName, $callId];
            },
        ])]);
    }

    public function testDirectInvokeAndCallDefaultBothDynamicToolShapesToTheirToolNames(): void
    {
        foreach ([self::probe(), new DynamicTool(['name' => 'probe', 'description' => 'String probe'], static fn (): string => 'ok')] as $tool) {
            foreach (['invoke', 'call'] as $method) {
                $starts = [];
                $config = self::config($starts);
                self::assertSame('ok', $tool->$method($tool instanceof DynamicTool ? 'input' : [], $config));
                self::assertSame([['probe', null]], $starts);
                self::assertNull($config->runName);
                self::assertNull($config->toolCall);
            }
        }
    }

    public function testDirectInvokeAndCallPreserveExplicitNamesIncludingAnEmptyName(): void
    {
        foreach (['custom trace', ''] as $name) {
            foreach (['invoke', 'call'] as $method) {
                $starts = [];
                $config = self::config($starts, $name);
                self::probe()->$method([], $config);
                self::assertSame([[$name, null]], $starts);
                self::assertSame($name, $config->runName);
            }
        }
    }

    public function testDirectInvokePreservesTheToolDefaultRunName(): void
    {
        $starts = [];
        $config = self::config($starts);
        $probe = self::probe(['defaultConfig' => ['runName' => 'default trace', 'callbacks' => $config->callbacks]]);

        $probe->invoke([]);

        self::assertSame([['default trace', null]], $starts);
    }

    public function testBothToolNodesPreserveDirectExplicitRunNames(): void
    {
        foreach ([ToolNode::class, AgentToolNode::class] as $nodeClass) {
            foreach (['custom trace', ''] as $name) {
                $starts = [];
                $config = self::config($starts, $name);
                (new $nodeClass([self::probe()]))->invoke(self::input(), $config);

                self::assertSame([[$name, 'call_probe']], $starts);
                self::assertSame($name, $config->runName);
            }
        }
    }

    public function testBothToolNodesClearGraphAssignedNamesOnSuccessAndError(): void
    {
        foreach ([ToolNode::class, AgentToolNode::class] as $nodeClass) {
            foreach ([false, true] as $throw) {
                $starts = [];
                $graph = (new StateGraph(MessagesAnnotation::root()))
                    ->addNode('tool_executor', new $nodeClass([self::probe(throw: $throw)]))
                    ->addEdge(Constants::START, 'tool_executor')
                    ->addEdge('tool_executor', Constants::END)
                    ->compile();

                $result = $graph->invoke(self::input(), self::config($starts, 'root trace'));

                self::assertSame([['probe', 'call_probe']], $starts);
                self::assertSame('probe', $result['messages'][1]->name);
                self::assertSame('call_probe', $result['messages'][1]->toolCallId);
                self::assertStringContainsString($throw ? 'probe failed' : 'ok', $result['messages'][1]->content);
            }
        }
    }

    public function testBothToolNodesPreserveToolDefaultRunNamesInsideGraphs(): void
    {
        foreach ([ToolNode::class, AgentToolNode::class] as $nodeClass) {
            $starts = [];
            $graph = (new StateGraph(MessagesAnnotation::root()))
                ->addNode('tool_executor', new $nodeClass([self::probe(['defaultConfig' => ['runName' => 'tool trace']])]))
                ->addEdge(Constants::START, 'tool_executor')
                ->addEdge('tool_executor', Constants::END)
                ->compile();

            $graph->invoke(self::input(), self::config($starts));

            self::assertSame([['tool trace', 'call_probe']], $starts);
        }
    }

    public function testDirectToolCallDoesNotMutateTheCallersConfig(): void
    {
        $starts = [];
        $config = self::config($starts);
        self::probe()->invoke(['name' => 'probe', 'args' => [], 'id' => 'direct_call', 'type' => 'tool_call'], $config);

        self::assertSame([['probe', 'direct_call']], $starts);
        self::assertNull($config->runName);
        self::assertNull($config->toolCall);
    }
}
