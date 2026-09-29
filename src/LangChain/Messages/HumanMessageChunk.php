<?php

declare(strict_types=1);

namespace LangChain\Messages;

/**
 * A chunk of a human message.
 *
 * Port of `HumanMessageChunk` from `@langchain/core/messages/human`.
 */
class HumanMessageChunk extends BaseMessageChunk
{
    public string $type = BaseMessage::ROLE_HUMAN;

    public function __construct(string|array $fields = [])
    {
        parent::__construct($fields);
    }

    public static function lcId(): array
    {
        return ['langchain_core', 'messages', 'HumanMessageChunk'];
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
