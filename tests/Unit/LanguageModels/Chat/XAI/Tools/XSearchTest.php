<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\XAI\Tools;

use LangChain\LanguageModels\Chat\XAI\Tools\XSearch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Ports `tools/tests/x_search.test.ts` (9 tests).
 */
#[CoversClass(XSearch::class)]
final class XSearchTest extends TestCase
{
    public function testCreatesAToolWithCorrectType(): void
    {
        self::assertSame(XSearch::TOOL_TYPE, XSearch::create()['type']);
    }

    public function testCreatesAToolWithDefaultOptionsEmpty(): void
    {
        $tool = XSearch::create();

        self::assertSame('x_search', $tool['type']);
        self::assertSame(['type'], array_keys($tool));
    }

    public function testCreatesAToolWithAllowedXHandlesOption(): void
    {
        self::assertSame(
            ['type' => 'x_search', 'allowed_x_handles' => ['elonmusk', 'xai']],
            XSearch::create(['allowedXHandles' => ['elonmusk', 'xai']]),
        );
    }

    public function testCreatesAToolWithExcludedXHandlesOption(): void
    {
        self::assertSame(
            ['type' => 'x_search', 'excluded_x_handles' => ['spamaccount']],
            XSearch::create(['excludedXHandles' => ['spamaccount']]),
        );
    }

    public function testCreatesAToolWithDateRangeOptions(): void
    {
        self::assertSame(
            ['type' => 'x_search', 'from_date' => '2024-01-01', 'to_date' => '2024-12-31'],
            XSearch::create(['fromDate' => '2024-01-01', 'toDate' => '2024-12-31']),
        );
    }

    public function testCreatesAToolWithImageUnderstandingOption(): void
    {
        self::assertSame(
            ['type' => 'x_search', 'enable_image_understanding' => true],
            XSearch::create(['enableImageUnderstanding' => true]),
        );
    }

    public function testCreatesAToolWithVideoUnderstandingOption(): void
    {
        self::assertSame(
            ['type' => 'x_search', 'enable_video_understanding' => true],
            XSearch::create(['enableVideoUnderstanding' => true]),
        );
    }

    public function testCreatesAToolWithAllOptions(): void
    {
        $tool = XSearch::create([
            'allowedXHandles' => ['elonmusk'],
            'fromDate' => '2024-10-01',
            'toDate' => '2024-10-31',
            'enableImageUnderstanding' => true,
            'enableVideoUnderstanding' => true,
        ]);

        self::assertSame([
            'type' => 'x_search',
            'allowed_x_handles' => ['elonmusk'],
            'from_date' => '2024-10-01',
            'to_date' => '2024-10-31',
            'enable_image_understanding' => true,
            'enable_video_understanding' => true,
        ], $tool);
    }

    public function testConvertsCamelCaseOptionsToSnakeCase(): void
    {
        $tool = XSearch::create([
            'allowedXHandles' => ['test'],
            'excludedXHandles' => ['spam'],
            'fromDate' => '2024-01-01',
            'toDate' => '2024-12-31',
            'enableImageUnderstanding' => true,
            'enableVideoUnderstanding' => false,
        ]);

        foreach (['allowed_x_handles', 'excluded_x_handles', 'from_date', 'to_date', 'enable_image_understanding', 'enable_video_understanding'] as $key) {
            self::assertArrayHasKey($key, $tool);
        }
        foreach (['allowedXHandles', 'excludedXHandles', 'fromDate', 'toDate', 'enableImageUnderstanding', 'enableVideoUnderstanding'] as $key) {
            self::assertArrayNotHasKey($key, $tool);
        }
    }
}
