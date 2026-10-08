<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Tools;

use LangChain\LanguageModels\Chat\OpenAI\Tools\Shell;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `tools/tests/shell.test.ts`. Upstream calls `tool.func` directly with `null` for unset
 * optionals; here the tool is invoked through its schema, so unset optionals are left out.
 */
#[CoversClass(Shell::class)]
final class ShellTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function exit(string $stdout = '', string $stderr = '', int $code = 0): array
    {
        return ['stdout' => $stdout, 'stderr' => $stderr, 'outcome' => ['type' => 'exit', 'exit_code' => $code]];
    }

    public function testCreatesValidToolDefinitions(): void
    {
        $tool = Shell::create(['execute' => static fn (array $a): array => ['output' => [self::exit('output')]]]);

        self::assertSame('shell', $tool->name);
        self::assertSame(['type' => 'shell'], $tool->extras['providerToolDefinition']);
    }

    public function testExecuteCallbackReceivesTheAction(): void
    {
        $actions = [];
        $tool = Shell::create(['execute' => static function (array $action) use (&$actions): array {
            $actions[] = $action;

            return [
                'output' => array_map(static fn (string $cmd): array => self::exit("executed: $cmd"), $action['commands']),
                'maxOutputLength' => $action['max_output_length'],
            ];
        }]);

        $result = $tool->invoke(['commands' => ['ls -la', 'pwd'], 'timeout_ms' => 5000, 'max_output_length' => 4096]);

        self::assertCount(1, $actions);
        self::assertSame(['ls -la', 'pwd'], $actions[0]['commands']);
        self::assertSame(5000, $actions[0]['timeout_ms']);
        self::assertSame(4096, $actions[0]['max_output_length']);

        $parsed = json_decode(self::text($result), true);
        self::assertCount(2, $parsed['output']);
        self::assertSame('executed: ls -la', $parsed['output'][0]['stdout']);
        self::assertSame('executed: pwd', $parsed['output'][1]['stdout']);
        self::assertSame(4096, $parsed['max_output_length']);
    }

    public function testHandlesTimeoutOutcome(): void
    {
        $tool = Shell::create(['execute' => static fn (array $a): array => ['output' => [
            ['stdout' => '', 'stderr' => 'Command timed out', 'outcome' => ['type' => 'timeout']],
        ]]]);

        $parsed = json_decode(self::text($tool->invoke(['commands' => ['sleep 1000'], 'timeout_ms' => 100])), true);

        self::assertCount(1, $parsed['output']);
        self::assertSame('timeout', $parsed['output'][0]['outcome']['type']);
    }

    public function testHandlesNonZeroExitCode(): void
    {
        $tool = Shell::create(['execute' => static fn (array $a): array => ['output' => [self::exit('', 'command not found', 127)]]]);

        $parsed = json_decode(self::text($tool->invoke(['commands' => ['nonexistent-command']])), true);

        self::assertSame('exit', $parsed['output'][0]['outcome']['type']);
        self::assertSame(127, $parsed['output'][0]['outcome']['exit_code']);
        self::assertSame('command not found', $parsed['output'][0]['stderr']);
    }

    public function testHandlesMultipleCommands(): void
    {
        $tool = Shell::create(['execute' => static fn (array $a): array => [
            'output' => array_map(static fn (int $i): array => self::exit("output $i"), array_keys($a['commands'])),
        ]]);

        $parsed = json_decode(self::text($tool->invoke(['commands' => ['echo hello', 'echo world', 'ls']])), true);

        self::assertCount(3, $parsed['output']);
        self::assertSame(['output 0', 'output 1', 'output 2'], array_column($parsed['output'], 'stdout'));
        self::assertArrayNotHasKey('max_output_length', $parsed);
    }

    private static function text(mixed $result): string
    {
        self::assertIsString($result);

        return $result;
    }
}
