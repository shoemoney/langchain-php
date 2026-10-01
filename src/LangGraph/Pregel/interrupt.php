<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangGraph\Errors\GraphInterrupt;
use LangGraph\Errors\GraphValueError;

/**
 * Pause a node and ask the caller a question.
 *
 * Port of `interrupt()` from `langgraph-core/src/interrupt.ts`.
 *
 * The mechanic is a *re-execution*, not a suspension. When `interrupt()` is
 * called with no resume value waiting, it throws; the task's output is
 * checkpointed as an interrupt, and the run returns. When the caller resumes,
 * **the node runs again from the top** — and this time a resume value is
 * waiting, so it returns instead of throwing.
 *
 * That re-execution is why node code before the `interrupt()` call runs twice,
 * and it is a real constraint rather than an implementation detail: a node must
 * not perform a side effect it cannot repeat. What makes the second run
 * distinguishable is {@see PregelScratchpad::$interruptCounter} — the Nth
 * `interrupt()` in a node takes the Nth resume value, so a node with three
 * interrupts can be resumed three times, once per question.
 *
 * The write that carries a resume value is deleted the first time it is
 * consumed, so a node that loops cannot accidentally read the same answer twice.
 *
 * @throws GraphInterrupt        when no resume value is waiting
 * @throws GraphValueError       when called outside a Pregel task
 */
// This file is BOTH a composer `autoload.files` entry (PHP cannot autoload functions, so the
// bootstrap must include it) AND reachable through the PSR-4 class loader, because its basename is a
// valid class name in this namespace. Composer's `files` guard only protects its own includes; the
// class loader uses a plain `include`. So `class_exists(__NAMESPACE__ . '\\interrupt')` includes this
// file a SECOND time, and an unguarded declaration raises an uncatchable
// `Cannot redeclare function` fatal that kills the process.
//
// The guard makes the second include a no-op. Every test in this suite calls the function and none
// probes for the class, so nothing else would ever observe this.
if (!\function_exists(__NAMESPACE__ . '\\interrupt')) {
    function interrupt(mixed $value = null): mixed
    {
        $config = PregelScratchpad::currentConfig();
        if ($config === null) {
            throw new GraphValueError(
                'interrupt() must be called from within a graph node. '
                . 'It has no way to reach the graph state from outside a task.'
            );
        }

        $scratchpad = $config->configurable[Constants::CONFIG_KEY_SCRATCHPAD] ?? null;
        if (!$scratchpad instanceof PregelScratchpad) {
            throw new GraphValueError('interrupt() called outside a Pregel task');
        }

        $scratchpad->interruptCounter += 1;

        // Resume values queued for this specific task first, then the
        // graph-wide one. A task-specific value wins so a parent can answer one
        // node's question without disturbing the others.
        if ($scratchpad->resume !== []) {
            return array_shift($scratchpad->resume);
        }

        $nullResume = $scratchpad->consumeNullResume();
        if ($nullResume !== null) {
            return $nullResume;
        }

        throw new GraphInterrupt([[
            'id' => $config->configurable[Constants::CONFIG_KEY_TASK_ID] ?? null,
            'value' => $value,
        ]]);
    }

}
