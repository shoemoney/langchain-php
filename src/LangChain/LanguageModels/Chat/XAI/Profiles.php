<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\XAI;

/**
 * Static capability profiles for xAI models.
 *
 * Port of `profiles.ts` from `@langchain/xai` (generated upstream; the table is
 * copied verbatim, camelCase keys included, so a profile compares equal to the
 * JS `ModelProfile`).
 */
final class Profiles
{
    /** @var array<string, array<string, int|bool>> */
    public const PROFILES = [
        'grok-3-fast-latest' => [
            'maxInputTokens' => 131072,
            'imageInputs' => false,
            'audioInputs' => false,
            'pdfInputs' => false,
            'videoInputs' => false,
            'maxOutputTokens' => 8192,
            'reasoningOutput' => false,
            'imageOutputs' => false,
            'audioOutputs' => false,
            'videoOutputs' => false,
            'toolCalling' => true,
            'structuredOutput' => true,
        ],
        'grok-2-vision' => [
            'maxInputTokens' => 8192,
            'imageInputs' => true,
            'audioInputs' => false,
            'pdfInputs' => false,
            'videoInputs' => false,
            'maxOutputTokens' => 4096,
            'reasoningOutput' => false,
            'imageOutputs' => false,
            'audioOutputs' => false,
            'videoOutputs' => false,
            'toolCalling' => true,
            'structuredOutput' => true,
        ],
        'grok-3' => [
            'maxInputTokens' => 131072,
            'imageInputs' => false,
            'audioInputs' => false,
            'pdfInputs' => false,
            'videoInputs' => false,
            'maxOutputTokens' => 8192,
            'reasoningOutput' => false,
            'imageOutputs' => false,
            'audioOutputs' => false,
            'videoOutputs' => false,
            'toolCalling' => true,
            'structuredOutput' => true,
        ],
        'grok-code-fast-1' => [
            'maxInputTokens' => 256000,
            'imageInputs' => false,
            'audioInputs' => false,
            'pdfInputs' => false,
            'videoInputs' => false,
            'maxOutputTokens' => 10000,
            'reasoningOutput' => true,
            'imageOutputs' => false,
            'audioOutputs' => false,
            'videoOutputs' => false,
            'toolCalling' => true,
            'structuredOutput' => true,
        ],
        'grok-2-vision-1212' => [
            'maxInputTokens' => 8192,
            'imageInputs' => true,
            'audioInputs' => false,
            'pdfInputs' => false,
            'videoInputs' => false,
            'maxOutputTokens' => 4096,
            'reasoningOutput' => false,
            'imageOutputs' => false,
            'audioOutputs' => false,
            'videoOutputs' => false,
            'toolCalling' => true,
            'structuredOutput' => true,
        ],
        'grok-4-1-fast-non-reasoning' => [
            'maxInputTokens' => 2000000,
            'imageInputs' => true,
            'audioInputs' => false,
            'pdfInputs' => false,
            'videoInputs' => false,
            'maxOutputTokens' => 30000,
            'reasoningOutput' => false,
            'imageOutputs' => false,
            'audioOutputs' => false,
            'videoOutputs' => false,
            'toolCalling' => true,
            'structuredOutput' => true,
        ],
        'grok-3-mini-fast' => [
            'maxInputTokens' => 131072,
            'imageInputs' => false,
            'audioInputs' => false,
            'pdfInputs' => false,
            'videoInputs' => false,
            'maxOutputTokens' => 8192,
            'reasoningOutput' => true,
            'imageOutputs' => false,
            'audioOutputs' => false,
            'videoOutputs' => false,
            'toolCalling' => true,
            'structuredOutput' => true,
        ],
        'grok-4-fast' => [
            'maxInputTokens' => 2000000,
            'imageInputs' => true,
            'audioInputs' => false,
            'pdfInputs' => false,
            'videoInputs' => false,
            'maxOutputTokens' => 30000,
            'reasoningOutput' => true,
            'imageOutputs' => false,
            'audioOutputs' => false,
            'videoOutputs' => false,
            'toolCalling' => true,
            'structuredOutput' => true,
        ],
        'grok-4' => [
            'maxInputTokens' => 256000,
            'imageInputs' => false,
            'audioInputs' => false,
            'pdfInputs' => false,
            'videoInputs' => false,
            'maxOutputTokens' => 64000,
            'reasoningOutput' => true,
            'imageOutputs' => false,
            'audioOutputs' => false,
            'videoOutputs' => false,
            'toolCalling' => true,
            'structuredOutput' => true,
        ],
        'grok-3-latest' => [
            'maxInputTokens' => 131072,
            'imageInputs' => false,
            'audioInputs' => false,
            'pdfInputs' => false,
            'videoInputs' => false,
            'maxOutputTokens' => 8192,
            'reasoningOutput' => false,
            'imageOutputs' => false,
            'audioOutputs' => false,
            'videoOutputs' => false,
            'toolCalling' => true,
            'structuredOutput' => true,
        ],
        'grok-4-1-fast' => [
            'maxInputTokens' => 2000000,
            'imageInputs' => true,
            'audioInputs' => false,
            'pdfInputs' => false,
            'videoInputs' => false,
            'maxOutputTokens' => 30000,
            'reasoningOutput' => true,
            'imageOutputs' => false,
            'audioOutputs' => false,
            'videoOutputs' => false,
            'toolCalling' => true,
            'structuredOutput' => true,
        ],
        'grok-2-vision-latest' => [
            'maxInputTokens' => 8192,
            'imageInputs' => true,
            'audioInputs' => false,
            'pdfInputs' => false,
            'videoInputs' => false,
            'maxOutputTokens' => 4096,
            'reasoningOutput' => false,
            'imageOutputs' => false,
            'audioOutputs' => false,
            'videoOutputs' => false,
            'toolCalling' => true,
            'structuredOutput' => true,
        ],
        'grok-3-mini-latest' => [
            'maxInputTokens' => 131072,
            'imageInputs' => false,
            'audioInputs' => false,
            'pdfInputs' => false,
            'videoInputs' => false,
            'maxOutputTokens' => 8192,
            'reasoningOutput' => true,
            'imageOutputs' => false,
            'audioOutputs' => false,
            'videoOutputs' => false,
            'toolCalling' => true,
            'structuredOutput' => true,
        ],
        'grok-3-mini' => [
            'maxInputTokens' => 131072,
            'imageInputs' => false,
            'audioInputs' => false,
            'pdfInputs' => false,
            'videoInputs' => false,
            'maxOutputTokens' => 8192,
            'reasoningOutput' => true,
            'imageOutputs' => false,
            'audioOutputs' => false,
            'videoOutputs' => false,
            'toolCalling' => true,
            'structuredOutput' => true,
        ],
        'grok-3-mini-fast-latest' => [
            'maxInputTokens' => 131072,
            'imageInputs' => false,
            'audioInputs' => false,
            'pdfInputs' => false,
            'videoInputs' => false,
            'maxOutputTokens' => 8192,
            'reasoningOutput' => true,
            'imageOutputs' => false,
            'audioOutputs' => false,
            'videoOutputs' => false,
            'toolCalling' => true,
            'structuredOutput' => true,
        ],
        'grok-2-latest' => [
            'maxInputTokens' => 131072,
            'imageInputs' => false,
            'audioInputs' => false,
            'pdfInputs' => false,
            'videoInputs' => false,
            'maxOutputTokens' => 8192,
            'reasoningOutput' => false,
            'imageOutputs' => false,
            'audioOutputs' => false,
            'videoOutputs' => false,
            'toolCalling' => true,
            'structuredOutput' => true,
        ],
        'grok-4-fast-non-reasoning' => [
            'maxInputTokens' => 2000000,
            'imageInputs' => true,
            'audioInputs' => false,
            'pdfInputs' => false,
            'videoInputs' => false,
            'maxOutputTokens' => 30000,
            'reasoningOutput' => false,
            'imageOutputs' => false,
            'audioOutputs' => false,
            'videoOutputs' => false,
            'toolCalling' => true,
            'structuredOutput' => true,
        ],
        'grok-vision-beta' => [
            'maxInputTokens' => 8192,
            'imageInputs' => true,
            'audioInputs' => false,
            'pdfInputs' => false,
            'videoInputs' => false,
            'maxOutputTokens' => 4096,
            'reasoningOutput' => false,
            'imageOutputs' => false,
            'audioOutputs' => false,
            'videoOutputs' => false,
            'toolCalling' => true,
            'structuredOutput' => true,
        ],
        'grok-3-fast' => [
            'maxInputTokens' => 131072,
            'imageInputs' => false,
            'audioInputs' => false,
            'pdfInputs' => false,
            'videoInputs' => false,
            'maxOutputTokens' => 8192,
            'reasoningOutput' => false,
            'imageOutputs' => false,
            'audioOutputs' => false,
            'videoOutputs' => false,
            'toolCalling' => true,
            'structuredOutput' => true,
        ],
    ];

    private function __construct()
    {
    }

    /**
     * Every known profile, keyed by model name.
     *
     * @return array<string, array<string, int|bool>>
     */
    public static function all(): array
    {
        return self::PROFILES;
    }

    /**
     * The profile for a model, or `[]` when the model is unknown
     * (upstream: `PROFILES[this.model] ?? {}`).
     *
     * @return array<string, int|bool>
     */
    public static function for(string $model): array
    {
        return self::PROFILES[$model] ?? [];
    }
}
