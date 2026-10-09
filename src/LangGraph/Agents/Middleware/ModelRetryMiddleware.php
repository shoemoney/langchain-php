<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware;

use LangChain\Messages\AIMessage;
use LangGraph\Agents\Middleware;

/**
 * Automatic retry with exponential backoff for failed model calls.
 *
 * Port of `modelRetryMiddleware` from `langchain/src/agents/middleware/modelRetry.ts`.
 *
 * Options: `maxRetries` (default 2), `retryOn` (a list of error classes matched EXACTLY, as upstream's
 * `error.constructor === Ctor`, or a predicate; the default retries unless the error is stamped
 * non-retryable), `onFailure` (`continue`, the default, returns an AI message describing the failure; `error`
 * re-throws; or `fn(\Throwable): string` for custom content), `backoffFactor` (2.0), `initialDelayMs` (1000),
 * `maxDelayMs` (60000) and `jitter` (true). Invalid options raise an {@see InvalidRetryConfigError}.
 *
 * A `Retry-After` hint on the error (see {@see Utils::getRetryAfterMs()}) is a floor for the delay. The wait
 * is a real {@see Utils::sleep()}; tests keep it short with a small `initialDelayMs`.
 *
 * ```
 * $agent = Agent::create(['model' => $model, 'middleware' => [ModelRetryMiddleware::create(['maxRetries' => 3])]]);
 * ```
 */
final class ModelRetryMiddleware
{
    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $config
     * @return array<string, mixed> the middleware
     * @throws InvalidRetryConfigError
     */
    public static function create(array $config = []): array
    {
        $parsed = Constants::parseRetrySchema($config);
        $issues = $parsed['success'] ? [] : $parsed['issues'];

        $onFailure = $config['onFailure'] ?? 'continue';
        if ($onFailure !== 'error' && $onFailure !== 'continue' && !($onFailure instanceof \Closure) && !(\is_callable($onFailure) && !\is_string($onFailure))) {
            $issues[] = ['path' => ['onFailure'], 'code' => 'invalid_union', 'message' => 'Invalid input'];
        }
        if ($issues !== [] || !$parsed['success']) {
            throw new InvalidRetryConfigError($issues);
        }

        ['maxRetries' => $maxRetries, 'retryOn' => $retryOn, 'backoffFactor' => $backoffFactor, 'initialDelayMs' => $initialDelayMs, 'maxDelayMs' => $maxDelayMs, 'jitter' => $jitter] = $parsed['data'];

        $shouldRetryException = static function (\Throwable $error) use ($retryOn): bool {
            if (!\is_array($retryOn) || !array_is_list($retryOn) || ($retryOn !== [] && !\is_string($retryOn[0]))) {
                return (bool) $retryOn($error);
            }

            // retryOn is a list of error classes, matched exactly.
            foreach ($retryOn as $errorClass) {
                if ($error::class === $errorClass) {
                    return true;
                }
            }

            return false;
        };

        $delayConfig = ['backoffFactor' => $backoffFactor, 'initialDelayMs' => $initialDelayMs, 'maxDelayMs' => $maxDelayMs, 'jitter' => $jitter];

        $handleFailure = static function (\Throwable $error, int $attemptsMade) use ($onFailure): AIMessage {
            if ($onFailure === 'error') {
                throw $error;
            }

            if ($onFailure === 'continue') {
                $errorType = (new \ReflectionClass($error))->getShortName();
                $attemptWord = $attemptsMade === 1 ? 'attempt' : 'attempts';
                $content = "Model call failed after {$attemptsMade} {$attemptWord} with {$errorType}: {$error->getMessage()}";
            } else {
                $content = $onFailure($error);
            }

            return new AIMessage(['content' => $content]);
        };

        return Middleware::create([
            'name' => 'modelRetryMiddleware',
            'contextSchema' => [
                'type' => 'object',
                'properties' => [
                    'maxRetries' => ['type' => 'number'],
                    'onFailure' => ['type' => 'string'],
                    'backoffFactor' => ['type' => 'number'],
                    'initialDelayMs' => ['type' => 'number'],
                    'maxDelayMs' => ['type' => 'number'],
                    'jitter' => ['type' => 'boolean'],
                ],
            ],
            'wrapModelCall' => static function (array $request, callable $handler) use ($maxRetries, $shouldRetryException, $delayConfig, $handleFailure): mixed {
                $delegated = [...$request, 'modelSettings' => ['maxRetries' => 0, ...(array) ($request['modelSettings'] ?? [])]];

                // Initial attempt + retries.
                for ($attempt = 0; $attempt <= $maxRetries; ++$attempt) {
                    try {
                        return $handler($delegated);
                    } catch (\Throwable $error) {
                        $attemptsMade = $attempt + 1; // attempt is 0-indexed

                        // Check if we should retry this exception.
                        if (!$shouldRetryException($error)) {
                            // Not retryable: handle the failure immediately.
                            return $handleFailure($error, $attemptsMade);
                        }

                        if ($attempt < $maxRetries) {
                            $delay = Utils::calculateRetryDelay($delayConfig, $attempt, Utils::getRetryAfterMs($error));
                            if ($delay > 0) {
                                Utils::sleep($delay);
                            }
                        } else {
                            // No more retries.
                            return $handleFailure($error, $attemptsMade);
                        }
                    }
                }

                // Unreachable: the loop always returns via the handler or handleFailure.
                throw new \RuntimeException('Unexpected: retry loop completed without returning');
            },
        ]);
    }
}
