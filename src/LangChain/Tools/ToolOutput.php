<?php

declare(strict_types=1);

namespace LangChain\Tools;

use LangChain\Messages\ToolMessage;
use LangChain\Utils\Js;

/**
 * Turns a tool's raw return value into what the caller receives.
 *
 * The single most consequential function in the tool layer, and the one place
 * where "did the model call this?" changes the shape of the answer.
 *
 * ## The rule
 *
 * Given a `tool_call_id`, the output becomes a {@see ToolMessage} carrying that
 * id — that is the only thing that lets the model correlate its result with the
 * call it made. Without an id, the raw value passes through untouched: a direct
 * `invoke()` by application code has nothing to attribute, and forcing a
 * `ToolMessage` with an empty id would produce a message the model cannot match
 * to anything.
 *
 * ## Content coercion
 *
 * A `ToolMessage`'s content must be text or content blocks. Anything else is
 * JSON-encoded rather than rejected, because a tool legitimately returning a
 * decoded array is the common case and rejecting it would break tools that work
 * fine. Arrays of content blocks are passed through *unencoded*, because
 * encoding them would turn an image reference into a JSON string and destroy it.
 *
 * A value already marked as direct tool output is passed through even when an id
 * is present — that is how a tool returns a `ToolMessage` (or anything else)
 * that must not be re-wrapped.
 */
final class ToolOutput
{
    /** The key that marks a value as "already final, do not wrap me". */
    public const DIRECT_OUTPUT_KEY = 'lc_direct_tool_output';

    private function __construct()
    {
    }

    /**
     * Whether a value has opted out of `ToolMessage` wrapping.
     *
     * Port of `isDirectToolOutput` from `@langchain/core/messages/tool`.
     *
     * A {@see ToolMessage} counts as direct output. It has to: a tool that
     * returns its own `ToolMessage` has already chosen the id, name, and status
     * that the model will see, and re-wrapping would overwrite all three with
     * values derived from the call rather than the tool's actual result.
     */
    public static function isDirectToolOutput(mixed $value): bool
    {
        if ($value instanceof ToolMessage) {
            return true;
        }

        return is_array($value)
            && !Js::isList($value)
            && ($value[self::DIRECT_OUTPUT_KEY] ?? null) === true;
    }

    /**
     * Build the tool's return value.
     *
     * @param mixed                $content  The tool's output.
     * @param mixed                $artifact The output not meant for the model, when
     *                                      `responseFormat` is `content_and_artifact`.
     * @param string|null          $toolCallId The originating call's id, if any.
     * @param string               $name     The tool's name, recorded on the message.
     * @param array<string, mixed> $metadata
     */
    public static function format(
        mixed $content,
        mixed $artifact,
        ?string $toolCallId,
        string $name,
        array $metadata = [],
    ): mixed {
        if ($toolCallId === null || self::isDirectToolOutput($content)) {
            return $content;
        }

        return new ToolMessage([
            'content' => self::coerceContent($content),
            'artifact' => $artifact,
            'tool_call_id' => $toolCallId,
            'tool_name' => $name,
            'additional_kwargs' => [],
            'response_metadata' => $metadata,
        ]);
    }

    /**
     * Coerce a tool's output into something a `ToolMessage` can carry.
     *
     * `null` becomes `''` rather than the string `'null'`. An absent result is
     * not the JSON value null; it is the absence of one, and `"null"` would put
     * a literal four characters in front of the model as if the tool had said
     * so.
     */
    private static function coerceContent(mixed $content): string|array
    {
        if (is_string($content)) {
            return $content;
        }

        if ($content === null) {
            return '';
        }

        if (is_array($content)) {
            // A list where every element is a content block passes through
            // untouched — encoding it would stringify image and file blocks.
            if (self::isListOfContentBlocks($content)) {
                return array_values($content);
            }

            return self::stringify($content);
        }

        if (is_bool($content)) {
            return $content ? 'true' : 'false';
        }

        if (is_scalar($content)) {
            return (string) $content;
        }

        return self::stringify($content);
    }

    /**
     * @param array<mixed> $content
     */
    private static function isListOfContentBlocks(array $content): bool
    {
        if (!Js::isList($content) || $content === []) {
            return false;
        }

        foreach ($content as $item) {
            if (!is_array($item) || !array_key_exists('type', $item)) {
                return false;
            }
        }

        return true;
    }

    /**
     * JSON-encode, falling back to a readable string.
     *
     * The fallback exists because `json_encode` refuses values that
     * `JSON_PARTIAL_OUTPUT_ON_ERROR` still cannot render (a malformed UTF-8
     * byte, a recursive structure), and a tool returning garbage should produce
     * a readable message rather than an exception from the error path.
     */
    private static function stringify(mixed $content): string
    {
        $encoded = json_encode($content, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR);

        return $encoded === false ? print_r($content, true) : $encoded;
    }
}
