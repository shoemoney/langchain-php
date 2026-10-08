<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Anthropic\Utils;

use LangChain\Messages\BaseMessage;

/**
 * Translation from LangChain's standard (v1) content blocks into Anthropic
 * request blocks.
 *
 * Port of `utils/standard.ts` from `@langchain/anthropic`. Standard blocks keep
 * their camelCase keys (`fileId`, `mimeType`, `toolCallId`, `startIndex`,
 * `citedText`) exactly as upstream, because they are the cross-provider shape.
 *
 * A `Uint8Array` payload becomes a `list<int>` of byte values here; a string is
 * passed through untouched (already base64 or plain text).
 */
final class Standard
{
    private const ALLOWED_IMAGE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    private function __construct()
    {
    }

    /**
     * `_formatStandardContent`: the message's standard content blocks as
     * Anthropic request content blocks.
     *
     * @return list<array<string, mixed>>
     */
    public static function formatStandardContent(BaseMessage $message): array
    {
        $result = [];
        $isAnthropicMessage = ($message->response_metadata['model_provider'] ?? null) === 'anthropic';

        foreach ($message->contentBlocks() as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = $block['type'] ?? null;

            if ($type === 'text') {
                if (isset($block['annotations'])) {
                    $result[] = [
                        'type' => 'text',
                        'text' => $block['text'] ?? '',
                        'citations' => self::formatStandardCitations((array) $block['annotations']),
                    ];
                } else {
                    $result[] = ['type' => 'text', 'text' => $block['text'] ?? ''];
                }
            } elseif ($type === 'tool_call') {
                $result[] = [
                    'type' => 'tool_use',
                    'id' => $block['id'] ?? '',
                    'name' => $block['name'] ?? '',
                    'input' => self::asObject($block['args'] ?? []),
                ];
            } elseif ($type === 'tool_call_chunk') {
                $args = $block['args'] ?? null;
                if (is_string($args)) {
                    $decoded = json_decode($args, true);
                    $input = json_last_error() === \JSON_ERROR_NONE ? $decoded : [];
                } else {
                    $input = $args;
                }
                $result[] = [
                    'type' => 'tool_use',
                    'id' => $block['id'] ?? '',
                    'name' => $block['name'] ?? '',
                    'input' => self::asObject($input),
                ];
            } elseif ($type === 'reasoning' && $isAnthropicMessage) {
                $result[] = [
                    'type' => 'thinking',
                    'thinking' => $block['reasoning'] ?? '',
                    'signature' => (string) ($block['signature'] ?? ''),
                ];
            } elseif ($type === 'server_tool_call' && $isAnthropicMessage) {
                $name = $block['name'] ?? null;
                if ($name === 'web_search' || $name === 'code_execution') {
                    $result[] = [
                        'type' => 'server_tool_use',
                        'name' => $name,
                        'id' => $block['id'] ?? '',
                        'input' => self::asObject($block['args'] ?? []),
                    ];
                }
            } elseif ($type === 'server_tool_call_result' && $isAnthropicMessage) {
                $name = $block['name'] ?? null;
                $output = $block['output'] ?? null;
                if ($name === 'web_search' && is_array($output) && is_array($output['urls'] ?? null)) {
                    $result[] = [
                        'type' => 'web_search_tool_result',
                        'tool_use_id' => $block['toolCallId'] ?? '',
                        'content' => array_map(
                            static fn (mixed $url): array => [
                                'type' => 'web_search_result',
                                'title' => '',
                                'encrypted_content' => '',
                                'url' => $url,
                            ],
                            array_values($output['urls']),
                        ),
                    ];
                } elseif ($name === 'code_execution') {
                    $result[] = [
                        'type' => 'code_execution_tool_result',
                        'tool_use_id' => $block['toolCallId'] ?? '',
                        'content' => $output,
                    ];
                } elseif ($name === 'mcp_tool_result') {
                    $result[] = [
                        'type' => 'mcp_tool_result',
                        'tool_use_id' => $block['toolCallId'] ?? '',
                        'content' => $output,
                    ];
                }
            } elseif ($type === 'audio') {
                throw new \InvalidArgumentException('Anthropic does not support audio content blocks.');
            } elseif ($type === 'file') {
                $result[] = self::fileBlock($block);
            } elseif ($type === 'image') {
                $image = self::imageBlock($block);
                if ($image !== null) {
                    $result[] = $image;
                }
            } elseif ($type === 'video') {
                // no-op, as upstream
            } elseif ($type === 'text-plain') {
                if (self::has($block, 'data')) {
                    $result[] = self::applyDocumentMetadata([
                        'type' => 'document',
                        'source' => [
                            'type' => 'text',
                            'data' => self::formatBase64Data($block['data']),
                            'media_type' => 'text/plain',
                        ],
                    ], $block['metadata'] ?? null);
                }
            } elseif ($type === 'non_standard' && $isAnthropicMessage) {
                $result[] = $block['value'] ?? null;
            }
        }

        return $result;
    }

    /**
     * The inverse direction, used when `outputVersion` is `'v1'`: an Anthropic
     * response's content as standard content blocks.
     *
     * Upstream gets this from core's Anthropic block translator, which this
     * port does not carry; this covers the block types
     * {@see self::formatStandardContent()} can send back, so a v1 message
     * round-trips.
     *
     * @param string|list<mixed>         $content
     * @param list<array<string, mixed>> $toolCalls
     *
     * @return list<array<string, mixed>>
     */
    public static function toStandardContent(string|array $content, array $toolCalls = []): array
    {
        if (is_string($content)) {
            $content = $content === '' ? [] : [['type' => 'text', 'text' => $content]];
        }

        $out = [];
        $sawToolUse = false;

        foreach ($content as $block) {
            if (!is_array($block)) {
                continue;
            }

            switch ($block['type'] ?? null) {
                case 'text':
                    $standard = ['type' => 'text', 'text' => $block['text'] ?? ''];
                    if (!empty($block['citations']) && is_array($block['citations'])) {
                        $standard['annotations'] = array_map([self::class, 'toStandardCitation'], array_values($block['citations']));
                    }
                    $out[] = $standard;
                    break;

                case 'tool_use':
                    $sawToolUse = true;
                    $out[] = [
                        'type' => 'tool_call',
                        'id' => $block['id'] ?? '',
                        'name' => $block['name'] ?? '',
                        'args' => $block['input'] ?? [],
                    ];
                    break;

                case 'thinking':
                    $out[] = [
                        'type' => 'reasoning',
                        'reasoning' => $block['thinking'] ?? '',
                        'signature' => $block['signature'] ?? null,
                    ];
                    break;

                case 'server_tool_use':
                    $out[] = [
                        'type' => 'server_tool_call',
                        'id' => $block['id'] ?? '',
                        'name' => $block['name'] ?? '',
                        'args' => $block['input'] ?? [],
                    ];
                    break;

                case 'web_search_tool_result':
                    $results = is_array($block['content'] ?? null) ? $block['content'] : [];
                    $out[] = [
                        'type' => 'server_tool_call_result',
                        'toolCallId' => $block['tool_use_id'] ?? '',
                        'name' => 'web_search',
                        'output' => ['urls' => array_values(array_map(
                            static fn (mixed $r): mixed => is_array($r) ? ($r['url'] ?? null) : null,
                            $results,
                        ))],
                    ];
                    break;

                case 'code_execution_tool_result':
                case 'mcp_tool_result':
                    $out[] = [
                        'type' => 'server_tool_call_result',
                        'toolCallId' => $block['tool_use_id'] ?? '',
                        'name' => $block['type'] === 'mcp_tool_result' ? 'mcp_tool_result' : 'code_execution',
                        'output' => $block['content'] ?? null,
                    ];
                    break;

                default:
                    $out[] = ['type' => 'non_standard', 'value' => $block];
                    break;
            }
        }

        if (!$sawToolUse) {
            foreach ($toolCalls as $call) {
                $out[] = [
                    'type' => 'tool_call',
                    'id' => $call['id'] ?? '',
                    'name' => $call['name'] ?? '',
                    'args' => $call['args'] ?? [],
                ];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $citation
     *
     * @return array<string, mixed>
     */
    private static function toStandardCitation(array $citation): array
    {
        $type = $citation['type'] ?? null;
        $source = match ($type) {
            'char_location' => 'char',
            'page_location' => 'page',
            'content_block_location' => 'block',
            'web_search_result_location' => 'url',
            'search_result_location' => 'search',
            default => null,
        };

        return array_filter([
            'type' => 'citation',
            'source' => $source,
            'url' => $citation['url'] ?? $citation['file_id'] ?? null,
            'title' => $citation['title'] ?? $citation['document_title'] ?? null,
            'startIndex' => $citation['start_char_index'] ?? $citation['start_page_number'] ?? $citation['start_block_index'] ?? null,
            'endIndex' => $citation['end_char_index'] ?? $citation['end_page_number'] ?? $citation['end_block_index'] ?? null,
            'citedText' => $citation['cited_text'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    private static function fileBlock(array $block): array
    {
        $metadata = $block['metadata'] ?? null;

        if (self::has($block, 'fileId')) {
            return self::applyDocumentMetadata([
                'type' => 'document',
                'source' => ['type' => 'file', 'file_id' => $block['fileId']],
            ], $metadata);
        }

        if (self::has($block, 'url')) {
            $mimeType = self::normalizeMimeType($block['mimeType'] ?? null);
            if ($mimeType === 'application/pdf' || $mimeType === '') {
                return self::applyDocumentMetadata([
                    'type' => 'document',
                    'source' => ['type' => 'url', 'url' => $block['url']],
                ], $metadata);
            }
        }

        if (self::has($block, 'data')) {
            $mimeType = self::normalizeMimeType($block['mimeType'] ?? null);
            $data = self::formatBase64Data($block['data']);

            if ($mimeType === '' || $mimeType === 'application/pdf') {
                $source = ['type' => 'base64', 'data' => $data, 'media_type' => 'application/pdf'];
            } elseif ($mimeType === 'text/plain') {
                $source = ['type' => 'text', 'data' => $data, 'media_type' => 'text/plain'];
            } elseif (in_array($mimeType, self::ALLOWED_IMAGE_MIME_TYPES, true)) {
                $source = [
                    'type' => 'content',
                    'content' => [[
                        'type' => 'image',
                        'source' => ['type' => 'base64', 'data' => $data, 'media_type' => $mimeType],
                    ]],
                ];
            } else {
                throw new \InvalidArgumentException(
                    "Unsupported file mime type for Anthropic base64 source: {$mimeType}"
                );
            }

            return self::applyDocumentMetadata(['type' => 'document', 'source' => $source], $metadata);
        }

        throw new \InvalidArgumentException('File content block must include a fileId, url, or data property.');
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>|null
     */
    private static function imageBlock(array $block): ?array
    {
        $metadata = $block['metadata'] ?? null;

        if (self::has($block, 'fileId')) {
            return self::applyImageMetadata([
                'type' => 'image',
                'source' => ['type' => 'file', 'file_id' => $block['fileId']],
            ], $metadata);
        }

        if (self::has($block, 'url')) {
            return self::applyImageMetadata([
                'type' => 'image',
                'source' => ['type' => 'url', 'url' => $block['url']],
            ], $metadata);
        }

        if (self::has($block, 'data')) {
            $mimeType = self::normalizeMimeType($block['mimeType'] ?? null);
            $mimeType = $mimeType === '' ? 'image/png' : $mimeType;

            // An unsupported image mime type is skipped without error, as upstream.
            if (!in_array($mimeType, self::ALLOWED_IMAGE_MIME_TYPES, true)) {
                return null;
            }

            return self::applyImageMetadata([
                'type' => 'image',
                'source' => [
                    'type' => 'base64',
                    'data' => self::formatBase64Data($block['data']),
                    'media_type' => $mimeType,
                ],
            ], $metadata);
        }

        throw new \InvalidArgumentException('Image content block must include a fileId, url, or data property.');
    }

    /**
     * @param list<mixed> $annotations
     *
     * @return list<array<string, mixed>>
     */
    private static function formatStandardCitations(array $annotations): array
    {
        $out = [];

        foreach ($annotations as $annotation) {
            if (!is_array($annotation) || ($annotation['type'] ?? null) !== 'citation') {
                continue;
            }

            $source = $annotation['source'] ?? null;
            $url = $annotation['url'] ?? '';
            $title = $annotation['title'] ?? null;
            $start = $annotation['startIndex'] ?? 0;
            $end = $annotation['endIndex'] ?? 0;
            $cited = $annotation['citedText'] ?? '';

            $out[] = match ($source) {
                'char' => [
                    'type' => 'char_location',
                    'file_id' => $url,
                    'start_char_index' => $start,
                    'end_char_index' => $end,
                    'document_title' => $title,
                    'document_index' => 0,
                    'cited_text' => $cited,
                ],
                'page' => [
                    'type' => 'page_location',
                    'file_id' => $url,
                    'start_page_number' => $start,
                    'end_page_number' => $end,
                    'document_title' => $title,
                    'document_index' => 0,
                    'cited_text' => $cited,
                ],
                'block' => [
                    'type' => 'content_block_location',
                    'file_id' => $url,
                    'start_block_index' => $start,
                    'end_block_index' => $end,
                    'document_title' => $title,
                    'document_index' => 0,
                    'cited_text' => $cited,
                ],
                'url' => [
                    'type' => 'web_search_result_location',
                    'url' => $url,
                    'title' => $title,
                    'encrypted_index' => (string) $start,
                    'cited_text' => $cited,
                ],
                'search' => [
                    'type' => 'search_result_location',
                    'title' => $title,
                    'start_block_index' => $start,
                    'end_block_index' => $end,
                    'search_result_index' => 0,
                    'source' => $source,
                    'cited_text' => $cited,
                ],
                default => null,
            };
        }

        return array_values(array_filter($out, static fn (?array $c): bool => $c !== null));
    }

    /**
     * A string passes through; a byte list (the `Uint8Array` analogue) is base64-encoded.
     */
    private static function formatBase64Data(mixed $data): string
    {
        if (is_string($data)) {
            return $data;
        }

        return base64_encode(pack('C*', ...array_map('intval', (array) $data)));
    }

    private static function normalizeMimeType(?string $mimeType): string
    {
        return strtolower(explode(';', (string) $mimeType)[0]);
    }

    /**
     * @param array<string, mixed> $block
     */
    private static function has(array $block, string $key): bool
    {
        $value = $block[$key] ?? null;

        return $value !== null && $value !== '' && $value !== false;
    }

    /**
     * An empty argument map must encode as `{}`, never `[]`.
     */
    private static function asObject(mixed $value): mixed
    {
        return is_array($value) && $value === [] ? (object) [] : $value;
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    private static function applyDocumentMetadata(array $block, mixed $metadata): array
    {
        foreach (['cache_control', 'citations', 'context', 'title'] as $key) {
            if (is_array($metadata) && array_key_exists($key, $metadata)) {
                $block[$key] = $metadata[$key];
            }
        }

        return $block;
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    private static function applyImageMetadata(array $block, mixed $metadata): array
    {
        if (is_array($metadata) && array_key_exists('cache_control', $metadata)) {
            $block['cache_control'] = $metadata['cache_control'];
        }

        return $block;
    }
}
