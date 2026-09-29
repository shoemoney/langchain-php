<?php

declare(strict_types=1);

namespace LangChain\Messages;

/**
 * A message from the model.
 *
 * Port of `AIMessage` from `@langchain/core/messages/ai`. Beyond content, an
 * AI message is the only one that can carry `toolCalls` (structured calls the
 * model wants executed) and `invalidToolCalls` (calls whose arguments failed to
 * parse — kept rather than discarded so a retry loop can inspect them).
 */
class AIMessage extends BaseMessage
{
    public string $type = BaseMessage::ROLE_AI;

    /**
     * Well-formed tool calls.
     *
     * @var list<array{id?: string, name: string, args: array<string, mixed>, type?: string, index?: int}>
     */
    public array $toolCalls = [];

    /**
     * Tool calls the model emitted but whose arguments did not parse.
     *
     * @var list<array{id?: string, name?: string, args?: string, error?: string, type?: string, index?: int}>
     */
    public array $invalidToolCalls = [];

    public function __construct(string|array $fields = [])
    {
        parent::__construct($fields);
        if (is_array($fields)) {
            if (isset($fields['tool_calls']) && is_array($fields['tool_calls'])) {
                $this->toolCalls = array_values($fields['tool_calls']);
            } elseif (isset($fields['toolCalls']) && is_array($fields['toolCalls'])) {
                $this->toolCalls = array_values($fields['toolCalls']);
            }
            if (isset($fields['invalid_tool_calls']) && is_array($fields['invalid_tool_calls'])) {
                $this->invalidToolCalls = array_values($fields['invalid_tool_calls']);
            } elseif (isset($fields['invalidToolCalls']) && is_array($fields['invalidToolCalls'])) {
                $this->invalidToolCalls = array_values($fields['invalidToolCalls']);
            }
        }

        if ($this->toolCalls !== [] || $this->invalidToolCalls !== []) {
            $this->kwargs['tool_calls'] = $this->toolCalls;
            $this->kwargs['invalid_tool_calls'] = $this->invalidToolCalls;
        }
    }

    public static function lcId(): array
    {
        return ['langchain_core', 'messages', 'AIMessage'];
    }
}
