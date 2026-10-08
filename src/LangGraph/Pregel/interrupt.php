<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangGraph\Errors\GraphInterrupt;
use LangGraph\Errors\GraphValueError;
use LangGraph\Utils\Hash;

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
 * The values are not consumed: each `interrupt()` that returns writes the
 * resume values answered so far back as a `RESUME` write against the task, so
 * the next re-execution replays them in order and only the unanswered
 * interrupt throws. The thrown interrupt's id is the XXH3 hash of the task's
 * checkpoint namespace — the key a `Command(resume: [id => value])` map is
 * looked up by.
 *
 * `$options` accepts an {@see InterruptOptions} or its array form
 * (`['responseSchema' => [...]]`). The schema is surfaced on the interrupt as
 * `response_schema`; it is not used to validate the resume value.
 *
 * @throws GraphInterrupt        when no resume value is waiting
 * @throws GraphValueError       when called outside a Pregel task, or when the graph has no checkpointer
 * @param  InterruptOptions|array{responseSchema?: array<string, mixed>|null}|null $options
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
    function interrupt(mixed $value = null, InterruptOptions|array|null $options = null): mixed
    {
        $config = PregelScratchpad::currentConfig();
        if ($config === null) {
            throw new GraphValueError(
                'interrupt() must be called from within a graph node. '
                . 'It has no way to reach the graph state from outside a task.'
            );
        }

        $conf = $config->configurable;

        if (($conf[Constants::CONFIG_KEY_CHECKPOINTER] ?? null) === null) {
            throw new GraphValueError('No checkpointer set', ['lc_error_code' => 'MISSING_CHECKPOINTER']);
        }

        $scratchpad = $conf[Constants::CONFIG_KEY_SCRATCHPAD] ?? null;
        if (!$scratchpad instanceof PregelScratchpad) {
            throw new GraphValueError('interrupt() called outside a Pregel task');
        }

        $responseSchema = $options instanceof InterruptOptions
            ? $options->responseSchema
            : ($options['responseSchema'] ?? null);

        $send = $conf[Constants::CONFIG_KEY_SEND] ?? null;

        $scratchpad->interruptCounter += 1;
        $idx = $scratchpad->interruptCounter;

        // A resume value already recorded for this position: the node is being
        // re-executed, so replay it and persist only through the interrupt being
        // consumed. Values past `$idx` belong to later interrupts and may be
        // unvalidated mapped resumes.
        if ($scratchpad->resume !== [] && $idx < \count($scratchpad->resume)) {
            if (\is_callable($send)) {
                $send([[Constants::RESUME, \array_slice($scratchpad->resume, 0, $idx + 1)]]);
            }

            return $scratchpad->resume[$idx];
        }

        // The graph-wide resume value answers the first interrupt not yet answered.
        if ($scratchpad->nullResume !== null) {
            if (\count($scratchpad->resume) !== $idx) {
                throw new \RuntimeException(
                    'Resume length mismatch: ' . \count($scratchpad->resume) . ' !== ' . $idx
                );
            }

            $resume = $scratchpad->consumeNullResume();
            $scratchpad->resume[] = $resume;
            if (\is_callable($send)) {
                $send([[Constants::RESUME, $scratchpad->resume]]);
            }

            return $resume;
        }

        // The interrupt id is the hash of the task's checkpoint namespace, which is
        // what a `Command(resume: [id => value])` map is keyed by.
        $ns = $conf[Constants::CONFIG_KEY_CHECKPOINT_NS] ?? null;
        $pending = [
            'id' => \is_string($ns) ? Hash::xxh3($ns) : null,
            'value' => $value,
        ];
        if ($responseSchema !== null) {
            $pending['response_schema'] = $responseSchema;
        }

        throw new GraphInterrupt([$pending]);
    }

}
