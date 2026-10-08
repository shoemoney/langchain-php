<?php

declare(strict_types=1);

namespace LangGraph\Func;

use LangChain\Utils\Promise;
use LangGraph\Channels\EphemeralValue;
use LangGraph\Channels\LastValue;
use LangGraph\Pregel\Call;
use LangGraph\Pregel\ChannelWrite;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use LangGraph\Pregel\PregelNode;
use LangGraph\Pregel\PregelScratchpad;
use LangGraph\Pregel\Retry\RetryPolicy;
use LangGraph\Pregel\Timeout;
use LangGraph\Utils\RunnableCallable;

/**
 * The functional API: `entrypoint()`, `task()` and `getPreviousState()`.
 *
 * Port of `langgraph-core/src/func/index.ts`. The three exports are static methods here
 * rather than namespaced functions, because PSR-4 does not autoload functions.
 *
 * An **entrypoint** is a whole workflow written as one function; it compiles to a one-node
 * {@see Pregel} graph. A **task** is a unit of work inside it. Calling a task does not just
 * call the function: it queues a PUSH task on the running graph, so the call is
 * checkpointed (a resumed run reuses the recorded result instead of running it again),
 * retried under its retry policy, and cached under its cache policy.
 *
 * Differences from the JavaScript original, all consequences of PHP having no event loop:
 *  - A task call runs to completion before it returns, so tasks started "in parallel" run in
 *    call order. The call still returns a settled {@see Promise}, so ported code that awaits
 *    keeps its shape; resolve one with {@see \LangChain\Utils\Await::sync()}.
 *  - Generator functions are rejected as in JS, detected with reflection.
 */
final class Func
{
    private function __construct()
    {
    }

    /**
     * Define a task.
     *
     * Port of `task`. A task can only be called from within an entrypoint or a StateGraph
     * node. The returned closure takes the task's arguments and returns a settled
     * {@see Promise} of its result.
     *
     * Options (`TaskOptions`): `name` (required), `retry` ({@see RetryPolicy}, or the array
     * of its constructor arguments), `cachePolicy` (`true`, or `['keyFunc' => callable,
     * 'ttl' => seconds]`), `timeout` (milliseconds or a {@see \LangGraph\Pregel\TimeoutPolicy}).
     *
     * @param string|array{name: string, retry?: RetryPolicy|array<string, mixed>|null, cachePolicy?: bool|array{keyFunc?: callable, ttl?: int|float}|null, cache?: bool|array{keyFunc?: callable, ttl?: int|float}|null, timeout?: mixed} $optionsOrName
     * @param callable $func
     * @return \Closure(mixed ...$args): Promise
     */
    public static function task(string|array $optionsOrName, callable $func): \Closure
    {
        $options = \is_string($optionsOrName) ? ['name' => $optionsOrName] : $optionsOrName;

        $name = $options['name'] ?? null;
        if (!\is_string($name) || $name === '') {
            throw new \InvalidArgumentException('A task requires a "name".');
        }

        $retry = $options['retry'] ?? null;
        if (\is_array($retry)) {
            $retry = new RetryPolicy(...$retry);
        }
        $timeout = Timeout::coerceTimeoutPolicy($options['timeout'] ?? null);

        if (self::isGeneratorFunction($func)) {
            throw new \InvalidArgumentException(
                'Generators are disallowed as tasks. For streaming responses, use config.write.'
            );
        }

        // `cache` was mistakenly used as an alias for `cachePolicy` in upstream v0.3.x.
        $cachePolicy = $options['cachePolicy'] ?? $options['cache'] ?? null;
        $cache = \is_bool($cachePolicy) ? ($cachePolicy ? [] : null) : $cachePolicy;

        return static fn (mixed ...$args): Promise => Call::call(
            ['func' => $func, 'name' => $name, 'retry' => $retry, 'cache' => $cache, 'timeout' => $timeout],
            ...$args,
        );
    }

    /**
     * Define a workflow.
     *
     * Port of `entrypoint`. The function receives the run input and the run config (the second
     * parameter is optional) and may return an {@see EntrypointFinal}. The result is a
     * {@see Pregel} graph with the entrypoint as its only node.
     *
     * Options (`EntrypointOptions`): `name` (required), `checkpointer`, `store`, `cache`,
     * `timeout`.
     *
     * @param string|array{name: string, checkpointer?: \LangGraph\Pregel\Checkpoint\BaseCheckpointSaver|null, store?: \LangGraph\Store\BaseStore|null, cache?: \LangGraph\Cache\BaseCache|null, timeout?: mixed} $optionsOrName
     * @param callable $func
     */
    public static function entrypoint(string|array $optionsOrName, callable $func): Pregel
    {
        $options = \is_string($optionsOrName) ? ['name' => $optionsOrName] : $optionsOrName;

        $name = $options['name'] ?? null;
        if (!\is_string($name) || $name === '') {
            throw new \InvalidArgumentException('An entrypoint requires a "name".');
        }

        $timeout = Timeout::coerceTimeoutPolicy($options['timeout'] ?? null);

        if (self::isGeneratorFunction($func)) {
            throw new \InvalidArgumentException(
                'Generators are disallowed as entrypoints. For streaming responses, use config.write.'
            );
        }

        $bound = Call::getRunnableForEntrypoint($name, $func);

        // Upstream writes two entries, `END <- pluckReturnValue` and `PREVIOUS <- pluckSaveValue`.
        // `ChannelWrite` here only applies a mapper to a pass-through value in its tuple form,
        // so both are produced by one mapper returning the `[channel, value]` pairs.
        $splitFinal = new RunnableCallable(
            static function (mixed $value): array {
                $isFinal = EntrypointFinal::isEntrypointFinal($value);

                return [
                    [Constants::END, $isFinal ? $value->value : $value],
                    [Constants::PREVIOUS, $isFinal ? $value->save : $value],
                ];
            },
            name: 'pluckReturnAndSaveValue',
        );

        $entrypointNode = new PregelNode(
            channels: [Constants::START],
            triggers: [Constants::START],
            bound: $bound,
            writers: [
                new ChannelWrite(
                    [['value' => ChannelWrite::passthrough(), 'mapper' => $splitFinal]],
                    [Constants::TAG_HIDDEN],
                ),
            ],
            timeout: $timeout,
        );

        return new Pregel(
            nodes: [$name => $entrypointNode],
            channels: [
                Constants::START => new EphemeralValue(),
                Constants::END => new LastValue(),
                Constants::PREVIOUS => new LastValue(),
            ],
            inputChannels: Constants::START,
            outputChannels: Constants::END,
            streamChannels: [Constants::END],
            checkpointer: $options['checkpointer'] ?? null,
            streamMode: ['updates'],
            triggerToNodes: [Constants::START => [$name]],
            name: $name,
            store: $options['store'] ?? null,
            cache: $options['cache'] ?? null,
        );
    }

    /**
     * Return a value to the caller and a separate value to checkpoint.
     *
     * Port of `entrypoint.final`.
     */
    public static function final(mixed $value = null, mixed $save = null): EntrypointFinal
    {
        return new EntrypointFinal($value, $save);
    }

    /**
     * The state saved by the previous invocation of the entrypoint on the current thread.
     *
     * Port of `getPreviousState`. Reads `CONFIG_KEY_PREVIOUS_STATE` from the config of the
     * task that is running, which the engine fills from the `PREVIOUS` channel of the
     * checkpoint the task was prepared against. `null` on the first invocation of a thread.
     */
    public static function getPreviousState(): mixed
    {
        $config = PregelScratchpad::currentConfig();
        if ($config === null) {
            throw new \LogicException('getPreviousState() can only be called from within an entrypoint or a StateGraph node.');
        }

        return $config->configurable[Constants::CONFIG_KEY_PREVIOUS_STATE] ?? null;
    }

    private static function isGeneratorFunction(callable $func): bool
    {
        return (new \ReflectionFunction(\Closure::fromCallable($func)))->isGenerator();
    }
}
