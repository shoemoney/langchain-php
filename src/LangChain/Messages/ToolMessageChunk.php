<?php

declare(strict_types=1);

namespace LangChain\Messages;

/**
 * A chunk of a tool result, produced while a tool streams its output.
 *
 * Port of `ToolMessageChunk` from `@langchain_core/messages/tool`.
 *
 * Like `AIMessageChunk`, this extends `BaseMessageChunk` rather than
 * {@see ToolMessage} — in the TypeScript original the chunk and the message
 * are siblings, and the chunk branch is what makes it foldable. The tool-call
 * correlation fields are therefore carried here directly.
 */
class ToolMessageChunk extends BaseMessageChunk
{
    public string $type = BaseMessage::ROLE_TOOL;

    public string $toolCallId = '';

    public ?string $toolName = null;

    public function __construct(string|array $fields = [])
    {
        parent::__construct($fields);
        if (is_array($fields)) {
            $id = $fields['tool_call_id'] ?? $fields['toolCallId'] ?? '';
            $this->toolCallId = is_string($id) ? $id : '';
            $name = $fields['tool_name'] ?? $fields['toolName'] ?? null;
            $this->toolName = is_string($name) ? $name : null;
        }

        $this->kwargs['tool_call_id'] = $this->toolCallId;
    }

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
