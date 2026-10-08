<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Converters;

use LangChain\LanguageModels\Chat\OpenAI\Utils\Tools;
use LangChain\Tools\StructuredTool;
use LangChain\Utils\Testing\StructuredToolSpec;

/**
 * Tool and tool-call handling specific to the Responses API.
 *
 * Port of the Responses-facing half of `utils/tools.ts` from `@langchain/openai`
 * (`isBuiltInTool`, the custom-tool and computer-tool predicates and parsers,
 * the two custom-tool format converters) and of `_reduceChatOpenAITools` from
 * `chat_models/responses.ts`.
 *
 * The Chat Completions rendering of an ordinary function tool stays in
 * {@see Tools}; this class never re-derives a name/description/schema. A tool it
 * has to render is rendered by `Tools::convert()` and then *flattened*, because
 * the Responses API puts `name`/`parameters` at the top level of a function tool
 * rather than under a `function` key.
 *
 * Tools and tool calls are plain arrays (the JSON shapes), except that a
 * LangChain tool is a {@see StructuredTool} object and may carry
 * `extras.providerToolDefinition` or `metadata.customTool`.
 */
final class ResponsesTools
{
    private function __construct()
    {
    }

    /**
     * A provider-native tool: has a `type` and it is not `function`.
     */
    public static function isBuiltInTool(mixed $tool): bool
    {
        return is_array($tool) && isset($tool['type']) && is_string($tool['type']) && $tool['type'] !== 'function';
    }

    /**
     * A tool that carries its own provider definition in
     * `extras.providerToolDefinition` (localShell, shell, computerUse, applyPatch).
     */
    public static function hasProviderToolDefinition(mixed $tool): bool
    {
        $extras = self::extrasOf($tool);

        return is_array($extras) && is_array($extras['providerToolDefinition'] ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function providerToolDefinition(mixed $tool): ?array
    {
        $extras = self::extrasOf($tool);

        return is_array($extras) && is_array($extras['providerToolDefinition'] ?? null)
            ? $extras['providerToolDefinition']
            : null;
    }

    public static function isBuiltInToolChoice(mixed $toolChoice): bool
    {
        return is_array($toolChoice) && isset($toolChoice['type']) && $toolChoice['type'] !== 'function';
    }

    /**
     * A LangChain tool wrapping an OpenAI custom tool (`metadata.customTool`).
     */
    public static function isCustomTool(mixed $tool): bool
    {
        return self::customToolOf($tool) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function customToolOf(mixed $tool): ?array
    {
        $metadata = null;
        if ($tool instanceof StructuredTool) {
            $metadata = $tool->metadata;
        } elseif (is_array($tool)) {
            $metadata = $tool['metadata'] ?? null;
        }

        return is_array($metadata) && is_array($metadata['customTool'] ?? null) ? $metadata['customTool'] : null;
    }

    /**
     * A Chat Completions `{type: "custom", custom: {...}}` tool.
     */
    public static function isOpenAICustomTool(mixed $tool): bool
    {
        return is_array($tool) && ($tool['type'] ?? null) === 'custom' && is_array($tool['custom'] ?? null);
    }

    /**
     * A Chat Completions `{type: "function", function: {name, ...}}` tool.
     */
    public static function isOpenAIFunctionTool(mixed $tool): bool
    {
        return is_array($tool)
            && ($tool['type'] ?? null) === 'function'
            && is_array($tool['function'] ?? null)
            && isset($tool['function']['name']);
    }

    /**
     * A raw `custom_tool_call` output item as a LangChain tool call.
     *
     * The ids are deliberately swapped, as upstream does: the LangChain `id` is
     * the API's `call_id` (what the tool result must answer), and the API's own
     * item id is kept as `call_id`.
     *
     * @param array<string, mixed> $rawToolCall
     *
     * @return array<string, mixed>|null
     */
    public static function parseCustomToolCall(array $rawToolCall): ?array
    {
        if (($rawToolCall['type'] ?? null) !== 'custom_tool_call') {
            return null;
        }

        return [
            ...$rawToolCall,
            'type' => 'tool_call',
            'call_id' => $rawToolCall['id'] ?? null,
            'id' => $rawToolCall['call_id'] ?? null,
            'name' => $rawToolCall['name'] ?? null,
            'isCustomTool' => true,
            'args' => ['input' => $rawToolCall['input'] ?? null],
        ];
    }

    /**
     * A raw `computer_call` output item as a LangChain tool call.
     *
     * @param array<string, mixed> $rawToolCall
     *
     * @return array<string, mixed>|null
     */
    public static function parseComputerCall(array $rawToolCall): ?array
    {
        if (($rawToolCall['type'] ?? null) !== 'computer_call') {
            return null;
        }

        return [
            ...$rawToolCall,
            'type' => 'tool_call',
            'call_id' => $rawToolCall['id'] ?? null,
            'id' => $rawToolCall['call_id'] ?? null,
            'name' => 'computer_use',
            'isComputerTool' => true,
            'args' => ['action' => $rawToolCall['action'] ?? null],
        ];
    }

    public static function isComputerToolCall(mixed $toolCall): bool
    {
        return is_array($toolCall)
            && ($toolCall['type'] ?? null) === 'tool_call'
            && ($toolCall['isComputerTool'] ?? null) === true;
    }

    /**
     * @param array<string, string>|null $customToolCallIds Item-id map recorded on the message.
     */
    public static function isCustomToolCall(mixed $toolCall, ?array $customToolCallIds = null): bool
    {
        if (!is_array($toolCall) || ($toolCall['type'] ?? null) !== 'tool_call') {
            return false;
        }

        if (($toolCall['isCustomTool'] ?? null) === true) {
            return true;
        }

        return $customToolCallIds !== null
            && is_string($toolCall['id'] ?? null)
            && array_key_exists($toolCall['id'], $customToolCallIds);
    }

    /**
     * Chat Completions custom tool to the Responses shape.
     *
     * @param array<string, mixed> $tool
     *
     * @return array<string, mixed>
     */
    public static function convertCompletionsCustomTool(array $tool): array
    {
        /** @var array<string, mixed> $custom */
        $custom = $tool['custom'];
        $format = null;

        if (is_array($custom['format'] ?? null)) {
            $source = $custom['format'];
            if (($source['type'] ?? null) === 'grammar') {
                $format = [
                    'type' => 'grammar',
                    'definition' => $source['grammar']['definition'] ?? null,
                    'syntax' => $source['grammar']['syntax'] ?? null,
                ];
            } elseif (($source['type'] ?? null) === 'text') {
                $format = ['type' => 'text'];
            }
        }

        return self::compact([
            'type' => 'custom',
            'name' => $custom['name'] ?? null,
            'description' => $custom['description'] ?? null,
            'format' => $format,
        ]);
    }

    /**
     * Responses custom tool to the Chat Completions shape.
     *
     * @param array<string, mixed> $tool
     *
     * @return array<string, mixed>
     */
    public static function convertResponsesCustomTool(array $tool): array
    {
        $format = null;

        if (is_array($tool['format'] ?? null)) {
            $source = $tool['format'];
            if (($source['type'] ?? null) === 'grammar') {
                $format = [
                    'type' => 'grammar',
                    'grammar' => [
                        'definition' => $source['definition'] ?? null,
                        'syntax' => $source['syntax'] ?? null,
                    ],
                ];
            } elseif (($source['type'] ?? null) === 'text') {
                $format = ['type' => 'text'];
            }
        }

        return [
            'type' => 'custom',
            'custom' => self::compact([
                'name' => $tool['name'] ?? null,
                'description' => $tool['description'] ?? null,
                'format' => $format,
            ]),
        ];
    }

    /**
     * Reduce bound tools to the `tools` array of a Responses request.
     *
     * Port of `_reduceChatOpenAITools`. A tool that matches none of the known
     * shapes is dropped, as upstream does. A `StructuredTool` or
     * `StructuredToolSpec` (which upstream would already have converted in
     * `bindTools`) is rendered by {@see Tools::convert()} and then flattened.
     *
     * @param list<mixed> $tools
     *
     * @return list<array<string, mixed>>
     */
    public static function reduceTools(array $tools, bool $stream = false, ?bool $strict = null): array
    {
        $reduced = [];

        foreach ($tools as $tool) {
            if (self::hasProviderToolDefinition($tool)) {
                $reduced[] = self::providerToolDefinition($tool);
                continue;
            }

            if (self::isBuiltInTool($tool)) {
                /** @var array<string, mixed> $tool */
                if (($tool['type'] ?? null) === 'image_generation' && $stream) {
                    // A streamed image_generation tool without partial_images is a 400.
                    $tool['partial_images'] = 1;
                }
                $reduced[] = $tool;
                continue;
            }

            $custom = self::customToolOf($tool);
            if ($custom !== null) {
                $reduced[] = self::compact([
                    'type' => 'custom',
                    'name' => $custom['name'] ?? null,
                    'description' => $custom['description'] ?? null,
                    'format' => $custom['format'] ?? null,
                ]);
                continue;
            }

            if ($tool instanceof StructuredTool || $tool instanceof StructuredToolSpec) {
                $tool = Tools::convert($tool);
            }

            if (self::isOpenAIFunctionTool($tool)) {
                /** @var array<string, mixed> $tool */
                $extra = array_diff_key($tool, ['type' => true, 'function' => true]);
                /** @var array<string, mixed> $function */
                $function = $tool['function'];

                $reduced[] = [
                    'type' => 'function',
                    'name' => $function['name'],
                    'parameters' => self::objectifyParameters($function['parameters'] ?? null),
                    ...(isset($function['description']) ? ['description' => $function['description']] : []),
                    'strict' => $strict,
                ] + $extra;
                continue;
            }

            if (self::isOpenAICustomTool($tool)) {
                $reduced[] = self::convertCompletionsCustomTool($tool);
            }
        }

        return $reduced;
    }

    /**
     * A tool choice in the Responses shape.
     *
     * A built-in choice passes through. Everything else is formatted by
     * {@see Tools::formatToolChoice()} and then flattened: Responses wants
     * `{type: "function", name}`, not `{type: "function", function: {name}}`.
     *
     * @param string|array<string, mixed> $toolChoice
     *
     * @return array<string, mixed>|string
     */
    public static function formatToolChoice(string|array $toolChoice): array|string
    {
        if (self::isBuiltInToolChoice($toolChoice)) {
            return $toolChoice;
        }

        $formatted = Tools::formatToolChoice($toolChoice);
        if (!is_array($formatted)) {
            return $formatted;
        }

        return match ($formatted['type'] ?? null) {
            'function' => ['type' => 'function', 'name' => $formatted['function']['name'] ?? ''],
            'allowed_tools' => [
                'type' => 'allowed_tools',
                'mode' => $formatted['allowed_tools']['mode'] ?? null,
                'tools' => $formatted['allowed_tools']['tools'] ?? null,
            ],
            'custom' => ['type' => 'custom', 'name' => $formatted['custom']['name'] ?? ''],
            default => $formatted,
        };
    }

    /**
     * A schema's `properties` map must stay an object on the wire.
     *
     * PHP has one array type, so a tool that takes no arguments carries
     * `properties: []`, which encodes as a JSON LIST: the provider is told the
     * tool takes a positional list. This is the no-argument-tool bug class from
     * HANDOFF. Only the top-level `properties` is repaired; a schema that
     * already holds an object is left alone.
     */
    private static function objectifyParameters(mixed $parameters): mixed
    {
        if (is_array($parameters) && array_key_exists('properties', $parameters) && $parameters['properties'] === []) {
            $parameters['properties'] = new \stdClass();
        }

        return $parameters;
    }

    private static function extrasOf(mixed $tool): mixed
    {
        if ($tool instanceof StructuredTool) {
            return $tool->extras;
        }

        return is_array($tool) ? ($tool['extras'] ?? null) : null;
    }

    /**
     * `undefined` members are absent from JSON, so null members are dropped.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    private static function compact(array $values): array
    {
        return array_filter($values, static fn (mixed $v): bool => $v !== null);
    }
}
