<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\XAI\Tools;

use LangChain\LanguageModels\Chat\XAI\ChatXAI;
use LangChain\LanguageModels\Chat\XAI\Tools\LiveSearch;
use LangChain\LanguageModels\Chat\XAI\Tools\Tools;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Ports `tools/tests/live_search.test.ts` (6 tests).
 */
#[CoversClass(LiveSearch::class)]
#[CoversClass(Tools::class)]
#[CoversClass(ChatXAI::class)]
final class LiveSearchToolTest extends TestCase
{
    private string|false $previousKey;

    protected function setUp(): void
    {
        $this->previousKey = getenv('XAI_API_KEY');
    }

    protected function tearDown(): void
    {
        putenv($this->previousKey === false ? 'XAI_API_KEY' : 'XAI_API_KEY=' . $this->previousKey);
    }

    public function testCreatesAToolWithCorrectProviderDefinition(): void
    {
        $tool = LiveSearch::create(['maxSearchResults' => 10, 'fromDate' => '2024-01-01', 'returnCitations' => true]);

        self::assertSame([
            'type' => LiveSearch::TOOL_TYPE,
            'name' => LiveSearch::TOOL_NAME,
            'max_search_results' => 10,
            'from_date' => '2024-01-01',
            'return_citations' => true,
        ], $tool);
    }

    public function testCreatesAToolWithDefaultOptions(): void
    {
        self::assertSame(['type' => LiveSearch::TOOL_TYPE, 'name' => LiveSearch::TOOL_NAME], LiveSearch::create());
    }

    public function testCreatesAToolWithWebAndNewsSourcesUsingExcludedWebsites(): void
    {
        $tool = LiveSearch::create(['sources' => [
            ['type' => 'web', 'excludedWebsites' => ['wikipedia.org']],
            ['type' => 'news', 'excludedWebsites' => ['bbc.co.uk']],
        ]]);

        self::assertSame([
            ['type' => 'web', 'excluded_websites' => ['wikipedia.org']],
            ['type' => 'news', 'excluded_websites' => ['bbc.co.uk']],
        ], $tool['sources']);
    }

    public function testEverySourceKindMapsItsCamelCaseKeys(): void
    {
        $tool = LiveSearch::create(['sources' => [
            ['type' => 'web', 'country' => 'US', 'allowedWebsites' => ['a.com'], 'excludedWebsites' => ['b.com'], 'safeSearch' => false],
            ['type' => 'news', 'country' => 'GB', 'safeSearch' => true],
            ['type' => 'x', 'includedXHandles' => ['xai'], 'excludedXHandles' => ['spam'], 'postFavoriteCount' => 5, 'postViewCount' => 100],
            ['type' => 'rss', 'links' => ['https://example.com/feed.rss']],
        ]]);

        self::assertSame([
            ['type' => 'web', 'country' => 'US', 'allowed_websites' => ['a.com'], 'excluded_websites' => ['b.com'], 'safe_search' => false],
            ['type' => 'news', 'country' => 'GB', 'safe_search' => true],
            ['type' => 'x', 'included_x_handles' => ['xai'], 'excluded_x_handles' => ['spam'], 'post_favorite_count' => 5, 'post_view_count' => 100],
            ['type' => 'rss', 'links' => ['https://example.com/feed.rss']],
        ], $tool['sources']);
    }

    public function testAnUnknownSourceTypeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        LiveSearch::create(['sources' => [['type' => 'tiktok']]]);
    }

    public function testTheToolsFacadeDelegatesToEachFactory(): void
    {
        self::assertSame(LiveSearch::create(['mode' => 'on']), Tools::xaiLiveSearch(['mode' => 'on']));
        self::assertSame(['type' => 'web_search', 'allowed_domains' => ['a.com']], Tools::xaiWebSearch(['allowedDomains' => ['a.com']]));
        self::assertSame(['type' => 'x_search', 'from_date' => '2024-01-01'], Tools::xaiXSearch(['fromDate' => '2024-01-01']));
        self::assertSame(['type' => 'code_interpreter'], Tools::xaiCodeExecution());
        self::assertSame(['type' => 'file_search', 'vector_store_ids' => ['c']], Tools::xaiCollectionsSearch(['vectorStoreIds' => ['c']]));
    }

    // ---- ChatXAI with xaiLiveSearch tool ------------------------------------

    public function testFormatStructuredToolToXaiPreservesProviderDefinition(): void
    {
        $model = new ChatXAI(['apiKey' => 'foo']);
        $searchTool = LiveSearch::create([
            'maxSearchResults' => 8,
            'sources' => [['type' => 'web', 'allowedWebsites' => ['example.com']]],
        ]);

        $formatted = $model->formatStructuredToolToXAI([$searchTool]);

        self::assertCount(1, $formatted);
        self::assertSame([
            'type' => LiveSearch::TOOL_TYPE,
            'name' => LiveSearch::TOOL_NAME,
            'max_search_results' => 8,
            'sources' => [['type' => 'web', 'allowed_websites' => ['example.com']]],
        ], $formatted[0]);
    }

    public function testFormatStructuredToolToXaiReturnsNullForNoTools(): void
    {
        self::assertNull((new ChatXAI(['apiKey' => 'foo']))->formatStructuredToolToXAI([]));
    }

    public function testInvocationParamsExtractsParametersFromFormattedTools(): void
    {
        $model = new ChatXAI(['apiKey' => 'foo']);

        $params = $model->invocationParams(['tools' => [[
            'type' => LiveSearch::TOOL_TYPE,
            'name' => LiveSearch::TOOL_NAME,
            'max_search_results' => 8,
            'sources' => [['type' => 'web', 'allowed_websites' => ['example.com']]],
        ]]]);

        self::assertSame([
            'mode' => 'auto',
            'max_search_results' => 8,
            'sources' => [['type' => 'web', 'allowed_websites' => ['example.com']]],
        ], $params['search_parameters']);
    }

    public function testExplicitSearchParametersOverrideToolParameters(): void
    {
        $model = new ChatXAI(['apiKey' => 'foo', 'searchParameters' => ['mode' => 'on', 'max_search_results' => 5]]);

        $params = $model->invocationParams(['tools' => [[
            'type' => LiveSearch::TOOL_TYPE,
            'name' => LiveSearch::TOOL_NAME,
            'max_search_results' => 10,
            'from_date' => '2024-01-01',
        ]]]);

        self::assertEquals(['mode' => 'on', 'max_search_results' => 5, 'from_date' => '2024-01-01'], $params['search_parameters']);
    }
}
