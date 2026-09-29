<?php

declare(strict_types=1);

namespace LangChain\Messages;

/**
 * A chunk of a tool result, produced while a tool streams its output.
 *
 * Port of `ToolMessageChunk` from `@langchain/core/messages/tool`.
 */
class ToolMessageChunk extends ToolMessage
{
    public string $type = BaseMessage::ROLE_TOOL;

    public static function lcId(): array
    {
        return ['langchain_core', 'messages', 'ToolMessageChunk'];
    }

    public function concat(BaseMessageChunk $other): BaseMessageChunk
    {
        $this->assertSameChunkClass($other, self::class);

        return new self([
            'content' => MessageMerge::mergeContent($this->content, $other->content),
            'additional_kwargs' => MessageMerge::mergeDicts($this->additional_kwargs, $other->additional_kwargs) ?? [],
            'response_metadata' => MessageMerge::mergeDicts($this->response_metadata, $other->response_metadata) ?? [],
            'tool_call_id' => $other->toolCallId !== '' ? $other->toolCallId : $this->toolCallId,
            'name' => $other->name ?? $this->name,
            'id' => $other->id ?? $this->id,
        ]);
    }
}
