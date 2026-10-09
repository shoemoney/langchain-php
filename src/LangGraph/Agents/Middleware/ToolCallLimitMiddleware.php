<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware;

use LangChain\Messages\AIMessage;
use LangChain\Messages\ToolMessage;
use LangGraph\Agents\Middleware;

/**
 * Creates a middleware to limit the number of tool calls at thread and run levels.
 *
 * Port of `toolCallLimitMiddleware` from `langchain/src/agents/middleware/toolCallLimit.ts`.
 *
 * Options: `toolName` (limit only that tool; omitted, every tool counts together), `threadLimit` (calls
 * across the thread, persisted by a checkpointer), `runLimit` (calls within one invocation) and
 * `exitBehavior`: `continue` (the default; blocked calls get an error ToolMessage and the others run),
 * `error` (throw a {@see ToolCallLimitExceededError}) or `end` (stop with an AI message; only valid when
 * every pending call is the limited tool).
 *
 * ```
 * $searchLimiter = ToolCallLimitMiddleware::create(['toolName' => 'search', 'threadLimit' => 5, 'runLimit' => 3]);
 * ```
 */
final class ToolCallLimitMiddleware
{
    private const VALID_EXIT_BEHAVIORS = ['continue', 'error', 'end'];
    private const DEFAULT_EXIT_BEHAVIOR = 'continue';
    private const DEFAULT_TOOL_COUNT_KEY = '__all__';

    private function __construct()
    {
    }

    /**
     * @param array{toolName?: string|null, threadLimit?: int|float|null, runLimit?: int|float|null, exitBehavior?: 'continue'|'error'|'end'|null} $options
     * @return array<string, mixed> the middleware
     * @throws \InvalidArgumentException for no limit, an unknown exit behavior or a run limit above the thread limit
     */
    public static function create(array $options): array
    {
        $toolName = $options['toolName'] ?? null;
        $threadLimit = $options['threadLimit'] ?? null;
        $runLimit = $options['runLimit'] ?? null;

        if ($threadLimit === null && $runLimit === null) {
            throw new \InvalidArgumentException('At least one limit must be specified (threadLimit or runLimit)');
        }

        $exitBehavior = $options['exitBehavior'] ?? self::DEFAULT_EXIT_BEHAVIOR;
        if (!\in_array($exitBehavior, self::VALID_EXIT_BEHAVIORS, true)) {
            $received = \is_string($exitBehavior) ? $exitBehavior : get_debug_type($exitBehavior);

            throw new \InvalidArgumentException("Invalid enum value. Expected 'continue' | 'error' | 'end', received '{$received}'\n  → at exitBehavior");
        }

        if ($threadLimit !== null && $runLimit !== null && $runLimit > $threadLimit) {
            throw new \InvalidArgumentException(
                "runLimit ({$runLimit}) cannot exceed threadLimit ({$threadLimit}). "
                . 'The run limit should be less than or equal to the thread limit.',
            );
        }

        $middlewareName = $toolName !== null && $toolName !== ''
            ? "ToolCallLimitMiddleware[{$toolName}]"
            : 'ToolCallLimitMiddleware';

        return Middleware::create([
            'name' => $middlewareName,
            'stateSchema' => [
                'type' => 'object',
                'properties' => [
                    'threadToolCallCount' => ['type' => 'object', 'additionalProperties' => ['type' => 'number'], 'default' => []],
                    'runToolCallCount' => ['type' => 'object', 'additionalProperties' => ['type' => 'number'], 'default' => []],
                ],
            ],
            'afterModel' => [
                'canJumpTo' => ['end'],
                'hook' => static fn (array $state): ?array => self::afterModel($state, $toolName, $threadLimit, $runLimit, $exitBehavior),
            ],
            'afterAgent' => static fn (): array => ['runToolCallCount' => []],
        ]);
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null
     */
    private static function afterModel(array $state, ?string $toolName, int|float|null $threadLimit, int|float|null $runLimit, string $exitBehavior): ?array
    {
        $lastAIMessage = null;
        foreach (array_reverse(array_values((array) ($state['messages'] ?? []))) as $message) {
            if ($message instanceof AIMessage) {
                $lastAIMessage = $message;
                break;
            }
        }

        if ($lastAIMessage === null || $lastAIMessage->toolCalls === []) {
            return null;
        }

        $toolCalls = $lastAIMessage->toolCalls;
        $countKey = $toolName ?? self::DEFAULT_TOOL_COUNT_KEY;

        $threadCounts = (array) ($state['threadToolCallCount'] ?? []);
        $runCounts = (array) ($state['runToolCallCount'] ?? []);
        $currentThreadCount = $threadCounts[$countKey] ?? 0;
        $currentRunCount = $runCounts[$countKey] ?? 0;

        // Split the calls this limiter owns into those that fit under the limits and those that do not.
        $allowed = [];
        $blocked = [];
        $tempThreadCount = $currentThreadCount;
        $tempRunCount = $currentRunCount;
        foreach ($toolCalls as $toolCall) {
            if ($toolName !== null && ($toolCall['name'] ?? null) !== $toolName) {
                continue;
            }

            $wouldExceed = ($threadLimit !== null && $tempThreadCount + 1 > $threadLimit)
                || ($runLimit !== null && $tempRunCount + 1 > $runLimit);
            if ($wouldExceed) {
                $blocked[] = $toolCall;
            } else {
                $allowed[] = $toolCall;
                ++$tempThreadCount;
                ++$tempRunCount;
            }
        }
        $finalThreadCount = $tempThreadCount;
        $finalRunCount = $tempRunCount + \count($blocked);

        $threadCounts[$countKey] = $finalThreadCount;
        $runCounts[$countKey] = $finalRunCount;

        if ($blocked === []) {
            if ($allowed !== []) {
                return ['threadToolCallCount' => $threadCounts, 'runToolCallCount' => $runCounts];
            }

            return null;
        }

        if ($exitBehavior === 'error') {
            // The hypothetical thread count shows which limit was exceeded.
            throw new ToolCallLimitExceededError($finalThreadCount + \count($blocked), $finalRunCount, $threadLimit, $runLimit, $toolName);
        }

        $toolMsgContent = self::buildToolMessageContent($toolName);
        $artificialMessages = array_map(
            static fn (array $toolCall): ToolMessage => new ToolMessage([
                'content' => $toolMsgContent,
                'tool_call_id' => (string) ($toolCall['id'] ?? ''),
                'name' => $toolCall['name'] ?? null,
                'additional_kwargs' => ['status' => 'error'],
            ]),
            $blocked,
        );

        if ($exitBehavior === 'end') {
            $otherTools = [];
            if ($toolName !== null) {
                $otherTools = array_values(array_filter($toolCalls, static fn (array $tc): bool => ($tc['name'] ?? null) !== $toolName));
            } else {
                $uniqueToolNames = array_unique(array_filter(array_map(static fn (array $tc): mixed => $tc['name'] ?? null, $toolCalls)));
                if (\count($uniqueToolNames) > 1) {
                    $otherTools = $allowed !== [] ? $allowed : $toolCalls;
                }
            }

            if ($otherTools !== []) {
                $names = implode(', ', array_unique(array_filter(array_map(static fn (array $tc): mixed => $tc['name'] ?? null, $otherTools))));

                throw new \RuntimeException(
                    "Cannot end execution with other tool calls pending. Found calls to: {$names}. Use 'continue' or 'error' behavior instead.",
                );
            }

            $hypotheticalThreadCount = $finalThreadCount + \count($blocked);
            $artificialMessages[] = new AIMessage(ToolCallLimitExceededError::buildFinalAIMessageContent(
                $hypotheticalThreadCount,
                $finalRunCount,
                $threadLimit,
                $runLimit,
                $toolName,
            ));

            return [
                'threadToolCallCount' => $threadCounts,
                'runToolCallCount' => $runCounts,
                'jumpTo' => 'end',
                'messages' => $artificialMessages,
            ];
        }

        return [
            'threadToolCallCount' => $threadCounts,
            'runToolCallCount' => $runCounts,
            'messages' => $artificialMessages,
        ];
    }

    /** The text sent to the model: always tells it not to call again, whichever limit was hit. */
    private static function buildToolMessageContent(?string $toolName): string
    {
        if ($toolName !== null && $toolName !== '') {
            return "Tool call limit exceeded. Do not call '{$toolName}' again.";
        }

        return 'Tool call limit exceeded. Do not make additional tool calls.';
    }
}
