<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Anthropic\Tools;

/**
 * Anthropic MCP toolset (`tools/mcpToolset.ts`).
 */
final class McpToolset
{
    /**
     * @param array{
     *     serverName: string,
     *     defaultConfig?: array{enabled?: bool, deferLoading?: bool},
     *     configs?: array<string, array{enabled?: bool, deferLoading?: bool}>,
     *     cacheControl?: array<string, mixed>
     * } $options
     *
     * @return array<string, mixed>
     */
    public static function mcpToolset_20251120(array $options): array
    {
        if (!isset($options['serverName']) || $options['serverName'] === '') {
            throw new \InvalidArgumentException('mcpToolset requires a serverName.');
        }

        $default = $options['defaultConfig'] ?? [];
        $defaultConfig = isset($default['enabled']) || isset($default['deferLoading'])
            ? self::config($default)
            : null;

        $configs = isset($options['configs'])
            ? array_map(static fn (array $c): array => self::config($c), $options['configs'])
            : null;

        return ServerToolDefinition::withoutNulls([
            'type' => 'mcp_toolset',
            'mcp_server_name' => $options['serverName'],
            'default_config' => $defaultConfig,
            'configs' => $configs,
            'cache_control' => $options['cacheControl'] ?? null,
        ]);
    }

    /**
     * @param array{enabled?: bool, deferLoading?: bool} $config
     *
     * @return array<string, mixed>
     */
    private static function config(array $config): array
    {
        return ServerToolDefinition::withoutNulls([
            'enabled' => $config['enabled'] ?? null,
            'defer_loading' => $config['deferLoading'] ?? null,
        ]);
    }
}
