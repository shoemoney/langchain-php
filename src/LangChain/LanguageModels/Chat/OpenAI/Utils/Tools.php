<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Utils;

use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;
use LangChain\Utils\Testing\StructuredToolSpec;

/**
 * Render bindable things into the OpenAI function-tool format.
 *
 * Port of `utils/tools.ts` from `@langchain/openai`.
 *
 * The wire shape is a `function` wrapper around the schema:
 *
 *     {type: "function", function: {name, description, parameters}}
 *
 * Three input shapes are accepted, and the distinction matters. A tool this SDK
 * knows about is *rendered*; a provider-native definition is *passed through*.
 * Re-wrapping the latter would nest it inside a second `function` key and
 * produce a definition the model cannot read — and it would do so silently,
 * because the result still looks like a well-formed tool.
 */
final class Tools
{
    /**
     * Render a bindable into the provider's tool format.
     *
     * @return array<string, mixed>
     */
    public static function convert(mixed $tool, ?bool $strict = null): array
    {
        // Already provider-shaped: leave it exactly as it is. The `is_array`
        // guard is not cosmetic — the checks below index the value, and a
        // StructuredTool is an object.
        if (is_array($tool) && isset($tool['type']) && is_string($tool['type'])) {
            return $tool;
        }

        $name = '';
        $description = '';
        $parameters = ['type' => 'object', 'properties' => []];

        if ($tool instanceof StructuredTool) {
            $name = $tool->name;
            $description = $tool->description;
            $parameters = $tool->schema->toJsonSchema();
        } elseif ($tool instanceof StructuredToolSpec) {
            $name = $tool->name;
            $description = (string) ($tool->description ?? '');
            $parameters = $tool->schema->toJsonSchema();
        } elseif (is_array($tool)) {
            $name = (string) ($tool['name'] ?? '');
            $description = (string) ($tool['description'] ?? '');
            $parameters = $tool['parameters'] ?? $tool['schema'] ?? $parameters;
        } else {
            throw new \InvalidArgumentException(
                'Cannot bind ' . get_debug_type($tool) . ' as a tool. Pass a StructuredTool,'
                . ' a StructuredToolSpec, or a provider-shaped array.'
            );
        }

        if ($name === '') {
            throw new \InvalidArgumentException('A bound tool must have a name.');
        }

        $function = [
            'name' => $name,
            'description' => $description,
            'parameters' => $parameters,
        ];

        if ($strict !== null) {
            $function['strict'] = $strict;
        }

        return ['type' => 'function', 'function' => $function];
    }

    /**
     * Render a list of bindables.
     *
     * @param list<mixed> $tools
     *
     * @return list<array<string, mixed>>
     */
    public static function convertAll(array $tools, ?bool $strict = null): array
    {
        $converted = [];

        foreach (array_values($tools) as $tool) {
            $converted[] = self::convert($tool, $strict);
        }

        return $converted;
    }

    /**
     * A tool choice in the provider's format.
     *
     * `"auto"`/`"none"` are literals the API defines and `"any"` is LangChain's
     * name for `"required"`; anything else is a forced tool name. Mixing those
     * up is easy — `"any"` in particular reads like a tool called "any" — so
     * the three cases are kept distinct here rather than inlined at the call
     * site.
     *
     * @param string|array<string, mixed> $toolChoice
     *
     * @return array<string, mixed>|string
     */
    public static function formatToolChoice(string|array $toolChoice): array|string
    {
        if (is_array($toolChoice)) {
            return $toolChoice;
        }

        return match ($toolChoice) {
            'none', 'auto' => $toolChoice,
            'any', 'required' => 'required',
            default => ['type' => 'function', 'function' => ['name' => $toolChoice]],
        };
    }
}
