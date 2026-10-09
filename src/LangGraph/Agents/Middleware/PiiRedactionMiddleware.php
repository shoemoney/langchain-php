<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\RemoveMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\ToolMessage;
use LangGraph\Agents\Middleware;

/**
 * Redacts PII from the messages sent to the model provider and restores the original values in the model's
 * response, so tools still receive the real data. Deprecated upstream in favour of {@see PiiMiddleware}.
 *
 * Port of `piiRedactionMiddleware` from `langchain/src/agents/middleware/piiRedaction.ts`.
 *
 * Request phase (`wrapModelCall`): every rule's pattern is applied to the content of every message (and to the
 * tool calls of AI messages); each match becomes a marker `[REDACTED_{RULE_NAME}_{ID}]` and the original is
 * remembered under that id. Response phase (`afterModel`): markers in the last AI message are replaced with
 * the remembered values, and a structured response (a final JSON message, or an `extract-*` tool call) is
 * restored too, by removing the AI message(s) and adding the restored copies.
 *
 * `rules` maps a rule name to a complete delimited PCRE such as `'/\b\d{3}-?\d{2}-?\d{4}\b/'` (upstream's global
 * `RegExp`; every match is replaced). Rules come from the options or the run context (`rules`), the context
 * winning. The redaction map lives for the life of the middleware instance, as upstream's does.
 *
 * ```
 * $agent = Agent::create([
 *     'model' => $model,
 *     'tools' => [$lookupUser],
 *     'middleware' => [PiiRedactionMiddleware::create(['rules' => ['ssn' => '/\b\d{3}-?\d{2}-?\d{4}\b/']])],
 * ]);
 * ```
 *
 * Differences from upstream: it warns with `E_USER_DEPRECATED` instead of `console.warn`; message content
 * that is a list of blocks is redacted through its JSON form for every message type (upstream only does so for AI
 * messages and fails on a block list elsewhere); and `afterModel` returns only the changed keys rather than
 * spreading the whole state back.
 */
final class PiiRedactionMiddleware
{
    private function __construct()
    {
    }

    /**
     * @param array{rules?: array<string, string>|null} $options
     * @return array<string, mixed> the middleware
     */
    public static function create(array $options = []): array
    {
        /** @var array<string, string> $redactionMap */
        $redactionMap = [];

        trigger_error(
            'DEPRECATED: piiRedactionMiddleware is deprecated. Please use piiMiddleware instead, go to https://docs.langchain.com/oss/javascript/langchain/middleware/built-in#pii-detection for more information.',
            \E_USER_DEPRECATED,
        );

        return Middleware::create([
            'name' => 'PIIRedactionMiddleware',
            'contextSchema' => [
                'type' => 'object',
                'properties' => [
                    'rules' => ['type' => 'object', 'description' => 'Rule names mapped to the regular expression that matches the PII'],
                ],
            ],
            'wrapModelCall' => static function (array $request, callable $handler) use (&$redactionMap, $options): mixed {
                // Merge the options with the context.
                $context = self::contextOf($request['runtime'] ?? null);
                $rules = $context['rules'] ?? $options['rules'] ?? [];

                // If no rules are provided, skip processing.
                if ($rules === []) {
                    return $handler($request);
                }

                $processedMessages = [];
                foreach (array_values((array) ($request['state']['messages'] ?? [])) as $message) {
                    $processedMessages[] = self::processMessage($message, $rules, $redactionMap);
                }

                return $handler([...$request, 'messages' => $processedMessages]);
            },
            'afterModel' => static function (array $state) use (&$redactionMap): ?array {
                // If no redactions were made, skip processing.
                if ($redactionMap === []) {
                    return null;
                }

                $messages = array_values((array) ($state['messages'] ?? []));
                $lastMessage = $messages !== [] ? $messages[\count($messages) - 1] : null;
                if (!$lastMessage instanceof AIMessage) {
                    return null;
                }

                // With structured output via tool calls a custom last message is added, so the one before it matters too.
                $secondLastMessage = \count($messages) >= 2 ? $messages[\count($messages) - 2] : null;

                ['message' => $restoredLastMessage, 'changed' => $changed] = self::restoreMessage($lastMessage, $redactionMap);
                if (!$changed) {
                    return null;
                }

                // A structured response given as the final JSON message.
                $structuredResponse = null;
                if (
                    $lastMessage->toolCalls === []
                    && \is_string($lastMessage->content)
                    && str_starts_with($lastMessage->content, '{')
                    && str_ends_with($lastMessage->content, '}')
                ) {
                    $decoded = json_decode(self::restoreRedactedValues($lastMessage->content, $redactionMap), true);
                    $structuredResponse = \is_array($decoded) ? $decoded : null;
                }

                // A structured response given as a tool call on the second last message.
                $isStructuredResponseToolCall = $secondLastMessage instanceof AIMessage
                    && $secondLastMessage->toolCalls !== []
                    && self::findExtractCall($secondLastMessage) !== null;
                if ($isStructuredResponseToolCall) {
                    ['message' => $restoredSecondLastMessage, 'changed' => $changedSecondLastMessage] = self::restoreMessage($secondLastMessage, $redactionMap);
                    $redactedArgs = self::findExtractCall($secondLastMessage)['args'] ?? null;
                    $toolStructuredResponse = $redactedArgs !== null && $redactedArgs !== []
                        ? json_decode(self::restoreRedactedValues(self::encode($redactedArgs), $redactionMap), true, 512, \JSON_THROW_ON_ERROR)
                        : null;

                    if ($changed || $changedSecondLastMessage) {
                        return [
                            ...($toolStructuredResponse ? ['structuredResponse' => $toolStructuredResponse] : []),
                            'messages' => [
                                new RemoveMessage(['id' => (string) $secondLastMessage->id]),
                                new RemoveMessage(['id' => (string) $lastMessage->id]),
                                $restoredSecondLastMessage,
                                $restoredLastMessage,
                            ],
                        ];
                    }
                }

                return [
                    ...($structuredResponse ? ['structuredResponse' => $structuredResponse] : []),
                    'messages' => [
                        new RemoveMessage(['id' => (string) $lastMessage->id]),
                        $restoredLastMessage,
                    ],
                ];
            },
        ]);
    }

    /**
     * Apply the PII rules to a single message, recording what was redacted.
     *
     * @param array<string, string> $rules
     * @param array<string, string> $redactionMap
     */
    private static function processMessage(BaseMessage $message, array $rules, array &$redactionMap): BaseMessage
    {
        if ($message instanceof HumanMessage || $message instanceof ToolMessage || $message instanceof SystemMessage) {
            $content = self::contentAsText($message);
            $processed = self::applyPiiRules($content, $rules, $redactionMap);

            return $processed !== $content ? self::rebuild($message, ['content' => self::contentFromText($message, $processed)]) : $message;
        }

        if ($message instanceof AIMessage) {
            $content = self::contentAsText($message);
            $toolCalls = self::encode($message->toolCalls);
            $processedContent = self::applyPiiRules($content, $rules, $redactionMap);
            $processedToolCalls = self::applyPiiRules($toolCalls, $rules, $redactionMap);

            if ($processedContent !== $content || $processedToolCalls !== $toolCalls) {
                return self::rebuild($message, [
                    'content' => self::contentFromText($message, $processedContent),
                    'tool_calls' => json_decode($processedToolCalls, true, 512, \JSON_THROW_ON_ERROR),
                ]);
            }

            return $message;
        }

        throw new \InvalidArgumentException("Unsupported message type: {$message->type}");
    }

    /**
     * Replace every match of every rule with a trackable marker like `[REDACTED_SSN_abc123]`.
     *
     * @param array<string, string> $rules
     * @param array<string, string> $redactionMap
     */
    private static function applyPiiRules(string $text, array $rules, array &$redactionMap): string
    {
        $processedText = $text;

        foreach ($rules as $name => $pattern) {
            $replacement = (string) preg_replace('/[^a-zA-Z0-9_-]/', '', strtoupper((string) $name));
            $result = preg_replace_callback(
                $pattern,
                static function (array $match) use ($replacement, &$redactionMap): string {
                    $id = self::generateRedactionId();
                    $redactionMap[$id] = $match[0];

                    return "[REDACTED_{$replacement}_{$id}]";
                },
                $processedText,
            );
            if ($result === null) {
                throw new \InvalidArgumentException(\sprintf('Invalid PII rule "%s" (%s): %s', $name, $pattern, preg_last_error_msg()));
            }
            $processedText = $result;
        }

        return $processedText;
    }

    /**
     * Restore the original values in a message.
     *
     * @param array<string, string> $redactionMap
     * @return array{message: BaseMessage, changed: bool}
     */
    private static function restoreMessage(BaseMessage $message, array $redactionMap): array
    {
        if ($message instanceof HumanMessage || $message instanceof ToolMessage || $message instanceof SystemMessage) {
            $content = self::contentAsText($message);
            $restored = self::restoreRedactedValues($content, $redactionMap);
            if ($restored !== $content) {
                return ['message' => self::rebuild($message, ['content' => self::contentFromText($message, $restored)]), 'changed' => true];
            }

            return ['message' => $message, 'changed' => false];
        }

        if ($message instanceof AIMessage) {
            $content = self::contentAsText($message);
            $toolCalls = self::encode($message->toolCalls);
            $processedContent = self::restoreRedactedValues($content, $redactionMap);
            $processedToolCalls = self::restoreRedactedValues($toolCalls, $redactionMap);
            if ($processedContent !== $content || $processedToolCalls !== $toolCalls) {
                return [
                    'message' => self::rebuild($message, [
                        'content' => self::contentFromText($message, $processedContent),
                        'tool_calls' => json_decode($processedToolCalls, true, 512, \JSON_THROW_ON_ERROR),
                    ]),
                    'changed' => true,
                ];
            }

            return ['message' => $message, 'changed' => false];
        }

        throw new \InvalidArgumentException("Unsupported message type: {$message->type}");
    }

    /**
     * Restore original values from redacted text, keeping a marker that has no mapping.
     *
     * @param array<string, string> $redactionMap
     */
    private static function restoreRedactedValues(string $text, array $redactionMap): string
    {
        return (string) preg_replace_callback(
            '/\[REDACTED_[A-Z_]+_(\w+)\]/',
            static fn (array $match): string => ($redactionMap[$match[1]] ?? '') !== '' ? $redactionMap[$match[1]] : $match[0],
            $text,
        );
    }

    /** @return array{name: string, args: mixed}|null the first `extract-*` tool call (the structured response tool) */
    private static function findExtractCall(AIMessage $message): ?array
    {
        foreach ($message->toolCalls as $call) {
            if (str_starts_with((string) ($call['name'] ?? ''), 'extract-')) {
                return $call;
            }
        }

        return null;
    }

    /** Upstream's `Math.random().toString(36).substring(2, 11)`: nine base-36 characters. */
    private static function generateRedactionId(): string
    {
        $id = '';
        for ($i = 0; $i < 9; ++$i) {
            $id .= base_convert((string) random_int(0, 35), 10, 36);
        }

        return $id;
    }

    /** A new message of the same class with some fields replaced. */
    private static function rebuild(BaseMessage $message, array $changes): BaseMessage
    {
        $class = $message::class;

        return new $class([...$message->kwargs(), ...$changes]);
    }

    private static function contentAsText(BaseMessage $message): string
    {
        return \is_string($message->content) ? $message->content : self::encode($message->content);
    }

    /** The content to store back: a string stays a string, block content is parsed back from its JSON form. */
    private static function contentFromText(BaseMessage $message, string $text): string|array
    {
        return \is_string($message->content) ? $text : json_decode($text, true, 512, \JSON_THROW_ON_ERROR);
    }

    private static function encode(mixed $value): string
    {
        return (string) json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @return array<string, mixed> */
    private static function contextOf(mixed $runtime): array
    {
        $context = \is_object($runtime) ? ($runtime->context ?? null) : (\is_array($runtime) ? ($runtime['context'] ?? null) : null);

        return \is_array($context) ? $context : (\is_object($context) ? get_object_vars($context) : []);
    }
}
