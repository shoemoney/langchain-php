<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Anthropic\Tools;

use LangChain\LanguageModels\Chat\Anthropic\Tools\Bash;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Bash::class)]
final class BashTest extends TestCase
{
    public function testCreatesAValidBashToolWithNoOptions(): void
    {
        $bash = Bash::bash_20250124();

        self::assertSame('bash', $bash->name);
        self::assertSame(['type' => 'bash_20250124', 'name' => 'bash'], $bash->extras['providerToolDefinition']);
    }

    public function testCreatesAValidBashToolWithExecuteFunction(): void
    {
        $bash = Bash::bash_20250124([
            'execute' => static fn (array $args): string => isset($args['restart']) ? 'Session restarted' : 'Executed: ' . $args['command'],
        ]);

        self::assertSame('bash', $bash->name);
        self::assertSame('Executed: pwd', $bash->invoke(['command' => 'pwd']));
    }

    public function testCanExecuteACommand(): void
    {
        $executed = null;
        $bash = Bash::bash_20250124([
            'execute' => static function (array $args) use (&$executed): string {
                $executed = $args['command'];

                return 'command output';
            },
        ]);

        self::assertSame('command output', $bash->invoke(['command' => 'ls -la']));
        self::assertSame('ls -la', $executed);
    }

    public function testCanRestartTheSession(): void
    {
        $restarted = false;
        $bash = Bash::bash_20250124([
            'execute' => static function (array $args) use (&$restarted): string {
                if (isset($args['restart'])) {
                    $restarted = true;

                    return 'Bash session restarted';
                }

                return 'command executed';
            },
        ]);

        self::assertSame('Bash session restarted', $bash->invoke(['restart' => true]));
        self::assertTrue($restarted);
    }
}
