<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\BaseCallbackHandler;
use LangGraph\Errors\NodeTimeoutError;

/**
 * Per-attempt node timeouts.
 *
 * Port of `langgraph-core/src/pregel/timeout.ts` and `pregel/utils/timeout.ts`.
 *
 * ## The PHP collapse (read this before relying on it)
 *
 * Upstream races the node against two timers and ABORTS the node when one fires. PHP runs a
 * node synchronously on one thread: nothing can preempt it, and a timer cannot fire while it
 * runs. So this port cannot stop a slow node; it can only notice, once the node has returned
 * (or thrown), that the budget was blown. What is preserved:
 *
 *  - the verdict: a node that ran past `runTimeout` is a {@see NodeTimeoutError} of kind
 *    `run`; one whose longest silence reached `idleTimeout` is kind `idle`. Upstream itself
 *    re-checks wall-clock time after the race for exactly this reason (its timers cannot
 *    fire while a CPU-bound node blocks the event loop), so for synchronous nodes the two
 *    runtimes agree;
 *  - the writes of a timed-out attempt are discarded, and a node error raised by a timed-out
 *    attempt is replaced by the timeout (upstream's `outcome` is overwritten the same way);
 *  - the error is retryable under the default retry policy, and the clock restarts per
 *    attempt.
 *
 * What is NOT preserved: the node is never interrupted, so a node that would have run for an
 * hour runs for an hour and is reported afterwards; its `signal` is not aborted (it has
 * already finished by the time the verdict exists); `elapsed` is the time when the verdict was
 * reached, not the moment the cap was crossed. No `sleep` is used anywhere to imitate
 * preemption.
 */
final class Timeout
{
    private function __construct()
    {
    }

    /**
     * Normalise a timeout value into a {@see TimeoutPolicy}, or null when none is configured.
     *
     * Port of `coerceTimeoutPolicy`. A bare number is a hard `runTimeout`. Throws when a
     * configured timeout is not greater than 0, or when `refreshOn` is not `auto` / `heartbeat`.
     * An empty policy collapses to null.
     *
     * @param int|float|TimeoutPolicy|array{runTimeout?: int|float|null, idleTimeout?: int|float|null, refreshOn?: string}|null $value
     */
    public static function coerceTimeoutPolicy(int|float|TimeoutPolicy|array|null $value = null): ?TimeoutPolicy
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value) || is_float($value)) {
            $policy = new TimeoutPolicy(runTimeout: $value);
        } elseif (is_array($value)) {
            $policy = new TimeoutPolicy(
                runTimeout: $value['runTimeout'] ?? null,
                idleTimeout: $value['idleTimeout'] ?? null,
                refreshOn: $value['refreshOn'] ?? 'auto',
            );
        } else {
            $policy = $value;
        }

        if ($policy->refreshOn !== 'auto' && $policy->refreshOn !== 'heartbeat') {
            throw new \InvalidArgumentException('refreshOn must be "auto" or "heartbeat"');
        }

        $runTimeout = self::coerceTimeoutMs($policy->runTimeout, 'runTimeout');
        $idleTimeout = self::coerceTimeoutMs($policy->idleTimeout, 'idleTimeout');

        if ($runTimeout === null && $idleTimeout === null) {
            return null;
        }

        return new TimeoutPolicy($runTimeout, $idleTimeout, $policy->refreshOn);
    }

    private static function coerceTimeoutMs(int|float|null $value, string $field): int|float|null
    {
        if ($value === null) {
            return null;
        }
        if (is_nan((float) $value) || $value <= 0) {
            throw new \InvalidArgumentException("{$field} must be greater than 0");
        }

        return $value;
    }

    /**
     * Run a single node attempt under a {@see TimeoutPolicy}.
     *
     * Port of `runAttemptWithTimeout`. `$invoke` receives the scoped config (progress hooks
     * installed) and runs the node. On success the node's value is returned and on a node error
     * the error is rethrown, unless the budget was blown, in which case the attempt's writes
     * are cleared and a {@see NodeTimeoutError} is thrown instead.
     *
     * @template T
     * @param callable(RunnableConfig): T $invoke
     * @return T
     */
    public static function runAttemptWithTimeout(
        PregelExecutableTask $task,
        RunnableConfig $config,
        TimeoutPolicy $policy,
        callable $invoke,
    ): mixed {
        $scope = new TimedAttemptScope($policy->refreshOn);
        $scopedConfig = self::wrapConfig($config, $scope, $policy, $task->name);

        $start = TimedAttemptScope::now();
        $value = null;
        $error = null;
        try {
            $value = $invoke($scopedConfig);
        } catch (\Throwable $e) {
            $error = $e;
        }

        $now = TimedAttemptScope::now();
        $kind = null;
        if ($policy->runTimeout !== null && $now - $start >= $policy->runTimeout) {
            $kind = 'run';
        } elseif ($policy->idleTimeout !== null && $scope->longestIdleGap($now) >= $policy->idleTimeout) {
            $kind = 'idle';
        }

        if ($kind === null) {
            if ($error !== null) {
                throw $error;
            }

            return $value;
        }

        // The budget was blown: close the scope (late writes are dropped), discard the attempt's
        // buffered writes, and surface the timeout in place of whatever the node produced.
        $scope->close();
        $task->writes = [];

        throw new NodeTimeoutError(
            node: $task->name,
            elapsed: (int) round($now - $start),
            kind: $kind,
            runTimeout: $policy->runTimeout,
            idleTimeout: $policy->idleTimeout,
        );
    }

    /**
     * Wrap a node runnable so every attempt of the task runs under its timeout.
     *
     * This is how a task's `timeout` reaches the runner: the wrapper is the task's `proc`, so a
     * retry policy re-enters it per attempt and gets a fresh clock.
     */
    public static function wrapProc(
        PregelExecutableTask $task,
        \LangChain\Runnables\RunnableInterface $proc,
        TimeoutPolicy $policy,
    ): \LangChain\Runnables\RunnableInterface {
        return new \LangChain\Runnables\RunnableLambda(
            static fn (mixed $input, RunnableConfig $config): mixed => self::runAttemptWithTimeout(
                $task,
                $config,
                $policy,
                static fn (RunnableConfig $scoped): mixed => $proc->invoke($input, $scoped),
            ),
        );
    }

    /**
     * Wrap the attempt config so progress signals refresh the idle clock and are dropped once
     * the scope is closed. Also injects `heartbeat` into `options`.
     */
    private static function wrapConfig(
        RunnableConfig $config,
        TimedAttemptScope $scope,
        TimeoutPolicy $policy,
        string $taskName,
    ): RunnableConfig {
        $wrapped = clone $config;
        $configurable = $wrapped->configurable;

        $send = $configurable[Constants::CONFIG_KEY_SEND] ?? null;
        if (is_callable($send)) {
            $configurable[Constants::CONFIG_KEY_SEND] = static function (array $writes) use ($send, $scope): mixed {
                if (!$scope->active) {
                    return null;
                }
                if ($writes !== []) {
                    $scope->autoTouch();
                }

                return $send($writes);
            };
        }

        $call = $configurable[Constants::CONFIG_KEY_CALL] ?? null;
        if (is_callable($call)) {
            $configurable[Constants::CONFIG_KEY_CALL] = static function (mixed ...$args) use ($call, $scope, $taskName): mixed {
                if (!$scope->active) {
                    throw new \RuntimeException("Node \"{$taskName}\" attempt was cancelled after its timeout fired");
                }
                $scope->autoTouch();

                return $call(...$args);
            };
        }
        $wrapped->configurable = $configurable;

        // `heartbeat` always resets the idle clock, even under `refreshOn: heartbeat`. It is a
        // no-op when no idle timeout is configured.
        $wrapped->options['heartbeat'] = static function () use ($scope, $policy): void {
            if ($policy->idleTimeout !== null) {
                $scope->touch();
            }
        };

        $writer = $wrapped->options['writer'] ?? null;
        if (is_callable($writer)) {
            $wrapped->options['writer'] = static function (mixed $chunk) use ($writer, $scope): mixed {
                if (!$scope->active) {
                    return null;
                }
                $scope->autoTouch();

                return $writer($chunk);
            };
        }

        if ($policy->refreshOn === 'auto' && $policy->idleTimeout !== null) {
            $wrapped->callbacks = [...$wrapped->callbacks, self::idleProgressHandler($scope)];
        }

        return $wrapped;
    }

    /**
     * A callback handler that refreshes the idle clock on any LangChain event under the node's
     * run. Attached through `config->callbacks`, so it only sees events from runs descended from
     * this attempt, not from sibling nodes.
     */
    private static function idleProgressHandler(TimedAttemptScope $scope): BaseCallbackHandler
    {
        $touch = static function () use ($scope): void {
            $scope->autoTouch();
        };

        $methods = [];
        foreach ([
            'handleLLMStart', 'handleChatModelStart', 'handleLLMNewToken', 'handleLLMEnd', 'handleLLMError',
            'handleChainStart', 'handleChainEnd', 'handleChainError', 'handleToolStart', 'handleToolEnd',
            'handleToolError', 'handleText', 'handleRetrieverStart', 'handleRetrieverEnd',
            'handleRetrieverError', 'handleCustomEvent',
        ] as $hook) {
            $methods[$hook] = $touch;
        }

        return BaseCallbackHandler::fromMethods($methods, ['name' => 'IdleProgressCallbackHandler', 'awaitHandlers' => false]);
    }
}
