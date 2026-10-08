<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Tools;

/**
 * OpenAI's hosted MCP tool, for the Responses API.
 *
 * Port of `tools.mcp` from `@langchain/openai`. Pass `serverUrl` for a remote
 * server or `connectorId` for one of OpenAI's connectors; `serverUrl` wins when
 * both are present, as upstream's `"serverUrl" in options` check does.
 */
final class Mcp
{
    private function __construct()
    {
    }

    /**
     * @param array{
     *     serverLabel: string,
     *     serverUrl?: string,
     *     connectorId?: string,
     *     allowedTools?: list<string>|array{toolNames?: list<string>, readOnly?: bool},
     *     authorization?: string,
     *     headers?: array<string, string>,
     *     requireApproval?: 'always'|'never'|array{always?: array<string, mixed>, never?: array<string, mixed>},
     *     serverDescription?: string
     * } $options
     *
     * @return array<string, mixed>
     */
    public static function tool(array $options): array
    {
        $tool = [
            'type' => 'mcp',
            'server_label' => $options['serverLabel'],
            'allowed_tools' => self::allowedTools($options['allowedTools'] ?? null),
            'authorization' => $options['authorization'] ?? null,
            'headers' => $options['headers'] ?? null,
            'require_approval' => self::requireApproval($options['requireApproval'] ?? null),
            'server_description' => $options['serverDescription'] ?? null,
        ];

        if (array_key_exists('serverUrl', $options)) {
            $tool['server_url'] = $options['serverUrl'];
        } else {
            $tool['connector_id'] = $options['connectorId'] ?? null;
        }

        return array_filter($tool, static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param array<string, mixed> $filter
     *
     * @return array<string, mixed>
     */
    private static function toolFilter(array $filter): array
    {
        return array_filter([
            'tool_names' => $filter['toolNames'] ?? null,
            'read_only' => $filter['readOnly'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param array<mixed>|null $allowedTools
     *
     * @return array<mixed>|null
     */
    private static function allowedTools(?array $allowedTools): ?array
    {
        if ($allowedTools === null) {
            return null;
        }

        return array_is_list($allowedTools) ? $allowedTools : self::toolFilter($allowedTools);
    }

    /**
     * @param string|array<string, mixed>|null $requireApproval
     *
     * @return string|array<string, mixed>|null
     */
    private static function requireApproval(string|array|null $requireApproval): string|array|null
    {
        if ($requireApproval === null || is_string($requireApproval)) {
            return $requireApproval;
        }

        return array_filter([
            'always' => isset($requireApproval['always']) ? self::toolFilter($requireApproval['always']) : null,
            'never' => isset($requireApproval['never']) ? self::toolFilter($requireApproval['never']) : null,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
