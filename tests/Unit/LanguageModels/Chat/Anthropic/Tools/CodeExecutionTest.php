<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Anthropic\Tools;

use LangChain\LanguageModels\Chat\Anthropic\Tools\CodeExecution;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CodeExecution::class)]
final class CodeExecutionTest extends TestCase
{
    public function testCreatesAValidCodeExecutionToolWithNoOptions(): void
    {
        self::assertSame(
            ['type' => 'code_execution_20250825', 'name' => 'code_execution'],
            CodeExecution::codeExecution_20250825(),
        );
    }

    public function testCreatesAValidCodeExecutionToolWithCacheControl(): void
    {
        self::assertSame(
            ['type' => 'code_execution_20250825', 'name' => 'code_execution', 'cache_control' => ['type' => 'ephemeral']],
            CodeExecution::codeExecution_20250825(['cacheControl' => ['type' => 'ephemeral']]),
        );
    }
}
