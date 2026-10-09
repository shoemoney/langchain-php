<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangGraph\Agents\Middleware;

/**
 * Creates a middleware that detects and handles personally identifiable information (PII) in conversations.
 *
 * Port of `piiMiddleware` from `langchain/src/agents/middleware/pii.ts`. The detectors, the strategies and
 * `resolveRedactionRule` live in {@see PiiDetectors}; the error is {@see PiiDetectionError}.
 *
 * Built-in PII types: `email`, `credit_card` (Luhn validated), `ip` (octets validated), `mac_address`, `url`.
 * Strategies: `block` (throw {@see PiiDetectionError}), `redact` (`[REDACTED_TYPE]`), `mask` (for example
 * `****-****-****-1234`) and `hash` (`<email_hash:a1b2c3d4>`, SHA-256).
 *
 * ```
 * $agent = Agent::create([
 *     'model' => $model,
 *     'middleware' => [
 *         PiiMiddleware::create('email', ['strategy' => 'redact']),
 *         PiiMiddleware::create('credit_card', ['strategy' => 'mask']),
 *         PiiMiddleware::create('api_key', ['detector' => 'sk-[a-zA-Z0-9]{32}', 'strategy' => 'block']),
 *     ],
 * ]);
 * ```
 *
 * Options: `strategy` (default `redact`), `detector` (a callable, a delimited PCRE string or a bare pattern
 * string, see {@see PiiDetectors}), `applyToInput` (default true: the last user message before the model call),
 * `applyToOutput` (default false: the last AI message after the model call) and `applyToToolResults` (default
 * false: the tool messages after the last AI message). The run context carries the same three flags and wins
 * over the options.
 *
 * Differences from upstream: message content that is a list of blocks is scanned as its text (upstream calls
 * `String()` on the block array, which yields `[object Object]`), and offsets are bytes (see {@see PiiDetectors}).
 */
final class PiiMiddleware
{
    private function __construct()
    {
    }

    /**
     * @param array{strategy?: string, detector?: callable|string|null, applyToInput?: bool, applyToOutput?: bool, applyToToolResults?: bool} $options
     * @return array<string, mixed> the middleware
     * @throws \InvalidArgumentException when `$piiType` is not built in and there is no detector
     */
    public static function create(string $piiType, array $options = []): array
    {
        $rule = PiiDetectors::resolveRedactionRule([
            'piiType' => $piiType,
            'strategy' => $options['strategy'] ?? 'redact',
            'detector' => $options['detector'] ?? null,
        ]);

        return Middleware::create([
            'name' => 'PIIMiddleware[' . $rule['piiType'] . ']',
            'contextSchema' => [
                'type' => 'object',
                'properties' => [
                    'applyToInput' => ['type' => 'boolean'],
                    'applyToOutput' => ['type' => 'boolean'],
                    'applyToToolResults' => ['type' => 'boolean'],
                ],
            ],
            'beforeModel' => static function (array $state, mixed $runtime) use ($options, $rule): ?array {
                $context = self::contextOf($runtime);
                $applyToInput = $context['applyToInput'] ?? $options['applyToInput'] ?? true;
                $applyToToolResults = $context['applyToToolResults'] ?? $options['applyToToolResults'] ?? false;

                if (!$applyToInput && !$applyToToolResults) {
                    return null;
                }

                $messages = $state['messages'] ?? [];
                if ($messages === []) {
                    return null;
                }

                $newMessages = array_values($messages);
                $anyModified = false;

                // Check the last user message.
                if ($applyToInput) {
                    $lastUserIdx = self::lastIndexOf($messages, HumanMessage::class);
                    $lastUserMessage = $lastUserIdx !== null ? $messages[$lastUserIdx] : null;
                    if ($lastUserMessage !== null && !self::isEmpty($lastUserMessage)) {
                        ['content' => $newContent, 'matches' => $matches] = self::processContent(self::textOf($lastUserMessage), $rule);
                        if ($matches !== []) {
                            $newMessages[$lastUserIdx] = new HumanMessage([
                                'content' => $newContent,
                                'id' => $lastUserMessage->id,
                                'name' => $lastUserMessage->name,
                            ]);
                            $anyModified = true;
                        }
                    }
                }

                // Check the tool messages after the last AI message.
                if ($applyToToolResults) {
                    $lastAiIdx = self::lastIndexOf($messages, AIMessage::class);
                    if ($lastAiIdx !== null) {
                        for ($i = $lastAiIdx + 1; $i < \count($messages); ++$i) {
                            $message = $messages[$i];
                            if (!$message instanceof ToolMessage || self::isEmpty($message)) {
                                continue;
                            }

                            ['content' => $newContent, 'matches' => $matches] = self::processContent(self::textOf($message), $rule);
                            if ($matches !== []) {
                                $newMessages[$i] = new ToolMessage([
                                    'content' => $newContent,
                                    'id' => $message->id,
                                    'name' => $message->name,
                                    'tool_call_id' => $message->toolCallId,
                                ]);
                                $anyModified = true;
                            }
                        }
                    }
                }

                return $anyModified ? ['messages' => $newMessages] : null;
            },
            'afterModel' => static function (array $state, mixed $runtime) use ($options, $rule): ?array {
                $context = self::contextOf($runtime);
                $applyToOutput = $context['applyToOutput'] ?? $options['applyToOutput'] ?? false;
                if (!$applyToOutput) {
                    return null;
                }

                $messages = $state['messages'] ?? [];
                if ($messages === []) {
                    return null;
                }

                $lastAiIdx = self::lastIndexOf($messages, AIMessage::class);
                if ($lastAiIdx === null || self::isEmpty($messages[$lastAiIdx])) {
                    return null;
                }

                /** @var AIMessage $lastAiMessage */
                $lastAiMessage = $messages[$lastAiIdx];
                ['content' => $newContent, 'matches' => $matches] = self::processContent(self::textOf($lastAiMessage), $rule);
                if ($matches === []) {
                    return null;
                }

                $newMessages = array_values($messages);
                $newMessages[$lastAiIdx] = new AIMessage([
                    'content' => $newContent,
                    'id' => $lastAiMessage->id,
                    'name' => $lastAiMessage->name,
                    'tool_calls' => $lastAiMessage->toolCalls,
                ]);

                return ['messages' => $newMessages];
            },
        ]);
    }

    /**
     * Detect PII in content and apply the rule's strategy.
     *
     * @param array{piiType: string, strategy: string, detector: callable} $rule
     * @return array{content: string, matches: list<array{text: string, start: int, end: int}>}
     */
    private static function processContent(string $content, array $rule): array
    {
        $matches = ($rule['detector'])($content);
        if ($matches === []) {
            return ['content' => $content, 'matches' => []];
        }

        return [
            'content' => PiiDetectors::applyStrategy($content, $matches, $rule['strategy'], $rule['piiType']),
            'matches' => $matches,
        ];
    }

    /**
     * @param list<mixed>        $messages
     * @param class-string<BaseMessage> $class
     */
    private static function lastIndexOf(array $messages, string $class): ?int
    {
        for ($i = \count($messages) - 1; $i >= 0; --$i) {
            if ($messages[$i] instanceof $class) {
                return $i;
            }
        }

        return null;
    }

    /** JavaScript's `!message.content`: the empty string (or no blocks). */
    private static function isEmpty(BaseMessage $message): bool
    {
        return $message->content === '' || $message->content === [];
    }

    private static function textOf(BaseMessage $message): string
    {
        return \is_string($message->content) ? $message->content : $message->text();
    }

    /** @return array<string, mixed> */
    private static function contextOf(mixed $runtime): array
    {
        $context = \is_object($runtime) ? ($runtime->context ?? null) : (\is_array($runtime) ? ($runtime['context'] ?? null) : null);

        return \is_array($context) ? $context : (\is_object($context) ? get_object_vars($context) : []);
    }
}
