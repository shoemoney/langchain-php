<?php

declare(strict_types=1);

namespace LangChain\Messages;

/**
 * Chunk form of a {@see FunctionMessage}.
 *
 * Upstream ships `FunctionMessageChunk` and `convertToChunk()` returns it for a
 * `function` message (messages/utils.ts:545-546). This port had no such class, so
 * `convertToChunk()`'s `default` arm relabelled every function message as an
 * `AIMessageChunk` — the speaker changed from the function's own turn to the
 * assistant's, which is exactly the kind of silent mislabel a chunk type exists to
 * prevent.
 */
class FunctionMessageChunk extends BaseMessageChunk
{
    public string $type = BaseMessage::ROLE_FUNCTION;

    public function __construct(string|array $fields = [])
    {
        parent::__construct($fields);
    }

    public static function lcId(): array
    {
        return ['langchain_core', 'messages', 'FunctionMessageChunk'];
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
