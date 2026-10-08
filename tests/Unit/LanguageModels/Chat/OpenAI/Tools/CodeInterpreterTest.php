<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Tools;

use LangChain\LanguageModels\Chat\OpenAI\Tools\CodeInterpreter;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** `tools/tests/codeInterpreter.test.ts`. */
#[CoversClass(CodeInterpreter::class)]
final class CodeInterpreterTest extends TestCase
{
    public function testBasicToolHasTheDefaultAutoContainer(): void
    {
        self::assertSame(['type' => 'code_interpreter', 'container' => ['type' => 'auto']], CodeInterpreter::tool());
    }

    public function testExplicitContainerIdIsUsedDirectly(): void
    {
        self::assertSame(
            ['type' => 'code_interpreter', 'container' => 'cntr_abc123'],
            CodeInterpreter::tool(['container' => 'cntr_abc123']),
        );
    }

    public function testMemoryLimit(): void
    {
        self::assertSame(
            ['type' => 'code_interpreter', 'container' => ['type' => 'auto', 'memory_limit' => '4g']],
            CodeInterpreter::tool(['container' => ['memoryLimit' => '4g']]),
        );
    }

    public function testFileIds(): void
    {
        self::assertSame(
            ['type' => 'code_interpreter', 'container' => ['type' => 'auto', 'file_ids' => ['file-abc123', 'file-def456']]],
            CodeInterpreter::tool(['container' => ['fileIds' => ['file-abc123', 'file-def456']]]),
        );
    }

    public function testAllAutoContainerOptions(): void
    {
        self::assertSame(
            ['type' => 'code_interpreter', 'container' => [
                'type' => 'auto',
                'file_ids' => ['file-abc123', 'file-def456', 'file-ghi789'],
                'memory_limit' => '16g',
            ]],
            CodeInterpreter::tool(['container' => ['memoryLimit' => '16g', 'fileIds' => ['file-abc123', 'file-def456', 'file-ghi789']]]),
        );
    }

    public function testSupportsAllMemoryLimitOptions(): void
    {
        foreach (['1g', '4g', '16g', '64g'] as $limit) {
            $tool = CodeInterpreter::tool(['container' => ['memoryLimit' => $limit]]);

            self::assertSame('code_interpreter', $tool['type']);
            self::assertSame($limit, $tool['container']['memory_limit']);
        }
    }
}
