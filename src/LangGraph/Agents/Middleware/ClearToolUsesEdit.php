<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\ToolMessage;

/**
 * Clears older tool results once the context exceeds a trigger, mirroring Anthropic's
 * `clear_tool_uses_20250919` behavior.
 *
 * Port of `ClearToolUsesEdit` from `langchain/src/agents/middleware/contextEditing.ts`.
 *
 * Config:
 *
 *  - `trigger`: a context size array (`tokens`, `messages`, `fraction`) or a list of them; any one may fire,
 *    and within one all properties must hold (default `['tokens' => 100000]`);
 *  - `keep`: how many of the most recent tool results survive: exactly one of `messages` (a count, default 3),
 *    `tokens` or `fraction`;
 *  - `clearToolInputs`: also clear the arguments of the cleared tool calls (default false);
 *  - `excludeTools`: names of tools whose results are never cleared;
 *  - `placeholder`: the content of a cleared result (default `[cleared]`);
 *  - `triggerTokens`, `keepMessages`, `clearAtLeast`: deprecated; see the upstream source.
 *
 * Tool messages that answer no tool call of an earlier AI message are dropped first. A cleared tool message
 * keeps its id (upstream's replacement has none and gets a fresh one from the messages reducer), so the
 * middleware can write the edit back to the thread by id.
 */
final class ClearToolUsesEdit implements ContextEdit
{
    private const DEFAULT_TOOL_PLACEHOLDER = '[cleared]';
    private const DEFAULT_TRIGGER_TOKENS = 100_000;
    private const DEFAULT_KEEP = 3;

    /** @var list<array{fraction?: int|float, tokens?: int|float, messages?: int}> */
    private array $triggerConditions;

    /** @var array{fraction?: int|float, tokens?: int|float, messages?: int}|list<array{fraction?: int|float, tokens?: int|float, messages?: int}> */
    public array $trigger;

    /** @var array{fraction?: int|float, tokens?: int|float, messages?: int} */
    public array $keep;

    public bool $clearToolInputs;

    /** @var array<string, true> */
    public array $excludeTools;

    public string $placeholder;

    public int|float $clearAtLeast;

    /**
     * @param array<string, mixed> $config
     * @throws \InvalidArgumentException for an invalid trigger or keep
     */
    public function __construct(array $config = [])
    {
        // Handle deprecated parameters.
        $trigger = $config['trigger'] ?? null;
        if (isset($config['triggerTokens'])) {
            trigger_error('triggerTokens is deprecated. Use `trigger: { tokens: value }` instead.', \E_USER_DEPRECATED);
            if ($trigger === null) {
                $trigger = ['tokens' => $config['triggerTokens']];
            }
        }

        $keep = $config['keep'] ?? null;
        if (isset($config['keepMessages'])) {
            trigger_error('keepMessages is deprecated. Use `keep: { messages: value }` instead.', \E_USER_DEPRECATED);
            if ($keep === null) {
                $keep = ['messages' => $config['keepMessages']];
            }
        }

        // Set defaults.
        $trigger ??= ['tokens' => self::DEFAULT_TRIGGER_TOKENS];
        $keep ??= ['messages' => self::DEFAULT_KEEP];

        // Validate the trigger conditions.
        if (\is_array($trigger) && array_is_list($trigger)) {
            $this->triggerConditions = array_map(static fn (mixed $t): array => SummarizationMiddleware::parseContextSize($t), $trigger);
            $this->trigger = $this->triggerConditions;
        } else {
            $validated = SummarizationMiddleware::parseContextSize($trigger);
            $this->triggerConditions = [$validated];
            $this->trigger = $validated;
        }

        $this->keep = SummarizationMiddleware::parseKeepSize($keep);

        if (isset($config['clearAtLeast'])) {
            trigger_error(
                'clearAtLeast is deprecated and will be removed in a future version. '
                . 'It conflicts with the `keep` property. Use `keep: { tokens: value }` or '
                . '`keep: { messages: value }` instead to control retention.',
                \E_USER_DEPRECATED,
            );
        }
        $this->clearAtLeast = $config['clearAtLeast'] ?? 0;

        $this->clearToolInputs = (bool) ($config['clearToolInputs'] ?? false);
        $this->excludeTools = array_fill_keys(array_map('strval', (array) ($config['excludeTools'] ?? [])), true);
        $this->placeholder = (string) ($config['placeholder'] ?? self::DEFAULT_TOOL_PLACEHOLDER);
    }

    /**
     * @param list<BaseMessage>                        $messages
     * @param callable(list<BaseMessage>): (int|float) $countTokens
     */
    public function apply(array &$messages, callable $countTokens, ?object $model = null): void
    {
        $tokens = $countTokens($messages);

        // Drop the tool messages that answer no tool call.
        $orphanedIndices = [];
        foreach ($messages as $i => $msg) {
            if (!$msg instanceof ToolMessage) {
                continue;
            }

            $aiMessage = $this->findAIMessageForToolCall(\array_slice($messages, 0, $i), $msg->toolCallId);
            if ($aiMessage === null || $this->findToolCall($aiMessage, $msg->toolCallId) === null) {
                $orphanedIndices[] = $i;
            }
        }

        for ($i = \count($orphanedIndices) - 1; $i >= 0; --$i) {
            array_splice($messages, $orphanedIndices[$i], 1);
        }

        $currentTokens = $tokens;
        if ($orphanedIndices !== []) {
            $currentTokens = $countTokens($messages);
        }

        if (!$this->shouldEdit($messages, $currentTokens, $model)) {
            return;
        }

        /** @var list<array{idx: int, msg: ToolMessage}> $candidates */
        $candidates = [];
        foreach ($messages as $i => $msg) {
            if ($msg instanceof ToolMessage) {
                $candidates[] = ['idx' => $i, 'msg' => $msg];
            }
        }

        if ($candidates === []) {
            return;
        }

        $keepCount = $this->determineKeepCount($candidates, $countTokens, $model);

        $candidatesToClear = $keepCount >= \count($candidates)
            ? []
            : ($keepCount > 0 ? \array_slice($candidates, 0, -$keepCount) : $candidates);

        // `clearAtLeast` is deprecated and conflicts with `keep`, but still supported: it keeps clearing the
        // retained results, newest first, until enough tokens are gone.
        $clearedTokens = 0;
        foreach ($candidatesToClear as ['idx' => $idx, 'msg' => $toolMessage]) {
            if ($this->clearToolMessage($messages, $idx, $toolMessage)) {
                $clearedTokens = max(0, $currentTokens - $countTokens($messages));
            }
        }

        if ($this->clearAtLeast > 0 && $clearedTokens < $this->clearAtLeast) {
            // The candidates that were kept.
            $remainingCandidates = $keepCount > 0 && $keepCount < \count($candidates)
                ? \array_slice($candidates, -$keepCount)
                : [];

            for ($i = \count($remainingCandidates) - 1; $i >= 0; --$i) {
                if ($clearedTokens >= $this->clearAtLeast) {
                    break;
                }

                ['idx' => $idx, 'msg' => $toolMessage] = $remainingCandidates[$i];
                if ($this->clearToolMessage($messages, $idx, $toolMessage)) {
                    $clearedTokens = max(0, $currentTokens - $countTokens($messages));
                }
            }
        }
    }

    /**
     * Replace one tool message with its cleared form (and optionally clear the call's inputs).
     *
     * @param list<BaseMessage> $messages
     * @return bool whether the message was cleared (not already cleared, answers a call, not excluded)
     */
    private function clearToolMessage(array &$messages, int $idx, ToolMessage $toolMessage): bool
    {
        // Skip if already cleared.
        if (($toolMessage->response_metadata['context_editing']['cleared'] ?? false)) {
            return false;
        }

        $aiMessage = $this->findAIMessageForToolCall(\array_slice($messages, 0, $idx), $toolMessage->toolCallId);
        if ($aiMessage === null) {
            return false;
        }

        $toolCall = $this->findToolCall($aiMessage, $toolMessage->toolCallId);
        if ($toolCall === null) {
            return false;
        }

        // Skip if the tool is excluded.
        $toolName = ($toolMessage->name !== null && $toolMessage->name !== '') ? $toolMessage->name : $toolCall['name'];
        if (isset($this->excludeTools[$toolName])) {
            return false;
        }

        $messages[$idx] = new ToolMessage([
            'tool_call_id' => $toolMessage->toolCallId,
            'content' => $this->placeholder,
            'name' => $toolMessage->name,
            'id' => $toolMessage->id,
            'response_metadata' => [
                ...$toolMessage->response_metadata,
                'context_editing' => ['cleared' => true, 'strategy' => 'clear_tool_uses'],
            ],
        ]);

        if ($this->clearToolInputs) {
            $aiMsgIdx = array_search($aiMessage, $messages, true);
            if ($aiMsgIdx !== false) {
                $messages[$aiMsgIdx] = $this->buildClearedToolInputMessage($aiMessage, $toolMessage->toolCallId);
            }
        }

        return true;
    }

    /**
     * Whether editing should run: any trigger (OR) whose properties all hold (AND).
     *
     * @param list<BaseMessage> $messages
     */
    private function shouldEdit(array $messages, int|float $totalTokens, ?object $model): bool
    {
        foreach ($this->triggerConditions as $trigger) {
            $conditionMet = true;
            $hasAnyProperty = false;

            if (isset($trigger['messages'])) {
                $hasAnyProperty = true;
                if (\count($messages) < $trigger['messages']) {
                    $conditionMet = false;
                }
            }

            if (isset($trigger['tokens'])) {
                $hasAnyProperty = true;
                if ($totalTokens < $trigger['tokens']) {
                    $conditionMet = false;
                }
            }

            if (isset($trigger['fraction'])) {
                $hasAnyProperty = true;
                if ($model === null) {
                    continue;
                }
                $maxInputTokens = SummarizationMiddleware::getProfileLimits($model);
                if ($maxInputTokens === null) {
                    // A fraction without model limits cannot be evaluated: skip this condition.
                    continue;
                }
                $threshold = (int) floor($maxInputTokens * $trigger['fraction']);
                if ($threshold <= 0) {
                    continue;
                }
                if ($totalTokens < $threshold) {
                    $conditionMet = false;
                }
            }

            if ($hasAnyProperty && $conditionMet) {
                return true;
            }
        }

        return false;
    }

    /**
     * How many tool results to keep.
     *
     * @param list<array{idx: int, msg: ToolMessage}> $candidates
     */
    private function determineKeepCount(array $candidates, callable $countTokens, ?object $model): int
    {
        if (isset($this->keep['messages'])) {
            return $this->keep['messages'];
        }

        if (isset($this->keep['tokens'])) {
            return $this->countKeptWithin($candidates, $countTokens, $this->keep['tokens']);
        }

        if (isset($this->keep['fraction'])) {
            if ($model === null) {
                return self::DEFAULT_KEEP;
            }
            $maxInputTokens = SummarizationMiddleware::getProfileLimits($model);
            if ($maxInputTokens !== null) {
                $targetTokens = (int) floor($maxInputTokens * $this->keep['fraction']);
                if ($targetTokens <= 0) {
                    return self::DEFAULT_KEEP;
                }

                return $this->countKeptWithin($candidates, $countTokens, $targetTokens);
            }
        }

        return self::DEFAULT_KEEP;
    }

    /**
     * The most recent tool results that fit the token budget, counted from the end.
     *
     * A simplified estimate, as upstream: each result is counted on its own.
     *
     * @param list<array{idx: int, msg: ToolMessage}> $candidates
     */
    private function countKeptWithin(array $candidates, callable $countTokens, int|float $targetTokens): int
    {
        $tokenCount = 0;
        $keepCount = 0;

        for ($i = \count($candidates) - 1; $i >= 0; --$i) {
            $msgTokens = $countTokens([$candidates[$i]['msg']]);
            if ($tokenCount + $msgTokens <= $targetTokens) {
                $tokenCount += $msgTokens;
                ++$keepCount;
            } else {
                break;
            }
        }

        return $keepCount;
    }

    /**
     * The nearest earlier AI message that made the tool call.
     *
     * @param list<BaseMessage> $previousMessages
     */
    private function findAIMessageForToolCall(array $previousMessages, string $toolCallId): ?AIMessage
    {
        for ($i = \count($previousMessages) - 1; $i >= 0; --$i) {
            $msg = $previousMessages[$i];
            if ($msg instanceof AIMessage && $this->findToolCall($msg, $toolCallId) !== null) {
                return $msg;
            }
        }

        return null;
    }

    /** @return array{id?: string, name: string, args: array<string, mixed>}|null */
    private function findToolCall(AIMessage $message, string $toolCallId): ?array
    {
        foreach ($message->toolCalls as $call) {
            if (($call['id'] ?? null) === $toolCallId) {
                return $call;
            }
        }

        return null;
    }

    private function buildClearedToolInputMessage(AIMessage $message, string $toolCallId): AIMessage
    {
        $updatedToolCalls = array_map(
            static fn (array $toolCall): array => ($toolCall['id'] ?? null) === $toolCallId ? [...$toolCall, 'args' => []] : $toolCall,
            $message->toolCalls,
        );

        $metadata = $message->response_metadata;
        $contextEntry = (array) ($metadata['context_editing'] ?? []);

        $clearedIds = array_fill_keys(array_map('strval', (array) ($contextEntry['cleared_tool_inputs'] ?? [])), true);
        $clearedIds[$toolCallId] = true;
        $sorted = array_map('strval', array_keys($clearedIds));
        sort($sorted, \SORT_STRING);
        $contextEntry['cleared_tool_inputs'] = $sorted;
        $metadata['context_editing'] = $contextEntry;

        return new AIMessage([
            'content' => $message->content,
            'tool_calls' => $updatedToolCalls,
            'response_metadata' => $metadata,
            'id' => $message->id,
            'name' => $message->name,
            'additional_kwargs' => $message->additional_kwargs,
        ]);
    }
}
