<?php

declare(strict_types=1);

namespace LangChain\Messages;

/**
 * The result of executing a tool, fed back to the model.
 *
 * Port of `ToolMessage` from `@langchain/core/messages/tool`. `toolCallId` is
 * what lets the model correlate the result with the call it made; omitting it
 * makes a tool-using loop impossible to stitch back together.
 */
class ToolMessage extends BaseMessage
{
    public string $type = BaseMessage::ROLE_TOOL;

    public string $toolCallId = '';

    /** The tool that produced this result, when known. */
    public ?string $toolName = null;

    /** The raw tool output, preserved in storage but not sent to the model. */
    public mixed $artifact = null;

    public function __construct(string|array $fields = [])
    {
        parent::__construct($fields);
        if (is_array($fields)) {
            $id = $fields['tool_call_id'] ?? $fields['toolCallId'] ?? '';
            $this->toolCallId = is_string($id) ? $id : '';
            $name = $fields['tool_name'] ?? $fields['toolName'] ?? null;
            $this->toolName = is_string($name) ? $name : null;
            $this->artifact = $fields['artifact'] ?? null;
            if (array_key_exists('artifact', $fields)) {
                $this->kwargs['artifact'] = $this->artifact;
            }
        }

        $this->kwargs['tool_call_id'] = $this->toolCallId;
        if ($this->toolName !== null) {
            $this->kwargs['tool_name'] = $this->toolName;
        }
    }

    public function kwargs(): array
    {
        $kwargs = parent::kwargs();
        // Upstream toJSON reads current properties for originally supplied constructor keys.
        if (array_key_exists('artifact', $kwargs)) {
            $kwargs['artifact'] = $this->artifact;
        }

        return $kwargs;
    }

    public static function lcId(): array
    {
        return ['langchain_core', 'messages', 'ToolMessage'];
    }
}
