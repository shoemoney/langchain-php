<?php

declare(strict_types=1);

namespace LangChain\Tools;

use LangChain\Runnables\Runnable;
use LangChain\Utils\Testing\StructuredToolSpec;

/**
 * The two small predicates the tool layer needs on its inputs.
 *
 * Port of `_isToolCall` / `_configHasToolCallId` from
 * `@langchain/core/tools/utils`, plus the `isStructuredTool` /
 * `isStructuredToolParams` / `isRunnableToolLike` / `isLangChainTool` predicates
 * from `@langchain/core/tools/types` that `utils/function_calling` re-exports.
 *
 * Both answer "is this the tool-call envelope rather than bare arguments?", which
 * is the single most consequential branch in {@see StructuredTool::call()}: a
 * tool-call input carries a `tool_call_id`, and that id is what the resulting
 * `ToolMessage` is stamped with. Misreading the shape means the model gets a
 * tool result it cannot attribute to its call, and the agent loop stalls.
 */
final class ToolUtils
{
    private function __construct()
    {
    }

    /**
     * Whether `$value` is a tool call rather than bare arguments.
     *
     * The discriminator is the literal `type: "tool_call"`. It is checked rather
     * than inferred from the presence of `name`/`args`, because a structured
     * tool's own arguments may legitimately contain those keys — inferring
     * would misread `{name: "x", args: {...}}` as a call and strip the arguments
     * the tool needed.
     *
     * A *list* is rejected even when its first element happens to be
     * `"tool_call"`. `['tool_call']` is a positional array, not an envelope, and
     * the check keys on `type` precisely so it cannot be confused with one.
     */
    public static function isToolCall(mixed $value): bool
    {
        return is_array($value)
            && !\LangChain\Utils\Js::isList($value)
            && ($value['type'] ?? null) === 'tool_call';
    }

    /**
     * Whether `$config` carries a tool call with a usable id.
     *
     * A tool call *without* an id is legal — it means "I am being called
     * directly, not on the model's behalf" — and in that case the result must
     * NOT be wrapped in a `ToolMessage`, because there is no id to attribute it
     * to. This predicate is what keeps that distinction from being lost.
     */
    public static function configHasToolCallId(mixed $config): bool
    {
        if (!is_array($config) || !isset($config['toolCall'])) {
            return false;
        }
        $toolCall = $config['toolCall'];
        if (!is_array($toolCall)) {
            return false;
        }

        return isset($toolCall['id']) && is_string($toolCall['id']);
    }

    /**
     * Whether `$tool` is a full tool object (upstream: carries an `lc_namespace`).
     */
    public static function isStructuredTool(mixed $tool): bool
    {
        return $tool instanceof StructuredTool;
    }

    /**
     * Whether `$tool` is a Runnable that was turned into a tool.
     *
     * Upstream keys on the constructor's `lc_name()` being `"RunnableToolLike"`;
     * the same discriminator is used here, since a class-name check would break
     * for a subclass.
     */
    public static function isRunnableToolLike(mixed $tool): bool
    {
        return $tool instanceof Runnable
            && method_exists($tool, 'lcName')
            && $tool::lcName() === 'RunnableToolLike';
    }

    /**
     * Whether `$tool` has the minimum a model needs to call it: a name and a schema.
     *
     * The schema must be a {@see Schema} or a JSON Schema whose `type` is one of
     * the JSON primitives. A bare `["name" => ..., "schema" => ...]` with a
     * schema of any other shape is not a tool, it is data that happens to have
     * those keys.
     */
    public static function isStructuredToolParams(mixed $tool): bool
    {
        if ($tool instanceof StructuredToolSpec) {
            return true;
        }
        if (!is_array($tool) || !array_key_exists('name', $tool) || !array_key_exists('schema', $tool)) {
            return false;
        }

        $schema = $tool['schema'];
        if ($schema instanceof Schema) {
            return true;
        }

        return is_array($schema)
            && isset($schema['type'])
            && is_string($schema['type'])
            && in_array($schema['type'], ['null', 'boolean', 'object', 'array', 'number', 'string'], true);
    }

    /**
     * Whether `$tool` is a StructuredTool, a RunnableToolLike or a StructuredToolParams.
     */
    public static function isLangChainTool(mixed $tool): bool
    {
        return self::isRunnableToolLike($tool)
            || self::isStructuredToolParams($tool)
            || self::isStructuredTool($tool);
    }
}
