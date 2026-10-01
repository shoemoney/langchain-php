<?php

declare(strict_types=1);

namespace LangChain\Messages;

/**
 * Coercion, rendering, and (de)serialization helpers for messages.
 *
 * Port of `@langchain/core/messages/utils`. These are the functions the rest
 * of the framework calls when it accepts "message-like" input: prompts, agent
 * loops, and checkpointer replay all funnel through
 * {@see self::coerceMessageLikeToMessage()}.
 */
final class MessageUtils
{
    /** Tool-call ids longer than this are truncated in rendered output only. */
    private const TOOL_CALL_ID_DISPLAY_LIMIT = 64;

    private function __construct()
    {
    }

    /**
     * Accept any message-like value and return a real {@see BaseMessage}.
     *
     * Accepts, in order:
     *  - a string → `HumanMessage`
     *  - a `BaseMessage` → itself
     *  - a `[type, content]` pair → the matching message class
     *  - a field map with a `role` → the class matching that role
     *  - any other field map with a `type`
     */
    public static function coerceMessageLikeToMessage(mixed $messageLike): BaseMessage
    {
        if (is_string($messageLike)) {
            return new HumanMessage($messageLike);
        }
        if ($messageLike instanceof BaseMessage) {
            return $messageLike;
        }
        if (!is_array($messageLike)) {
            throw new \InvalidArgumentException('Cannot coerce value to a message: ' . get_debug_type($messageLike));
        }

        // `[type, content]` positional pair.
        if (\LangChain\Utils\Js::isList($messageLike) && count($messageLike) === 2 && is_string($messageLike[0])) {
            return self::constructFromParams(['type' => $messageLike[0], 'content' => $messageLike[1]]);
        }

        if (isset($messageLike['role']) && is_string($messageLike['role'])) {
            return self::constructFromParams($messageLike);
        }

        return self::constructFromParams($messageLike);
    }

    /**
     * Coerce a list of message-likes.
     *
     * @param iterable<mixed> $messages
     * @return list<BaseMessage>
     */
    public static function convertToMessages(iterable $messages): array
    {
        $out = [];
        foreach ($messages as $m) {
            $out[] = self::coerceMessageLikeToMessage($m);
        }

        return $out;
    }

    /**
     * Build the right message subclass for a `type`/`role` discriminator.
     *
     * @param array<string, mixed> $params
     */
    public static function constructFromParams(array $params): BaseMessage
    {
        $type = $params['type'] ?? $params['role'] ?? BaseMessage::ROLE_HUMAN;
        if (!is_string($type)) {
            $type = BaseMessage::ROLE_HUMAN;
        }

        // Normalise the provider-specific aliases onto the canonical roles.
        $type = match ($type) {
            'user' => BaseMessage::ROLE_HUMAN,
            'assistant' => BaseMessage::ROLE_AI,
            'generic' => BaseMessage::ROLE_CHAT,
            default => $type,
        };

        $body = $params;
        unset($body['type'], $body['role']);
        if ($type === BaseMessage::ROLE_CHAT) {
            $body['role'] = is_string($params['role'] ?? null) ? $params['role'] : 'chat';
        }

        return match ($type) {
            BaseMessage::ROLE_HUMAN => new HumanMessage($body),
            BaseMessage::ROLE_AI => new AIMessage($body),
            BaseMessage::ROLE_SYSTEM => new SystemMessage($body),
            BaseMessage::ROLE_TOOL => new ToolMessage($body),
            BaseMessage::ROLE_FUNCTION => new FunctionMessage($body),
            default => new ChatMessage(['role' => $type] + $body),
        };
    }

    /**
     * Render a content block to a compact string.
     *
     * Text passes through; multimodal blocks become short placeholders like
     * `[image]` so their presence is visible without inflating the token count
     * with base64 payloads.
     */
    public static function contentBlockToString(mixed $block): string
    {
        if (is_string($block)) {
            return $block;
        }
        if (!is_array($block)) {
            return '';
        }
        $type = $block['type'] ?? null;

        return match ($type) {
            'text', 'text-plain' => is_string($block['text'] ?? null)
                ? $block['text']
                : '[text-plain file]',
            'image', 'image_url' => '[image]',
            'audio', 'input_audio' => '[audio]',
            'video' => '[video]',
            'file' => '[file]',
            'reasoning', 'tool_call', 'tool_call_chunk', 'invalid_tool_call',
            'server_tool_call', 'server_tool_call_chunk',
            'server_tool_call_result', 'non_standard' => '',
            default => is_string($type) ? "[{$type}]" : '',
        };
    }

    /**
     * Render a message list as a plain-text transcript.
     *
     * ```
     * Human: What's the weather?
     * AI: Let me check...[tool_calls]
     * Tool: 72F and sunny
     * ```
     *
     * This is what memory classes read; it avoids the token inflation of
     * stringifying message objects directly.
     *
     * @param list<BaseMessage> $messages
     */
    public static function getBufferString(array $messages, string $humanPrefix = 'Human', string $aiPrefix = 'AI'): string
    {
        $lines = [];
        foreach ($messages as $m) {
            // A ChatMessage is matched BY CLASS, not by its `type`. Upstream gives
            // ChatMessage the type "generic" and reads the role off the instance
            // (`role = (m as ChatMessage).role`, utils.ts:390-391); this port sets
            // `type` to the ROLE instead, so matching on `type` against ROLE_CHAT
            // never fired and every chat message threw "Got unsupported message
            // type: user" — measured, and the role arm is now reached through the
            // class instead.
            //
            // A FunctionMessage still throws, and that is FAITHFUL: upstream has no
            // "function" arm either and throws for one.
            $role = $m instanceof ChatMessage
                ? $m->type
                : match ($m->type) {
                    BaseMessage::ROLE_HUMAN => $humanPrefix,
                    BaseMessage::ROLE_AI => $aiPrefix,
                    BaseMessage::ROLE_SYSTEM => 'System',
                    BaseMessage::ROLE_TOOL => 'Tool',
                    default => throw new \InvalidArgumentException("Got unsupported message type: {$m->type}"),
                };

            $nameStr = ($m->name ?? '') !== '' ? "{$m->name}, " : '';

            $readable = is_string($m->content)
                ? $m->content
                : implode('', array_filter(
                    array_map(self::contentBlockToString(...), $m->contentBlocks()),
                    static fn (string $s): bool => $s !== ''
                ));

            $line = "{$role}: {$nameStr}{$readable}";

            if ($m instanceof AIMessage && $m->toolCalls !== []) {
                $line .= self::renderToolCalls($m->toolCalls);
            } elseif ($m instanceof AIMessage
                && isset($m->additional_kwargs['function_call'])) {
                $line .= (string) json_encode($m->additional_kwargs['function_call']);
            }

            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @param list<array<string, mixed>> $toolCalls
     */
    private static function renderToolCalls(array $toolCalls): string
    {
        $limit = self::TOOL_CALL_ID_DISPLAY_LIMIT;
        $anyLong = false;
        foreach ($toolCalls as $call) {
            $id = $call['id'] ?? null;
            if (is_string($id) && strlen($id) > $limit) {
                $anyLong = true;
                break;
            }
        }

        $renderable = $toolCalls;
        if ($anyLong) {
            $renderable = array_map(static function (array $call) use ($limit): array {
                $id = $call['id'] ?? null;
                if (is_string($id) && strlen($id) > $limit) {
                    $call['id'] = substr($id, 0, $limit) . '...';
                }

                return $call;
            }, $toolCalls);
        }

        return (string) json_encode($renderable);
    }

    /**
     * Rehydrate a `{type, data}` payload into a message instance.
     *
     * The v1 shape (`{type, role, text}`) is accepted and upgraded, so
     * checkpoints written by older runtimes still load.
     *
     * @param array<string, mixed> $stored
     */
    public static function mapStoredMessageToChatMessage(array $stored): BaseMessage
    {
        if (!array_key_exists('data', $stored)) {
            // v1 shape
            $stored = [
                'type' => $stored['type'] ?? BaseMessage::ROLE_HUMAN,
                'data' => [
                    'content' => $stored['text'] ?? '',
                    'role' => $stored['role'] ?? null,
                    'name' => null,
                    'tool_call_id' => null,
                ],
            ];
        }

        $type = $stored['type'];
        /** @var array<string, mixed> $data */
        $data = $stored['data'];

        $params = [
            'content' => $data['content'] ?? '',
            'additional_kwargs' => $data['additional_kwargs'] ?? [],
            'response_metadata' => $data['response_metadata'] ?? [],
        ];
        if (isset($data['id'])) {
            $params['id'] = $data['id'];
        }
        if (isset($data['name']) && $data['name'] !== null) {
            $params['name'] = $data['name'];
        }
        if (isset($data['tool_call_id']) && $data['tool_call_id'] !== null) {
            $params['tool_call_id'] = $data['tool_call_id'];
        }
        if (isset($data['tool_calls'])) {
            $params['tool_calls'] = $data['tool_calls'];
        }
        if (isset($data['invalid_tool_calls'])) {
            $params['invalid_tool_calls'] = $data['invalid_tool_calls'];
        }

        return match ($type) {
            BaseMessage::ROLE_HUMAN => new HumanMessage($params),
            BaseMessage::ROLE_AI => new AIMessage($params),
            BaseMessage::ROLE_SYSTEM => new SystemMessage($params),
            BaseMessage::ROLE_TOOL => new ToolMessage($params),
            BaseMessage::ROLE_FUNCTION => new FunctionMessage($params),
            default => new ChatMessage(['role' => (string) $type] + $params),
        };
    }

    /**
     * @param list<array<string, mixed>> $stored
     * @return list<BaseMessage>
     */
    public static function mapStoredMessagesToChatMessages(array $stored): array
    {
        return array_map(self::mapStoredMessageToChatMessage(...), $stored);
    }

    /**
     * @param list<BaseMessage> $messages
     * @return list<array<string, mixed>>
     */
    public static function mapChatMessagesToStoredMessages(array $messages): array
    {
        return array_map(static fn (BaseMessage $m): array => $m->toDict(), $messages);
    }

    /**
     * Produce the streaming-chunk counterpart of a finished message.
     *
     * Converting in the other direction (chunk → message) is `concat()` plus
     * {@see AIMessageChunk::parseToolCalls()}.
     */
    public static function convertToChunk(BaseMessage $message): BaseMessageChunk
    {
        if ($message instanceof BaseMessageChunk) {
            return $message;
        }

        $base = [
            'content' => $message->content,
            'additional_kwargs' => $message->additional_kwargs,
            'response_metadata' => $message->response_metadata,
        ];
        if ($message->id !== null) {
            $base['id'] = $message->id;
        }
        if ($message->name !== null) {
            $base['name'] = $message->name;
        }

        if ($message instanceof ToolMessage) {
            $base['tool_call_id'] = $message->toolCallId;

            // The artifact is the tool's structured payload, distinct from `content`. The chunk
            // class stores and merges it, but without this nothing ever put it IN the chunk — the half of
            // the fix that lives in a different file from the field it feeds.
            if ($message->artifact !== null) {
                $base['artifact'] = $message->artifact;
            }

            return new ToolMessageChunk($base);
        }

        $chunks = [];
        if ($message instanceof AIMessage) {
            foreach ($message->toolCalls as $i => $call) {
                $chunks[] = array_filter([
                    'index' => $call['index'] ?? $i,
                    'id' => $call['id'] ?? null,
                    'name' => $call['name'] ?? null,
                    // An EMPTY args object must encode as `{}`, not `[]`. In JSON a
                    // call with no arguments carries an empty OBJECT, and `json_encode([])`
                    // emits an empty ARRAY — the same defect class that broke round-tripping
                    // in an earlier release, one layer down: the value is not lost, it is
                    // the wrong shape, so a consumer decoding `args` gets a list where it
                    // expects a map and every key lookup misses.
                    'args' => ($call['args'] ?? []) === []
                        ? '{}'
                        : json_encode($call['args'], JSON_UNESCAPED_SLASHES),
                ], static fn ($v) => $v !== null);
            }
        }
        if ($chunks !== []) {
            $base['tool_call_chunks'] = $chunks;
        }

        // Upstream branches on the CLASS for chat messages, not the role:
        // `ChatMessage.isInstance(message)` (messages/utils.ts:547). A ChatMessage's
        // `type` IS its role ('user', 'assistant', ...), so a role-keyed match can
        // never reach it — which is exactly how it fell into the `default` arm and
        // came out an AIMessageChunk.
        if ($message instanceof ChatMessage) {
            // The role lives in `type`, and `$base` carries content, kwargs,
            // metadata, id and name but not type — so without this the chunk comes
            // back a generic 'chat' and loses the speaker.
            $base['role'] = $message->type;

            return new ChatMessageChunk($base);
        }

        return match ($message->type) {
            BaseMessage::ROLE_HUMAN => new HumanMessageChunk($base),
            BaseMessage::ROLE_SYSTEM => new SystemMessageChunk($base),
            BaseMessage::ROLE_AI => new AIMessageChunk($base),
            BaseMessage::ROLE_FUNCTION => new FunctionMessageChunk($base),
            // Upstream throws `new Error("Unknown message type.")` rather than
            // relabelling. Silently calling an unknown turn an assistant turn is
            // how a message changes speaker with nothing reporting it.
            default => throw new \InvalidArgumentException(
                'Cannot convert a ' . $message->type . ' message to a chunk.'
            ),
        };
    }
}
