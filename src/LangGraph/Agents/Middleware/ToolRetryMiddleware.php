<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware;

use LangChain\Messages\ToolMessage;
use LangGraph\Agents\Middleware;
use LangGraph\Errors\Guard;

/**
 * Automatic retry with exponential backoff for failed tool calls.
 *
 * Port of `toolRetryMiddleware` from `langchain/src/agents/middleware/toolRetry.ts`.
 *
 * Options: `maxRetries` (default 2), `tools` (names or tool instances; empty means every tool), `retryOn` (a
 * list of error classes matched with `instanceof`, or a predicate; the default retries unless the error is
 * stamped non-retryable), `onFailure` (`continue`, the default, returns an error ToolMessage; `error`
 * re-throws; `raise` / `return_message` are the deprecated spellings of those two; or `fn(\Throwable):
 * string` for custom content), `backoffFactor` (2.0), `initialDelayMs` (1000), `maxDelayMs` (60000) and
 * `jitter` (true). Invalid options raise an {@see InvalidRetryConfigError}.
 *
 * Graph control flow (an interrupt and every other bubble-up) is re-thrown at once: it is never retried and
 * never reaches `retryOn` or `onFailure`.
 *
 * ```
 * $agent = Agent::create([
 *     'model' => $model,
 *     'tools' => [$searchTool],
 *     'middleware' => [ToolRetryMiddleware::create(['maxRetries' => 3, 'tools' => ['search']])],
 * ]);
 * ```
 */
final class ToolRetryMiddleware
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed> the middleware
     * @throws InvalidRetryConfigError
     * @throws \TypeError for a `tools` entry that is neither a name nor a tool
     */
    public static function create(array $config = []): array
    {
        $parsed = Constants::parseRetrySchema($config);
        $issues = $parsed['success'] ? [] : $parsed['issues'];

        $onFailureConfig = $config['onFailure'] ?? 'continue';
        $literals = ['error', 'continue', 'raise', 'return_message'];
        if (!\in_array($onFailureConfig, $literals, true) && !($onFailureConfig instanceof \Closure) && !(\is_callable($onFailureConfig) && !\is_string($onFailureConfig))) {
            $issues[] = ['path' => ['onFailure'], 'code' => 'invalid_union', 'message' => 'Invalid input'];
        }
        if (isset($config['tools']) && !\is_array($config['tools'])) {
            $issues[] = ['path' => ['tools'], 'code' => 'invalid_type', 'message' => 'Expected array, received ' . get_debug_type($config['tools'])];
        }
        if ($issues !== [] || !$parsed['success']) {
            throw new InvalidRetryConfigError($issues);
        }

        ['maxRetries' => $maxRetries, 'retryOn' => $retryOn, 'backoffFactor' => $backoffFactor, 'initialDelayMs' => $initialDelayMs, 'maxDelayMs' => $maxDelayMs, 'jitter' => $jitter] = $parsed['data'];

        $onFailure = $onFailureConfig;
        if ($onFailureConfig === 'raise') {
            trigger_error("`onFailure: 'raise'` is deprecated. Use `onFailure: 'error'` instead.", \E_USER_DEPRECATED);
            $onFailure = 'error';
        } elseif ($onFailureConfig === 'return_message') {
            trigger_error("`onFailure: 'return_message'` is deprecated. Use `onFailure: 'continue'` instead.", \E_USER_DEPRECATED);
            $onFailure = 'continue';
        }

        // Extract tool names from tool instances or strings.
        $toolFilter = [];
        foreach ((array) ($config['tools'] ?? []) as $tool) {
            if (\is_string($tool)) {
                $toolFilter[] = $tool;
            } elseif (\is_object($tool) && property_exists($tool, 'name') && \is_string($tool->name)) {
                $toolFilter[] = $tool->name;
            } elseif (\is_object($tool) && method_exists($tool, 'getName')) {
                $toolFilter[] = (string) $tool->getName();
            } elseif (\is_array($tool) && \is_string($tool['name'] ?? null)) {
                $toolFilter[] = $tool['name'];
            } else {
                throw new \TypeError('Expected a tool name string or tool instance to be passed to toolRetryMiddleware');
            }
        }

        $shouldRetryTool = static fn (string $toolName): bool => $toolFilter === [] || \in_array($toolName, $toolFilter, true);

        $shouldRetryException = static function (\Throwable $error) use ($retryOn): bool {
            if (!\is_array($retryOn) || !array_is_list($retryOn) || ($retryOn !== [] && !\is_string($retryOn[0]))) {
                return (bool) $retryOn($error);
            }

            // retryOn is a list of error classes.
            foreach ($retryOn as $errorClass) {
                if ($error instanceof $errorClass) {
                    return true;
                }
            }

            return false;
        };

        $delayConfig = ['backoffFactor' => $backoffFactor, 'initialDelayMs' => $initialDelayMs, 'maxDelayMs' => $maxDelayMs, 'jitter' => $jitter];

        $handleFailure = static function (string $toolName, string $toolCallId, \Throwable $error, int $attemptsMade) use ($onFailure): ToolMessage {
            if ($onFailure === 'error') {
                throw $error;
            }

            if ($onFailure === 'continue') {
                $errorType = (new \ReflectionClass($error))->getShortName();
                $attemptWord = $attemptsMade === 1 ? 'attempt' : 'attempts';
                $content = "Tool '{$toolName}' failed after {$attemptsMade} {$attemptWord} with {$errorType}";
            } else {
                $content = $onFailure($error);
            }

            return new ToolMessage([
                'content' => $content,
                'tool_call_id' => $toolCallId,
                'name' => $toolName,
                'additional_kwargs' => ['status' => 'error'],
            ]);
        };

        return Middleware::create([
            'name' => 'toolRetryMiddleware',
            'contextSchema' => [
                'type' => 'object',
                'properties' => [
                    'tools' => ['type' => 'array'],
                    'maxRetries' => ['type' => 'number'],
                    'onFailure' => ['type' => 'string'],
                    'backoffFactor' => ['type' => 'number'],
                    'initialDelayMs' => ['type' => 'number'],
                    'maxDelayMs' => ['type' => 'number'],
                    'jitter' => ['type' => 'boolean'],
                ],
            ],
            'wrapToolCall' => static function (array $request, callable $handler) use ($maxRetries, $shouldRetryTool, $shouldRetryException, $delayConfig, $handleFailure): mixed {
                $tool = $request['tool'] ?? null;
                $toolName = (string) (match (true) {
                    \is_object($tool) && property_exists($tool, 'name') => $tool->name,
                    \is_object($tool) && method_exists($tool, 'getName') => $tool->getName(),
                    default => null,
                } ?? $request['toolCall']['name'] ?? '');

                // Check if retry should apply to this tool.
                if (!$shouldRetryTool($toolName)) {
                    return $handler($request);
                }

                $toolCallId = (string) ($request['toolCall']['id'] ?? '');

                // Initial attempt + retries.
                for ($attempt = 0; $attempt <= $maxRetries; ++$attempt) {
                    try {
                        return $handler($request);
                    } catch (\Throwable $error) {
                        if (Guard::isGraphBubbleUp($error)) {
                            throw $error;
                        }

                        $attemptsMade = $attempt + 1; // attempt is 0-indexed

                        // Check if we should retry this exception.
                        if (!$shouldRetryException($error)) {
                            return $handleFailure($toolName, $toolCallId, $error, $attemptsMade);
                        }

                        if ($attempt < $maxRetries) {
                            $delay = Utils::calculateRetryDelay($delayConfig, $attempt, Utils::getRetryAfterMs($error));
                            if ($delay > 0) {
                                Utils::sleep($delay);
                            }
                        } else {
                            return $handleFailure($toolName, $toolCallId, $error, $attemptsMade);
                        }
                    }
                }

                // Unreachable: the loop always returns via the handler or handleFailure.
                throw new \RuntimeException('Unexpected: retry loop completed without returning');
            },
        ]);
    }
}
