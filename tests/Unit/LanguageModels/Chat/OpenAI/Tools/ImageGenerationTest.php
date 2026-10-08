<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Tools;

use LangChain\LanguageModels\Chat\OpenAI\Tools\ImageGeneration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** `tools/tests/imageGeneration.test.ts`. */
#[CoversClass(ImageGeneration::class)]
final class ImageGenerationTest extends TestCase
{
    public function testCreatesValidToolDefinitions(): void
    {
        $tool = ImageGeneration::tool([
            'action' => 'edit',
            'background' => 'opaque',
            'inputFidelity' => 'high',
            'inputImageMask' => ['imageUrl' => 'data:image/png;base64,abc123', 'fileId' => 'file-xyz789'],
            'model' => 'gpt-image-1.5',
            'moderation' => 'auto',
            'outputCompression' => 85,
            'outputFormat' => 'webp',
            'partialImages' => 3,
            'quality' => 'medium',
            'size' => '1536x1024',
        ]);

        self::assertEquals([
            'type' => 'image_generation',
            'action' => 'edit',
            'background' => 'opaque',
            'input_fidelity' => 'high',
            'input_image_mask' => ['file_id' => 'file-xyz789', 'image_url' => 'data:image/png;base64,abc123'],
            'model' => 'gpt-image-1.5',
            'moderation' => 'auto',
            'output_compression' => 85,
            'output_format' => 'webp',
            'partial_images' => 3,
            'quality' => 'medium',
            'size' => '1536x1024',
        ], $tool);
    }

    public function testOmitsActionWhenNotProvided(): void
    {
        $tool = ImageGeneration::tool();

        self::assertSame('image_generation', $tool['type']);
        self::assertArrayNotHasKey('action', $tool);
    }

    public function testSupportsAllActionValues(): void
    {
        foreach (['generate', 'edit', 'auto'] as $action) {
            self::assertSame(['type' => 'image_generation', 'action' => $action], ImageGeneration::tool(['action' => $action]));
        }
    }
}
