<?php

declare(strict_types=1);

namespace LangChain\Messages;

/**
 * A message from a human.
 *
 * Port of `HumanMessage` from `@langchain/core/messages/human`.
 */
class HumanMessage extends BaseMessage
{
    public string $type = BaseMessage::ROLE_HUMAN;

    public function __construct(string|array $fields = [])
    {
        parent::__construct($fields);
    }

    public static function lcId(): array
    {
        return ['langchain_core', 'messages', 'HumanMessage'];
    }
}
