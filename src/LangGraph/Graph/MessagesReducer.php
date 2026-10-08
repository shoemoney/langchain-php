<?php

declare(strict_types=1);

namespace LangGraph\Graph;

use LangChain\Messages\BaseMessage;
use LangChain\Messages\MessageUtils;
use LangChain\Messages\RemoveMessage;
use Ramsey\Uuid\Uuid;

/**
 * The reducers behind a `messages` state key.
 *
 * Port of `messagesStateReducer`, `messagesDeltaReducer` and `REMOVE_ALL_MESSAGES`
 * from `langgraph-core/src/graph/messages_reducer.ts`.
 *
 * A `Messages` input is a message, a message-like (string, `[type, content]` pair,
 * or field map) or a LIST of any of those. As upstream does with `Array.isArray`, a
 * PHP list is always a list of messages and a field map is always one message.
 */
final class MessagesReducer
{
    /**
     * An id that, on a {@see RemoveMessage}, discards every message before it.
     */
    public const REMOVE_ALL_MESSAGES = '__remove_all__';

    private function __construct()
    {
    }

    /**
     * Merge two sets of messages, keyed by message id.
     *
     * Port of `messagesStateReducer`. Missing ids are assigned (a v4 UUID, written
     * to the message so it survives serialisation). A message whose id already
     * exists REPLACES the earlier one in place; a {@see RemoveMessage} marks that id
     * for deletion; one whose id does not exist is an error. A remove-all marker
     * anywhere in `$right` returns only what follows the last such marker.
     *
     * @param  BaseMessage|array<mixed>|string $left  The existing messages.
     * @param  BaseMessage|array<mixed>|string $right The messages to apply.
     * @return list<BaseMessage>
     *
     * @throws \InvalidArgumentException when a RemoveMessage names an id that is not present
     */
    public static function messagesStateReducer(mixed $left, mixed $right): array
    {
        $leftMessages = array_map(
            MessageUtils::coerceMessageLikeToMessage(...),
            self::asList($left),
        );
        $rightMessages = array_map(
            MessageUtils::coerceMessageLikeToMessage(...),
            self::asList($right),
        );

        foreach ($leftMessages as $message) {
            if ($message->id === null) {
                $message->updateId(Uuid::uuid4()->toString());
            }
        }

        $removeAllIdx = null;
        foreach ($rightMessages as $i => $message) {
            if ($message->id === null) {
                $message->updateId(Uuid::uuid4()->toString());
            }

            if (RemoveMessage::isInstance($message) && $message->id === self::REMOVE_ALL_MESSAGES) {
                $removeAllIdx = $i;
            }
        }

        if ($removeAllIdx !== null) {
            return \array_slice($rightMessages, $removeAllIdx + 1);
        }

        $merged = $leftMessages;
        $mergedById = [];
        foreach ($merged as $i => $message) {
            $mergedById[$message->id] = $i;
        }
        $idsToRemove = [];

        foreach ($rightMessages as $message) {
            $existingIdx = $mergedById[$message->id] ?? null;
            if ($existingIdx !== null) {
                if (RemoveMessage::isInstance($message)) {
                    $idsToRemove[$message->id] = true;
                } else {
                    unset($idsToRemove[$message->id]);
                    $merged[$existingIdx] = $message;
                }
            } else {
                if (RemoveMessage::isInstance($message)) {
                    throw new \InvalidArgumentException(
                        "Attempting to delete a message with an ID that doesn't exist ('{$message->id}')"
                    );
                }
                $mergedById[$message->id] = \count($merged);
                $merged[] = $message;
            }
        }

        return array_values(array_filter(
            $merged,
            static fn (BaseMessage $m): bool => !isset($idsToRemove[$m->id]),
        ));
    }

    /**
     * Fold a whole batch of writes in one pass.
     *
     * Port of `messagesDeltaReducer` (experimental upstream): the batching-invariant
     * reducer a `DeltaChannel` needs, `reducer(reducer(state, xs), ys) ==
     * reducer(state, xs ++ ys)`. Unlike {@see self::messagesStateReducer()} it does
     * not assign missing ids and does not reject a RemoveMessage for an unknown id.
     *
     * @param  list<mixed>                      $state  The messages accumulated so far.
     * @param  list<BaseMessage|array<mixed>|string> $writes Each write is one message-like or a list of them.
     * @return list<BaseMessage>
     */
    public static function messagesDeltaReducer(array $state, array $writes): array
    {
        $flat = [];
        foreach ($writes as $write) {
            foreach (self::asList($write) as $item) {
                $flat[] = $item;
            }
        }

        $stateMessages = ($state !== [] && $state[0] instanceof BaseMessage)
            ? $state
            : array_map(MessageUtils::coerceMessageLikeToMessage(...), $state);
        $messages = array_map(MessageUtils::coerceMessageLikeToMessage(...), $flat);

        /** @var array<string, int> $index */
        $index = [];
        foreach ($stateMessages as $i => $message) {
            if ($message->id !== null) {
                $index[$message->id] = $i;
            }
        }

        /** @var array<int, BaseMessage|null> $result */
        $result = array_values($stateMessages);
        foreach ($messages as $message) {
            $mid = $message->id;
            if (RemoveMessage::isInstance($message) && $mid === self::REMOVE_ALL_MESSAGES) {
                $result = [];
                $index = [];
            } elseif ($mid === null) {
                $result[] = $message;
            } elseif (RemoveMessage::isInstance($message)) {
                if (isset($index[$mid])) {
                    $result[$index[$mid]] = null;
                    unset($index[$mid]);
                }
            } elseif (isset($index[$mid])) {
                $result[$index[$mid]] = $message;
            } else {
                $index[$mid] = \count($result);
                $result[] = $message;
            }
        }

        return array_values(array_filter($result, static fn (?BaseMessage $m): bool => $m !== null));
    }

    /**
     * `Array.isArray(x) ? x : [x]`: a list stays a list, anything else is one item.
     *
     * @return list<mixed>
     */
    private static function asList(mixed $value): array
    {
        return \is_array($value) && array_is_list($value) ? $value : [$value];
    }
}
