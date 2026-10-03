<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangGraph\Errors\GraphInterrupt;
use LangGraph\Errors\Guard;

/**
 * Runs a superstep's tasks and routes whatever they raise.
 *
 * Port of `PregelRunner` from `langgraph-core/src/pregel/runner.ts`.
 *
 * Where the loop decides *what* should run, the runner decides *what happens
 * when it doesn't go well*. Three outcomes, and keeping them distinct is the
 * whole job:
 *
 *  1. **A node error.** Recorded, and the other tasks in the step are given the
 *     chance to finish first — so their writes are not lost, and so an abort
 *     cascade is not mistaken for two independent failures. Only then is the
 *     error raised, with a message naming the step.
 *  2. **A bubble-up** (interrupt, drain, parent command). Never counted as a
 *     failure and never sent to an error handler; it propagates, with
 *     interrupts from several tasks merged into one.
 *  3. **Success.** Writes are already recorded via
 *     {@see PregelLoop::putWrites()} as each task finished.
 *
 * PHP has no `AbortController`, so TS's "abort the siblings" trick has no
 * analogue — and none is needed. Tasks execute sequentially, so there is
 * nothing to abort: a failure simply stops the step. That is a genuine
 * behavioural difference from JS and is documented in PORT_STATUS.md.
 */
class PregelRunner
{
    /**
     * Index assigned to each kind of special write when persisting.
     *
     * Port of `WRITES_IDX_MAP`. Special writes get negative indices so they
     * cannot collide with a task's ordinary writes by position.
     */
    public const WRITES_IDX_MAP = [
        Constants::ERROR => -1,
        Constants::INTERRUPT => -3,
        Constants::RESUME => -4,
    ];

    public function __construct(
        private readonly PregelLoop $loop,
    ) {
    }

    /**
     * Run every task in the current step.
     *
     * Tasks that already have writes are skipped: they were re-attached from a
     * checkpoint and have nothing left to do.
     */
    public function tick(): void
    {
        $nodeErrors = [];
        $graphBubbleUp = null;

        $pending = array_values(array_filter(
            $this->loop->tasks,
            static fn (PregelExecutableTask $t): bool => $t->writes === [],
        ));

        foreach ($pending as $task) {
            // `debug` only: the boundary a caller cannot otherwise see. Emitted
            // before the run so a task that throws still has a `task` event paired
            // with the error that ended it — without this, a failure is invisible in
            // the debug stream, which is the one mode anyone debugging would use.
            $this->loop->emitDebug('task', $this->loop->debugTaskPayload($task, true));

            $error = $this->runWithRetry($task);
            $this->commit($task, $error);

            if ($error === null) {
                continue;
            }

            if (Guard::isGraphInterrupt($error)) {
                // Merge interrupts rather than replacing: two tasks pausing in
                // one step is two questions for the caller, not one.
                $graphBubbleUp = $graphBubbleUp instanceof GraphInterrupt
                    ? new GraphInterrupt(
                        array_merge($graphBubbleUp->interrupts, $error->interrupts)
                    )
                    : $error;
            } elseif (Guard::isGraphBubbleUp($graphBubbleUp ?? null) || $graphBubbleUp === null) {
                if ($graphBubbleUp === null) {
                    $graphBubbleUp = $error;
                }
            } else {
                $nodeErrors[] = $error;
            }
        }

        if (count($nodeErrors) === 1) {
            throw $nodeErrors[0];
        }

        if (count($nodeErrors) > 1) {
            throw new \RuntimeException(
                'Multiple errors occurred during superstep ' . $this->loop->step
                . '. See the "errors" field of this exception for more details.',
                0,
                $nodeErrors[0]
            );
        }

        if ($graphBubbleUp !== null) {
            throw $graphBubbleUp;
        }
    }

    /**
     * Record what a task produced, or why it produced nothing.
     *
     * Port of `PregelRunner._commit`. This is the bridge between "a task ran"
     * and "the next step can see it", and each branch encodes a different
     * outcome a caller must be able to distinguish:
     *
     *  - **Interrupt** — converted to `__interrupt__` writes carrying the
     *    payloads. The run is suspended, not failed, and the payloads are how
     *    the caller learns what it is being asked.
     *  - **Drain** — the task is left *uncommitted* unless it already wrote, so
     *    a later resume re-runs it. A drain is "stop cleanly", not "this task
     *    is done".
     *  - **A bubble-up with writes** — committed. A `Command` addressed to a
     *    parent still produced real output, and discarding it would lose state.
     *  - **A node error** — recorded as an `__error__` write rather than
     *    thrown immediately, so the other tasks in the step still get to
     *    finish and their writes are not lost. The error surfaces after the
     *    whole step, which is what {@see self::tick()} does.
     *  - **Success with no writes** — recorded as `__no_writes__`. A node that
     *    returned nothing is a *decision*, not a crash, and without this marker
     *    the loop could not distinguish it from a task that never ran.
     */
    /**
     * What a task produced, as upstream's `task_result.result` payload.
     *
     * Upstream's `result` is the task's state delta — the writes it committed,
     * keyed by channel — which is the same thing `updates` reports one mode over.
     * Assembled here rather than reused from `emitValues`, because the two differ:
     * a result is scoped to ONE task and carries the channel keys, while a
     * `values` chunk is the whole accumulated state.
     *
     * @return array<string, mixed>
     */
    private function taskResult(PregelExecutableTask $task): array
    {
        $out = [];
        foreach ($task->writes as $write) {
            $channel = $write[0] ?? null;
            if (!is_string($channel) || $channel === Constants::NO_WRITES) {
                continue;
            }
            $out[$channel] = $write[1] ?? null;
        }

        return $out;
    }

    private function commit(PregelExecutableTask $task, ?\Throwable $error): void
    {
        if ($error === null) {
            if ($task->writes === []) {
                $task->writes[] = [Constants::NO_WRITES, null];
            }
            $this->loop->putWrites($task->id, $task->writes);

            // `debug` only. Emitted AFTER the writes are persisted so the event
            // cannot claim a result the saver has not taken — a debug stream that
            // reports writes which were then lost to a failed write is worse than
            // one that reports nothing.
            $this->loop->emitDebug('task_result', [
                'id' => $task->id,
                'name' => $task->name,
                'interrupts' => $task->interrupts,
                'result' => $this->taskResult($task),
            ]);

            return;
        }

        if (Guard::isGraphInterrupt($error)) {
            if ($error->interrupts !== []) {
                $interrupts = [];
                foreach ($error->interrupts as $interrupt) {
                    $interrupts[] = [Constants::INTERRUPT, $interrupt];
                }
                foreach ($task->writes as $write) {
                    if ($write[0] === Constants::RESUME) {
                        $interrupts[] = $write;
                    }
                }
                $this->loop->putWrites($task->id, $interrupts);
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

    /**
     * Run one task under its retry policy.
     *
     * Port of `_runWithRetry`. Returns null on success, the throwable otherwise.
     *
     * The retry loop clears the task's writes before each attempt, so a failed
     * attempt's partial output cannot leak into a successful one — a node that
     * wrote a channel and then threw has produced *no* output, not half of one.
     *
     * The backoff sleep is skipped in two cases that would be pure latency: the
     * last attempt, and an error the policy declines to retry. `jitter` is
     * applied with a fixed seed so the schedule is deterministic and testable.
     */
    private function runWithRetry(PregelExecutableTask $task): ?\Throwable
    {
        $policy = $task->retryPolicy;
        $attempts = 0;
        $config = $task->config;

        $firstAttempt = true;

        while (true) {
            // A previous attempt's writes are void.
            $task->writes = [];

            try {
                \assert($task->proc !== null);
                // Publish the task config for the duration of the call, so a
                // node's `interrupt()` can reach the scratchpad even though the
                // node body itself takes only a state argument.
                PregelScratchpad::withConfig(
                    $config,
                    static fn (): mixed => $task->proc?->invoke($task->input, $config)
                );

                return null;
            } catch (\Throwable $e) {
                // A bubble-up is control flow, not a failure: never retried.
                if (Guard::isGraphBubbleUp($e)) {
                    return $e;
                }

                if ($policy === null) {
                    return $e;
                }

                $attempts += 1;

                if ($attempts >= $policy->effectiveMaxAttempts()) {
                    return $e;
                }

                if (!$policy->shouldRetry($e)) {
                    return $e;
                }

                $sleepMs = $policy->intervalFor($attempts, 0.5);

                if ($policy->shouldLogWarning()) {
                    trigger_error(
                        sprintf(
                            'Retrying task "%s" after %dms (attempt %d) after %s: %s',
                            $task->name,
                            $sleepMs,
                            $attempts,
                            $e::class,
                            $e->getMessage()
                        ),
                        E_USER_NOTICE
                    );
                }

                // Signals a subgraph to resume rather than restart.
                $config = clone $config;
                $config->configurable[Constants::CONFIG_KEY_RESUMING] = true;
                $firstAttempt = false;
            }
        }
    }
}
