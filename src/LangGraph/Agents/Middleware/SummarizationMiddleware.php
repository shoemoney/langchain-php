<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\MessageUtils;
use LangChain\Messages\RemoveMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Utils\Uuid;
use LangGraph\Agents\Middleware;
use LangGraph\Agents\Runtime;
use LangGraph\Agents\Utils as AgentUtils;
use LangGraph\Graph\MessagesReducer;
use LangGraph\Pregel\PregelScratchpad;

/**
 * Summarization middleware: summarizes the conversation history when it nears a size limit.
 *
 * Port of `summarizationMiddleware` from `langchain/src/agents/middleware/summarization.ts`.
 *
 * Before every model call the middleware measures the history, and when a trigger fires it asks a model to
 * summarize the older messages and replaces them (via `REMOVE_ALL_MESSAGES`) with one summary human message
 * followed by the preserved recent messages. A cut never separates an AI message from its tool messages.
 *
 * Options (all can also be overridden per run through the run context):
 *
 *  - `model` (required): the summarization model, an instance with `invoke($prompt, RunnableConfig)`, or a
 *    "provider:model" string resolved through {@see \LangChain\LanguageModels\Chat\Universal\InitChatModel::init()};
 *  - `trigger`: a context size array, or a list of them (any one may fire); within one array every property
 *    must hold. Properties: `tokens`, `messages`, `fraction` (of the model's `maxInputTokens`). No trigger
 *    disables summarization;
 *  - `keep`: exactly one of `messages` (default 20), `tokens` or `fraction`;
 *  - `tokenCounter`: `fn(list<BaseMessage>): int|float`, default {@see Utils::countTokensApproximately()};
 *  - `summaryPrompt`: the prompt, with a `{messages}` placeholder (default {@see self::DEFAULT_SUMMARY_PROMPT});
 *  - `summaryPrefix`: text before the summary in the summary message;
 *  - `trimTokensToSummarize`: token budget of the history sent to the summarizer (default 4000);
 *  - `maxTokensBeforeSummary`, `messagesToKeep`: deprecated spellings of `trigger: ['tokens' => n]` and
 *    `keep: ['messages' => n]`.
 *
 * The summary call runs with the parent run's callbacks, tags and metadata, plus the `lc_source: summarization`
 * metadata and {@see Constants::INTERNAL_CALL_TAG}, which keeps its tokens out of the messages stream.
 *
 * ```
 * $summarizer = SummarizationMiddleware::create([
 *     'model' => $cheapModel,
 *     'trigger' => ['tokens' => 4000, 'messages' => 10],
 *     'keep' => ['messages' => 20],
 * ]);
 * ```
 */
final class SummarizationMiddleware
{
    public const DEFAULT_SUMMARY_PROMPT = <<<'PROMPT'
<role>
Context Extraction Assistant
</role>

<primary_objective>
Your sole objective in this task is to extract the highest quality/most relevant context from the conversation history below.
</primary_objective>

<objective_information>
You're nearing the total number of input tokens you can accept, so you must extract the highest quality/most relevant pieces of information from your conversation history.
This context will then overwrite the conversation history presented below. Because of this, ensure the context you extract is only the most important information to your overall goal.
</objective_information>

<instructions>
The conversation history below will be replaced with the context you extract in this step. Because of this, you must do your very best to extract and record all of the most important context from the conversation history.
You want to ensure that you don't repeat any actions you've already completed, so the context you extract from the conversation history should be focused on the most important information to your overall goal.
</instructions>

The user will message you with the full message history you'll be extracting context from, to then replace. Carefully read over it all, and think deeply about what information is most important to your overall goal that should be saved:

With all of this in mind, please carefully read over the entire conversation history, and extract the most important and relevant context to replace it so that you can free up space in the conversation history.
Respond ONLY with the extracted context. Do not include any additional information, or text before or after the extracted context.

<messages>
Messages to summarize:
{messages}
</messages>
PROMPT;

    private const DEFAULT_SUMMARY_PREFIX = 'Here is a summary of the conversation to date:';
    private const DEFAULT_MESSAGES_TO_KEEP = 20;
    private const DEFAULT_TRIM_TOKEN_LIMIT = 4000;
    private const DEFAULT_FALLBACK_MESSAGE_COUNT = 15;
    private const SEARCH_RANGE_FOR_TOOL_PAIRS = 5;

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $options see the class documentation
     * @return array<string, mixed> the middleware
     * @throws \InvalidArgumentException for invalid options
     */
    public static function create(array $options): array
    {
        $userOptions = self::parseOptions($options);

        return Middleware::create([
            'name' => 'SummarizationMiddleware',
            // `model` is required when creating the middleware but can be omitted from the run context.
            'contextSchema' => [
                'type' => 'object',
                'properties' => [
                    'model' => [],
                    'trigger' => [],
                    'keep' => [],
                    'tokenCounter' => [],
                    'summaryPrompt' => ['type' => 'string', 'default' => self::DEFAULT_SUMMARY_PROMPT],
                    'trimTokensToSummarize' => ['type' => 'number'],
                    'summaryPrefix' => ['type' => 'string'],
                    'maxTokensBeforeSummary' => ['type' => 'number'],
                    'messagesToKeep' => ['type' => 'number'],
                ],
            ],
            'beforeModel' => static fn (array $state, mixed $runtime): ?array => self::beforeModel($userOptions, $state, $runtime),
        ]);
    }

    /**
     * Context window size (max input tokens) from the model profile, falling back to a lookup by model name.
     *
     * Port of `getProfileLimits`. The profile is the model's public `profile` property or its `profile()`
     * method; a profile that declares `maxInputTokens` (even as null) is authoritative.
     */
    public static function getProfileLimits(object $input): ?int
    {
        $vars = get_object_vars($input);

        $profile = null;
        if (\array_key_exists('profile', $vars)) {
            $profile = $vars['profile'];
        } elseif (method_exists($input, 'profile')) {
            $profile = $input->profile();
        }
        if (\is_object($profile)) {
            $profile = get_object_vars($profile);
        }

        if (\is_array($profile) && \array_key_exists('maxInputTokens', $profile)
            && (\is_int($profile['maxInputTokens']) || \is_float($profile['maxInputTokens']) || $profile['maxInputTokens'] === null)) {
            return $profile['maxInputTokens'] === null ? null : (int) $profile['maxInputTokens'];
        }

        // Fall back to the model name.
        if (isset($vars['model']) && \is_string($vars['model'])) {
            return self::getModelContextSize($vars['model']);
        }
        if (isset($vars['modelName']) && \is_string($vars['modelName'])) {
            return self::getModelContextSize($vars['modelName']);
        }

        return null;
    }

    /**
     * Validate a trigger condition: at least one of `fraction` (0, 1], `tokens` > 0 or an integer `messages` > 0.
     *
     * Port of `contextSizeSchema.parse`. Unknown keys are dropped, as Zod does.
     *
     * @param mixed $value
     * @return array{fraction?: int|float, tokens?: int|float, messages?: int}
     * @throws \InvalidArgumentException
     */
    public static function parseContextSize(mixed $value, string $path = 'trigger'): array
    {
        $issues = [];
        $parsed = self::pickSizeKeys($value, $path, $issues);

        if (\array_key_exists('fraction', $parsed)) {
            if ($parsed['fraction'] <= 0) {
                $issues[] = ['Fraction must be greater than 0', "{$path}.fraction"];
            } elseif ($parsed['fraction'] > 1) {
                $issues[] = ['Fraction must be less than or equal to 1', "{$path}.fraction"];
            }
        }
        if (\array_key_exists('tokens', $parsed) && $parsed['tokens'] <= 0) {
            $issues[] = ['Tokens must be greater than 0', "{$path}.tokens"];
        }
        if (\array_key_exists('messages', $parsed)) {
            if (!self::isInteger($parsed['messages'])) {
                $issues[] = ['Messages must be an integer', "{$path}.messages"];
            } elseif ($parsed['messages'] <= 0) {
                $issues[] = ['Messages must be greater than 0', "{$path}.messages"];
            }
        }
        if ($issues === [] && $parsed === []) {
            $issues[] = ['At least one of fraction, tokens, or messages must be provided', $path];
        }

        self::throwIssues($issues);

        return $parsed;
    }

    /**
     * Validate a keep size: exactly one of `fraction` [0, 1], `tokens` >= 0 or an integer `messages` >= 0.
     *
     * Port of `keepSchema.parse`.
     *
     * @param mixed $value
     * @return array{fraction?: int|float, tokens?: int|float, messages?: int}
     * @throws \InvalidArgumentException
     */
    public static function parseKeepSize(mixed $value, string $path = 'keep'): array
    {
        $issues = [];
        $parsed = self::pickSizeKeys($value, $path, $issues);

        if (\array_key_exists('fraction', $parsed)) {
            if ($parsed['fraction'] < 0) {
                $issues[] = ['Messages must be non-negative', "{$path}.fraction"];
            } elseif ($parsed['fraction'] > 1) {
                $issues[] = ['Fraction must be less than or equal to 1', "{$path}.fraction"];
            }
        }
        if (\array_key_exists('tokens', $parsed) && $parsed['tokens'] < 0) {
            $issues[] = ['Tokens must be greater than or equal to 0', "{$path}.tokens"];
        }
        if (\array_key_exists('messages', $parsed)) {
            if (!self::isInteger($parsed['messages'])) {
                $issues[] = ['Messages must be an integer', "{$path}.messages"];
            } elseif ($parsed['messages'] < 0) {
                $issues[] = ['Messages must be non-negative', "{$path}.messages"];
            }
        }
        if ($issues === [] && \count($parsed) !== 1) {
            $issues[] = ['Exactly one of fraction, tokens, or messages must be provided', $path];
        }

        self::throwIssues($issues);

        return $parsed;
    }

    /**
     * Zod's `.optional()` number fields: absent or null are undefined, anything but a number is a type error.
     *
     * @param list<array{0: string, 1: string}> $issues
     * @return array<string, int|float>
     */
    private static function pickSizeKeys(mixed $value, string $path, array &$issues): array
    {
        if (!\is_array($value) || ($value !== [] && array_is_list($value))) {
            $issues[] = ['Expected object, received ' . self::receivedType($value), $path];

            return [];
        }

        $parsed = [];
        foreach (['fraction', 'tokens', 'messages'] as $key) {
            if (!isset($value[$key])) {
                continue;
            }
            if (!\is_int($value[$key]) && !\is_float($value[$key])) {
                $issues[] = ['Expected number, received ' . self::receivedType($value[$key]), "{$path}.{$key}"];
                continue;
            }
            $parsed[$key] = $value[$key];
        }

        return $parsed;
    }

    /** @param list<array{0: string, 1: string}> $issues */
    private static function throwIssues(array $issues): void
    {
        if ($issues === []) {
            return;
        }

        throw new \InvalidArgumentException(implode("\n", array_map(
            static fn (array $issue): string => "✖ {$issue[0]}\n  → at {$issue[1]}",
            $issues,
        )));
    }

    private static function isInteger(int|float $value): bool
    {
        return \is_int($value) || floor($value) === $value;
    }

    private static function receivedType(mixed $value): string
    {
        return match (true) {
            $value === null => 'undefined',
            \is_string($value) => 'string',
            \is_bool($value) => 'boolean',
            \is_int($value), \is_float($value) => 'number',
            \is_array($value) => array_is_list($value) ? 'array' : 'object',
            default => 'object',
        };
    }

    /**
     * Validate the options given to create() (upstream parses them through the Zod context schema).
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function parseOptions(array $options): array
    {
        try {
            $parsed = $options;

            if (isset($options['trigger'])) {
                $parsed['trigger'] = self::parseTrigger($options['trigger']);
            }
            if (isset($options['keep'])) {
                $parsed['keep'] = self::parseKeepSize($options['keep']);
            }
            if (isset($options['tokenCounter']) && !\is_callable($options['tokenCounter'])) {
                throw new \InvalidArgumentException("✖ Invalid input\n  → at tokenCounter");
            }
            foreach (['trimTokensToSummarize', 'maxTokensBeforeSummary', 'messagesToKeep'] as $key) {
                if (isset($options[$key]) && !\is_int($options[$key]) && !\is_float($options[$key])) {
                    throw new \InvalidArgumentException("✖ Expected number, received " . self::receivedType($options[$key]) . "\n  → at {$key}");
                }
            }
            foreach (['summaryPrompt', 'summaryPrefix'] as $key) {
                if (isset($options[$key]) && !\is_string($options[$key])) {
                    throw new \InvalidArgumentException("✖ Expected string, received " . self::receivedType($options[$key]) . "\n  → at {$key}");
                }
            }
        } catch (\InvalidArgumentException $e) {
            throw new \InvalidArgumentException('Invalid summarization middleware options: ' . $e->getMessage(), 0, $e);
        }

        $parsed['summaryPrompt'] = $options['summaryPrompt'] ?? self::DEFAULT_SUMMARY_PROMPT;

        return $parsed;
    }

    /**
     * A trigger is one condition or a list of them.
     *
     * @return list<array{fraction?: int|float, tokens?: int|float, messages?: int}>
     */
    private static function parseTrigger(mixed $trigger): array
    {
        if (\is_array($trigger) && array_is_list($trigger)) {
            return array_map(static fn (mixed $t): array => self::parseContextSize($t), $trigger);
        }

        return [self::parseContextSize($trigger)];
    }

    /**
     * @param array<string, mixed> $userOptions
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null
     */
    private static function beforeModel(array $userOptions, array $state, mixed $runtime): ?array
    {
        $context = self::contextOf($runtime);

        $trigger = $userOptions['trigger'] ?? null;
        $keep = $userOptions['keep'] ?? null;

        // Handle deprecated parameters.
        if (isset($userOptions['maxTokensBeforeSummary'])) {
            trigger_error('maxTokensBeforeSummary is deprecated. Use `trigger: { tokens: value }` instead.', \E_USER_DEPRECATED);
            if ($trigger === null) {
                $trigger = [['tokens' => $userOptions['maxTokensBeforeSummary']]];
            }
        }
        if (isset($userOptions['messagesToKeep'])) {
            trigger_error('messagesToKeep is deprecated. Use `keep: { messages: value }` instead.', \E_USER_DEPRECATED);
            if ($keep === null || (\array_key_exists('messages', $keep) && $keep['messages'] === self::DEFAULT_MESSAGES_TO_KEEP)) {
                $keep = ['messages' => $userOptions['messagesToKeep']];
            }
        }

        // Merge the context with the user options.
        $resolvedTrigger = ($context['trigger'] ?? null) !== null ? $context['trigger'] : $trigger;
        $resolvedKeep = ($context['keep'] ?? null) !== null
            ? $context['keep']
            : ($keep ?? ['messages' => self::DEFAULT_MESSAGES_TO_KEEP]);

        $validatedKeep = self::parseKeepSize($resolvedKeep);

        $triggerConditions = [];
        if ($resolvedTrigger !== null) {
            $triggerConditions = self::parseTrigger($resolvedTrigger);
        }

        // Fractions need the model's context size.
        $requiresProfile = \array_key_exists('fraction', $validatedKeep);
        foreach ($triggerConditions as $condition) {
            $requiresProfile = $requiresProfile || \array_key_exists('fraction', $condition);
        }

        $model = $userOptions['model'] ?? null;
        if (\is_string($model)) {
            $model = \LangChain\LanguageModels\Chat\Universal\InitChatModel::init($model);
        }
        if (!\is_object($model)) {
            throw new \InvalidArgumentException('SummarizationMiddleware requires a "model".');
        }

        if ($requiresProfile && !self::getProfileLimits($model)) {
            throw new \Exception(
                'Model profile information is required to use fractional token limits. '
                . 'Use absolute token counts instead.',
            );
        }

        $summaryPrompt = ($context['summaryPrompt'] ?? null) === self::DEFAULT_SUMMARY_PROMPT
            ? ($userOptions['summaryPrompt'] ?? self::DEFAULT_SUMMARY_PROMPT)
            : ($context['summaryPrompt'] ?? $userOptions['summaryPrompt'] ?? self::DEFAULT_SUMMARY_PROMPT);
        $summaryPrefix = $context['summaryPrefix'] ?? $userOptions['summaryPrefix'] ?? self::DEFAULT_SUMMARY_PREFIX;
        $trimTokensToSummarize = ($context['trimTokensToSummarize'] ?? null) !== null
            ? $context['trimTokensToSummarize']
            : ($userOptions['trimTokensToSummarize'] ?? self::DEFAULT_TRIM_TOKEN_LIMIT);

        $messages = array_values((array) ($state['messages'] ?? []));

        // Ensure all messages have IDs.
        foreach ($messages as $message) {
            if ($message->id === null || $message->id === '') {
                $message->id = Uuid::v4();
            }
        }

        /** @var callable(list<BaseMessage>): (int|float) $tokenCounter */
        $tokenCounter = ($context['tokenCounter'] ?? null) !== null
            ? $context['tokenCounter']
            : ($userOptions['tokenCounter'] ?? Utils::countTokensApproximately(...));

        $totalTokens = $tokenCounter($messages);
        if (!self::shouldSummarize($messages, $totalTokens, $triggerConditions, $model)) {
            return null;
        }

        // Separate the system message from the conversation.
        $systemPrompt = null;
        $conversationMessages = $messages;
        if ($messages !== [] && $messages[0] instanceof SystemMessage) {
            $systemPrompt = $messages[0];
            $conversationMessages = \array_slice($messages, 1);
        }

        $cutoffIndex = self::determineCutoffIndex($conversationMessages, $validatedKeep, $tokenCounter, $model);
        if ($cutoffIndex <= 0) {
            return null;
        }

        // Include the system message in the messages to summarize to capture previous summaries.
        $messagesToSummarize = \array_slice($conversationMessages, 0, $cutoffIndex);
        $preservedMessages = \array_slice($conversationMessages, $cutoffIndex);
        if ($systemPrompt !== null) {
            array_unshift($messagesToSummarize, $systemPrompt);
        }

        $summary = self::createSummary($messagesToSummarize, $model, $summaryPrompt, $tokenCounter, $trimTokensToSummarize, $runtime);

        // Reuse a replaced message's id so the summary projects as an update, not a new message.
        $summaryMessage = new HumanMessage([
            'content' => "{$summaryPrefix}\n\n{$summary}",
            'id' => $conversationMessages[0]->id,
            'additional_kwargs' => ['lc_source' => 'summarization'],
        ]);

        return [
            'messages' => [
                new RemoveMessage(['id' => MessagesReducer::REMOVE_ALL_MESSAGES]),
                $summaryMessage,
                ...$preservedMessages,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function contextOf(mixed $runtime): array
    {
        $context = $runtime instanceof Runtime ? $runtime->context : (\is_object($runtime) ? ($runtime->context ?? null) : ($runtime['context'] ?? null));

        return \is_array($context) ? $context : (\is_object($context) ? get_object_vars($context) : []);
    }

    /**
     * Whether summarization should run: any trigger (OR) whose properties all hold (AND).
     *
     * @param list<BaseMessage>                                                                  $messages
     * @param list<array{fraction?: int|float, tokens?: int|float, messages?: int}> $triggerConditions
     */
    private static function shouldSummarize(array $messages, int|float $totalTokens, array $triggerConditions, object $model): bool
    {
        if ($triggerConditions === []) {
            return false;
        }

        foreach ($triggerConditions as $trigger) {
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
                $maxInputTokens = self::getProfileLimits($model);
                if ($maxInputTokens !== null) {
                    $threshold = (int) floor($maxInputTokens * $trigger['fraction']);
                    if ($totalTokens < $threshold) {
                        $conditionMet = false;
                    }
                } else {
                    // A fraction without model limits cannot be evaluated: skip this condition.
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
     * @param list<BaseMessage>                                     $messages
     * @param array{fraction?: int|float, tokens?: int|float, messages?: int} $keep
     */
    private static function determineCutoffIndex(array $messages, array $keep, callable $tokenCounter, object $model): int
    {
        if (\array_key_exists('tokens', $keep) || \array_key_exists('fraction', $keep)) {
            $tokenBasedCutoff = self::findTokenBasedCutoff($messages, $keep, $tokenCounter, $model);
            if ($tokenBasedCutoff !== null) {
                return $tokenBasedCutoff;
            }

            // Fall back to the message count if the token-based cutoff cannot be computed.
            return self::findSafeCutoff($messages, self::DEFAULT_MESSAGES_TO_KEEP);
        }

        return self::findSafeCutoff($messages, $keep['messages'] ?? self::DEFAULT_MESSAGES_TO_KEEP);
    }

    /**
     * Cutoff index that retains about the target number of tokens.
     *
     * @param list<BaseMessage>                                     $messages
     * @param array{fraction?: int|float, tokens?: int|float, messages?: int} $keep
     */
    private static function findTokenBasedCutoff(array $messages, array $keep, callable $tokenCounter, object $model): ?int
    {
        if ($messages === []) {
            return 0;
        }

        if (isset($keep['fraction'])) {
            $maxInputTokens = self::getProfileLimits($model);
            if ($maxInputTokens === null) {
                return null;
            }
            $targetTokenCount = (int) floor($maxInputTokens * $keep['fraction']);
        } elseif (isset($keep['tokens'])) {
            $targetTokenCount = (int) floor($keep['tokens']);
        } else {
            return null;
        }

        if ($targetTokenCount <= 0) {
            $targetTokenCount = 1;
        }

        $totalTokens = $tokenCounter($messages);
        if ($totalTokens <= $targetTokenCount) {
            return 0;
        }

        // Binary search for the earliest message index that keeps the suffix within the token budget.
        $left = 0;
        $right = \count($messages);
        $cutoffCandidate = \count($messages);
        $maxIterations = (int) floor(log(\count($messages), 2)) + 1;

        for ($i = 0; $i < $maxIterations; ++$i) {
            if ($left >= $right) {
                break;
            }

            $mid = intdiv($left + $right, 2);
            $suffixTokens = $tokenCounter(\array_slice($messages, $mid));
            if ($suffixTokens <= $targetTokenCount) {
                $cutoffCandidate = $mid;
                $right = $mid;
            } else {
                $left = $mid + 1;
            }
        }

        if ($cutoffCandidate === \count($messages)) {
            $cutoffCandidate = $left;
        }

        if ($cutoffCandidate >= \count($messages)) {
            if (\count($messages) === 1) {
                return 0;
            }
            $cutoffCandidate = \count($messages) - 1;
        }

        // Find a safe cutoff that preserves AI/Tool pairs: a cutoff on a ToolMessage moves backward.
        $safeCutoff = self::findSafeCutoffPoint($messages, $cutoffCandidate);

        // It moved backward (or stayed), so it is already safe.
        if ($safeCutoff <= $cutoffCandidate) {
            return $safeCutoff;
        }

        // Fallback: iterate backward to find a safe cutoff.
        for ($i = $cutoffCandidate; $i >= 0; --$i) {
            if (self::isSafeCutoffPoint($messages, $i)) {
                return $i;
            }
        }

        return 0;
    }

    /**
     * Safe cutoff index that preserves AI/Tool message pairs.
     *
     * @param list<BaseMessage> $messages
     */
    private static function findSafeCutoff(array $messages, int|float $messagesToKeep): int
    {
        if (\count($messages) <= $messagesToKeep) {
            return 0;
        }

        $targetCutoff = (int) (\count($messages) - $messagesToKeep);

        // A cutoff on a ToolMessage moves backward to include the AIMessage that made the call.
        $safeCutoff = self::findSafeCutoffPoint($messages, $targetCutoff);
        if ($safeCutoff <= $targetCutoff) {
            return $safeCutoff;
        }

        for ($i = $targetCutoff; $i >= 0; --$i) {
            if (self::isSafeCutoffPoint($messages, $i)) {
                return $i;
            }
        }

        return 0;
    }

    /**
     * Whether cutting at the index would separate an AI message from its tool messages.
     *
     * @param list<BaseMessage> $messages
     */
    private static function isSafeCutoffPoint(array $messages, int $cutoffIndex): bool
    {
        if ($cutoffIndex >= \count($messages)) {
            return true;
        }

        // The preserved messages must not start with an AI message that has tool calls.
        if ($messages[$cutoffIndex] instanceof AIMessage && AgentUtils::hasToolCalls($messages[$cutoffIndex])) {
            return false;
        }

        $searchStart = max(0, $cutoffIndex - self::SEARCH_RANGE_FOR_TOOL_PAIRS);
        $searchEnd = min(\count($messages), $cutoffIndex + self::SEARCH_RANGE_FOR_TOOL_PAIRS);

        for ($i = $searchStart; $i < $searchEnd; ++$i) {
            if (!AgentUtils::hasToolCalls($messages[$i])) {
                continue;
            }

            $toolCallIds = self::extractToolCallIds($messages[$i]);
            if (self::cutoffSeparatesToolPair($messages, $i, $cutoffIndex, $toolCallIds)) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, true> */
    private static function extractToolCallIds(AIMessage $aiMessage): array
    {
        $ids = [];
        foreach ($aiMessage->toolCalls as $toolCall) {
            $id = $toolCall['id'] ?? null;
            if ($id !== null && $id !== '') {
                $ids[$id] = true;
            }
        }

        return $ids;
    }

    /**
     * A safe cutoff that doesn't split AI/Tool message pairs.
     *
     * If the message at the cutoff is a ToolMessage, searches backward for the AIMessage holding the matching
     * tool calls and moves the cutoff to it. With no match (an orphan), advances past the ToolMessages.
     *
     * @param list<BaseMessage> $messages
     */
    private static function findSafeCutoffPoint(array $messages, int $cutoffIndex): int
    {
        if ($cutoffIndex >= \count($messages) || !$messages[$cutoffIndex] instanceof ToolMessage) {
            return $cutoffIndex;
        }

        // Collect the tool_call_ids of the consecutive ToolMessages at and after the cutoff.
        $toolCallIds = [];
        $idx = $cutoffIndex;
        while ($idx < \count($messages) && $messages[$idx] instanceof ToolMessage) {
            if ($messages[$idx]->toolCallId !== '') {
                $toolCallIds[$messages[$idx]->toolCallId] = true;
            }
            ++$idx;
        }

        // Search backward for the AIMessage with matching tool calls.
        for ($i = $cutoffIndex - 1; $i >= 0; --$i) {
            $message = $messages[$i];
            if ($message instanceof AIMessage && AgentUtils::hasToolCalls($message)) {
                $aiToolCallIds = self::extractToolCallIds($message);
                foreach (array_keys($toolCallIds) as $id) {
                    if (isset($aiToolCallIds[$id])) {
                        return $i;
                    }
                }
            }
        }

        // No matching AIMessage: advance past the ToolMessages to avoid orphaned tool responses.
        return $idx;
    }

    /**
     * Whether the cutoff separates an AI message from the tool messages answering it.
     *
     * @param list<BaseMessage>   $messages
     * @param array<string, true> $toolCallIds
     */
    private static function cutoffSeparatesToolPair(array $messages, int $aiMessageIndex, int $cutoffIndex, array $toolCallIds): bool
    {
        for ($j = $aiMessageIndex + 1; $j < \count($messages); ++$j) {
            $message = $messages[$j];
            if ($message instanceof ToolMessage && isset($toolCallIds[$message->toolCallId])) {
                if (($aiMessageIndex < $cutoffIndex) !== ($j < $cutoffIndex)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Generate the summary for the messages.
     *
     * The call inherits the parent run's config so LangGraph's handlers can track and tag it.
     *
     * @param list<BaseMessage> $messagesToSummarize
     */
    private static function createSummary(
        array $messagesToSummarize,
        object $model,
        string $summaryPrompt,
        callable $tokenCounter,
        int|float|null $trimTokensToSummarize,
        mixed $runtime,
    ): string {
        if ($messagesToSummarize === []) {
            return 'No previous conversation history.';
        }

        $trimmedMessages = self::trimMessagesForSummary($messagesToSummarize, $tokenCounter, $trimTokensToSummarize);
        if ($trimmedMessages === []) {
            return 'Previous conversation was too long to summarize.';
        }

        // getBufferString keeps the prompt compact: no JSON of message metadata.
        $formattedMessages = MessageUtils::getBufferString($trimmedMessages);

        try {
            $position = strpos($summaryPrompt, '{messages}');
            $formattedPrompt = $position === false
                ? $summaryPrompt
                : substr_replace($summaryPrompt, $formattedMessages, $position, \strlen('{messages}'));

            $config = RunnableConfig::mergeConfigs(
                self::pickRunnableConfigKeys($runtime),
                ['metadata' => ['lc_source' => 'summarization'], 'tags' => [Constants::INTERNAL_CALL_TAG]],
            );
            $response = $model->invoke($formattedPrompt, $config);
            $content = \is_array($response) ? ($response['content'] ?? null) : ($response->content ?? null);

            if (\is_string($content)) {
                return trim($content);
            }
            if (\is_array($content)) {
                $text = '';
                foreach ($content as $item) {
                    if (\is_string($item)) {
                        $text .= $item;
                    } elseif (\is_array($item) && \array_key_exists('text', $item)) {
                        $text .= (string) $item['text'];
                    }
                }

                return trim($text);
            }

            return 'Error generating summary: Invalid response format';
        } catch (\Throwable $e) {
            return 'Error generating summary: ' . (new \ReflectionClass($e))->getShortName() . ': ' . $e->getMessage();
        }
    }

    /**
     * The config keys a child call inherits from the parent run.
     *
     * Port of `pickRunnableConfigKeys(runtime)`. Upstream's runtime IS the node config; here a
     * {@see Runtime} carries only part of it, so the config of the running task supplies the callbacks,
     * tags and metadata. An object or array runtime exposing the keys itself is read as it is.
     */
    private static function pickRunnableConfigKeys(mixed $runtime): RunnableConfig
    {
        $source = $runtime;
        if ($runtime instanceof Runtime) {
            $source = PregelScratchpad::currentConfig() ?? ['configurable' => $runtime->configurable, 'signal' => $runtime->signal];
        }

        $get = static function (string $key) use ($source): mixed {
            if (\is_array($source)) {
                return $source[$key] ?? null;
            }

            return \is_object($source) ? (get_object_vars($source)[$key] ?? null) : null;
        };

        return new RunnableConfig(
            tags: (array) ($get('tags') ?? []),
            metadata: (array) ($get('metadata') ?? []),
            callbacks: (array) ($get('callbacks') ?? []),
            maxConcurrency: $get('maxConcurrency'),
            recursionLimit: $get('recursionLimit') ?? 25,
            signal: $get('signal'),
            configurable: (array) ($get('configurable') ?? []),
        );
    }

    /**
     * Trim the messages to fit the summary generation limit: the last messages, keeping a leading system
     * message, with the message that straddles the limit cut down if it can be.
     *
     * @param list<BaseMessage> $messages
     * @return list<BaseMessage>
     */
    private static function trimMessagesForSummary(array $messages, callable $tokenCounter, int|float|null $trimTokensToSummarize): array
    {
        if ($trimTokensToSummarize === null) {
            return $messages;
        }

        try {
            return self::trimMessagesLast($messages, $trimTokensToSummarize, $tokenCounter);
        } catch (\Throwable) {
            // Fall back to the last N messages if trimming fails.
            return \array_slice($messages, -self::DEFAULT_FALLBACK_MESSAGE_COUNT);
        }
    }

    /**
     * `trimMessages(messages, {maxTokens, tokenCounter, strategy: "last", allowPartial: true, includeSystem: true})`.
     *
     * The slice of `@langchain/core/messages/transformers` the summarizer needs (`_lastMaxTokens` over
     * `_firstMaxTokens`, no `startOn`/`endOn`, default newline text splitter). The messages are copied first,
     * so partial trimming never touches the conversation.
     *
     * @param list<BaseMessage> $messages
     * @return list<BaseMessage>
     */
    private static function trimMessagesLast(array $messages, int|float $maxTokens, callable $tokenCounter): array
    {
        $messagesCopy = array_map(static fn (BaseMessage $message): BaseMessage => clone $message, $messages);

        $swappedSystem = $messagesCopy !== [] && $messagesCopy[0] instanceof SystemMessage;
        $reversed = $swappedSystem
            ? [$messagesCopy[0], ...array_reverse(\array_slice($messagesCopy, 1))]
            : array_reverse($messagesCopy);

        $reversed = self::firstMaxTokens($reversed, $maxTokens, $tokenCounter);

        if ($swappedSystem) {
            return $reversed === [] ? [] : [$reversed[0], ...array_reverse(\array_slice($reversed, 1))];
        }

        return array_reverse($reversed);
    }

    /**
     * `_firstMaxTokens` with `partialStrategy: "last"`.
     *
     * @param list<BaseMessage> $messages
     * @return list<BaseMessage>
     */
    private static function firstMaxTokens(array $messages, int|float $maxTokens, callable $tokenCounter): array
    {
        $messagesCopy = $messages;
        $count = \count($messagesCopy);
        $idx = 0;
        for ($i = 0; $i < $count; ++$i) {
            $remaining = $i > 0 ? \array_slice($messagesCopy, 0, -$i) : $messagesCopy;
            if ($tokenCounter($remaining) <= $maxTokens) {
                $idx = $count - $i;
                break;
            }
        }

        if ($idx < \count($messagesCopy)) {
            $includedPartial = false;
            $excluded = $messagesCopy[$idx];

            if (\is_array($excluded->content)) {
                $numBlock = \count($excluded->content);
                $reversedContent = array_reverse($excluded->content);
                for ($i = 1; $i <= $numBlock; ++$i) {
                    $updatedMessage = clone $excluded;
                    $updatedMessage->content = array_values(\array_slice($reversedContent, -$i));
                    $slicedMessages = [...\array_slice($messagesCopy, 0, $idx), $updatedMessage];
                    if ($tokenCounter($slicedMessages) <= $maxTokens) {
                        $messagesCopy = $slicedMessages;
                        ++$idx;
                        $includedPartial = true;
                    } else {
                        break;
                    }
                }
                if ($includedPartial) {
                    $excluded->content = array_reverse($reversedContent);
                }
            }

            if (!$includedPartial) {
                $excluded = $messagesCopy[$idx];
                $text = null;
                if (\is_array($excluded->content)
                    && array_filter($excluded->content, static fn (mixed $block): bool => \is_string($block) || (\is_array($block) && ($block['type'] ?? null) === 'text')) !== []) {
                    foreach ($excluded->content as $block) {
                        if (\is_array($block) && ($block['type'] ?? null) === 'text' && ($block['text'] ?? '') !== '') {
                            $text = $block['text'];
                            break;
                        }
                    }
                } elseif (\is_string($excluded->content)) {
                    $text = $excluded->content;
                }

                if ($text !== null && $text !== '') {
                    // The default text splitter: lines, each keeping its newline.
                    $parts = explode("\n", $text);
                    $last = array_pop($parts);
                    $splitTexts = [...array_map(static fn (string $s): string => $s . "\n", $parts), $last];
                    $numSplits = \count($splitTexts);
                    $splitTexts = array_reverse($splitTexts);
                    for ($n = 0; $n < $numSplits - 1; ++$n) {
                        array_pop($splitTexts);
                        $excluded->content = implode('', $splitTexts);
                        if ($tokenCounter([...\array_slice($messagesCopy, 0, $idx), $excluded]) <= $maxTokens) {
                            $excluded->content = implode('', array_reverse($splitTexts));
                            $messagesCopy = [...\array_slice($messagesCopy, 0, $idx), $excluded];
                            ++$idx;
                            break;
                        }
                    }
                }
            }
        }

        return \array_slice($messagesCopy, 0, $idx);
    }

    /**
     * Context window size by model name.
     *
     * Port of `getModelContextSize` (and `getModelNameForTiktoken`) from `@langchain/core/language_models/base`.
     */
    public static function getModelContextSize(string $modelName): int
    {
        $normalized = match (true) {
            str_starts_with($modelName, 'gpt-5') => 'gpt-5',
            str_starts_with($modelName, 'gpt-3.5-turbo-16k') => 'gpt-3.5-turbo-16k',
            str_starts_with($modelName, 'gpt-3.5-turbo-') => 'gpt-3.5-turbo',
            str_starts_with($modelName, 'gpt-4-32k') => 'gpt-4-32k',
            str_starts_with($modelName, 'gpt-4-') => 'gpt-4',
            str_starts_with($modelName, 'gpt-4o') => 'gpt-4o',
            default => $modelName,
        };

        return match ($normalized) {
            'gpt-5', 'gpt-5-turbo', 'gpt-5-turbo-preview' => 400000,
            'gpt-4o', 'gpt-4o-mini', 'gpt-4o-2024-05-13', 'gpt-4o-2024-08-06' => 128000,
            'gpt-4-turbo', 'gpt-4-turbo-preview', 'gpt-4-turbo-2024-04-09', 'gpt-4-0125-preview', 'gpt-4-1106-preview' => 128000,
            'gpt-4-32k', 'gpt-4-32k-0314', 'gpt-4-32k-0613' => 32768,
            'gpt-4', 'gpt-4-0314', 'gpt-4-0613' => 8192,
            'gpt-3.5-turbo-16k', 'gpt-3.5-turbo-16k-0613' => 16384,
            'gpt-3.5-turbo', 'gpt-3.5-turbo-0301', 'gpt-3.5-turbo-0613', 'gpt-3.5-turbo-1106', 'gpt-3.5-turbo-0125' => 4096,
            'text-davinci-003', 'text-davinci-002' => 4097,
            'text-davinci-001' => 2049,
            'text-curie-001', 'text-babbage-001', 'text-ada-001' => 2048,
            'code-davinci-002', 'code-davinci-001' => 8000,
            'code-cushman-001' => 2048,
            'claude-3-5-sonnet-20241022', 'claude-3-5-sonnet-20240620', 'claude-3-opus-20240229', 'claude-3-sonnet-20240229', 'claude-3-haiku-20240307', 'claude-2.1' => 200000,
            'claude-2.0', 'claude-instant-1.2' => 100000,
            'gemini-1.5-pro', 'gemini-1.5-pro-latest', 'gemini-1.5-flash', 'gemini-1.5-flash-latest' => 1000000,
            'gemini-pro', 'gemini-pro-vision' => 32768,
            default => 4097,
        };
    }
}
