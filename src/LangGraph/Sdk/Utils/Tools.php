<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Utils;

/**
 * Port of `utils/tools.ts`.
 *
 * Messages are the wire-shaped arrays the SDK clients return (`type`, `content`, `tool_calls`,
 * `tool_call_id`, `id`, `status`), not LangChain message objects: the SDK deals in what the server
 * sent, and `types.messages.ts` is a set of JSON shapes.
 */
final class Tools
{
    /**
     * Pair every AI tool call with its tool result and a lifecycle state.
     *
     * A call with no result is `pending` unless a LATER AI message exists, which implies the tools
     * finished (tools returning a `Command` embed their ToolMessage in a state update rather than
     * streaming it). `result` is null when there is none.
     *
     * @param list<array<string, mixed>> $messages
     *
     * @return list<array{id: string, call: array<string, mixed>, result: array<string, mixed>|null, aiMessage: array<string, mixed>, index: int, state: string}>
     */
    public static function getToolCallsWithResults(array $messages): array
    {
        $results = [];

        $toolResultsById = [];
        foreach ($messages as $message) {
            if (($message['type'] ?? null) === 'tool') {
                $toolResultsById[(string) $message['tool_call_id']] = $message;
            }
        }

        $count = count($messages);
        for ($msgIdx = 0; $msgIdx < $count; $msgIdx++) {
            $message = $messages[$msgIdx];
            if (($message['type'] ?? null) !== 'ai' || empty($message['tool_calls'])) {
                continue;
            }

            $impliedCompleted = false;
            for ($j = $msgIdx + 1; $j < $count; $j++) {
                if (($messages[$j]['type'] ?? null) === 'ai') {
                    $impliedCompleted = true;
                    break;
                }
            }

            foreach (array_values($message['tool_calls']) as $i => $call) {
                $callId = $call['id'] ?? null;
                $result = $callId !== null && $callId !== '' ? ($toolResultsById[$callId] ?? null) : null;

                $results[] = [
                    'id' => $callId !== null && $callId !== '' ? (string) $callId : ($message['id'] ?? 'unknown') . '-' . $i,
                    'call' => $call,
                    'result' => $result,
                    'aiMessage' => $message,
                    'index' => $i,
                    'state' => self::computeToolCallState($result, $impliedCompleted),
                ];
            }
        }

        return $results;
    }

    /**
     * @param array<string, mixed>|null $result
     */
    private static function computeToolCallState(?array $result, bool $impliedCompleted): string
    {
        if ($result !== null) {
            return ($result['status'] ?? null) === 'error' ? 'error' : 'completed';
        }

        return $impliedCompleted ? 'completed' : 'pending';
    }
}
