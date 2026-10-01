<?php

declare(strict_types=1);

namespace LangChain\Messages;

/**
 * A chunk of a system message.
 *
 * Port of `SystemMessageChunk` from `@langchain/core/messages/system`.
 */
class SystemMessageChunk extends BaseMessageChunk
{
    public string $type = BaseMessage::ROLE_SYSTEM;

    public function __construct(string|array $fields = [])
    {
        parent::__construct($fields);
    }

    public static function lcId(): array
    {
        return ['langchain_core', 'messages', 'SystemMessageChunk'];
    }

    public function concat(BaseMessageChunk $other): BaseMessageChunk
    {
        $this->assertSameChunkClass($other, self::class);

        return new self([
            'content' => MessageMerge::mergeContent($this->content, $other->content),
            'additional_kwargs' => MessageMerge::mergeDicts($this->additional_kwargs, $other->additional_kwargs) ?? [],
            'response_metadata' => MessageMerge::mergeDicts($this->response_metadata, $other->response_metadata) ?? [],
            'id' => $this->id ?? $other->id,
            // Upstream `system.ts:63`: `name: this.name ?? chunk.name` — the accumulated chunk wins.
            'name' => $this->name ?? $other->name,
        ]);
    }
}
