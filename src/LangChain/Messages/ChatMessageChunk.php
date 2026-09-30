<?php

declare(strict_types=1);

namespace LangChain\Messages;

/**
 * Chunk form of a {@see ChatMessage}.
 *
 * Upstream returns `new ChatMessageChunk({...message})` for a ChatMessage
 * (messages/utils.ts:547-548). This port had no such class, so `convertToChunk()`'s
 * `default` arm relabelled a chat-role turn as an `AIMessageChunk`.
 *
 * The `$type` default mirrors {@see ChatMessage} rather than being hard-wired: a
 * ChatMessage's `type` IS its role, and the constructor below reproduces the same
 * promotion from `$fields['role']` so a chunk carries the same role its message had.
 */
class ChatMessageChunk extends BaseMessageChunk
{
    public string $type = BaseMessage::ROLE_CHAT;

    public function __construct(string|array $fields = [])
    {
        if (is_string($fields)) {
            $fields = ['role' => 'chat', 'content' => $fields];
        }
        if (isset($fields['role']) && is_string($fields['role']) && $fields['role'] !== '') {
            $this->type = $fields['role'];
        }
        parent::__construct($fields);
    }

    public static function lcId(): array
    {
        return ['langchain_core', 'messages', 'ChatMessageChunk'];
    }

    public function concat(BaseMessageChunk $other): BaseMessageChunk
    {
        $this->assertSameChunkClass($other, self::class);

        return new self([
            'content' => MessageMerge::mergeContent($this->content, $other->content),
            'additional_kwargs' => MessageMerge::mergeDicts($this->additional_kwargs, $other->additional_kwargs) ?? [],
            'response_metadata' => MessageMerge::mergeDicts($this->response_metadata, $other->response_metadata) ?? [],
            'id' => $other->id ?? $this->id,
            'name' => $other->name ?? $this->name,
        ]);
    }
}
