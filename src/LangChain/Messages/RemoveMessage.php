<?php

declare(strict_types=1);

namespace LangChain\Messages;

/**
 * A marker that deletes the message with the same id from a message list.
 *
 * Port of `RemoveMessage` from `@langchain/core/messages/modifier`. It carries no
 * content of its own: a reducer such as `LangGraph\Graph\MessagesReducer` reads its
 * `id` and drops the matching message from the state.
 */
class RemoveMessage extends BaseMessage
{
    public string $type = BaseMessage::ROLE_REMOVE;

    /**
     * @param string|array<string, mixed> $fields A field map with a required string `id`.
     */
    public function __construct(string|array $fields = [])
    {
        $map = \is_array($fields) ? $fields : ['id' => $fields];
        if (!isset($map['id']) || !\is_string($map['id'])) {
            throw new \InvalidArgumentException('RemoveMessage requires a string id.');
        }

        parent::__construct(['id' => $map['id'], 'content' => ''] + $map);
    }

    public static function lcId(): array
    {
        return ['langchain_core', 'messages', 'RemoveMessage'];
    }

    public static function isInstance(mixed $value): bool
    {
        return $value instanceof self;
    }
}
