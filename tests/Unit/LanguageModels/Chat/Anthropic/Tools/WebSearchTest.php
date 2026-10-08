<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Anthropic\Tools;

use LangChain\LanguageModels\Chat\Anthropic\Tools\WebSearch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(WebSearch::class)]
final class WebSearchTest extends TestCase
{
    public function testWebSearch20250305CreatesAValidToolDefinition(): void
    {
        self::assertSame(
            ['type' => 'web_search_20250305', 'name' => 'web_search'],
            WebSearch::webSearch_20250305(),
        );

        self::assertSame([
            'type' => 'web_search_20250305',
            'name' => 'web_search',
            'max_uses' => 3,
            'allowed_domains' => ['example.com', 'docs.example.com'],
            'cache_control' => ['type' => 'ephemeral'],
            'defer_loading' => true,
            'strict' => true,
            'user_location' => [
                'type' => 'approximate',
                'country' => 'US',
                'region' => 'California',
                'city' => 'San Francisco',
            ],
        ], WebSearch::webSearch_20250305([
            'maxUses' => 3,
            'allowedDomains' => ['example.com', 'docs.example.com'],
            'cacheControl' => ['type' => 'ephemeral'],
            'deferLoading' => true,
            'strict' => true,
            'userLocation' => ['type' => 'approximate', 'country' => 'US', 'region' => 'California', 'city' => 'San Francisco'],
        ]));
    }
}
