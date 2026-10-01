<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Anthropic\Utils;

use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;

/**
 * Translation from Anthropic responses into LangChain messages.
 *
 * Port of `utils/message_outputs.ts` from `@langchain/anthropic`.
 *
 * An Anthropic response's `content` is a list of *blocks*, and the interesting
 * one is `tool_use`. A tool call is not a separate field here the way it is on
 * OpenAI — it is interleaved with the text the model wrote while calling the
 * tool, in the order the model produced it. Preserving that order is why this
 * walks the blocks rather than reaching for a top-level `tool_calls`.
 */
final class MessageOutputs
{
    /**
     * A whole (non-streamed) response as one `AIMessage`.
     *
     * @param array<string, mixed> $payload
     */
    public static function responseToMessage(array $payload): AIMessage
    {
        $toolCalls = [];
        $invalidToolCalls = [];
        $additionalKwargs = [];
        $textBlocks = [];

        foreach (self::blocks($payload) as $block) {
            $type = $block['type'] ?? null;

            if ($type === 'text') {
                $textBlocks[] = ['type' => 'text', 'text' => (string) ($block['text'] ?? '')];
                continue;
            }

            if ($type === 'tool_use') {
                $input = $block['input'] ?? [];

                if (!is_array($input)) {
                    $invalidToolCalls[] = [
                        'name' => $block['name'] ?? null,
                        'args' => $input,
                        'id' => $block['id'] ?? null,
                        'error' => 'Tool call input was not an object.',
                        'type' => 'invalid_tool_call',
                    ];
                    continue;
                }

                $toolCalls[] = [
                    'name' => (string) ($block['name'] ?? ''),
                    'args' => $input,
                    'id' => $block['id'] ?? null,
                    'type' => 'tool_call',
                ];
                continue;
            }

            // `thinking` / `redacted_thinking` / server_tool_use etc. are kept
            // verbatim rather than discarded: they are output-only blocks a
            // caller may need to reason about, and dropping them would make a
            // response look simpler than it was.
            $additionalKwargs[$type ?? 'unknown'] = $block;
        }

        // Non-text, non-tool_use blocks (thinking, redacted_thinking, server
        // tool use) are kept verbatim under their own type key rather than
        // discarded: they are output-only blocks a caller may need to reason
        // about, and dropping them would make a response look simpler than it
        // was. Upstream keys them the same way and has no aggregate copy.
        return new AIMessage([
            'content' => self::contentOf($payload, $textBlocks),
            'tool_calls' => $toolCalls,
            'invalid_tool_calls' => $invalidToolCalls,
            'additional_kwargs' => $additionalKwargs,
            'response_metadata' => self::responseMetadata($payload),
            'id' => $payload['id'] ?? null,
        ]);
    }

    /**
     * One streaming event, as a foldable `AIMessageChunk` — or null if the
     * event carries no content.
     *
     * The event stream is a state machine, and only two of its events produce
     * text: `content_block_start` (which opens a block, possibly a tool call)
     * and `content_block_delta` (which fills it in). Lifecycle events —
     * `message_start`, `ping`, `message_stop` — carry no foldable content and
     * yield nothing, so a caller iterating the stream never has to filter them.
     *
     * Tool-call arguments arrive as `input_json_delta` fragments of a JSON
     * *string*, exactly as on OpenAI. They are passed through as
     * `tool_call_chunks` so the folding algebra can accumulate them; decoding
     * per delta would defeat streaming a tool call.
     *
     * @param array<string, mixed> $event
     */
    public static function eventToChunk(array $event): ?AIMessageChunk
    {
        $type = $event['type'] ?? null;

        if ($type === 'content_block_start') {
            // The key is `content_block`, not `content`. Reading `content` here
            // made every streamed Anthropic tool call vanish: the real event did
            // not match, so the whole branch was skipped and the tool call —
            // id, name and all — never reached the caller. It survived because
            // the test fixture used the same wrong key the code did, which is
            // the way a test can agree with a bug perfectly.
            $block = $event['content_block'] ?? $event['content'] ?? null;

            if (!is_array($block)) {
                return null;
            }

            if (($block['type'] ?? null) === 'tool_use') {
                return new AIMessageChunk([
                    'content' => '',
                    'tool_call_chunks' => [[
                        'index' => (int) ($event['index'] ?? 0),
                        'id' => $block['id'] ?? null,
                        'name' => $block['name'] ?? null,
                        'args' => '',
                    ]],
                ]);
            }

            if (($block['type'] ?? null) === 'text') {
                return new AIMessageChunk(['content' => (string) ($block['text'] ?? '')]);
            }

            return null;
        }

        if ($type === 'content_block_delta') {
            $delta = $event['delta'] ?? [];
            $deltaType = $delta['type'] ?? null;

            if ($deltaType === 'text_delta') {
                return new AIMessageChunk(['content' => (string) ($delta['text'] ?? '')]);
            }

            if ($deltaType === 'input_json_delta') {
                return new AIMessageChunk([
                    'content' => '',
                    'tool_call_chunks' => [[
                        'index' => (int) ($event['index'] ?? 0),
                        'args' => (string) ($delta['partial_json'] ?? ''),
                    ]],
                ]);
            }

            if ($deltaType === 'thinking_delta') {
                return new AIMessageChunk([
                    'content' => '',
                    'additional_kwargs' => ['thinking' => (string) ($delta['thinking'] ?? '')],
                ]);
            }

            return null;
        }

        return null;
    }

    /**
     * Usage carried on a `message_start` or `message_delta` event.
     *
     * The two events put it in different places: `message_start` nests it under
     * `message`, `message_delta` puts it at the top level. Reading only one of
     * them silently reports every streamed call as zero input tokens.
     *
     * @param array<string, mixed> $event
     *
     * @return array<string, int>
     */
    public static function usageFromEvent(array $event): array
    {
        $usage = $event['usage'] ?? null;
        if (!is_array($usage) && is_array($event['message'] ?? null)) {
            $usage = $event['message']['usage'] ?? null;
        }

        return is_array($usage) ? self::usageMetadata($usage) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function blocks(array $payload): array
    {
        $blocks = $payload['content'] ?? [];

        return is_array($blocks) ? array_values(array_filter($blocks, 'is_array')) : [];
    }

    /**
     * A single text block is a string; anything else keeps its block structure.
     *
     * This is upstream's rule exactly (`anthropicResponseToChatMessages`):
     *
     *  - exactly one block and it is text -> `content` is that text;
     *  - anything else -> `content` is the raw block array, unflattened.
     *
     * Joining several text blocks with a separator — which this did — loses the
     * structure entirely: a caller cannot tell one paragraph from two, the
     * block types are gone, and a text block interleaved with a `thinking` block
     * silently merges into it. For a single text block, which is the
     * overwhelmingly common case, the string form is both upstream's behaviour
     * and what the rest of this SDK expects.
     *
     * @param list<array<string, mixed>> $textBlocks
     *
     * @return string|list<array<string, mixed>>
     */
    /**
     * The plain-text answer for a payload, flattened from however many blocks it arrived in.
     *
     * `contentOf()` returns a STRING only when there is exactly one `type: text` block, and the raw
     * block array otherwise. Reading that with `is_string($content) ? $content : ''` therefore produced
     * an EMPTY STRING for every multi-block answer, while `$message->content` kept every block.
     *
     * The case that matters is `thinking` + `text`: a thinking block ahead of the answer is what
     * Anthropic's extended thinking emits on essentially every request, so the ordinary shape for a
     * thinking-enabled model was an empty answer. `StrOutputParser` and trace text both read this string.
     *
     * Only `text` blocks contribute. `thinking`, `redacted_thinking` and `tool_use` are not answer text,
     * and a `tool_use` block's payload is arguments, not prose.
     *
     * Named to parallel `Completions::stringifyContent()` on the OpenAI side, which flattens its own
     * content shape — the two providers now both give string consumers the answer rather than one of
     * them silently yielding ''.
     *
     * @param string|list<array<string, mixed>> $content
     */
    public static function stringifyText(string|array $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        $text = '';
        foreach ($content as $block) {
            if (!is_array($block) || ($block['type'] ?? null) !== 'text') {
                continue;
            }

            $text .= (string) ($block['text'] ?? '');
        }

        return $text;
    }

    private static function contentOf(array $payload, array $textBlocks): string|array
    {
        $blocks = self::blocks($payload);

        if (count($blocks) === 1 && ($blocks[0]['type'] ?? null) === 'text') {
            return $textBlocks[0]['text'] ?? '';
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    public static function responseMetadata(array $payload): array
    {
        $metadata = [
            'model_provider' => 'anthropic',
            'model_name' => $payload['model'] ?? null,
        ];

        if (isset($payload['id'])) {
            $metadata['id'] = $payload['id'];
        }
        if (isset($payload['stop_reason'])) {
            $metadata['stop_reason'] = $payload['stop_reason'];
        }
        if (isset($payload['usage']) && is_array($payload['usage'])) {
            $metadata['usage'] = $payload['usage'];
            $metadata['usage_metadata'] = self::usageMetadata($payload['usage']);
        }

        return array_filter($metadata, static fn (mixed $v): bool => $v !== null);
    }

    /**
     * Anthropic counts input and output tokens; the total is their sum.
     *
     * @param array<string, mixed> $usage
     *
     * @return array<string, int>
     */
    private static function usageMetadata(array $usage): array
    {
        $input = (int) ($usage['input_tokens'] ?? 0);
        $output = (int) ($usage['output_tokens'] ?? 0);

        return [
            'input_tokens' => $input,
            'output_tokens' => $output,
            'total_tokens' => $input + $output,
        ];
    }
}
