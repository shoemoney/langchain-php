<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware\Provider\OpenAI;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangGraph\Agents\Middleware;

/**
 * Moderates agent traffic with OpenAI's moderation endpoint.
 *
 * Port of `openAIModerationMiddleware` from `langchain/src/agents/middleware/provider/openai/moderation.ts`.
 *
 * Checks at three stages: the last user message and (optionally) the tool results before the model runs, and
 * the model's reply after. A flagged message is handled by `exitBehavior`: "end" (default) jumps to the end
 * with a violation message, "error" throws an {@see OpenAIModerationError}, "replace" swaps the flagged
 * message's content for the violation message and carries on.
 *
 * Options: `model` (an OpenAI chat model, or a model string resolved lazily with {@see \LangChain\LanguageModels\Chat\Universal\InitChatModel::init()}
 * the first time a check runs), `moderationModel` (default "omni-moderation-latest"), `checkInput` (true),
 * `checkOutput` (true), `checkToolResults` (false), `exitBehavior`, and `violationMessage`, a template with the
 * placeholders `{categories}`, `{category_scores}` and `{original_content}`.
 *
 * The model is recognised as OpenAI by the name it reports (`ChatOpenAI`, any variant), and supplies the
 * credentials and transport the {@see ModerationClient} posts `/moderations` with.
 *
 * ```
 * $agent = Agent::create([
 *     'model' => $chatOpenAI,
 *     'middleware' => [ModerationMiddleware::create(['model' => $chatOpenAI, 'exitBehavior' => 'end'])],
 * ]);
 * ```
 */
final class ModerationMiddleware
{
    private const DEFAULT_VIOLATION_TEMPLATE = "I'm sorry, but I can't comply with that request. It was flagged for {categories}.";

    private function __construct()
    {
    }

    /**
     * @param array{model: string|object, moderationModel?: string, checkInput?: bool, checkOutput?: bool, checkToolResults?: bool, exitBehavior?: string, violationMessage?: string|null} $options
     * @return array<string, mixed> the middleware
     */
    public static function create(array $options): array
    {
        $model = $options['model'];
        $moderationModel = $options['moderationModel'] ?? 'omni-moderation-latest';
        $checkInput = $options['checkInput'] ?? true;
        $checkOutput = $options['checkOutput'] ?? true;
        $checkToolResults = $options['checkToolResults'] ?? false;
        $exitBehavior = $options['exitBehavior'] ?? 'end';
        $violationMessage = $options['violationMessage'] ?? null;

        /** @var ModerationClient|null $client */
        $client = null;
        $moderate = static function (string $text) use ($model, $moderationModel, &$client): ?array {
            $client ??= self::initModerationClient($model);
            $response = $client->create(['input' => $text, 'model' => $moderationModel]);
            foreach ($response['results'] ?? [] as $result) {
                if (($result['flagged'] ?? false) === true) {
                    return $result;
                }
            }

            return null;
        };

        $applyViolation = static function (array $messages, ?int $index, string $stage, string $content, array $result) use ($exitBehavior, $violationMessage): ?array {
            $violationText = self::formatViolationMessage($content, $result, $violationMessage);

            if ($exitBehavior === 'error') {
                throw new OpenAIModerationError($content, $stage, $result, $violationText);
            }
            if ($exitBehavior === 'end') {
                return ['jumpTo' => 'end', 'messages' => [new AIMessage(['content' => $violationText])]];
            }
            if ($index === null) {
                return null;
            }

            // Replace the original message with one that carries the violation text.
            $replacement = clone $messages[$index];
            $replacement->content = $violationText;
            $messages[$index] = $replacement;

            return ['messages' => $messages];
        };

        $moderateUserMessage = static function (array $messages) use ($moderate, $applyViolation): ?array {
            $idx = self::findLastIndex($messages, HumanMessage::class);
            $text = $idx === null ? null : self::extractText($messages[$idx]);
            if ($idx === null || $text === null) {
                return null;
            }

            $flagged = $moderate($text);

            return $flagged === null ? null : $applyViolation($messages, $idx, 'input', $text, $flagged);
        };

        $moderateToolMessages = static function (array $messages) use ($moderate, $applyViolation): ?array {
            $lastAiIdx = self::findLastIndex($messages, AIMessage::class);
            if ($lastAiIdx === null) {
                return null;
            }

            $working = $messages;
            $modified = false;
            for ($idx = $lastAiIdx + 1; $idx < \count($working); $idx++) {
                $message = $working[$idx];
                $text = $message instanceof ToolMessage ? self::extractText($message) : null;
                if ($text === null) {
                    continue;
                }

                $flagged = $moderate($text);
                if ($flagged === null) {
                    continue;
                }

                $action = $applyViolation($working, $idx, 'tool', $text, $flagged);
                if ($action !== null) {
                    if (\array_key_exists('jumpTo', $action)) {
                        return $action;
                    }
                    $working = $action['messages'];
                    $modified = true;
                }
            }

            return $modified ? ['messages' => $working] : null;
        };

        $moderateOutput = static function (array $messages) use ($moderate, $applyViolation): ?array {
            $lastAiIdx = self::findLastIndex($messages, AIMessage::class);
            $text = $lastAiIdx === null ? null : self::extractText($messages[$lastAiIdx]);
            if ($lastAiIdx === null || $text === null) {
                return null;
            }

            $flagged = $moderate($text);

            return $flagged === null ? null : $applyViolation($messages, $lastAiIdx, 'output', $text, $flagged);
        };

        $moderateInputs = static function (array $messages) use ($checkInput, $checkToolResults, $moderateToolMessages, $moderateUserMessage): ?array {
            $working = $messages;
            $modified = false;

            foreach ([[$checkToolResults, $moderateToolMessages], [$checkInput, $moderateUserMessage]] as [$enabled, $check]) {
                if (!$enabled) {
                    continue;
                }
                $action = $check($working);
                if ($action === null) {
                    continue;
                }
                if (\array_key_exists('jumpTo', $action)) {
                    return $action;
                }
                $working = $action['messages'];
                $modified = true;
            }

            return $modified ? ['messages' => $working] : null;
        };

        return Middleware::create([
            'name' => 'OpenAIModerationMiddleware',
            'beforeModel' => [
                'hook' => static function (array $state) use ($checkInput, $checkToolResults, $moderateInputs): ?array {
                    if (!$checkInput && !$checkToolResults) {
                        return null;
                    }
                    $messages = array_values((array) ($state['messages'] ?? []));

                    return $messages === [] ? null : $moderateInputs($messages);
                },
                'canJumpTo' => ['end'],
            ],
            'afterModel' => [
                'hook' => static function (array $state) use ($checkOutput, $moderateOutput): ?array {
                    if (!$checkOutput) {
                        return null;
                    }
                    $messages = array_values((array) ($state['messages'] ?? []));

                    return $messages === [] ? null : $moderateOutput($messages);
                },
                'canJumpTo' => ['end'],
            ],
        ]);
    }

    /** Resolve and validate the moderation model, then build the client that talks to its account. */
    private static function initModerationClient(string|object $model): ModerationClient
    {
        if (\is_string($model)) {
            $model = \LangChain\LanguageModels\Chat\Universal\InitChatModel::init($model);
        }
        // `init()` wraps the client; moderation needs the client's own credentials and transport.
        if ($model instanceof \LangChain\LanguageModels\Chat\Universal\ConfigurableModel) {
            $model = $model->getModelInstance();
        }

        $name = \is_object($model) && method_exists($model, 'getName') ? (string) $model->getName() : get_debug_type($model);
        if (!str_contains($name, 'ChatOpenAI')) {
            throw new \Exception("Model must be an OpenAI model to use moderation middleware. Got: {$name}");
        }

        // A model that carries no credentials and transport cannot be moderated through.
        if (!property_exists($model, 'apiKey') || !property_exists($model, 'httpClient')) {
            throw new \Exception('Model must support moderation to use moderation middleware.');
        }

        return ModerationClient::fromModel($model);
    }

    /** The message's text, or null when it has none. */
    private static function extractText(BaseMessage $message): ?string
    {
        $text = $message->text();

        return $text === '' ? null : $text;
    }

    /**
     * @param list<BaseMessage>         $messages
     * @param class-string<BaseMessage> $class
     */
    private static function findLastIndex(array $messages, string $class): ?int
    {
        for ($idx = \count($messages) - 1; $idx >= 0; $idx--) {
            if ($messages[$idx] instanceof $class) {
                return $idx;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $result */
    private static function formatViolationMessage(string $content, array $result, ?string $violationMessage): string
    {
        $categories = [];
        foreach ((array) ($result['categories'] ?? []) as $name => $flagged) {
            if ($flagged) {
                $categories[] = str_replace('_', ' ', (string) $name);
            }
        }
        $categoryLabel = $categories !== [] ? implode(', ', $categories) : "OpenAI's safety policies";

        $template = $violationMessage !== null && $violationMessage !== '' ? $violationMessage : self::DEFAULT_VIOLATION_TEMPLATE;

        $scores = (array) ($result['category_scores'] ?? []);
        // JSON.stringify(scores, null, 2): two-space indent, `{}` when empty.
        $scoresJson = (string) preg_replace_callback(
            '/^ +/m',
            static fn (array $m): string => str_repeat(' ', intdiv(\strlen($m[0]), 2)),
            (string) json_encode($scores === [] ? new \stdClass() : $scores, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
        );

        // Like String#replace with a string pattern: the first occurrence of each placeholder only.
        $message = $template;
        foreach (['{categories}' => $categoryLabel, '{category_scores}' => $scoresJson, '{original_content}' => $content] as $placeholder => $value) {
            $at = strpos($message, $placeholder);
            if ($at !== false) {
                $message = substr_replace($message, $value, $at, \strlen($placeholder));
            }
        }

        return $message;
    }
}
