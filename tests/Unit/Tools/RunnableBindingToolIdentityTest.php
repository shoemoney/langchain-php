<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Tools;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tools\Schema;
use LangChain\Tracers\CallbackHandler;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Nodes\ToolNode as AgentToolNode;
use LangGraph\Prebuilt\ToolNode;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

final class RunnableBindingToolIdentityTest extends TestCase
{
    public function testBindingPreservesToolNameDefaultConfigAndBothNodeDispatchPaths(): void
    {
        foreach ([ToolNode::class, AgentToolNode::class] as $class) {
            $starts = [];
            $handler = CallbackHandler::fromMethods(['handleToolStart' => static function (
                $tool, $input, $id, $parent, $tags, $metadata, $runName, $callId,
            ) use (&$starts): void { $starts[] = [$runName, $callId]; }]);
            $probe = tool(static fn (): string => 'ok', ['name' => 'probe', 'description' => 'Bound probe',
                'schema' => Schema::object([]), 'defaultConfig' => ['runName' => 'tool default', 'callbacks' => [$handler]]])
                ->withConfig(['tags' => ['bound']]);

            self::assertSame('probe', $probe->getName());
            self::assertSame('ok', $probe->invoke([], new RunnableConfig(tags: ['call'])));
            $result = (new $class([$probe]))->invoke(['messages' => [new AIMessage(['content' => '', 'tool_calls' => [
                ['name' => 'probe', 'args' => [], 'id' => 'call_probe', 'type' => 'tool_call'],
            ]])]]);
            self::assertSame('probe', $result['messages'][0]->toolName);
            self::assertSame('probe', $result['messages'][0]->name);
            self::assertSame('call_probe', $result['messages'][0]->toolCallId);
            self::assertSame('ok', $result['messages'][0]->content);
            self::assertSame([['tool default', null], ['tool default', 'call_probe']], $starts);
        }
    }

    public function testBoundRunNameOverridesToolDefaultAndPerCallNameOverridesBinding(): void
    {
        $names = [];
        $probe = tool(static fn (): string => 'ok', ['name' => 'probe', 'description' => 'Bound probe',
            'schema' => Schema::object([]), 'defaultConfig' => ['runName' => 'tool default']])
            ->withConfig(['runName' => 'bound']);
        $handler = CallbackHandler::fromMethods(['handleToolStart' => static function (
            $tool, $input, $id, $parent, $tags, $metadata, $runName,
        ) use (&$names): void { $names[] = $runName; }]);
        $probe->invoke([], new RunnableConfig(callbacks: [$handler]));
        $probe->invoke([], new RunnableConfig(callbacks: [$handler], runName: 'call'));
        self::assertSame(['bound', 'call'], $names);
    }

    public function testBindingPreservesExplicitDirectToolOutputIdentity(): void
    {
        $output = new ToolMessage(['content' => 'ok', 'name' => 'explicit output', 'tool_call_id' => 'explicit id']);
        $probe = tool(static fn () => $output, ['name' => 'probe', 'description' => 'Direct output', 'schema' => Schema::object([])])
            ->withConfig([]);
        self::assertSame($output, $probe->invoke(['name' => 'probe', 'args' => [], 'id' => 'call_probe', 'type' => 'tool_call']));
        self::assertSame('explicit output', $output->name);
        self::assertSame('explicit id', $output->toolCallId);
    }

    public function testRealV3ToolCallProjectionRetainsBoundToolIdentity(): void
    {
        foreach ([false, true] as $throw) {
            $probe = tool(static function () use ($throw): string {
                if ($throw) {
                    throw new \RuntimeException('bound probe failed');
                }

                return 'ok';
            }, ['name' => 'probe', 'description' => 'Bound probe', 'schema' => Schema::object([])])
                ->withConfig(['tags' => ['bound']]);
            $agent = Agent::create(['model' => AgentAssertions::fakeChat([
                new AIMessage(['content' => '', 'tool_calls' => [['name' => 'probe', 'args' => [], 'id' => 'call_probe', 'type' => 'tool_call']]]),
                new AIMessage('done'),
            ]), 'tools' => [$probe]]);
            $run = $agent->streamEvents(['messages' => [new HumanMessage('probe')]], null, 'v3');
            $calls = iterator_to_array($run->toolCalls, false);
            self::assertCount(1, $calls);
            self::assertSame('probe', $calls[0]->name);
            self::assertSame('call_probe', $calls[0]->callId);
            self::assertSame($throw ? 'error' : 'finished', $calls[0]->status->value());
            if ($throw) {
                self::assertSame('bound probe failed', $calls[0]->error->value());
                self::assertTrue($calls[0]->output->isRejected());
            } else {
                self::assertSame('ok', $calls[0]->output->value());
            }
            self::assertSame('probe', $run->output()['messages'][2]->name);
        }
    }

    public function testRealV3PreservesAnExplicitBoundToolTraceName(): void
    {
        $probe = tool(static fn (): string => 'ok', ['name' => 'probe', 'description' => 'Bound trace', 'schema' => Schema::object([])])
            ->withConfig(['runName' => 'bound trace']);
        $agent = Agent::create(['model' => AgentAssertions::fakeChat([
            new AIMessage(['content' => '', 'tool_calls' => [['name' => 'probe', 'args' => [], 'id' => 'call_probe', 'type' => 'tool_call']]]),
            new AIMessage('done'),
        ]), 'tools' => [$probe]]);
        $run = $agent->streamEvents(['messages' => [new HumanMessage('probe')]], null, 'v3');
        $calls = iterator_to_array($run->toolCalls, false);
        self::assertCount(1, $calls);
        self::assertSame('bound trace', $calls[0]->name);
        self::assertSame('call_probe', $calls[0]->callId);
        self::assertSame('probe', $run->output()['messages'][2]->name);
    }
}
