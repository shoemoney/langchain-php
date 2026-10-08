<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenRouter;

use LangChain\LanguageModels\Chat\OpenRouter\Converters\Tools;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of the `formatToolChoice` block of `converters/tests/index.test.ts`,
 * plus the `convertToolsToOpenRouter` behaviour its callers depend on.
 */
#[CoversClass(Tools::class)]
final class ToolsConverterTest extends TestCase
{
    public function testReturnsNullForNull(): void
    {
        self::assertNull(Tools::formatToolChoice(null));
    }

    public function testMapsAutoToAuto(): void
    {
        self::assertSame('auto', Tools::formatToolChoice('auto'));
    }

    public function testMapsNoneToNone(): void
    {
        self::assertSame('none', Tools::formatToolChoice('none'));
    }

    public function testMapsAnyToRequired(): void
    {
        self::assertSame('required', Tools::formatToolChoice('any'));
    }

    public function testMapsRequiredToRequired(): void
    {
        self::assertSame('required', Tools::formatToolChoice('required'));
    }

    public function testWrapsANamedToolStringInFunctionFormat(): void
    {
        self::assertSame(
            ['type' => 'function', 'function' => ['name' => 'get_weather']],
            Tools::formatToolChoice('get_weather'),
        );
    }

    public function testPassesAnArrayThroughUnchanged(): void
    {
        $choice = ['type' => 'function', 'function' => ['name' => 'foo']];

        self::assertSame($choice, Tools::formatToolChoice($choice));
    }

    public function testConvertsToolsToTheOpenAiShapeAndAppliesStrict(): void
    {
        $tool = ['type' => 'function', 'function' => ['name' => 'f', 'description' => 'd', 'parameters' => ['type' => 'object']]];

        self::assertSame([$tool], Tools::convertToolsToOpenRouter([$tool]));
        self::assertTrue(Tools::convertToolsToOpenRouter([$tool], ['strict' => true])[0]['function']['strict']);
        self::assertFalse(Tools::convertToolsToOpenRouter([$tool], ['strict' => false])[0]['function']['strict']);
    }
}
