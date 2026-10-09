<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware;

use LangChain\Messages\BaseMessage;
use LangChain\Messages\RemoveMessage;
use LangChain\Messages\SystemMessage;
use LangGraph\Agents\Middleware;
use LangGraph\Pregel\Command;

/**
 * Context editing middleware: prunes the messages of a model request when the context grows too large.
 *
 * Port of `contextEditingMiddleware` from `langchain/src/agents/middleware/contextEditing.ts`.
 *
 * Every model call runs the configured {@see ContextEdit}s in sequence over the request messages. The default
 * edit is {@see ClearToolUsesEdit}: it clears the results of older tool calls once the context passes 100,000
 * tokens, keeping the three most recent.
 *
 * Config:
 *
 *  - `edits`: the {@see ContextEdit}s to apply, in order (default one {@see ClearToolUsesEdit});
 *  - `tokenCountMethod`: `approx` (default; {@see Utils::countTokensApproximately()}) or `model` (the chat
 *    model's `getNumTokensFromMessages()`, plus the system prompt; throws when the model has none).
 *
 * ```
 * $middleware = ContextEditingMiddleware::create([
 *     'edits' => [new ClearToolUsesEdit(['trigger' => ['tokens' => 50000], 'keep' => ['messages' => 5]])],
 * ]);
 * ```
 *
 * Upstream edits `request.messages` in place, and that array is the thread's own message list, so the edit
 * outlives the call. PHP arrays are values, so the same effect is a state update: the edited messages replace
 * theirs by id and the removed ones go through `RemoveMessage`, alongside the model's reply. An edit that
 * changes nothing leaves the reply untouched.
 */
final class ContextEditingMiddleware
{
    private function __construct()
    {
    }

    /**
     * @param array{edits?: list<ContextEdit>, tokenCountMethod?: 'approx'|'model'} $config
     * @return array<string, mixed> the middleware
     */
    public static function create(array $config = []): array
    {
        $edits = $config['edits'] ?? [new ClearToolUsesEdit()];
        $tokenCountMethod = $config['tokenCountMethod'] ?? 'approx';

        return Middleware::create([
            'name' => 'ContextEditingMiddleware',
            'wrapModelCall' => static function (array $request, callable $handler) use ($edits, $tokenCountMethod): mixed {
                $original = array_values((array) ($request['messages'] ?? []));
                if ($original === []) {
                    return $handler($request);
                }

                $model = \is_object($request['model'] ?? null) ? $request['model'] : null;
                $systemPrompt = $request['systemPrompt'] ?? null;
                $systemMsg = \is_string($systemPrompt) && $systemPrompt !== '' ? [new SystemMessage($systemPrompt)] : [];

                $countTokens = $tokenCountMethod === 'approx'
                    ? Utils::countTokensApproximately(...)
                    : static fn (array $messages): int|float => self::countWithModel($model, [...$systemMsg, ...$messages]);

                // Apply each edit in sequence.
                $messages = $original;
                foreach ($edits as $edit) {
                    $edit->apply($messages, $countTokens, $model);
                }

                $response = $handler([...$request, 'messages' => $messages]);

                $update = self::stateUpdate($original, $messages);
                if ($update === [] || !$response instanceof BaseMessage) {
                    return $response;
                }

                return new Command(update: ['messages' => [...$update, $response]]);
            },
        ]);
    }

    /**
     * The model's own count: only models with `getNumTokensFromMessages()` (OpenAI) can.
     *
     * @param list<BaseMessage> $messages
     */
    private static function countWithModel(?object $model, array $messages): int|float
    {
        if ($model !== null && method_exists($model, 'getNumTokensFromMessages')) {
            $counted = $model->getNumTokensFromMessages($messages);

            return \is_array($counted) ? $counted['totalCount'] : $counted;
        }

        $name = $model !== null && method_exists($model, 'getName') ? $model->getName() : get_debug_type($model);

        throw new \Exception("Model \"{$name}\" does not support token counting");
    }

    /**
     * The state update that writes an edit back to the thread: removals, then replacements (same id).
     *
     * @param list<BaseMessage> $original
     * @param list<BaseMessage> $edited
     * @return list<BaseMessage>
     */
    private static function stateUpdate(array $original, array $edited): array
    {
        $originalObjects = [];
        $originalIds = [];
        foreach ($original as $message) {
            $originalObjects[spl_object_id($message)] = true;
            if ($message->id !== null) {
                $originalIds[$message->id] = true;
            }
        }

        $editedObjects = [];
        $editedIds = [];
        foreach ($edited as $message) {
            $editedObjects[spl_object_id($message)] = true;
            if ($message->id !== null) {
                $editedIds[$message->id] = true;
            }
        }

        $removals = [];
        foreach ($original as $message) {
            if ($message->id !== null && !isset($editedObjects[spl_object_id($message)]) && !isset($editedIds[$message->id])) {
                $removals[] = new RemoveMessage(['id' => $message->id]);
            }
        }

        $replacements = [];
        foreach ($edited as $message) {
            if ($message->id !== null && !isset($originalObjects[spl_object_id($message)]) && isset($originalIds[$message->id])) {
                $replacements[] = $message;
            }
        }

        return [...$removals, ...$replacements];
    }
}
