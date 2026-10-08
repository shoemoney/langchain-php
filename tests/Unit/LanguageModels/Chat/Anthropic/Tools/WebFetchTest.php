<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Anthropic\Tools;

use LangChain\LanguageModels\Chat\Anthropic\Tools\WebFetch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebFetch::class)]
final class WebFetchTest extends TestCase
{
    public function testCreatesAValidToolDefinitionWithNoOptions(): void
    {
        self::assertSame(['type' => 'web_fetch_20250910', 'name' => 'web_fetch'], WebFetch::webFetch_20250910());
    }

    public function testCreatesAValidToolDefinitionWithAllOptions(): void
    {
        self::assertSame([
            'type' => 'web_fetch_20250910',
            'name' => 'web_fetch',
            'max_uses' => 5,
            'allowed_domains' => ['example.com', 'docs.example.com'],
            'cache_control' => ['type' => 'ephemeral'],
            'citations' => ['enabled' => true],
            'max_content_tokens' => 50000,
        ], WebFetch::webFetch_20250910([
            'maxUses' => 5,
            'allowedDomains' => ['example.com', 'docs.example.com'],
            'cacheControl' => ['type' => 'ephemeral'],
            'citations' => ['enabled' => true],
            'maxContentTokens' => 50000,
        ]));
    }

    public function testCreatesAValidToolDefinitionWithBlockedDomains(): void
    {
        self::assertSame([
            'type' => 'web_fetch_20250910',
            'name' => 'web_fetch',
            'max_uses' => 10,
            'blocked_domains' => ['private.example.com', 'internal.example.com'],
        ], WebFetch::webFetch_20250910([
            'maxUses' => 10,
            'blockedDomains' => ['private.example.com', 'internal.example.com'],
        ]));
    }

    public function testCreatesAValidToolDefinitionWithCitationsDisabled(): void
    {
        self::assertSame(
            ['type' => 'web_fetch_20250910', 'name' => 'web_fetch', 'citations' => ['enabled' => false]],
            WebFetch::webFetch_20250910(['citations' => ['enabled' => false]]),
        );
    }
}
