<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Tools;

/**
 * OpenAI's hosted image generation tool, for the Responses API.
 *
 * Port of `tools.imageGeneration` from `@langchain/openai`. Unset options are
 * omitted from the tool array.
 */
final class ImageGeneration
{
    private function __construct()
    {
    }

    /**
     * @param array{
     *     action?: 'generate'|'edit'|'auto',
     *     background?: 'transparent'|'opaque'|'auto',
     *     inputFidelity?: 'high'|'low',
     *     inputImageMask?: array{imageUrl?: string, fileId?: string},
     *     model?: string,
     *     moderation?: 'auto'|'low',
     *     outputCompression?: int,
     *     outputFormat?: 'png'|'webp'|'jpeg',
     *     partialImages?: int,
     *     quality?: 'low'|'medium'|'high'|'auto',
     *     size?: '1024x1024'|'1024x1536'|'1536x1024'|'auto'
     * } $options
     *
     * @return array<string, mixed>
     */
    public static function tool(array $options = []): array
    {
        $mask = $options['inputImageMask'] ?? null;

        return array_filter([
            'type' => 'image_generation',
            'action' => $options['action'] ?? null,
            'background' => $options['background'] ?? null,
            'input_fidelity' => $options['inputFidelity'] ?? null,
            'input_image_mask' => $mask === null ? null : array_filter([
                'image_url' => $mask['imageUrl'] ?? null,
                'file_id' => $mask['fileId'] ?? null,
            ], static fn (mixed $v): bool => $v !== null),
            'model' => $options['model'] ?? null,
            'moderation' => $options['moderation'] ?? null,
            'output_compression' => $options['outputCompression'] ?? null,
            'output_format' => $options['outputFormat'] ?? null,
            'partial_images' => $options['partialImages'] ?? null,
            'quality' => $options['quality'] ?? null,
            'size' => $options['size'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);
    }
}
