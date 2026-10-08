<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangChain\Utils\Promise;
use LangGraph\Errors\Guard;
use LangGraph\Pregel\Retry\RetryPolicy;

/**
 * Schedules and runs the tasks a running task calls.
 *
 * Port of the `call` function in `langgraph-core/src/pregel/runner.ts`, which the runner
 * binds to `CONFIG_KEY_CALL` on every task it executes.
 *
 * When a task body calls a functional-API task, this is what answers. It pushes a PUSH task
 * onto the loop ({@see PregelLoop::acceptPush()}) and then settles it one of three ways:
 *
 *  - the task already has writes - it finished on an earlier run, or its result was cached -
 *    so the recorded result is returned and the function is NOT called again;
 *  - it has none, so it runs now under its own retry policy, and its writes are persisted
 *    with {@see PregelLoop::putWrites()} so a later resume can reuse them;
 *  - the loop declined to schedule it (an interrupt target), so there is no result.
 *
 * JS starts the task and returns a promise the runner settles later. This port is
 * sequential, so the task runs to completion inside this call and the promise it returns
 * is already settled. The retry and commit steps below are the same ones
 * {@see PregelRunner} applies to a top-level task: a nested task must be retried and
 * checkpointed exactly as a top-level one is, or the second run of a workflow would redo
 * its tasks.
 */
final class CallScheduler
{
    public function __construct(private readonly PregelLoop $loop)
    {
    }

    /**
     * @param list<mixed>                                                                 $args
     * @param array{retry?: RetryPolicy|null, cache?: array<string, mixed>|null, timeout?: mixed, callbacks?: mixed} $options
     */
    public function call(PregelExecutableTask $parent, callable $func, string $name, array $args, array $options = []): Promise
    {
        $scratchpad = $parent->config?->configurable[Constants::CONFIG_KEY_SCRATCHPAD] ?? null;
        if (!$scratchpad instanceof PregelScratchpad) {
            throw new \LogicException("BUG: No scratchpad found on task {$parent->name}__{$parent->id}");
        }

        $index = $scratchpad->callCounter;
        $scratchpad->callCounter += 1;

        $next = $this->loop->acceptPush($parent, $index, new Call(
            func: $func,
            name: $name,
            input: $args,
            retry: $options['retry'] ?? null,
            cache: $options['cache'] ?? null,
            timeout: $options['timeout'] ?? null,
            callbacks: $options['callbacks'] ?? null,
        ));

        if ($next === null) {
            return Promise::resolved(null);
        }

        if ($next->writes === []) {
            $childPad = $next->config?->configurable[Constants::CONFIG_KEY_SCRATCHPAD] ?? null;
            $hadResume = $childPad instanceof PregelScratchpad && $childPad->nullResume !== null;

            $this->loop->emitDebug('task', $this->loop->debugTaskPayload($next, true));
            $error = $this->runWithRetry($next);
            $this->commit($next, $error);

            // The graph-wide resume value is spent once an interrupt took it; tell the caller so
            // the tasks it calls next (and its own interrupts) do not take it again.
            if ($hadResume && $childPad->nullResume === null) {
                $scratchpad->nullResume = null;
            }

            if ($error !== null) {
                return Promise::rejected($error);
            }
        }

        return $this->settle($next);
    }

    /** The promise for a task that has writes: its `RETURN` value, or its recorded error. */
    private function settle(PregelExecutableTask $task): Promise
    {
        $returns = [];
        $errors = [];
        foreach ($task->writes as $write) {
            if ($write[0] === Constants::RETURN) {
                $returns[] = $write;
            } elseif ($write[0] === Constants::ERROR) {
                $errors[] = $write;
            }
        }

        if (\count($returns) > 1) {
            throw new \LogicException("BUG: multiple returns found for task {$task->name}__{$task->id}");
        }
        if (\count($returns) === 1) {
            return Promise::resolved($returns[0][1]);
        }

        if (\count($errors) > 1) {
            throw new \LogicException("BUG: multiple errors found for task {$task->name}__{$task->id}");
        }
        if (\count($errors) === 1) {
            $value = $errors[0][1];
            $message = \is_array($value) ? (string) ($value['message'] ?? 'Task failed') : (string) $value;

            return Promise::rejected(new \RuntimeException($message));
        }

        return Promise::resolved(null);
    }

    /**
     * Run a task under its retry policy. Returns null on success, the throwable otherwise.
     *
     * Port of `_runWithRetry`, as applied by {@see PregelRunner}: each attempt starts from
     * empty writes, an engine control signal is never retried, and a retry marks the config
     * as resuming so a subgraph continues instead of restarting.
     */
    private function runWithRetry(PregelExecutableTask $task): ?\Throwable
    {
        $policy = $task->retryPolicy;
        $attempts = 0;
        $config = $task->config;

        while (true) {
            $task->writes = [];

            try {
                \assert($task->proc !== null);
                PregelScratchpad::withConfig(
                    $config,
                    static fn (): mixed => $task->proc?->invoke($task->input, $config)
                );

                return null;
            } catch (\Throwable $e) {
                if (Guard::isGraphBubbleUp($e) || $policy === null) {
                    return $e;
                }

                $attempts += 1;

                if ($attempts >= $policy->effectiveMaxAttempts() || !$policy->shouldRetry($e)) {
                    return $e;
                }

                if ($policy->shouldLogWarning()) {
                    trigger_error(
                        \sprintf(
                            'Retrying task "%s" after %dms (attempt %d) after %s: %s',
                            $task->name,
                            $policy->intervalFor($attempts, 0.5),
                            $attempts,
                            $e::class,
                            $e->getMessage()
                        ),
                        E_USER_NOTICE
                    );
                }

                $config = clone $config;
                $config->configurable[Constants::CONFIG_KEY_RESUMING] = true;
            }
        }
    }

    /**
     * Record what a task produced, or why it produced nothing.
     *
     * Port of `PregelRunner._commit`; see {@see PregelRunner} for what each branch means.
     */
    private function commit(PregelExecutableTask $task, ?\Throwable $error): void
    {
        if ($error === null) {
            if ($task->writes === []) {
                $task->writes[] = [Constants::NO_WRITES, null];
            }
            $this->loop->putWrites($task->id, $task->writes);
            $this->loop->emitDebug('task_result', [
                'id' => $task->id,
                'name' => $task->name,
                'interrupts' => $task->interrupts,
                'result' => $this->resultOf($task),
            ]);

            return;
        }

        if (Guard::isGraphInterrupt($error)) {
            if ($error->interrupts !== []) {
                $writes = [];
                foreach ($error->interrupts as $interrupt) {
                    $writes[] = [Constants::INTERRUPT, $interrupt];
                }
                foreach ($task->writes as $write) {
                    if ($write[0] === Constants::RESUME) {
                        $writes[] = $write;
                    }
                }
                $this->loop->putWrites($task->id, $writes);
            }

            return;
        }

        if (Guard::isGraphDrained($error)) {
            if ($task->writes !== []) {
                $this->loop->putWrites($task->id, $task->writes);
            }

            return;
        }

        if (Guard::isGraphBubbleUp($error) && $task->writes !== []) {
            $this->loop->putWrites($task->id, $task->writes);

            return;
        }

        $task->writes[] = [Constants::ERROR, ['message' => $error->getMessage(), 'name' => $error::class]];
        $this->loop->putWrites($task->id, $task->writes);
    }

    /** @return array<string, mixed> */
    private function resultOf(PregelExecutableTask $task): array
    {
        $out = [];
        foreach ($task->writes as $write) {
            if ($write[0] !== Constants::NO_WRITES) {
                $out[$write[0]] = $write[1] ?? null;
            }
        }

        return $out;
    }
}
