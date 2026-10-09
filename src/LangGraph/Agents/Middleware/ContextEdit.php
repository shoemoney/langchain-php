<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware;

use LangChain\Messages\BaseMessage;

/**
 * A strategy that edits the messages of a model request to manage context size.
 *
 * Port of the `ContextEdit` interface of `langchain/src/agents/middleware/contextEditing.ts`.
 *
 * Upstream's `apply({messages, countTokens, model})` mutates `messages` in place; PHP arrays are values, so
 * the list is taken by reference and edited in place the same way.
 */
interface ContextEdit
{
    /**
     * @param list<BaseMessage>                    $messages    the request messages, edited in place
     * @param callable(list<BaseMessage>): (int|float) $countTokens
     * @param object|null                          $model       the chat model the request goes to
     */
    public function apply(array &$messages, callable $countTokens, ?object $model = null): void;
}
