<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Converters;

/**
 * Standard (v0 data) content block to a Chat Completions content part.
 *
 * Port of `completionsApiContentBlockConverter` from `converters/completions.ts`
 * plus the dispatch in core's `convertToProviderContentBlock`. The Responses
 * input converter uses it for every data block that is not a file, so that an
 * image or audio part keeps the exact shape the Completions path would send.
 */
final class CompletionsContentBlockConverter
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $block A v0 data block.
     *
     * @return array<string, mixed>
     */
    public static function convert(array $block): array
    {
        return match ($block['type'] ?? null) {
            'text' => ['type' => 'text', 'text' => $block['text'] ?? ''],
            'image' => self::image($block),
            'audio' => self::audio($block),
            'file' => self::file($block),
            default => throw new \InvalidArgumentException(
                "Unable to convert content block type '" . (is_string($block['type'] ?? null) ? $block['type'] : '')
                . "' to provider-specific format: not recognized."
            ),
        };
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    private static function image(array $block): array
    {
        $source = $block['source_type'] ?? null;
        $detail = is_array($block['metadata'] ?? null) ? ($block['metadata']['detail'] ?? null) : null;

        if ($source === 'url') {
            $url = (string) $block['url'];
        } elseif ($source === 'base64') {
            $url = 'data:' . ($block['mime_type'] ?? '') . ';base64,' . $block['data'];
        } else {
            throw new \InvalidArgumentException(
                "Image content blocks with source_type {$source} are not supported for ChatOpenAI"
            );
        }

        return [
            'type' => 'image_url',
            'image_url' => ['url' => $url] + (Misc::truthy($detail) ? ['detail' => $detail] : []),
        ];
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    private static function audio(array $block): array
    {
        $source = $block['source_type'] ?? null;

        if ($source === 'url') {
            $parsed = StandardContentBlocks::parseBase64DataUrl((string) $block['url']);
            if ($parsed === null) {
                throw new \InvalidArgumentException(
                    "URL audio blocks with source_type {$source} must be formatted as a data URL for ChatOpenAI"
                );
            }
            $mime = $parsed['mime_type'] !== '' ? $parsed['mime_type'] : (string) ($block['mime_type'] ?? '');
            $data = $parsed['data'];
        } elseif ($source === 'base64') {
            $mime = (string) ($block['mime_type'] ?? '');
            $data = (string) $block['data'];
        } else {
            throw new \InvalidArgumentException(
                "Audio content blocks with source_type {$source} are not supported for ChatOpenAI"
            );
        }

        if (preg_match('#^audio/(wav|mp3)$#i', $mime, $m) !== 1) {
            throw new \InvalidArgumentException(
                "Audio blocks with source_type {$source} must have mime type of audio/wav or audio/mp3"
            );
        }

        return ['type' => 'input_audio', 'input_audio' => ['format' => strtolower($m[1]), 'data' => $data]];
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    private static function file(array $block): array
    {
        $source = $block['source_type'] ?? null;

        if ($source === 'url') {
            $filename = Misc::getRequiredFilenameFromMetadata($block);
            if (StandardContentBlocks::parseBase64DataUrl((string) $block['url']) === null) {
                throw new \InvalidArgumentException(
                    "URL file blocks with source_type {$source} must be formatted as a data URL for ChatOpenAI"
                );
            }

            return ['type' => 'file', 'file' => ['file_data' => $block['url'], 'filename' => $filename]];
        }

        if ($source === 'base64') {
            return ['type' => 'file', 'file' => [
                'file_data' => 'data:' . ($block['mime_type'] ?? '') . ';base64,' . $block['data'],
                'filename' => Misc::getRequiredFilenameFromMetadata($block),
            ]];
        }

        if ($source === 'id') {
            return ['type' => 'file', 'file' => ['file_id' => $block['id']]];
        }

        throw new \InvalidArgumentException(
            "File content blocks with source_type {$source} are not supported for ChatOpenAI"
        );
    }
}
