<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Utils;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\FunctionMessage;
use LangChain\Messages\ToolMessage;
use LangChain\OutputParsers\OpenAITools\JsonOutputToolsParser;
use LangChain\Utils\Js;

/**
 * Translation between LangChain messages and the Chat Completions wire format.
 *
 * Port of `converters/completions.ts` from `@langchain/openai`.
 *
 * This is where most of a provider client's real complexity lives, and almost
 * all of it is lossy in one direction or the other. Three cases carry the weight:
 *
 *  - **Role mapping.** LangChain distinguishes six roles; the Completions API
 *    has four. `ai` becomes `assistant`, `human` becomes `user`, and a `chat`
 *    message with no recognised role is a hard error rather than a guess — a
 *    wrong role is a 400 from the provider, and failing here names the offending
 *    message instead.
 *
 *  - **Tool calls are structural, not content.** An assistant turn carries its
 *    calls in a top-level `tool_calls` field, and a human turn carries results
 *    in a `tool` message keyed by `tool_call_id`. Neither travels as text.
 *
 * ## Divergence from upstream
 *
 * Upstream drops six `content` block types on the way out — `tool_use`,
 * `tool_call`, `functionCall`, `reasoning`, `reasoning_content` and `thinking` —
 * because strict OpenAI-compatible providers reject them echoed back in history.
 * This port drops the same six and nothing else; see
 * `DROPPED_CONTENT_BLOCK_TYPES`. Multimodal blocks (`image`, `audio`, `file`,
 * `video`) are legal Chat Completions input and are forwarded untouched, as
 * upstream does.
 *
 * Two narrower differences remain, both consequences of the message layer:
 * upstream's standard content-block conversion is what produces `thinking` and
 * `tool_use` blocks in `content` in the first place, and this port has no
 * `output_version: v1` conversion path — so those two spellings only ever turn
 * up in a hand-built content list. And upstream additionally applies
 * prompt-cache breakpoints to data blocks on the way through
 * (`applyPromptCacheBreakpoint`); this port has no cache-control concept at this
 * layer, so those blocks pass through verbatim.
 *
 * An earlier version of this docblock claimed "This port passes `content`
 * through unchanged… a caller hand-building such a content list must filter it
 * themselves". That was false on both counts — the filter existed (added by
 * `c1620c8`), and it was an allow-list that discarded every non-`text` block,
 * not a deny-list of six. A docblock that describes the opposite of the code is
 * worse than no docblock, because it is the thing a future editor trusts.
 */
final class Completions
{
    /**
     * Content-block types dropped on the way out to the Chat Completions wire.
     *
     * Verbatim from upstream `converters/completions.ts:846-857`, which reaches
     * this list by asking "is this one of the six the API rejects as input?" and
     * answers `return []` for those and `return m` for EVERYTHING ELSE. It is a
     * deny-list, deliberately: `image`, `audio`, `file` and `video` blocks are
     * legal Chat Completions input and upstream forwards them untouched.
     *
     *   - Tool-call blocks travel in the message's `tool_calls` field, so
     *     resending them as content is a duplicate/invalid part.
     *   - Reasoning traces are output-only. Echoing one back in request history
     *     is rejected by strict OpenAI-compatible providers, e.g. DeepSeek's
     *     "unknown variant `reasoning`, expected `text`".
     *
     * `tool_use`, `functionCall` and `reasoning_content` are Anthropic/Gemini
     * spellings that arrive only in a hand-built content list — this port has no
     * `output_version: v1` conversion path — so they are named here as literals
     * for the same reason upstream names them.
     */
    private const DROPPED_CONTENT_BLOCK_TYPES = [
        'tool_use',
        'tool_call',
        'functionCall',
        'reasoning',
        'reasoning_content',
        'thinking',
    ];

    /**
     * Dropped here but NOT by upstream — a deliberate, recorded divergence.
     *
     * Upstream's list is a closed enumeration, so `redacted_thinking` is
     * forwarded by upstream and a strict endpoint answers 400 on it exactly as
     * it would on `thinking`. That is an upstream gap rather than intended
     * behaviour, and the alternative — being faithful to a list that provokes
     * the failure this filter exists to prevent — is not worth the fidelity.
     *
     * Kept separate from the six above so the two are never confused: one is
     * upstream's rule and must track upstream, the other is this port's, and
     * `NonOpenAIBlocksDroppedTest` guards it independently.
     */
    private const PORT_DROPPED_CONTENT_BLOCK_TYPES = [
        'redacted_thinking',
    ];

    /**
     * Render a message list as Chat Completions `messages` params.
     *
     * @param list<BaseMessage> $messages
     *
     * @return list<array<string, mixed>>
     */
    public static function convertMessages(array $messages): array
    {
        $out = [];

        foreach ($messages as $message) {
            $out[] = self::convertMessage($message);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function convertMessage(BaseMessage $message): array
    {
        $role = self::roleOf($message);
        $param = ['role' => $role];

        if ($message instanceof ToolMessage) {
            // A tool result is not content — it is a protocol field addressed by
            // the id of the call being answered.
            $param['tool_call_id'] = $message->toolCallId;
            $param['content'] = self::stringifyContent($message->content);

            return $param;
        }

        if ($message instanceof FunctionMessage) {
            // The legacy `function` role. The content is the function's RETURN
            // VALUE — omitting it sends the provider a call with no result,
            // which it accepts silently and the model then reasons about as if
            // the function returned nothing.
            $param['name'] = $message->name;
            $param['content'] = self::stringifyContent($message->content);

            return $param;
        }

// Content can be an Anthropic-shaped BLOCK ARRAY, not just a string: a reply carrying a
        // `thinking` block keeps its blocks (that is correct — the blocks belong to the message), and
        // forwarding them verbatim put `{"type":"thinking"}` on an OpenAI-compatible wire, which has no
        // such block type and answers 400. Upstream drops exactly six types on the way out and
        // forwards every other block untouched, which is what DROPPED_CONTENT_BLOCK_TYPES encodes.
        //
        // This was once `=== 'text'`, i.e. an ALLOW-list that kept text and discarded everything else.
        // That is upstream's rule INVERTED, and iteration 487 proved the cost by execution: a
        // four-block message (text + image + audio + file) went out as ONE block, so the request asked
        // "What is in this image?" with no image on the wire and nothing reported the loss. Upstream
        // forwards `image`/`audio`/`file`/`video`, so this port was dropping legal multimodal input.
        //
        // This consumer was simply never exercised before iteration 345 corrected `contentOf()` to
        // stop collapsing a multi-block reply to a string. Both sides were correct in isolation.
        $param['content'] = is_array($message->content)
            ? array_values(array_filter(
                $message->content,
                static fn (mixed $block): bool => !is_array($block)
                    || !in_array(
                        $block['type'] ?? null,
                        // array_merge, NOT `+`: both operands are lists keyed from 0, and `+` is a
// union that keeps the FIRST value per key, so the second list's only entry
// collided with key 0 and was silently discarded — `redacted_thinking` stopped
// being dropped while the multi-modal probe still passed, because that probe
// carries no redacted_thinking block. NonOpenAIBlocksDroppedTest caught it.
                        array_merge(
                            self::DROPPED_CONTENT_BLOCK_TYPES,
                            self::PORT_DROPPED_CONTENT_BLOCK_TYPES,
                        ),
                        true,
                    ),
            ))
            : $message->content;

        if ($message->name !== null && $message->name !== '') {
            $param['name'] = $message->name;
        }

        if ($message instanceof AIMessage) {
            if ($message->toolCalls !== []) {
                $param['tool_calls'] = array_map(
                    static fn (array $call): array => self::toolCallToWire($call),
                    $message->toolCalls,
                );
            } elseif (isset($message->additional_kwargs['tool_calls'])) {
                $param['tool_calls'] = $message->additional_kwargs['tool_calls'];
            }

            if (isset($message->additional_kwargs['function_call'])) {
                $param['function_call'] = $message->additional_kwargs['function_call'];
            }
        }

        return $param;
    }

    /**
     * The Completions role for a message.
     */
    private static function roleOf(BaseMessage $message): string
    {
        return match ($message->type) {
            'system' => 'system',
            'human' => 'user',
            'ai' => 'assistant',
            'tool' => 'tool',
            'function' => 'function',
            default => throw new \InvalidArgumentException(
                'Got unsupported message type: ' . $message->type
            ),
        };
    }

    /**
     * One tool call as the provider wants it.
     *
     * The arguments go out as a **JSON string**, not an object. That is the
     * single most common mistake in a hand-rolled client: the response side
     * parses the string, so the request side must produce one, and an object
     * there is silently dropped by the provider rather than rejected.
     *
     * An argument map with no entries is encoded as `{}`, not `[]`. PHP has one
     * array type, so `json_encode([])` yields `[]` — which is a *different JSON
     * value* to the provider, and turns a no-argument tool into one that claims
     * to take a positional list. Casting to object restores the distinction:
     * an empty map is `{}` and a genuinely empty *list* stays `[]`, because
     * `\LangChain\Utils\Js::isList()` can tell them apart.
     *
     * @param array<string, mixed> $call
     *
     * @return array<string, mixed>
     */
    public static function toolCallToWire(array $call): array
    {
        if (!isset($call['id']) || !is_string($call['id'])) {
            throw new \InvalidArgumentException('All OpenAI tool calls must have an "id" field.');
        }

        $args = $call['args'] ?? [];

        return [
            'id' => $call['id'],
            'type' => 'function',
            'function' => [
                'name' => $call['name'] ?? '',
                // `JSON_THROW_ON_ERROR`, and deliberately NOT
                // `JSON_PARTIAL_OUTPUT_ON_ERROR`. Upstream calls
                // `JSON.stringify(toolCall.args)`, which THROWS on a circular
                // structure; the partial flag instead substituted `null` and
                // sent the call anyway, so a tool received arguments the caller
                // never passed, with no error anywhere. Failing loudly is the
                // faithful behaviour and the only safe one.
                //
                // The cast keeps the empty case honest: PHP has one array type,
                // so an empty *map* would encode as `[]` — a different JSON
                // value to the provider, presenting a no-argument tool as one
                // taking a positional list. A genuine empty list stays `[]`.
                'arguments' => Js::encode(
                    is_array($args) && $args !== [] && Js::isList($args) ? $args : (object) $args,
                ),
            ],
        ];
    }

    /**
     * Flatten content to a string for the fields that only accept one.
     *
     * The Completions API takes a string or an array of typed parts; the tool
     * and function message fields are string-only.
     */
    public static function stringifyContent(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }

        if ($content === null) {
            return '';
        }

        return \LangChain\Utils\Js::encode($content);
    }

    /**
     * One streamed delta, as a folded `AIMessageChunk`.
     *
     * A streamed tool call arrives as fragments: the name in the first delta and
     * the arguments a few bytes at a time. They are passed through as
     * `tool_call_chunks` with the arguments still a string so the chunk-folding
     * algebra can accumulate them; decoding here would defeat the point of
     * streaming a tool call at all.
     *
     * @param array<string, mixed> $delta        The `delta` of one choice.
     * @param array<string, mixed> $rawChunk     The whole chunk, for metadata.
     * @param int                  $index        The choice index.
     */
    public static function deltaToChunk(array $delta, array $rawChunk, int $index = 0): \LangChain\Messages\AIMessageChunk
    {
        $fields = [
            'content' => $delta['content'] ?? '',
            'id' => $rawChunk['id'] ?? null,
            'additional_kwargs' => [],
            'response_metadata' => self::responseMetadata($rawChunk),
        ];

        $toolCallChunks = [];
        foreach ($delta['tool_calls'] ?? [] as $position => $call) {
            $toolCallChunks[] = array_filter([
                'index' => $call['index'] ?? (int) $position,
                'id' => $call['id'] ?? null,
                'name' => $call['function']['name'] ?? null,
                'args' => $call['function']['arguments'] ?? null,
            ], static fn (mixed $v): bool => $v !== null);
        }

        if ($toolCallChunks !== []) {
            $fields['tool_call_chunks'] = $toolCallChunks;
        }

        if (isset($delta['refusal'])) {
            $fields['additional_kwargs']['refusal'] = $delta['refusal'];
        }

        if (isset($delta['function_call'])) {
            $fields['additional_kwargs']['function_call'] = $delta['function_call'];
        }

        $fields['additional_kwargs'] = array_filter(
            $fields['additional_kwargs'] + ['completion_index' => $index],
            static fn (mixed $v): bool => $v !== null,
        );

        return new \LangChain\Messages\AIMessageChunk($fields);
    }

    /**
     * One non-streamed choice, as a finished `AIMessage`.
     *
     * A tool call whose arguments do not parse becomes an *invalid* tool call
     * rather than being dropped. The model hallucinated it, and a caller needs
     * to see that in order to react — a silently shorter tool list is
     * indistinguishable from a model that chose not to call.
     *
     * @param array<string, mixed> $choice
     * @param array<string, mixed> $rawResponse
     */
    public static function choiceToMessage(array $choice, array $rawResponse): AIMessage
    {
        $message = is_array($choice['message'] ?? null) ? $choice['message'] : [];
        $rawToolCalls = is_array($message['tool_calls'] ?? null) ? $message['tool_calls'] : [];

        $toolCalls = [];
        $invalidToolCalls = [];
        foreach ($rawToolCalls as $rawToolCall) {
            if (!is_array($rawToolCall)) {
                continue;
            }

            try {
                $parsed = JsonOutputToolsParser::parseToolCall($rawToolCall, true, false);
                if ($parsed === null) {
                    $invalidToolCalls[] = self::invalidToolCall($rawToolCall, 'Tool call was not a function call.');
                    continue;
                }
                $parsed['type'] = 'tool_call';
                $toolCalls[] = $parsed;
            } catch (\Throwable $e) {
                $invalidToolCalls[] = self::invalidToolCall($rawToolCall, $e->getMessage());
            }
        }

        $additionalKwargs = array_filter([
            'function_call' => $message['function_call'] ?? null,
            'tool_calls' => $rawToolCalls !== [] ? $rawToolCalls : null,
            'refusal' => $message['refusal'] ?? null,
        ], static fn (mixed $v): bool => $v !== null);

        return new AIMessage([
            'content' => $message['content'] ?? '',
            'tool_calls' => $toolCalls,
            'invalid_tool_calls' => $invalidToolCalls,
            'additional_kwargs' => $additionalKwargs,
            'response_metadata' => self::responseMetadata($rawResponse),
            'id' => $rawResponse['id'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $rawToolCall
     *
     * @return array<string, mixed>
     */
    private static function invalidToolCall(array $rawToolCall, string $message): array
    {
        return array_filter([
            'name' => $rawToolCall['function']['name'] ?? null,
            'args' => $rawToolCall['function']['arguments'] ?? null,
            'id' => $rawToolCall['id'] ?? null,
            'error' => $message,
            'type' => 'invalid_tool_call',
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * Provider identity and token usage for a response.
     *
     * Usage is read from `usage` here rather than from a first-class field,
     * which is the same accommodation `llmOutputFromUsage()` makes on the
     * streaming path.
     *
     * @param array<string, mixed> $rawResponse
     *
     * @return array<string, mixed>
     */
    public static function responseMetadata(array $rawResponse): array
    {
        $metadata = [
            'model_provider' => 'openai',
            'model_name' => $rawResponse['model'] ?? null,
        ];

        if (isset($rawResponse['system_fingerprint'])) {
            $metadata['system_fingerprint'] = $rawResponse['system_fingerprint'];
        }

        if (isset($rawResponse['usage']) && is_array($rawResponse['usage'])) {
            $metadata['usage'] = $rawResponse['usage'];
            $metadata['usage_metadata'] = self::usageMetadata($rawResponse['usage']);
        }

        return array_filter($metadata, static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param array<string, mixed> $usage
     *
     * @return array<string, int>
     */
    private static function usageMetadata(array $usage): array
    {
        $prompt = (int) ($usage['prompt_tokens'] ?? 0);
        $completion = (int) ($usage['completion_tokens'] ?? 0);

        return [
            'input_tokens' => $prompt,
            'output_tokens' => $completion,
            'total_tokens' => (int) ($usage['total_tokens'] ?? $prompt + $completion),
        ];
    }
}
