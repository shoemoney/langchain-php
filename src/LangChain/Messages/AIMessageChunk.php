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
     * Tool-call deltas, folded by index or id. Each `args` is an undecoded
     * string: JSON for function tools, raw input text for custom tools.
     *
     * @var list<array{index?: int, id?: string, name?: string, args?: string, type?: string, isCustomTool?: bool}>
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
            'id' => $this->id ?? $other->id,
            'name' => $other->name ?? $this->name,
        ]);
    }

    /**
     * Decode the accumulated `tool_call_chunks` into real tool calls.
     *
     * Function arguments that do not parse as JSON become invalid calls.
     * Custom tools preserve their raw arguments in `args.input`, including
     * whitespace and text that happens to be valid JSON.
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

            // A chunk's `args` is an undecoded string by construction — that is
            // what lets deltas be accumulated as strings. An already-decoded
            // array is therefore not a shape this method can re-parse, and
            // calling trim() on it raised a TypeError that aborted stream
            // reconstruction outright — a crash, not a bad tool call.
            //
            // It is routed to the invalid list instead, which is the existing
            // destination for arguments that cannot form a valid tool call.
            // A caller that genuinely holds decoded arguments should
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

            // ai.ts delegates to messages/utils.ts::collapseToolCallChunks:
            // custom chunks with a call id use { input: joinedArgs } rather
            // than JSON parsing or trimming their accumulated input string.
            if (($chunk['isCustomTool'] ?? false) === true) {
                if ($id === null || $id === '') {
                    $invalid[] = array_filter([
                        'name' => $name,
                        'args' => $rawArgs,
                        'error' => 'Custom tool call is missing an id',
                        'index' => $index,
                    ], static fn ($v) => $v !== null);
                    continue;
                }

                $calls[] = array_filter([
                    'name' => $name,
                    'args' => ['input' => $rawArgs ?? ''],
                    'id' => $id,
                    'type' => 'tool_call',
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
