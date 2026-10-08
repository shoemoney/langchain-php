<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangChain\Tools\Schema;
use LangGraph\Agents\Agent;
use LangGraph\Agents\ReactAgent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * The abort-signal cases of `reactAgent.test.ts` ("Should respect a passed signal" and "supports abort signal").
 *
 * An abort signal here is a callable returning `false` while live and `true` or a `\Throwable` (the abort
 * reason) once aborted. PHP runs one thing at a time, so where upstream aborts from a timer while an
 * operation is pending, these tests abort from inside the operation (or from a deadline the operation
 * outlives) and assert on the same outcome: the run stops with the abort reason and the signals stay independent.
 */
#[CoversClass(ReactAgent::class)]
final class ReactAgentAbortSignalTest extends TestCase
{
    public function testShouldRespectAPassedSignal(): void
    {
        // The model takes 150ms; the signal aborts after 30ms.
        $model = AgentAssertions::fakeChat([new AIMessage('result')], ['sleep' => 150]);
        $agent = Agent::create(['model' => $model, 'tools' => [], 'systemPrompt' => 'You are a helpful assistant']);

        $deadline = microtime(true) + 0.03;
        $signal = static fn (): bool|\Throwable => microtime(true) > $deadline ? new \RuntimeException('This operation was aborted') : false;

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('This operation was aborted');

        $agent->invoke(['messages' => [new HumanMessage('Hello Input!')]], ['signal' => $signal]);
    }

    public function testShouldHandleAbortSignal(): void
    {
        $model = AgentAssertions::fakeChat([new AIMessage('ai response')]);
        $agent = Agent::create([
            'model' => $model,
            'tools' => [],
            'signal' => static fn (): \Throwable => new \Exception('custom abortion'),
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('custom abortion');

        $agent->invoke(['messages' => 'hello']);
    }

    public function testShouldHandleAbortSignalInTools(): void
    {
        $aborted = false;
        $abortableTool = tool(
            static function (array $in) use (&$aborted): string {
                // The work is interrupted by the abort: the tool is cut short with the abort reason.
                $aborted = true;

                throw new \Exception('custom abort');
            },
            ['name' => 'abortable_tool', 'description' => 'A tool that can be aborted', 'schema' => Schema::object(['input' => ['type' => 'string']], ['input'])],
        );

        $model = AgentAssertions::fakeChat([
            new AIMessage(['content' => '', 'tool_calls' => [['id' => 'test-call-1', 'name' => 'abortable_tool', 'args' => ['input' => 'test'], 'type' => 'tool_call']]]),
        ]);

        $agent = Agent::create([
            'model' => $model,
            'tools' => [$abortableTool],
            'signal' => static function () use (&$aborted): bool|\Throwable {
                return $aborted ? new \Exception('custom abort') : false;
            },
        ]);

        try {
            $agent->invoke(['messages' => [['role' => 'user', 'content' => "Please run the abortable tool with input 'test'"]]]);
            self::fail('The run should have been aborted.');
        } catch (\Throwable $e) {
            // The tool error is not turned into a tool message once the signal has aborted: it bubbles up.
            self::assertSame('custom abort', $e->getMessage());
        }
    }

    public function testShouldMergeAbortSignalsFromAgentAndConfig(): void
    {
        $agentAborted = false;
        $configAborted = false;

        $signalCheckTool = tool(
            static function (array $in) use (&$configAborted): string {
                // The caller aborts while the first tool call is running.
                $configAborted = true;

                return 'Not aborted';
            },
            ['name' => 'signal_check_tool', 'description' => 'Checks abort signal', 'schema' => Schema::object(['input' => ['type' => 'string']], ['input'])],
        );

        $call = static fn (string $id): AIMessage => new AIMessage([
            'content' => '',
            'tool_calls' => [['id' => $id, 'name' => 'signal_check_tool', 'args' => ['input' => 'test'], 'type' => 'tool_call']],
        ]);
        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([$call('test-call-2'), $call('test-call-3')]),
            'tools' => [$signalCheckTool],
            'signal' => static function () use (&$agentAborted): bool|\Throwable {
                return $agentAborted ? new \Exception('Agent abort') : false;
            },
        ]);

        try {
            $agent->invoke(
                ['messages' => [['role' => 'user', 'content' => "Run signal_check_tool with input 'test'"]]],
                ['signal' => static function () use (&$configAborted): bool|\Throwable {
                    return $configAborted ? new \Exception('Config abort') : false;
                }],
            );
            self::fail('The run should have been aborted.');
        } catch (\Throwable $e) {
            self::assertMatchesRegularExpression('/Config abort/', $e->getMessage());
        }

        self::assertFalse($agentAborted);
        self::assertTrue($configAborted);
    }
}
