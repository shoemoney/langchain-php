<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\MessageMerge;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tools\ToolUtils;
use LangChain\Utils\FunctionCalling;
use LangGraph\Agents\Nodes\Utils as NodeUtils;
use LangGraph\Agents\Utils as AgentUtils;

/**
 * Helpers shared by the middleware machinery.
 *
 * Port of `langchain/src/agents/middleware/utils.ts`, plus the two helpers the middleware nodes need that
 * upstream gets from Zod (`parseContext`) and from `SystemMessage#concat` (`concatSystemMessage`).
 */
final class Utils
{
    private function __construct()
    {
    }

    /**
     * Default token counter that approximates based on character count (4 characters per token).
     *
     * If tools are provided, the token count also includes stringified tool schemas; a LangChain tool is
     * converted to the OpenAI tool format before counting.
     *
     * @param list<BaseMessage>              $messages
     * @param list<mixed>|null               $tools
     */
    public static function countTokensApproximately(array $messages, ?array $tools = null): int
    {
        $charsPerToken = 4;
        $totalChars = 0;

        if ($tools !== null && $tools !== []) {
            foreach ($tools as $tool) {
                $toolDict = ToolUtils::isLangChainTool($tool) ? FunctionCalling::convertToOpenAITool($tool) : $tool;
                $totalChars += self::length((string) json_encode($toolDict, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
            }
        }

        foreach ($messages as $message) {
            if (\is_string($message->content)) {
                $textContent = $message->content;
            } else {
                $textContent = '';
                foreach ($message->content as $item) {
                    if (\is_string($item)) {
                        $textContent .= $item;
                    } elseif (\is_array($item) && ($item['type'] ?? null) === 'text' && \array_key_exists('text', $item)) {
                        $textContent .= (string) $item['text'];
                    }
                }
            }

            if ($message instanceof AIMessage && $message->toolCalls !== []) {
                $textContent .= (string) json_encode($message->toolCalls, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
            }

            if ($message instanceof ToolMessage) {
                $textContent .= $message->toolCallId;
            }

            $totalChars += self::length($textContent);
        }

        return (int) ceil($totalChars / $charsPerToken);
    }

    /**
     * The jump targets a hook declares it may use, or null for a bare callable.
     *
     * @param callable|array{hook: callable, canJumpTo?: list<string>}|null $hook
     * @return list<string>|null
     */
    public static function getHookConstraint(mixed $hook): ?array
    {
        if ($hook === null || self::isBareHook($hook)) {
            return null;
        }

        return \is_array($hook) ? ($hook['canJumpTo'] ?? null) : null;
    }

    /**
     * The function behind a hook, whichever form it was declared in.
     *
     * @param callable|array{hook: callable, canJumpTo?: list<string>} $hook
     */
    public static function getHookFunction(mixed $hook): callable
    {
        if (self::isBareHook($hook)) {
            return $hook;
        }

        return $hook['hook'];
    }

    /** A hook given as a plain callable (not the `['hook' => ..., 'canJumpTo' => ...]` form). */
    private static function isBareHook(mixed $hook): bool
    {
        return \is_callable($hook) && !(\is_array($hook) && \array_key_exists('hook', $hook));
    }

    /**
     * Sleep for the specified number of milliseconds.
     */
    public static function sleep(int|float $ms): void
    {
        if ($ms > 0) {
            usleep((int) round($ms * 1000));
        }
    }

    /**
     * Calculate the delay for a retry attempt with exponential backoff and jitter.
     *
     * @param array{backoffFactor: int|float, initialDelayMs: int|float, maxDelayMs: int|float, jitter: bool} $config
     * @param int                                                                                              $retryNumber  The retry attempt number (0-indexed).
     * @param int|float|null                                                                                   $retryAfterMs A provider-supplied wait hint (e.g. a `Retry-After` header),
     *                                                                                                         used as a floor so a retry is never sooner than the server asked.
     * @return int|float Delay in milliseconds before the next retry.
     */
    public static function calculateRetryDelay(array $config, int $retryNumber, int|float|null $retryAfterMs = null): int|float
    {
        $backoffFactor = $config['backoffFactor'];
        $initialDelayMs = $config['initialDelayMs'];
        $maxDelayMs = $config['maxDelayMs'];

        $delay = $backoffFactor == 0.0 ? $initialDelayMs : $initialDelayMs * ($backoffFactor ** $retryNumber);

        // Cap at maxDelayMs.
        $delay = min($delay, $maxDelayMs);

        if (($config['jitter'] ?? false) && $delay > 0) {
            $jitterAmount = $delay * 0.25;
            $delay += (mt_rand() / mt_getrandmax() * 2 - 1) * $jitterAmount;
            // Ensure the delay is not negative after jitter.
            $delay = max(0, $delay);
        }

        return max($delay, $retryAfterMs ?? 0);
    }

    /**
     * The `retryAfterMs` an error carries (a public property or a `getRetryAfterMs()` method), if any.
     */
    public static function getRetryAfterMs(mixed $error): int|float|null
    {
        if (!\is_object($error)) {
            return null;
        }

        $value = null;
        if (method_exists($error, 'getRetryAfterMs')) {
            $value = $error->getRetryAfterMs();
        } elseif (property_exists($error, 'retryAfterMs')) {
            $value = $error->retryAfterMs;
        }

        return (\is_int($value) || \is_float($value)) && $value >= 0 ? $value : null;
    }

    /**
     * Parse the run context through a middleware's context schema.
     *
     * Upstream: `interopParse(contextSchema, relevantContext)` after picking only the keys the schema's
     * shape declares. Here the schema is a JSON Schema array: unknown keys are dropped, `default` values fill
     * gaps, and a `required` property that is still missing raises.
     *
     * @param array<string, mixed>|null $contextSchema
     * @param mixed                     $context       The run context (an array, or null for none).
     * @return array<string, mixed>
     * @throws \InvalidArgumentException when a required property is missing and has no default
     */
    public static function parseContext(?array $contextSchema, mixed $context, string $middlewareName = ''): array
    {
        if ($contextSchema === null || AgentUtils::schemaKeys($contextSchema) === null) {
            return [];
        }

        $source = \is_array($context) ? $context : (\is_object($context) ? get_object_vars($context) : []);
        $required = array_map('strval', (array) ($contextSchema['required'] ?? []));
        $parsed = [];
        $missing = [];

        foreach (NodeUtils::getSchemaShape($contextSchema) as $key => $property) {
            if (\array_key_exists($key, $source)) {
                $parsed[$key] = $source[$key];
            } elseif (\is_array($property) && \array_key_exists('default', $property)) {
                $parsed[$key] = $property['default'];
            } elseif (\in_array($key, $required, true)) {
                $missing[] = $key;
            }
        }

        if ($missing !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid context%s: %s',
                $middlewareName !== '' ? ' for middleware "' . $middlewareName . '"' : '',
                implode(', ', array_map(static fn (string $key): string => $key . ': Required', $missing)),
            ));
        }

        return $parsed;
    }

    /**
     * Concatenate a string or another system message onto a system message.
     *
     * Port of `SystemMessage#concat` (`@langchain/core/messages/system`), which the port's `SystemMessage`
     * does not have yet. Content merges the way `mergeContent` merges it (a string appended to block
     * content becomes one more text block); metadata and the id/name keep the receiver's value first.
     */
    public static function concatSystemMessage(SystemMessage $message, string|SystemMessage $chunk): SystemMessage
    {
        if (\is_string($chunk)) {
            return new SystemMessage([
                'content' => MessageMerge::mergeContent($message->content, $chunk),
                'additional_kwargs' => $message->additional_kwargs,
                'response_metadata' => $message->response_metadata,
                'id' => $message->id,
                'name' => $message->name,
            ]);
        }

        return new SystemMessage([
            'content' => MessageMerge::mergeContent($message->content, $chunk->content),
            'additional_kwargs' => [...$message->additional_kwargs, ...$chunk->additional_kwargs],
            'response_metadata' => [...$message->response_metadata, ...$chunk->response_metadata],
            'id' => $message->id ?? $chunk->id,
            'name' => $message->name ?? $chunk->name,
        ]);
    }

    /** JavaScript's `typeof`, for error messages that name what a hook returned. */
    public static function typeOf(mixed $value): string
    {
        return match (true) {
            $value === null => 'undefined',
            \is_string($value) => 'string',
            \is_int($value), \is_float($value) => 'number',
            \is_bool($value) => 'boolean',
            $value instanceof \Closure => 'function',
            default => 'object',
        };
    }

    private static function length(string $text): int
    {
        return mb_strlen($text);
    }
}
