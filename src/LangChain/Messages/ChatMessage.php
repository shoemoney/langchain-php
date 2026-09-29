<?php

declare(strict_types=1);

namespace LangChain\Messages;

/**
 * A message with a caller-supplied role.
 *
 * Port of `ChatMessage` from `@langchain_core/messages/chat`. Used when a
 * provider or a proxy needs a role the standard set does not cover.
 */
class ChatMessage extends BaseMessage
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
        return ['langchain_core', 'messages', 'ChatMessage'];
    }
}
