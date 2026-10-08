<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Converters;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;

/**
 * The `contentBlocks` getter of `BaseMessage` / `AIMessage`, as a function.
 *
 * Port of `BaseMessage.contentBlocks` (`@langchain/core/messages/base`) with the
 * `convertToV1FromDataContent` and `convertToV1FromChatCompletionsInput` steps
 * from `messages/block_translators`. The PHP `BaseMessage::contentBlocks()` only
 * wraps a string, so the v0-to-v1 data-block normalisation the Responses
 * converters rely on lives here.
 *
 * Not ported: per-provider `translateContent` (the registry of translators keyed
 * by `model_provider`) and the Anthropic input step. A non-`v1` AI message
 * therefore falls to the generic path whatever its provider.
 */
final class StandardContentBlocks
{
    private function __construct()
    {
    }

    /**
     * @return list<mixed>
     */
    public static function of(BaseMessage $message): array
    {
        $isAi = $message instanceof AIMessage;

        if ($isAi && ($message->response_metadata['output_version'] ?? null) === 'v1') {
            return is_string($message->content)
                ? [['type' => 'text', 'text' => $message->content]]
                : $message->content;
        }

        $blocks = is_string($message->content)
            ? [['type' => 'text', 'text' => $message->content]]
            : $message->content;

        $blocks = array_map(
            static fn (mixed $block): mixed => self::fromChatCompletionsInput(self::fromDataBlock($block)),
            $blocks,
        );

        if ($isAi) {
            foreach ($message->toolCalls as $toolCall) {
                $present = false;
                foreach ($blocks as $block) {
                    if (is_array($block)
                        && ($block['id'] ?? null) === ($toolCall['id'] ?? null)
                        && ($block['name'] ?? null) === ($toolCall['name'] ?? null)) {
                        $present = true;
                        break;
                    }
                }
                if (!$present) {
                    $blocks[] = [
                        'type' => 'tool_call',
                        'id' => $toolCall['id'] ?? null,
                        'name' => $toolCall['name'] ?? null,
                        'args' => $toolCall['args'] ?? [],
                    ];
                }
            }
        }

        return array_values($blocks);
    }

    /**
     * v0 data block (`source_type` + `mime_type`) to the v1 shape.
     *
     * A `text` source falls through untouched, exactly as upstream.
     */
    public static function fromDataBlock(mixed $block): mixed
    {
        if (!self::isDataBlock($block)) {
            return $block;
        }

        /** @var array<string, mixed> $block */
        $source = $block['source_type'];

        if ($source === 'url' && is_string($block['url'] ?? null)) {
            return self::compact([
                'type' => $block['type'],
                'mimeType' => $block['mime_type'] ?? null,
                'url' => $block['url'],
                'metadata' => $block['metadata'] ?? null,
            ]);
        }

        if ($source === 'base64' && is_string($block['data'] ?? null)) {
            return self::compact([
                'type' => $block['type'],
                'mimeType' => $block['mime_type'] ?? 'application/octet-stream',
                'data' => $block['data'],
                'metadata' => $block['metadata'] ?? null,
            ]);
        }

        if ($source === 'id' && is_string($block['id'] ?? null)) {
            return self::compact([
                'type' => $block['type'],
                'mimeType' => $block['mime_type'] ?? null,
                'fileId' => $block['id'],
                'metadata' => $block['metadata'] ?? null,
            ]);
        }

        return $block;
    }

    /**
     * Chat Completions `image_url` / `input_audio` / `file` parts to v1 blocks.
     */
    public static function fromChatCompletionsInput(mixed $block): mixed
    {
        if (!is_array($block)) {
            return $block;
        }

        $type = $block['type'] ?? null;

        if ($type === 'image_url' && is_array($block['image_url'] ?? null) && is_string($block['image_url']['url'] ?? null)) {
            $parsed = self::parseBase64DataUrl($block['image_url']['url']);

            return $parsed !== null
                ? ['type' => 'image', 'mimeType' => $parsed['mime_type'], 'data' => $parsed['data']]
                : ['type' => 'image', 'url' => $block['image_url']['url']];
        }

        if ($type === 'input_audio' && is_array($block['input_audio'] ?? null)
            && is_string($block['input_audio']['data'] ?? null) && is_string($block['input_audio']['format'] ?? null)) {
            return [
                'type' => 'audio',
                'data' => $block['input_audio']['data'],
                'mimeType' => 'audio/' . $block['input_audio']['format'],
            ];
        }

        if ($type === 'file' && is_array($block['file'] ?? null) && is_string($block['file']['data'] ?? null)) {
            $parsed = self::parseBase64DataUrl($block['file']['data']);
            if ($parsed !== null) {
                return ['type' => 'file', 'data' => $parsed['data'], 'mimeType' => $parsed['mime_type']];
            }
            if (is_string($block['file']['file_id'] ?? null)) {
                return ['type' => 'file', 'fileId' => $block['file']['file_id']];
            }
        }

        return $block;
    }

    /**
     * `isDataContentBlock`: a typed block carrying a recognised `source_type`.
     */
    public static function isDataBlock(mixed $block): bool
    {
        return is_array($block)
            && is_string($block['type'] ?? null)
            && in_array($block['source_type'] ?? null, ['url', 'base64', 'text', 'id'], true);
    }

    /**
     * @return array{data: string, mime_type: string}|null
     */
    public static function parseBase64DataUrl(string $dataUrl): ?array
    {
        if (preg_match('#^data:(\w+/\w+);base64,([A-Za-z0-9+/]+=*)$#', $dataUrl, $m) !== 1) {
            return null;
        }

        return ['mime_type' => strtolower($m[1]), 'data' => $m[2]];
    }

    /**
     * Drop null members: `JSON.stringify` omits `undefined`, and a block that
     * carries `metadata: null` is a different block to a provider.
     *
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    private static function compact(array $block): array
    {
        return array_filter($block, static fn (mixed $v): bool => $v !== null);
    }
}
