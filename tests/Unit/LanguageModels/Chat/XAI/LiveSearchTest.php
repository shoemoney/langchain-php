<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\XAI;

use LangChain\LanguageModels\Chat\XAI\LiveSearch;
use LangChain\LanguageModels\Chat\XAI\Tools\LiveSearch as LiveSearchTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Ports `src/tests/live_search.test.ts` (13 tests).
 */
#[CoversClass(LiveSearch::class)]
final class LiveSearchTest extends TestCase
{
    // ---- mergeSearchParams -------------------------------------------------

    public function testMergeReturnsNullWhenNoParamsAreProvided(): void
    {
        self::assertNull(LiveSearch::mergeSearchParams());
    }

    public function testMergeReturnsInstanceParamsWhenOnlyInstanceParamsAreProvided(): void
    {
        $instance = ['mode' => 'auto', 'max_search_results' => 5];

        self::assertSame($instance, LiveSearch::mergeSearchParams($instance, null, null));
    }

    public function testCallLevelParamsOverrideInstanceLevelParams(): void
    {
        $result = LiveSearch::mergeSearchParams(
            ['mode' => 'auto', 'max_search_results' => 5, 'return_citations' => true],
            ['mode' => 'on', 'max_search_results' => 10],
            null,
        );

        self::assertEquals(['mode' => 'on', 'max_search_results' => 10, 'return_citations' => true], $result);
    }

    public function testInstanceParamsOverrideToolParams(): void
    {
        $result = LiveSearch::mergeSearchParams(
            ['max_search_results' => 5],
            null,
            ['max_search_results' => 10, 'from_date' => '2024-01-01'],
        );

        self::assertEquals(['max_search_results' => 5, 'from_date' => '2024-01-01'], $result);
    }

    public function testAppliesPrecedenceToolInstanceCall(): void
    {
        $result = LiveSearch::mergeSearchParams(
            ['mode' => 'auto', 'max_search_results' => 5, 'return_citations' => true],
            ['mode' => 'on', 'max_search_results' => 10],
            ['mode' => 'off', 'from_date' => '2024-01-01', 'to_date' => '2024-01-31'],
        );

        self::assertEquals([
            'from_date' => '2024-01-01',
            'to_date' => '2024-01-31',
            'mode' => 'on',
            'max_search_results' => 10,
            'return_citations' => true,
        ], $result);
    }

    public function testAnEmptyArrayIsGivenParamsNotAbsentOnes(): void
    {
        self::assertSame([], LiveSearch::mergeSearchParams([]));
    }

    // ---- buildSearchParametersPayload --------------------------------------

    public function testBuildReturnsNullWhenParamsAreNull(): void
    {
        self::assertNull(LiveSearch::buildSearchParametersPayload(null));
    }

    public function testBuildsPayloadWithBasicFields(): void
    {
        $params = [
            'mode' => 'on',
            'max_search_results' => 7,
            'from_date' => '2024-01-01',
            'to_date' => '2024-01-31',
            'return_citations' => false,
        ];

        self::assertSame($params, LiveSearch::buildSearchParametersPayload($params));
    }

    public function testIncludesSourcesOnlyWhenNonEmpty(): void
    {
        $sources = [
            ['type' => 'web', 'allowed_websites' => ['x.ai']],
            ['type' => 'news', 'excluded_websites' => ['example.com']],
        ];

        self::assertSame(['mode' => 'auto', 'sources' => $sources], LiveSearch::buildSearchParametersPayload(['mode' => 'auto', 'sources' => $sources]));
        self::assertSame(['mode' => 'auto'], LiveSearch::buildSearchParametersPayload(['mode' => 'auto', 'sources' => []]));
    }

    public function testBuildDefaultsTheModeToAutoAndDropsUnknownKeys(): void
    {
        self::assertSame(['mode' => 'auto'], LiveSearch::buildSearchParametersPayload(['type' => 'x', 'name' => 'live_search']));
    }

    // ---- filterXAIBuiltInTools ---------------------------------------------

    public function testFilterReturnsNullWhenNoToolsAreProvided(): void
    {
        self::assertNull(LiveSearch::filterXAIBuiltInTools());
        self::assertNull(LiveSearch::filterXAIBuiltInTools([]));
        self::assertNull(LiveSearch::filterXAIBuiltInTools(['tools' => []]));
    }

    public function testReturnsToolsUnchangedWhenNoExcludedTypesAreProvided(): void
    {
        $tools = [['type' => 'foo'], ['type' => LiveSearchTool::TOOL_TYPE]];

        self::assertSame($tools, LiveSearch::filterXAIBuiltInTools(['tools' => $tools]));
    }

    public function testFiltersOutToolsWhoseTypeIsInExcludedTypes(): void
    {
        $liveSearchTool = ['type' => LiveSearchTool::TOOL_TYPE, 'name' => 'live'];
        $otherTool = ['type' => 'some_other_tool', 'name' => 'other'];

        $result = LiveSearch::filterXAIBuiltInTools([
            'tools' => [$liveSearchTool, $otherTool],
            'excludedTypes' => [LiveSearchTool::TOOL_TYPE],
        ]);

        self::assertSame([$otherTool], $result);
    }

    public function testKeepsToolsWithoutATypeProperty(): void
    {
        $result = LiveSearch::filterXAIBuiltInTools([
            'tools' => [['id' => 1], ['type' => LiveSearchTool::TOOL_TYPE]],
            'excludedTypes' => [LiveSearchTool::TOOL_TYPE],
        ]);

        self::assertSame([['id' => 1]], $result);
    }

    public function testReturnsNullWhenAllToolsAreFilteredOut(): void
    {
        $result = LiveSearch::filterXAIBuiltInTools([
            'tools' => [['type' => LiveSearchTool::TOOL_TYPE]],
            'excludedTypes' => [LiveSearchTool::TOOL_TYPE],
        ]);

        self::assertNull($result);
    }
}
