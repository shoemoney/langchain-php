<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenRouter\Converters;

use LangChain\Utils\FunctionCalling;

/**
 * Tool conversion for OpenRouter.
 *
 * Port of `converters/tools.ts` from `@langchain/openrouter`.
 */
final class Tools
{
    private function __construct()
    {
    }

    /**
     * Convert LangChain tool inputs to the OpenRouter (OpenAI-compatible) format.
     *
     * @param list<mixed>          $tools
     * @param array{strict?: bool|null} $options
     *
     * @return list<array<string, mixed>>
     */
    public static function convertToolsToOpenRouter(array $tools, array $options = []): array
    {
        $fields = isset($options['strict']) ? ['strict' => $options['strict']] : null;

        return array_map(
            static fn (mixed $tool): array => FunctionCalling::convertToOpenAITool($tool, $fields),
            array_values($tools),
        );
    }

    /**
     * Convert a LangChain `ToolChoice` value to the OpenRouter wire format.
     *
     * `"auto"` and `"none"` pass through, `"any"` is LangChain's name for
     * `"required"`, any other string forces that tool by name, and an array is
     * already wire-shaped.
     *
     * @param string|array<string, mixed>|null $toolChoice
     *
     * @return string|array<string, mixed>|null
     */
    public static function formatToolChoice(string|array|null $toolChoice = null): string|array|null
    {
        if ($toolChoice === null) {
            return null;
        }
        if (is_array($toolChoice)) {
            return $toolChoice;
        }

        return match ($toolChoice) {
            'auto' => 'auto',
            'none' => 'none',
            'any', 'required' => 'required',
            default => ['type' => 'function', 'function' => ['name' => $toolChoice]],
        };
    }
}
