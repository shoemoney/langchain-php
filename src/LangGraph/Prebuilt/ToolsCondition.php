<?php

declare(strict_types=1);

namespace LangGraph\Prebuilt;

use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\BaseMessage;
use LangGraph\Pregel\Constants;

/**
 * A conditional edge that routes to a tools node while the last message asks for tools.
 *
 * Port of `toolsCondition` from `langgraph-core/src/prebuilt/tool_node.ts`.
 *
 * Accepts either a bare message list or a `['messages' => [...]]` state, looks at the LAST message,
 * and answers `"tools"` when it carries at least one tool call and {@see Constants::END} otherwise.
 * Use it as `addConditionalEdges('agent', ToolsCondition::toolsCondition(...), ['tools', END])`;
 * an instance is callable too.
 */
final class ToolsCondition
{
    /**
     * @param list<BaseMessage>|array{messages: list<BaseMessage>} $state
     */
    public static function toolsCondition(array $state): string
    {
        $messages = array_is_list($state) ? $state : (array) ($state['messages'] ?? []);
        $message = $messages === [] ? null : $messages[array_key_last($messages)];

        $toolCalls = match (true) {
            $message instanceof AIMessage => $message->toolCalls,
            $message instanceof AIMessageChunk => $message->toMessage()->toolCalls,
            default => [],
        };

        return $toolCalls !== [] ? 'tools' : Constants::END;
    }

    /**
     * @param list<BaseMessage>|array{messages: list<BaseMessage>} $state
     */
    public function __invoke(array $state): string
    {
        return self::toolsCondition($state);
    }
}
