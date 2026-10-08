<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Utils;

use LangChain\Utils\AsyncLocalStorage;
use LangChain\Utils\ContextVariables;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `utils/tests/context.test.ts` (the `context` template tag), plus the context
 * variable and configure-hook half of `singletons/async_local_storage/context.ts`.
 *
 * The tag is called with the template's raw parts and values, which is what a
 * JavaScript tag receives. PHP single-quoted strings keep backslashes literal, so
 * they stand in for JS `strings.raw`.
 */
#[CoversClass(ContextVariables::class)]
final class ContextVariablesTest extends TestCase
{
    protected function setUp(): void
    {
        AsyncLocalStorage::setGlobalInstance(null);
    }

    protected function tearDown(): void
    {
        AsyncLocalStorage::setGlobalInstance(null);
    }

    public function testShouldHandleSimpleStringsWithoutInterpolation(): void
    {
        $this->assertSame('Hello, world!', ContextVariables::context(['Hello, world!']));
    }

    public function testShouldInterpolateStringValues(): void
    {
        $this->assertSame('Hello, Alice!', ContextVariables::context(['Hello, ', '!'], ['Alice']));
    }

    public function testShouldInterpolateNonStringValuesAsJson(): void
    {
        $this->assertSame('Age: 30', ContextVariables::context(['Age: ', ''], [30]));
    }

    public function testShouldNormalizeIndentationInMultiLineStrings(): void
    {
        $result = ContextVariables::context(["\n      You are an ", ".\n      Your task is to help users.\n    "], ['agent']);

        $this->assertSame("You are an agent.\nYour task is to help users.", $result);
    }

    public function testShouldPreserveRelativeIndentation(): void
    {
        $result = ContextVariables::context(["\n      First line\n        Indented line\n      Back to normal\n    "]);

        $this->assertSame("First line\n  Indented line\nBack to normal", $result);
    }

    public function testShouldHandleEmptyLinesWithinContent(): void
    {
        $this->assertSame("Line 1\n\nLine 3", ContextVariables::context(["\n      Line 1\n\n      Line 3\n    "]));
    }

    public function testShouldRemoveLeadingAndTrailingBlankLines(): void
    {
        $this->assertSame('Content', ContextVariables::context(["\n\n      Content\n    \n    "]));
    }

    public function testShouldHandleComplexObjectsByStringifyingThem(): void
    {
        $result = ContextVariables::context(['Data: ', ''], [['key' => 'value', 'count' => 42]]);

        $this->assertSame('Data: {"key":"value","count":42}', $result);
    }

    public function testShouldWorkWithRealisticPromptExamples(): void
    {
        $result = ContextVariables::context(
            ["\n      You are an ", ".\n      \n      Your primary task is to ", ".\n      Please be helpful and accurate.\n    "],
            ['agent', 'answer questions'],
        );

        $this->assertSame(
            "You are an agent.\n\nYour primary task is to answer questions.\nPlease be helpful and accurate.",
            $result,
        );
    }

    public function testShouldHandleSingleLineStrings(): void
    {
        $this->assertSame('Hello Bob', ContextVariables::context(['Hello ', ''], ['Bob']));
    }

    public function testShouldHandleMultipleInterpolationsOnSameLine(): void
    {
        $this->assertSame('Name: John Doe', ContextVariables::context(['Name: ', ' ', ''], ['John', 'Doe']));
    }

    public function testShouldHandleArraysByStringifyingThem(): void
    {
        $result = ContextVariables::context(['Items: ', ''], [['apple', 'banana', 'cherry']]);

        $this->assertSame('Items: ["apple","banana","cherry"]', $result);
    }

    public function testShouldAlignMultiLineInterpolatedValues(): void
    {
        $items = "- Item 1\n- Item 2\n- Item 3";
        $result = ContextVariables::context(["\n      Shopping list:\n        ", "\n      End of list.\n    "], [$items]);

        $this->assertSame("Shopping list:\n  - Item 1\n  - Item 2\n  - Item 3\nEnd of list.", $result);
    }

    public function testShouldPreserveMultiLineStringIndentationAtRootLevel(): void
    {
        $code = "function foo() {\n  return 42;\n}";
        $result = ContextVariables::context(["\n      Code:\n      ", "\n    "], [$code]);

        $this->assertSame("Code:\nfunction foo() {\n  return 42;\n}", $result);
    }

    public function testShouldHandleEscapedNewlinesForLineContinuation(): void
    {
        $result = ContextVariables::context(["\n      This is a very long line that \\\n      continues here.\n    "]);

        $this->assertSame('This is a very long line that continues here.', $result);
    }

    public function testShouldHandleEscapedBackticks(): void
    {
        $this->assertSame('Use `code` for inline code.', ContextVariables::context(["\n      Use \\`code\\` for inline code.\n    "]));
    }

    public function testShouldHandleEscapedDollarSigns(): void
    {
        $this->assertSame('The price is $100.', ContextVariables::context(["\n      The price is \\\$100.\n    "]));
    }

    public function testShouldHandleEscapedBraces(): void
    {
        $this->assertSame('Use ${variable} syntax.', ContextVariables::context(["\n      Use \\\${variable} syntax.\n    "]));
    }

    public function testALiteralBackslashNBecomesANewlineAtTheEnd(): void
    {
        $this->assertSame("one\ntwo", ContextVariables::context(['one\\ntwo']));
    }

    // ------------------------------------------------------- context variables

    public function testSetContextVariableThrowsWithoutAStorageInstance(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('has not been initialized');

        ContextVariables::setContextVariable('foo', 'bar');
    }

    public function testGetContextVariableIsNullWithoutAStorageInstance(): void
    {
        $this->assertNull(ContextVariables::getContextVariable('foo'));
    }

    public function testChildChangesDoNotAffectParents(): void
    {
        AsyncLocalStorage::initializeGlobalInstance(new AsyncLocalStorage());
        $als = AsyncLocalStorage::getInstance();

        $nested = static function (): mixed {
            $inherited = ContextVariables::getContextVariable('foo');
            ContextVariables::setContextVariable('foo', 'baz');

            return [$inherited, ContextVariables::getContextVariable('foo')];
        };

        $this->assertNull(ContextVariables::getContextVariable('foo'));

        $result = $als->run(null, function () use ($nested): array {
            ContextVariables::setContextVariable('foo', 'bar');
            $res = $this->runNested($nested);

            return [$res, ContextVariables::getContextVariable('foo')];
        });

        $this->assertSame([['bar', 'baz'], 'bar'], $result);
        $this->assertNull(ContextVariables::getContextVariable('foo'));
    }

    /**
     * @param callable(): mixed $fn
     */
    private function runNested(callable $fn): mixed
    {
        return AsyncLocalStorage::getInstance()->run(AsyncLocalStorage::getInstance()->getStore(), $fn);
    }

    public function testVariablesSetOutsideAnyFrameAreGlobal(): void
    {
        AsyncLocalStorage::initializeGlobalInstance(new AsyncLocalStorage());
        ContextVariables::setContextVariable('answer', 42);
        ContextVariables::setContextVariable(7, 'numeric key');

        $this->assertSame(42, ContextVariables::getContextVariable('answer'));
        $this->assertSame('numeric key', ContextVariables::getContextVariable(7));
    }

    public function testRegisterConfigureHookAccumulates(): void
    {
        AsyncLocalStorage::initializeGlobalInstance(new AsyncLocalStorage());
        $this->assertSame([], ContextVariables::getConfigureHooks());

        ContextVariables::registerConfigureHook(['contextVar' => 'my_tracer']);
        ContextVariables::registerConfigureHook(['envVar' => 'MY_TRACER_ENABLED', 'handlerClass' => \stdClass::class]);

        $hooks = ContextVariables::getConfigureHooks();
        $this->assertCount(2, $hooks);
        $this->assertSame('my_tracer', $hooks[0]['contextVar']);
        $this->assertSame('MY_TRACER_ENABLED', $hooks[1]['envVar']);
    }

    public function testRegisterConfigureHookRequiresAHandlerClassWithAnEnvVar(): void
    {
        AsyncLocalStorage::initializeGlobalInstance(new AsyncLocalStorage());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('handlerClass must also be set');

        ContextVariables::registerConfigureHook(['envVar' => 'X']);
    }
}
