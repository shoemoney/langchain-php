<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Tools;

use LangChain\LanguageModels\Chat\OpenAI\Tools\LocalShell;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** `tools/tests/localShell.test.ts`. */
#[CoversClass(LocalShell::class)]
final class LocalShellTest extends TestCase
{
    public function testCreatesValidToolDefinitions(): void
    {
        $tool = LocalShell::create(['execute' => static fn (array $a): string => 'output']);

        self::assertSame('local_shell', $tool->name);
        self::assertSame(['type' => 'local_shell'], $tool->extras['providerToolDefinition']);
    }

    public function testExecuteReceivesTheExecAction(): void
    {
        $seen = null;
        $tool = LocalShell::create(['execute' => static function (array $action) use (&$seen): string {
            $seen = $action;

            return 'ran';
        }]);

        self::assertSame('ran', $tool->invoke(['type' => 'exec', 'command' => ['ls', '-la'], 'working_directory' => '/tmp']));
        self::assertSame(['type' => 'exec', 'command' => ['ls', '-la'], 'working_directory' => '/tmp'], $seen);
    }
}
