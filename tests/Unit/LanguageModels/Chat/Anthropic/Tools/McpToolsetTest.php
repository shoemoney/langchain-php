<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Anthropic\Tools;

use LangChain\LanguageModels\Chat\Anthropic\Tools\McpToolset;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(McpToolset::class)]
final class McpToolsetTest extends TestCase
{
    public function testCreatesABasicValidToolDefinition(): void
    {
        self::assertSame(
            ['type' => 'mcp_toolset', 'mcp_server_name' => 'example-mcp'],
            McpToolset::mcpToolset_20251120(['serverName' => 'example-mcp']),
        );
    }

    public function testCreatesAllowlistPattern(): void
    {
        self::assertSame([
            'type' => 'mcp_toolset',
            'mcp_server_name' => 'google-calendar-mcp',
            'default_config' => ['enabled' => false],
            'configs' => [
                'search_events' => ['enabled' => true],
                'create_event' => ['enabled' => true],
            ],
        ], McpToolset::mcpToolset_20251120([
            'serverName' => 'google-calendar-mcp',
            'defaultConfig' => ['enabled' => false],
            'configs' => ['search_events' => ['enabled' => true], 'create_event' => ['enabled' => true]],
        ]));
    }

    public function testCreatesDenylistPattern(): void
    {
        self::assertSame([
            'type' => 'mcp_toolset',
            'mcp_server_name' => 'google-calendar-mcp',
            'configs' => [
                'delete_all_events' => ['enabled' => false],
                'share_calendar_publicly' => ['enabled' => false],
            ],
        ], McpToolset::mcpToolset_20251120([
            'serverName' => 'google-calendar-mcp',
            'configs' => ['delete_all_events' => ['enabled' => false], 'share_calendar_publicly' => ['enabled' => false]],
        ]));
    }

    public function testSupportsDeferredLoadingForToolSearch(): void
    {
        self::assertSame([
            'type' => 'mcp_toolset',
            'mcp_server_name' => 'example-mcp',
            'default_config' => ['defer_loading' => true],
        ], McpToolset::mcpToolset_20251120(['serverName' => 'example-mcp', 'defaultConfig' => ['deferLoading' => true]]));
    }

    public function testSupportsMixedAllowlistWithPerToolConfiguration(): void
    {
        self::assertSame([
            'type' => 'mcp_toolset',
            'mcp_server_name' => 'google-calendar-mcp',
            'default_config' => ['enabled' => false, 'defer_loading' => true],
            'configs' => [
                'search_events' => ['enabled' => true, 'defer_loading' => false],
                'list_events' => ['enabled' => true],
            ],
        ], McpToolset::mcpToolset_20251120([
            'serverName' => 'google-calendar-mcp',
            'defaultConfig' => ['enabled' => false, 'deferLoading' => true],
            'configs' => [
                'search_events' => ['enabled' => true, 'deferLoading' => false],
                'list_events' => ['enabled' => true],
            ],
        ]));
    }

    public function testSupportsCacheControl(): void
    {
        self::assertSame([
            'type' => 'mcp_toolset',
            'mcp_server_name' => 'example-mcp',
            'cache_control' => ['type' => 'ephemeral'],
        ], McpToolset::mcpToolset_20251120(['serverName' => 'example-mcp', 'cacheControl' => ['type' => 'ephemeral']]));
    }

    public function testRequiresAServerName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        McpToolset::mcpToolset_20251120([]);
    }
}
