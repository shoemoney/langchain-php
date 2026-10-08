<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Tools;

use LangChain\LanguageModels\Chat\OpenAI\Tools\ComputerUse;
use LangChain\Messages\AIMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tools\ToolRuntime;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** `tools/tests/computerUse.test.ts`, plus the execute path the upstream file leaves untested. */
#[CoversClass(ComputerUse::class)]
final class ComputerUseTest extends TestCase
{
    public function testCreatesAValidToolForBrowserEnvironment(): void
    {
        $tool = ComputerUse::create([
            'displayWidth' => 1024,
            'displayHeight' => 768,
            'environment' => 'browser',
            'execute' => static fn (array $action, ToolRuntime $runtime): string => '',
        ]);

        self::assertSame('computer_use', $tool->name);
        self::assertSame(
            ['type' => 'computer_use_preview', 'display_width' => 1024, 'display_height' => 768, 'environment' => 'browser'],
            $tool->extras['providerToolDefinition'],
        );
    }

    public function testExecuteResultIsStampedWithTheComputerCallId(): void
    {
        $seen = null;
        $tool = ComputerUse::create([
            'displayWidth' => 800,
            'displayHeight' => 600,
            'environment' => 'linux',
            'execute' => static function (array $action, ToolRuntime $runtime) use (&$seen): string {
                $seen = $action;

                return 'screenshot-data';
            },
        ]);
        $ai = new AIMessage(['content' => '', 'tool_calls' => [['id' => 'call_cu', 'name' => 'computer_use', 'args' => []]]]);
        $config = new RunnableConfig(configurable: ['__state' => ['messages' => [$ai]]], toolCall: ['id' => 'call_cu', 'name' => 'computer_use', 'args' => []]);

        $result = $tool->invoke(['action' => ['type' => 'screenshot']], $config);

        self::assertSame(['type' => 'screenshot'], $seen);
        self::assertInstanceOf(ToolMessage::class, $result);
        self::assertSame('call_cu', $result->toolCallId);
        self::assertSame('screenshot-data', $result->content);
        self::assertSame('computer_call_output', $result->additional_kwargs['type']);
    }

    public function testMissingComputerCallThrows(): void
    {
        $tool = ComputerUse::create([
            'displayWidth' => 800,
            'displayHeight' => 600,
            'environment' => 'mac',
            'execute' => static fn (array $action, ToolRuntime $runtime): string => 'x',
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Computer use call id not found');

        $tool->invoke(['action' => ['type' => 'screenshot']], new RunnableConfig(toolCall: ['id' => 'c', 'name' => 'computer_use', 'args' => []]));
    }
}
