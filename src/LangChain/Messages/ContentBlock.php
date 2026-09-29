<?php

declare(strict_types=1);

namespace LangChain\Messages;

/**
 * Factories and type guards for message content blocks.
 *
 * Port of `@langchain/core/messages/content`. The TypeScript original models
 * content as a discriminated union of plain object literals. PHP associative
 * arrays are the faithful counterpart — critically, they serialize to exactly
 * the same JSON, which is what makes a checkpoint written by either runtime
 * readable by the other.
 *
 * Every factory returns a plain `array` with a `type` discriminator; every guard
 * is a narrowing predicate. The class is a namespace, not a value type.
 */
final class ContentBlock
{
    public const TEXT = 'text';
    public const IMAGE = 'image';
    public const AUDIO = 'audio';
    public const VIDEO = 'video';
    public const FILE = 'file';
    public const URL = 'url';
    public const REASONING = 'reasoning';
    public const CITATION = 'citation';
    public const TOOL_CALL = 'tool_call';
    public const TOOL_CALL_CHUNK = 'tool_call_chunk';
    public const INVALID_TOOL_CALL = 'invalid_tool_call';
    public const SERVER_TOOL_CALL = 'server_tool_call';
    public const SERVER_TOOL_CALL_CHUNK = 'server_tool_call_chunk';
    public const SERVER_TOOL_CALL_RESULT = 'server_tool_call_result';
    public const NON_STANDARD = 'non_standard';

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function text(string $text, array $extra = []): array
    {
        return ['type' => self::TEXT, 'text' => $text] + $extra;
    }

    /**
     * A base64 data block: `data:<mime>;base64,<payload>`.
     *
     * @return array<string, mixed>
     */
    public static function data(string $data, string $mimeType, string $sourceType = 'id', array $extra = []): array
    {
        return array_merge([
            'type' => self::FILE,
            'source_type' => $sourceType,
            'mime_type' => $mimeType,
            'data' => $data,
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    public static function url(string $url, array $extra = []): array
    {
        return ['type' => self::FILE, 'source_type' => 'url', 'url' => $url] + $extra;
    }

    /**
     * @return array<string, mixed>
     */
    public static function image(string $url, array $extra = []): array
    {
        return ['type' => self::IMAGE, 'source_type' => 'url', 'url' => $url] + $extra;
    }

    /**
     * @return array<string, mixed>
     */
    public static function audio(string $url, array $extra = []): array
    {
        return ['type' => self::AUDIO, 'source_type' => 'url', 'url' => $url] + $extra;
    }

    /**
     * @return array<string, mixed>
     */
    public static function video(string $url, array $extra = []): array
    {
        return ['type' => self::VIDEO, 'source_type' => 'url', 'url' => $url] + $extra;
    }

    /**
     * @return array<string, mixed>
     */
    public static function reasoning(string $reasoning, array $extra = []): array
    {
        return ['type' => self::REASONING, 'reasoning' => $reasoning] + $extra;
    }

    /**
     * @return array<string, mixed>
     */
    public static function toolCall(string $id, string $name, array $args = [], array $extra = []): array
    {
        return [
            'type' => self::TOOL_CALL,
            'id' => $id,
            'name' => $name,
            'args' => $args,
        ] + $extra;
    }

    /**
     * A partial tool call, as emitted by streaming providers.
     *
     * @return array<string, mixed>
     */
    public static function toolCallChunk(?string $name, ?string $args = null, ?string $id = null, ?int $index = null, array $extra = []): array
    {
        $block = ['type' => self::TOOL_CALL_CHUNK];
        if ($name !== null) {
            $block['name'] = $name;
        }
        if ($args !== null) {
            $block['args'] = $args;
        }
        if ($id !== null) {
            $block['id'] = $id;
        }
        if ($index !== null) {
            $block['index'] = $index;
        }

        return $block + $extra;
    }

    /**
     * @return array<string, mixed>
     */
    public static function invalidToolCall(?string $name, ?string $args = null, ?string $error = null, ?string $id = null, array $extra = []): array
    {
        $block = ['type' => self::INVALID_TOOL_CALL];
        if ($name !== null) {
            $block['name'] = $name;
        }
        if ($args !== null) {
            $block['args'] = $args;
        }
        if ($error !== null) {
            $block['error'] = $error;
        }
        if ($id !== null) {
            $block['id'] = $id;
        }

        return $block + $extra;
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    public static function nonStandard(string $provider, string $rawContent, array $extra = []): array
    {
        return [
            'type' => self::NON_STANDARD,
            'provider' => $provider,
            'raw_content' => $rawContent,
        ] + $extra;
    }

    /**
     * A block is a list member if it is an array with a string `type`.
     */
    public static function isBlock(mixed $value): bool
    {
        return is_array($value) && isset($value['type']) && is_string($value['type']);
    }

    public static function isText(mixed $value): bool
    {
        return self::isBlock($value) && $value['type'] === self::TEXT;
    }

    /**
     * A "data" content block is a `file` block carrying inline base64, as
     * opposed to a URL-backed one.
     */
    public static function isData(mixed $value): bool
    {
        return self::isBlock($value)
            && $value['type'] === self::FILE
            && isset($value['source_type'])
            && in_array($value['source_type'], ['id', 'text', 'base64'], true);
    }

    public static function isUrl(mixed $value): bool
    {
        return self::isBlock($value)
            && $value['type'] === self::FILE
            && isset($value['source_type'])
            && $value['source_type'] === 'url';
    }

    public static function isReasoning(mixed $value): bool
    {
        return self::isBlock($value) && $value['type'] === self::REASONING;
    }

    public static function isToolCall(mixed $value): bool
    {
        return self::isBlock($value) && $value['type'] === self::TOOL_CALL;
    }

    public static function isToolCallChunk(mixed $value): bool
    {
        return self::isBlock($value) && $value['type'] === self::TOOL_CALL_CHUNK;
    }

    /**
     * Every text block's text, concatenated.
     *
     * @param list<mixed> $blocks
     */
    public static function textFrom(array $blocks): string
    {
        $out = '';
        foreach ($blocks as $block) {
            if (self::isText($block) && isset($block['text']) && is_string($block['text'])) {
                $out .= $block['text'];
            }
        }

        return $out;
    }
}
