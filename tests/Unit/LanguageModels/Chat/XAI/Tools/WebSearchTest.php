<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\XAI\Tools;

use LangChain\LanguageModels\Chat\XAI\Tools\WebSearch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Ports `tools/tests/web_search.test.ts` (7 tests).
 */
#[CoversClass(WebSearch::class)]
final class WebSearchTest extends TestCase
{
    public function testCreatesAToolWithCorrectType(): void
    {
        self::assertSame(WebSearch::TOOL_TYPE, WebSearch::create()['type']);
    }

    public function testCreatesAToolWithDefaultOptionsEmpty(): void
    {
        $tool = WebSearch::create();

        self::assertSame('web_search', $tool['type']);
        self::assertSame(['type'], array_keys($tool));
    }

    public function testCreatesAToolWithAllowedDomainsOption(): void
    {
        $tool = WebSearch::create(['allowedDomains' => ['wikipedia.org', 'github.com']]);

        self::assertSame(['type' => 'web_search', 'allowed_domains' => ['wikipedia.org', 'github.com']], $tool);
    }

    public function testCreatesAToolWithExcludedDomainsOption(): void
    {
        $tool = WebSearch::create(['excludedDomains' => ['example.com', 'spam.net']]);

        self::assertSame(['type' => 'web_search', 'excluded_domains' => ['example.com', 'spam.net']], $tool);
    }

    public function testCreatesAToolWithEnableImageUnderstandingOption(): void
    {
        self::assertSame(
            ['type' => 'web_search', 'enable_image_understanding' => true],
            WebSearch::create(['enableImageUnderstanding' => true]),
        );
    }

    public function testCreatesAToolWithAllOptions(): void
    {
        $tool = WebSearch::create(['allowedDomains' => ['example.com'], 'enableImageUnderstanding' => true]);

        self::assertSame(['type' => 'web_search', 'allowed_domains' => ['example.com'], 'enable_image_understanding' => true], $tool);
    }

    public function testConvertsCamelCaseOptionsToSnakeCase(): void
    {
        $tool = WebSearch::create(['allowedDomains' => ['test.com'], 'excludedDomains' => ['bad.com'], 'enableImageUnderstanding' => false]);

        foreach (['allowed_domains', 'excluded_domains', 'enable_image_understanding'] as $key) {
            self::assertArrayHasKey($key, $tool);
        }
        foreach (['allowedDomains', 'excludedDomains', 'enableImageUnderstanding'] as $key) {
            self::assertArrayNotHasKey($key, $tool);
        }
        self::assertFalse($tool['enable_image_understanding']);
    }
}
