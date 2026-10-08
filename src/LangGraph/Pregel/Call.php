<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableInterface;
use LangChain\Runnables\RunnableSequence;
use LangChain\Utils\Await;
use LangChain\Utils\Promise;
use LangGraph\Pregel\Retry\RetryPolicy;
use LangGraph\Utils\RunnableCallable;

/**
 * A call to a functional-API task, queued as a PUSH task.
 *
 * Port of the `Call` class from `langgraph-core/src/pregel/types.ts` together with
 * the helpers of `langgraph-core/src/pregel/call.ts` (`getRunnableForFunc`,
 * `getRunnableForEntrypoint`, `call`).
 *
 * A `Call` is the payload a task invocation leaves behind: the function, the name
 * it is checkpointed under, the arguments it was given, and the policies that
 * govern it. {@see Algorithm::prepareSingleTask()} recognises a PUSH path whose
 * last element is a `Call` and turns it into an executable task - which is what
 * makes a task call a unit of work the engine checkpoints, retries and caches
 * rather than a plain function call.
 */
final class Call
{
    public const LG_TYPE = 'call';

    /**
     * @param callable                                          $func      The task body, invoked as `func(...$input)`.
     * @param string                                            $name      The task's name.
     * @param list<mixed>                                       $input     The arguments the task was called with.
     * @param RetryPolicy|null                                  $retry     Retry policy for the task.
     * @param array{keyFunc?: callable, ttl?: int|float}|null   $cache     Cache policy for the task.
     * @param mixed                                             $timeout   A {@see TimeoutPolicy}, milliseconds, or null.
     * @param mixed                                             $callbacks Callbacks of the calling run.
     */
    public function __construct(
        public readonly mixed $func,
        public readonly string $name,
        public readonly array $input = [],
        public readonly ?RetryPolicy $retry = null,
        public readonly ?array $cache = null,
        public readonly mixed $timeout = null,
        public readonly mixed $callbacks = null,
    ) {
    }

    /** Port of `isCall`. */
    public static function isCall(mixed $value): bool
    {
        return $value instanceof self;
    }

    /**
     * Wrap a task function in a runnable that writes its return value to `RETURN`.
     *
     * Port of `getRunnableForFunc`. The runnable receives the argument list as its
     * input and spreads it into the function. A function that returns a
     * {@see Promise} (a task awaiting another task) is awaited, mirroring the
     * `async` function a JS task body is.
     *
     * @param callable $func
     */
    public static function getRunnableForFunc(string $name, callable $func): RunnableInterface
    {
        $run = new RunnableCallable(
            static fn (mixed $input): mixed => Await::sync($func(...(array) $input)),
            name: $name,
            trace: false,
            recurse: false,
        );

        return new RunnableSequence(
            [
                $run,
                new ChannelWrite(
                    [['channel' => Constants::RETURN, 'value' => ChannelWrite::passthrough()]],
                    [Constants::TAG_HIDDEN],
                ),
            ],
            [$name, 'ChannelWrite<' . Constants::RETURN . '>'],
        );
    }

    /**
     * Wrap an entrypoint function in a runnable.
     *
     * Port of `getRunnableForEntrypoint`. The function receives the run input and
     * the run config; a returned {@see Promise} is awaited.
     *
     * @param callable $func
     */
    public static function getRunnableForEntrypoint(string $name, callable $func): RunnableInterface
    {
        return new RunnableCallable(
            static fn (mixed $input, RunnableConfig $config): mixed => Await::sync($func($input, $config)),
            name: $name,
            trace: false,
            recurse: false,
        );
    }

    /**
     * Schedule a task call on the running graph.
     *
     * Port of `call`. Reads the scheduling callback the engine published on the
     * current task's config (`CONFIG_KEY_CALL`) and hands it the function, its
     * name, its arguments and its policies. The result is a settled
     * {@see Promise}: tasks execute sequentially in this port, so the work is
     * done by the time the promise is returned.
     *
     * @param array{func: callable, name: string, retry?: RetryPolicy|null, cache?: array<string, mixed>|null, timeout?: mixed} $options
     */
    public static function call(array $options, mixed ...$args): Promise
    {
        $config = PregelScratchpad::currentConfig();
        $scheduler = $config?->configurable[Constants::CONFIG_KEY_CALL] ?? null;

        if (!\is_callable($scheduler)) {
            throw new \LogicException(
                'A task can only be called from within an entrypoint or a StateGraph node.'
            );
        }

        $result = $scheduler($options['func'], $options['name'], array_values($args), [
            'retry' => $options['retry'] ?? null,
            'cache' => $options['cache'] ?? null,
            'timeout' => $options['timeout'] ?? null,
            'callbacks' => $config->callbacks,
        ]);

        return Promise::from($result);
    }
}
