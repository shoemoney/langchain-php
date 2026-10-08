<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\DeepSeek;

/**
 * Static capability profiles for DeepSeek models.
 *
 * Port of `profiles.ts` from `@langchain/deepseek` (generated upstream; the
 * table is copied verbatim, camelCase keys included, so a profile compares equal
 * to the JS `ModelProfile`).
 */
final class Profiles
{
    /** @var array<string, array<string, int|bool>> */
    public const PROFILES = [
        'deepseek-chat' => [
            'maxInputTokens' => 1000000,
            'imageInputs' => false,
            'audioInputs' => false,
            'pdfInputs' => false,
            'videoInputs' => false,
            'maxOutputTokens' => 384000,
            'reasoningOutput' => false,
            'imageOutputs' => false,
            'audioOutputs' => false,
            'videoOutputs' => false,
            'toolCalling' => true,
            'structuredOutput' => false,
        ],
        'deepseek-v4-pro' => [
            'maxInputTokens' => 1000000,
            'imageInputs' => false,
            'audioInputs' => false,
            'pdfInputs' => false,
            'videoInputs' => false,
            'maxOutputTokens' => 384000,
            'reasoningOutput' => true,
            'imageOutputs' => false,
            'audioOutputs' => false,
            'videoOutputs' => false,
            'toolCalling' => true,
            'structuredOutput' => true,
        ],
        'deepseek-reasoner' => [
            'maxInputTokens' => 1000000,
            'imageInputs' => false,
            'audioInputs' => false,
            'pdfInputs' => false,
            'videoInputs' => false,
            'maxOutputTokens' => 384000,
            'reasoningOutput' => true,
            'imageOutputs' => false,
            'audioOutputs' => false,
            'videoOutputs' => false,
            'toolCalling' => true,
            'structuredOutput' => false,
        ],
        'deepseek-v4-flash' => [
            'maxInputTokens' => 1000000,
            'imageInputs' => false,
            'audioInputs' => false,
            'pdfInputs' => false,
            'videoInputs' => false,
            'maxOutputTokens' => 384000,
            'reasoningOutput' => true,
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
