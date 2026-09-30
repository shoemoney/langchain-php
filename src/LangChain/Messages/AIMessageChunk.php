<?php

declare(strict_types=1);

namespace LangChain\Messages;

/**
 * A chunk of an AI message, produced while streaming.
 *
 * Port of `AIMessageChunk` from `@langchain/core/messages/ai`.
 *
 * Note the hierarchy: in the TypeScript original `AIMessageChunk` extends
 * `BaseMessageChunk`, making it a *sibling* of `AIMessage`, not a subclass of
 * it. That is deliberate — a chunk is not a finished AI message, it only gains
 * the ability to fold. The finished-message fields (parsed `toolCalls`,
 * `invalidToolCalls`) live on {@see AIMessage}; the streaming representation
 * (partial `toolCallChunks`) lives here, and {@see self::toMessage()} turns one
 * into the other.
 */
class AIMessageChunk extends BaseMessageChunk
{
    public string $type = BaseMessage::ROLE_AI;

    /**
     * Partially-parsed tool calls, folded by index or id. Each `args` is a
     * *string* of JSON accumulated across deltas, not yet decoded.
     *
     * @var list<array{index?: int, id?: string, name?: string, args?: string, type?: string}>
     */
    public array $toolCallChunks = [];

    public function __construct(string|array $fields = [])
    {
        parent::__construct($fields);
        if (is_array($fields) && isset($fields['tool_call_chunks']) && is_array($fields['tool_call_chunks'])) {
            $this->toolCallChunks = array_values($fields['tool_call_chunks']);
            $this->kwargs['tool_call_chunks'] = $this->toolCallChunks;
        }
    }

    public static function lcId(): array
    {
        return ['langchain_core', 'messages', 'AIMessageChunk'];
    }

    /**
     * A new chunk holding this one folded together with `$other`.
     *
     * Returns a NEW instance and leaves `$this` untouched, which is what
     * upstream does: `ai.ts:432-446` builds a `combinedFields` object and
     * returns a fresh `AIMessageChunk` from it. The old wording here — "fold
     * another chunk into this one" — read as an in-place mutation, and a caller
     * written against that reading would discard the accumulated result.
     *
     * So this is a wording fix, not a behaviour fix: the code was already right
     * and had been for the whole time.
     */
    public function concat(BaseMessageChunk $other): BaseMessageChunk
    {
        $this->assertSameChunkClass($other, self::class);

        return new self([
            'content' => MessageMerge::mergeContent($this->content, $other->content),
            'additional_kwargs' => MessageMerge::mergeDicts($this->additional_kwargs, $other->additional_kwargs) ?? [],
            'response_metadata' => MessageMerge::mergeDicts($this->response_metadata, $other->response_metadata) ?? [],
            'tool_call_chunks' => MessageMerge::mergeLists($this->toolCallChunks, $other->toolCallChunks) ?? [],
            'id' => $other->id ?? $this->id,
            'name' => $other->name ?? $this->name,
        ]);
    }

    /**
     * Decode the accumulated `tool_call_chunks` into real tool calls.
     *
     * Chunks whose args do not parse as JSON become invalid tool calls rather
     * than being silently dropped — the model hallucinated them, and the caller
     * needs to see that in order to react.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>}
     *         [toolCalls, invalidToolCalls]
     */
    public function parseToolCalls(): array
    {
        $calls = [];
        $invalid = [];

        foreach ($this->toolCallChunks as $chunk) {
            $name = $chunk['name'] ?? null;
            $rawArgs = $chunk['args'] ?? null;
            $id = $chunk['id'] ?? null;
            $index = $chunk['index'] ?? null;

            if ($name === null || $name === '') {
                $invalid[] = array_filter([
                    'name' => $name,
                    'args' => $rawArgs,
                    'error' => 'Tool call is missing a name',
                    'id' => $id,
                    'index' => $index,
                ], static fn ($v) => $v !== null);
                continue;
            }

            // A chunk's `args` is undecoded JSON *by construction* — that is
            // what lets deltas be accumulated as strings. An already-decoded
            // array is therefore not a shape this method can re-parse, and
            // calling trim() on it raised a TypeError that aborted stream
            // reconstruction outright — a crash, not a bad tool call.
            //
            // It is routed to the invalid list instead, which is the existing
            // destination for arguments that could not be turned into an
            // object. A caller that genuinely holds decoded arguments should
            // use `AIMessage::toolCalls`, not round-trip them through chunks.
            if ($rawArgs !== null && !is_string($rawArgs)) {
                $invalid[] = array_filter([
                    'name' => $name,
                    'args' => $rawArgs,
                    'error' => 'Tool call arguments in a chunk must be an undecoded JSON string, got '
                        . get_debug_type($rawArgs) . '.',
                    'id' => $id,
                    'index' => $index,
                ], static fn ($v) => $v !== null);
                continue;
            }

            if ($rawArgs === null || trim($rawArgs) === '') {
                $calls[] = array_filter([
                    'name' => $name,
                    'args' => [],
                    'id' => $id,
                    'type' => 'tool_call',
                    'index' => $index,
                ], static fn ($v) => $v !== null);
                continue;
            }

            $decoded = json_decode($rawArgs, true);
            if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                $invalid[] = array_filter([
                    'name' => $name,
                    'args' => $rawArgs,
                    'error' => 'Could not parse tool call arguments: ' . json_last_error_msg(),
                    'id' => $id,
                    'index' => $index,
                ], static fn ($v) => $v !== null);
                continue;
            }

            $calls[] = array_filter([
                'name' => $name,
                'args' => $decoded,
                'id' => $id,
                'type' => 'tool_call',
                'index' => $index,
            ], static fn ($v) => $v !== null);
        }

        return [$calls, $invalid];
    }

    /**
     * Promote a fully-folded chunk into a finished {@see AIMessage}.
     *
     * This is the last step of stream reconstruction: fold every chunk, then
     * convert.
     */
    public function toMessage(): AIMessage
    {
        [$calls, $invalid] = $this->parseToolCalls();

        return new AIMessage([
            'content' => $this->content,
            'additional_kwargs' => $this->additional_kwargs,
            'response_metadata' => $this->response_metadata,
            'tool_calls' => $calls,
            'invalid_tool_calls' => $invalid,
            'id' => $this->id,
            'name' => $this->name,
        ]);
    }
}
