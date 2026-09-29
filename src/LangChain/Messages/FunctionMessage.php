<?php

declare(strict_types=1);

namespace LangChain\Messages;

/**
 * A message from a model that is neither a chat participant nor a tool.
 *
 * Port of `FunctionMessage` from `@langchain/core/messages/function`. Kept for
 * parity with the wire format; new code should prefer {@see ToolMessage}.
 */
class FunctionMessage extends BaseMessage
{
    public string $type = BaseMessage::ROLE_FUNCTION;

    public string $name = '';

    public function __construct(string|array $fields = [])
    {
        parent::__construct($fields);
        if (is_array($fields) && isset($fields['name']) && is_string($fields['name'])) {
            $this->name = $fields['name'];
        }
    }

    public static function lcId(): array
    {
        return ['langchain_core', 'messages', 'FunctionMessage'];
    }
}
