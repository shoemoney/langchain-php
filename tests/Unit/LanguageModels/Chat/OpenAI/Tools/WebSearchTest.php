<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Tools;

use LangChain\LanguageModels\Chat\OpenAI\Tools\WebSearch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** `tools/tests/webSearch.test.ts`. Upstream's `undefined` keys are omitted here. */
#[CoversClass(WebSearch::class)]
final class WebSearchTest extends TestCase
{
    public function testCreatesABasicValidToolDefinition(): void
    {
        self::assertSame(['type' => 'web_search'], WebSearch::tool());
    }

    public function testCreatesToolWithDomainFiltering(): void
    {
        self::assertSame(
            ['type' => 'web_search', 'filters' => ['allowed_domains' => ['openai.com', 'arxiv.org', 'nature.com']]],
            WebSearch::tool(['filters' => ['allowedDomains' => ['openai.com', 'arxiv.org', 'nature.com']]]),
        );
    }

    public function testCreatesToolWithUserLocation(): void
    {
        $location = ['type' => 'approximate', 'country' => 'US', 'city' => 'San Francisco', 'region' => 'California', 'timezone' => 'America/Los_Angeles'];

        self::assertSame(
            ['type' => 'web_search', 'user_location' => $location],
            WebSearch::tool(['userLocation' => $location]),
        );
    }

    public function testSearchContextSizePassesThrough(): void
    {
        self::assertSame('high', WebSearch::tool(['search_context_size' => 'high'])['search_context_size']);
    }
}
