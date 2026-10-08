<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\XAI\Tools;

/**
 * The `tools` object exported by `tools/index.ts` in `@langchain/xai`.
 */
final class Tools
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     *
     * @deprecated Use {@see self::xaiWebSearch()} and {@see self::xaiXSearch()}.
     */
    public static function xaiLiveSearch(array $options = []): array
    {
        return LiveSearch::create($options);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function xaiWebSearch(array $options = []): array
    {
        return WebSearch::create($options);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function xaiXSearch(array $options = []): array
    {
        return XSearch::create($options);
    }

    /**
     * @return array{type: string}
     */
    public static function xaiCodeExecution(): array
    {
        return CodeExecution::create();
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function xaiCollectionsSearch(array $options = []): array
    {
        return CollectionsSearch::create($options);
    }
}
