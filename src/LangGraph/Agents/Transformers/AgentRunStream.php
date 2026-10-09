<?php

declare(strict_types=1);

namespace LangGraph\Agents\Transformers;

use LangGraph\Stream\ReplayableIterable;
use LangGraph\Stream\RunStream;

/**
 * The run stream of `ReactAgent::streamEvents(..., 'v3')`.
 *
 * Counterpart of upstream's `AgentRunStream` (`transformers/types.ts`): a {@see RunStream} with the native
 * agent-level projections assigned on the instance. Upstream has no runtime class for it (a type overlay); here
 * it is a subclass, so `messages()` and `messagesFrom()` can hand out {@see AgentMessageStream}s, which read
 * like messages (`->content`) as well as like chat-model streams.
 *
 * @property-read \LangGraph\Stream\StreamChannel $toolCalls tool call streams from the {@see ToolCallTransformer}
 * @property-read \LangGraph\Stream\StreamChannel $subagents named subagent runs from the {@see SubagentTransformer}
 */
final class AgentRunStream extends RunStream
{
    /**
     * @return \IteratorAggregate<int, AgentMessageStream>
     */
    public function messages(): \IteratorAggregate
    {
        return self::wrapMessages(parent::messages());
    }

    /**
     * @return \IteratorAggregate<int, AgentMessageStream>
     */
    public function messagesFrom(string $node): \IteratorAggregate
    {
        return self::wrapMessages(parent::messagesFrom($node));
    }

    /**
     * @param \IteratorAggregate<int, \LangGraph\Stream\ChatModelStream> $messages
     * @return \IteratorAggregate<int, AgentMessageStream>
     */
    private static function wrapMessages(\IteratorAggregate $messages): \IteratorAggregate
    {
        return new ReplayableIterable(static function () use ($messages): \Generator {
            foreach ($messages as $message) {
                yield new AgentMessageStream($message);
            }
        });
    }
}
