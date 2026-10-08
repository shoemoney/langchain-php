<?php

declare(strict_types=1);

namespace LangChain\Utils;

use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;
use LangChain\Tools\ToolUtils;
use LangChain\Utils\Testing\StructuredToolSpec;

/**
 * Format tools for OpenAI-style function and tool calling.
 *
 * Port of `@langchain/core/utils/function_calling`. The `isStructuredTool` /
 * `isStructuredToolParams` / `isRunnableToolLike` / `isLangChainTool`
 * predicates that file re-exports for backwards compatibility live on
 * {@see ToolUtils}, which is where upstream moved them.
 */
final class FunctionCalling
{
    private function __construct()
    {
    }

    /**
     * Format a tool as an OpenAI function definition.
     *
     * @param StructuredTool|StructuredToolSpec|array<string, mixed> $tool   anything with a name, description and schema
     * @param array{strict?: bool}|null                              $fields `strict` guarantees model output matches the schema exactly
     *
     * @return array{name: string, description?: string, parameters: array<string, mixed>, strict?: bool}
     */
    public static function convertToOpenAIFunction(mixed $tool, ?array $fields = null): array
    {
        [$name, $description, $schema] = self::parts($tool);

        $function = ['name' => $name];
        if ($description !== null) {
            $function['description'] = $description;
        }
        $function['parameters'] = JsonSchema::toJsonSchema($schema);

        // `strict` is only emitted when explicitly set, so an unset value does not
        // reach the wire as `false`.
        if (isset($fields['strict'])) {
            $function['strict'] = $fields['strict'];
        }

        return $function;
    }

    /**
     * Format a tool as an OpenAI tool definition.
     *
     * A LangChain tool is wrapped as `{type: "function", function: {...}}`. Anything
     * else is taken to be provider-shaped already and passed through; re-wrapping
     * it would nest it inside a second `function` key the model cannot read.
     *
     * @param StructuredTool|StructuredToolSpec|array<string, mixed> $tool
     * @param array{strict?: bool}|null                              $fields
     *
     * @return array<string, mixed>
     */
    public static function convertToOpenAITool(mixed $tool, ?array $fields = null): array
    {
        if (ToolUtils::isLangChainTool($tool)) {
            $toolDef = [
                'type' => 'function',
                'function' => self::convertToOpenAIFunction($tool),
            ];
        } else {
            /** @var array<string, mixed> $toolDef */
            $toolDef = $tool;
        }

        if (isset($fields['strict']) && isset($toolDef['function']) && is_array($toolDef['function'])) {
            $toolDef['function']['strict'] = $fields['strict'];
        }

        return $toolDef;
    }

    /**
     * @return array{0: string, 1: string|null, 2: Schema|array<string, mixed>|object}
     */
    private static function parts(mixed $tool): array
    {
        if ($tool instanceof StructuredTool || $tool instanceof StructuredToolSpec) {
            return [$tool->name, $tool->description, $tool->schema];
        }

        if (is_array($tool) && isset($tool['name'], $tool['schema'])) {
            $description = $tool['description'] ?? null;

            return [(string) $tool['name'], $description === null ? null : (string) $description, $tool['schema']];
        }

        throw new \InvalidArgumentException(
            'Cannot convert ' . get_debug_type($tool) . ' to a function definition. Pass a StructuredTool,'
            . ' a StructuredToolSpec, or an array with a name and a schema.'
        );
    }
}
