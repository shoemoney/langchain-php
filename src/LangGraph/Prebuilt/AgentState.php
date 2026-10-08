<?php

declare(strict_types=1);

namespace LangGraph\Prebuilt;

use LangChain\Messages\BaseMessage;
use LangGraph\Graph\MessagesReducer;
use LangGraph\State\Annotation;
use LangGraph\State\AnnotationRoot;

/**
 * The state a {@see ReactAgent} carries.
 *
 * Port of the `AgentState` interface and `createReactAgentAnnotation` from
 * `langgraph-core/src/prebuilt/react_agent_executor.ts`. Upstream's interface is a pair of fields,
 * `messages: BaseMessage[]` and `structuredResponse`; PHP state is a plain array of that shape, and this
 * class declares the channels that carry it.
 *
 * `messages` accumulates through {@see MessagesReducer::messagesStateReducer()} (default `[]`).
 * `structuredResponse` is a plain last-value channel, written only when the agent was given a
 * `responseFormat`.
 *
 * @phpstan-type AgentStateShape array{messages: list<BaseMessage>, structuredResponse?: array<string, mixed>}
 */
final class AgentState
{
    /** The channel a pre-model hook writes to override what the model sees. */
    public const LLM_INPUT_MESSAGES = 'llmInputMessages';

    private function __construct()
    {
    }

    /** Port of `createReactAgentAnnotation`. */
    public static function annotation(): AnnotationRoot
    {
        return Annotation::root([
            'messages' => Annotation::withReducer(MessagesReducer::messagesStateReducer(...), static fn (): array => []),
            'structuredResponse' => Annotation::last(),
        ]);
    }

    /**
     * Port of `PreHookAnnotation`: the extra input channel the `agent` node reads when a `preModelHook` is present.
     *
     * Every write REPLACES the channel (`messagesStateReducer([], update)`), so a stale list never leaks from one
     * model call into the next, and a bare string becomes a one-message list.
     */
    public static function preHookAnnotation(): AnnotationRoot
    {
        return Annotation::root([
            self::LLM_INPUT_MESSAGES => Annotation::withReducer(
                static fn (mixed $left, mixed $update): array => MessagesReducer::messagesStateReducer([], $update),
                static fn (): array => [],
            ),
        ]);
    }
}
