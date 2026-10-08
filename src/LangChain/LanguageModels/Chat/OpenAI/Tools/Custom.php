<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Tools;

use LangChain\Runnables\RunnableConfig;
use LangChain\Tools\DynamicTool;

/**
 * Wrap a callable as an OpenAI custom tool.
 *
 * Port of `customTool` from `@langchain/openai`. The OpenAI definition (`name`
 * plus optional `description` and `format`) rides in `metadata.customTool`, which
 * is where `ResponsesTools::isCustomTool()` looks, and the LangChain description
 * is blank as upstream's is.
 */
final class Custom
{
    private function __construct()
    {
    }

    /**
     * @param callable(string, RunnableConfig|null): mixed $func   Receives the raw string input and the call config.
     * @param array<string, mixed>                         $fields The custom tool definition without `type`.
     */
    public static function create(callable $func, array $fields): DynamicTool
    {
        return new DynamicTool(
            [
                ...$fields,
                'description' => '',
                'metadata' => ['customTool' => $fields],
            ],
            static function (mixed $input, mixed $runManager, ?RunnableConfig $config) use ($func): mixed {
                if (is_array($input) && array_key_exists('input', $input)) {
                    $input = $input['input'];
                }

                return $func($input, $config);
            },
        );
    }
}
