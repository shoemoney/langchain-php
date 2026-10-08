<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\XAI\Tools;

use LangChain\LanguageModels\Chat\XAI\Tools\CodeExecution;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Ports `tools/tests/code_execution.test.ts` (4 tests).
 */
#[CoversClass(CodeExecution::class)]
final class CodeExecutionTest extends TestCase
{
    public function testCreatesAToolWithCorrectType(): void
    {
        self::assertSame(CodeExecution::TOOL_TYPE, CodeExecution::create()['type']);
    }

    public function testCreatesAToolWithTypeCodeInterpreter(): void
    {
        self::assertSame('code_interpreter', CodeExecution::create()['type']);
    }

    public function testToolHasNoAdditionalProperties(): void
    {
        self::assertSame(['type'], array_keys(CodeExecution::create()));
    }

    public function testMultipleCallsReturnEquivalentTools(): void
    {
        self::assertEquals(CodeExecution::create(), CodeExecution::create());
    }
}
