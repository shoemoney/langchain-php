<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prompts;

use LangChain\Prompts\PromptTemplate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A callable partial's return value must not be stringified.
 *
 * `mergePartialAndUserVariables()` cast the callable's result with `(string)`.
 * Upstream does not: `partialValues[key] = await (value as () => Promise<string>)()`
 * (base.ts:112-117) assigns it as-is.
 *
 * Measured with a partial returning `['A','B']`: the merge produced the literal
 * string `"Array"` and the template rendered `'FArray'` — the caller's value
 * replaced by the word "Array", with a PHP warning on top. Under this package's
 * `failOnWarning="true"` that is also a test failure.
 *
 * Upstream's declared type is string-valued, so the ordinary case is identical
 * either way and is pinned below. The divergence only appears outside that
 * contract — and there the port was inventing text while upstream would have
 * carried the value through and let the template deal with it.
 */
#[CoversClass(PromptTemplate::class)]
final class CallablePartialValueTest extends TestCase
{
    public function testAStringReturningCallableIsUnaffected(): void
    {
        $prompt = new PromptTemplate(
            template: '{foo}{bar}',
            inputVariables: ['foo'],
            partialVariables: ['bar' => static fn (): string => 'S'],
        );

        $merged = $prompt->mergePartialAndUserVariables(['foo' => 'F']);

        self::assertSame('S', $merged['bar']);
        self::assertSame('F', $merged['foo']);
        self::assertSame('FS', $prompt->format(['foo' => 'F']));
    }

    public function testANonStringReturningCallableKeepsItsValue(): void
    {
        $prompt = new PromptTemplate(
            template: '{foo}{bar}',
            inputVariables: ['foo'],
            partialVariables: ['bar' => static fn (): array => ['A', 'B']],
        );

        $merged = $prompt->mergePartialAndUserVariables(['foo' => 'F']);

        // The regression: this was the string "Array".
        self::assertSame(['A', 'B'], $merged['bar'], 'the callable\'s value must survive the merge');
        self::assertStringNotContainsString(
            'Array',
            $prompt->format(['foo' => 'F']),
            'the array must not be laundered into the literal string "Array"',
        );
    }

    public function testALiteralStringPartialIsUnaffected(): void
    {
        $prompt = new PromptTemplate(
            template: '{foo}{bar}',
            inputVariables: ['foo'],
            partialVariables: ['bar' => 'baz'],
        );

        self::assertSame('baz', $prompt->mergePartialAndUserVariables(['foo' => 'f'])['bar']);
    }

    public function testUserVariablesStillOverridePartials(): void
    {
        $prompt = new PromptTemplate(
            template: '{foo}{bar}',
            inputVariables: ['foo'],
            partialVariables: ['bar' => static fn (): string => 'partial'],
        );

        self::assertSame(
            'user',
            $prompt->mergePartialAndUserVariables(['foo' => 'f', 'bar' => 'user'])['bar'],
        );
    }
}
