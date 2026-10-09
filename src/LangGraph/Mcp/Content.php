<?php

declare(strict_types=1);

namespace LangGraph\Mcp;

/**
 * MCP `CallToolResult` to LangChain content and artifacts.
 *
 * Port of `langchain-mcp-adapters/src/content.ts`. Descriptors and results are the MCP SDK's
 * JSON shapes as decoded arrays. Conversion never dereferences resource URIs or performs IO.
 *
 * `outputHandling` is `'content'`, `'artifact'`, or a per-content-type map of those
 * (`['resource' => 'artifact', 'text' => 'content']`). Unmapped types default to `content`,
 * except `resource`, which defaults to `artifact`.
 */
final class Content
{
    /** The MCP `ContentBlock` discriminators, in the SDK's declaration order. */
    public const CALL_TOOL_RESULT_CONTENT_TYPES = ['text', 'image', 'audio', 'resource_link', 'resource'];

    private function __construct()
    {
    }

    /**
     * Expand an output policy into its per-content-type form.
     *
     * @param string|array<string, string|null>|null $outputHandling
     *
     * @return array<string, string>
     */
    public static function resolveDetailedOutputHandling(string|array|null $outputHandling, bool $applyDefaults = false): array
    {
        if ($outputHandling === null) {
            return [];
        }
        if (is_string($outputHandling)) {
            return array_fill_keys(self::CALL_TOOL_RESULT_CONTENT_TYPES, $outputHandling);
        }

        $resolved = [];
        foreach (self::CALL_TOOL_RESULT_CONTENT_TYPES as $type) {
            $chosen = $outputHandling[$type] ?? null;
            if ($chosen || $applyDefaults) {
                $resolved[$type] = $chosen ?? ($type === 'resource' ? 'artifact' : 'content');
            }
        }

        return $resolved;
    }

    /**
     * Apply a server-level policy over an adapter-level one.
     *
     * @param string|array<string, string|null>|null $base
     * @param string|array<string, string|null>|null $override
     *
     * @return array<string, string>
     */
    public static function resolveAndApplyOverrideHandlingOverrides(string|array|null $base, string|array|null $override): array
    {
        return [...self::resolveDetailedOutputHandling($base), ...self::resolveDetailedOutputHandling($override)];
    }

    /**
     * Convert a terminal MCP tool result into `[content, artifacts]`.
     *
     * Content is a plain string when the model-visible output is exactly one metadata-free text
     * block, otherwise a list of standard content blocks. Resource links and embedded-resource
     * provenance are retained as `mcp_content` artifacts, structured content as
     * `mcp_structured_content`, and `_meta` as `mcp_meta`.
     *
     * @param array<string, mixed>                   $result
     * @param string|array<string, string|null>|null $outputHandling
     *
     * @return array{0: string|list<array<string, mixed>>, 1: list<array<string, mixed>>}
     *
     * @throws ToolException when the server reported `isError` or sent an unknown block type
     */
    public static function convertCallToolResult(string $serverName, string $toolName, array $result, string|array|null $outputHandling = null): array
    {
        $blocks = array_values($result['content'] ?? []);

        if (!empty($result['isError'])) {
            $text = array_map(
                static fn (mixed $block): string => is_array($block) && ($block['type'] ?? null) === 'text' ? (string) ($block['text'] ?? '') : '',
                $blocks,
            );

            throw new ToolException(
                "MCP tool '{$toolName}' on server '{$serverName}' returned an error: " . implode("\n", $text),
                null,
                $result,
            );
        }

        $converted = [];
        $artifacts = [];
        $routing = [];
        foreach ($blocks as $index => $block) {
            $routing[$index] = self::outputTypeFor((string) ($block['type'] ?? ''), $outputHandling);
            if ($routing[$index] === 'content') {
                array_push($converted, ...self::toContentBlocks($block, $toolName, $serverName));
            } else {
                $artifacts[] = $block;
            }
        }

        foreach ($blocks as $index => $block) {
            $type = $block['type'] ?? null;
            $retained = $type === 'text' ? ['type', 'text'] : ['type', 'data', 'mimeType'];
            $hasExtras = array_diff(array_keys($block), $retained) !== [];

            if ($routing[$index] !== 'artifact' && ($type === 'resource' || $type === 'resource_link' || $hasExtras)) {
                $artifacts[] = ['type' => 'mcp_content', 'data' => $block];
            }
        }

        if (array_key_exists('structuredContent', $result)) {
            $artifacts[] = ['type' => 'mcp_structured_content', 'data' => $result['structuredContent']];
        }
        if (isset($result['_meta']) && $result['_meta'] !== false) {
            $artifacts[] = ['type' => 'mcp_meta', 'data' => $result['_meta']];
        }

        // Preserve the plain-text convenience without dropping resource provenance.
        if (count($converted) === 1 && $converted[0]['type'] === 'text' && !array_key_exists('metadata', $converted[0])) {
            return [$converted[0]['text'], $artifacts];
        }

        return [$converted, $artifacts];
    }

    /**
     * @param string|array<string, string|null>|null $outputHandling
     */
    private static function outputTypeFor(string $contentType, string|array|null $outputHandling): string
    {
        if ($outputHandling === 'content' || $outputHandling === 'artifact') {
            return $outputHandling;
        }

        return self::resolveDetailedOutputHandling($outputHandling)[$contentType]
            ?? ($contentType === 'resource' ? 'artifact' : 'content');
    }

    /**
     * @param array<string, mixed> $content
     *
     * @return list<array<string, mixed>>
     */
    private static function toContentBlocks(array $content, string $toolName, string $serverName): array
    {
        $type = $content['type'] ?? null;

        switch ($type) {
            case 'text':
                return [['type' => 'text', 'text' => $content['text']]];
            case 'image':
                return [['type' => 'image', 'data' => $content['data'], 'mimeType' => $content['mimeType']]];
            case 'audio':
                return [['type' => 'audio', 'data' => $content['data'], 'mimeType' => $content['mimeType']]];
            case 'resource':
                $resource = $content['resource'];
                $metadata = ['uri' => $resource['uri']];

                if (array_key_exists('text', $resource)) {
                    return [['type' => 'text', 'text' => $resource['text'], 'metadata' => $metadata]];
                }

                $mimeType = $resource['mimeType'] ?? 'application/octet-stream';

                return [[
                    'type' => str_starts_with($mimeType, 'image/') ? 'image' : (str_starts_with($mimeType, 'audio/') ? 'audio' : 'file'),
                    'data' => $resource['blob'],
                    'mimeType' => $mimeType,
                    'metadata' => $metadata,
                ]];
            case 'resource_link':
                $metadata = array_key_exists('title', $content)
                    ? ['uri' => $content['uri'], 'name' => $content['name'], 'title' => $content['title']]
                    : ['uri' => $content['uri'], 'name' => $content['name']];

                $file = ['type' => 'file', 'url' => $content['uri']];
                if (array_key_exists('mimeType', $content)) {
                    $file['mimeType'] = $content['mimeType'];
                }
                $file['metadata'] = $metadata;

                return [$file];
            default:
                throw new ToolException(
                    "MCP tool '{$toolName}' on server '{$serverName}' returned unexpected content type \""
                    . (is_scalar($type) ? (string) $type : 'undefined') . '". Expected '
                    . implode(', ', self::CALL_TOOL_RESULT_CONTENT_TYPES) . '.',
                );
        }
    }
}
