<?php

declare(strict_types=1);

namespace LangChain\Tools;

/**
 * The two small predicates the tool layer needs on its inputs.
 *
 * Port of `_isToolCall` / `_configHasToolCallId` from
 * `@langchain/core/tools/utils`.
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
}
