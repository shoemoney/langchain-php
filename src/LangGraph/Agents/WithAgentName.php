<?php

declare(strict_types=1);

namespace LangGraph\Agents;

use LangChain\Messages\BaseMessage;
use LangChain\Runnables\RunnableInterface;
use LangChain\Runnables\RunnableLambda;
use LangChain\Runnables\RunnableSequence;

/**
 * Attach formatted agent names to the messages passed to and from a language model.
 *
 * Port of `withAgentName` from `langchain/src/agents/withAgentName.ts`. This is the agents variant
 * (the `<name>`/`<content>` tag logic lives in {@see Utils}); `LangGraph\Prebuilt\AgentName` is the
 * `langgraph-core` one and differs on streamed chunks.
 *
 * Useful for making a message history with multiple agents more coherent. The agent name is consumed
 * from the message `name` field.
 */
final class WithAgentName
{
    public const MODE_INLINE = Utils::AGENT_NAME_MODE_INLINE;

    private function __construct()
    {
    }

    /**
     * @param string $agentNameMode How to expose the agent name to the LLM. `"inline"` adds it directly
     *                              into the content of the AI message with XML-style tags:
     *                              "How can I help you" becomes
     *                              "<name>agent_name</name><content>How can I help you?</content>".
     */
    public static function withAgentName(RunnableInterface $model, string $agentNameMode): RunnableInterface
    {
        if ($agentNameMode !== self::MODE_INLINE) {
            throw new \Exception(
                sprintf('Invalid agent name mode: %s. Needs to be one of: "inline"', $agentNameMode),
            );
        }

        return RunnableSequence::from([
            RunnableLambda::from(static fn (mixed $messages): array => array_map(
                static fn (mixed $message): mixed => Utils::addInlineAgentName($message),
                \is_array($messages) ? $messages : [$messages],
            )),
            $model,
            RunnableLambda::from(static fn (mixed $message): mixed => $message instanceof BaseMessage
                ? Utils::removeInlineAgentName($message)
                : $message),
        ]);
    }
}
