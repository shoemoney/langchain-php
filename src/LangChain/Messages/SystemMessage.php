<?php

declare(strict_types=1);

namespace LangChain\Messages;

/**
 * A message that configures the model's behaviour.
 *
 * Port of `SystemMessage` from `@langchain/core/messages/system`.
 */
class SystemMessage extends BaseMessage
{
    public string $type = BaseMessage::ROLE_SYSTEM;

    public function __construct(string|array $fields = [])
    {
        parent::__construct($fields);
    }

    public static function lcId(): array
    {
        return ['langchain_core', 'messages', 'SystemMessage'];
    }
}
