<?php

declare(strict_types=1);

namespace LangGraph\Graph;

use LangChain\Messages\BaseMessage;
use LangChain\Messages\MessageUtils;
use LangChain\Runnables\RunnableConfig;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\PregelScratchpad;

/**
 * Push a message into the state from the middle of a node.
 *
 * Port of `pushMessage` from `langgraph-core/src/graph/message.ts`.
 *
 * The write goes through the task's `__pregel_send`, so the message is persisted to
 * the state when the node finishes exactly as if the node had returned it.
 *
 * NOT PORTED: the live emission to a `StreamMessagesHandler` /
 * `StreamProtocolMessagesHandler` callback, which is the other half of the upstream
 * function. This port has no `messages` stream mode yet, so there is no handler to
 * find in the config's callbacks.
 */
final class PushMessage
{
    private function __construct()
    {
    }

    /**
     * @param  BaseMessage|array<mixed>|string $message  A message, or anything coercible to one.
     * @param  RunnableConfig|null             $config   The node's config; defaults to the task currently running.
     * @param  string|null                     $stateKey The state key to push to, or null to emit without persisting.
     *
     * @throws \InvalidArgumentException when the message has no id
     */
    public static function pushMessage(
        mixed $message,
        ?RunnableConfig $config = null,
        ?string $stateKey = 'messages',
    ): BaseMessage {
        $config ??= PregelScratchpad::currentConfig() ?? new RunnableConfig();

        $validMessage = MessageUtils::coerceMessageLikeToMessage($message);
        if ($validMessage->id === null || $validMessage->id === '') {
            throw new \InvalidArgumentException('Message ID is required.');
        }

        if ($stateKey !== null) {
            $send = $config->configurable[Constants::CONFIG_KEY_SEND] ?? null;
            if (\is_callable($send)) {
                $send([[$stateKey, $validMessage]]);
            }
        }

        return $validMessage;
    }
}
