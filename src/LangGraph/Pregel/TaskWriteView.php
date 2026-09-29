<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

/**
 * A live view of a task's writes, for a read that folds them in.
 *
 * Port of the `{ name, writes, triggers, path }` object literal that
 * `algo.ts` builds inline for the `CONFIG_KEY_READ` closure.
 *
 * A class rather than an array because PHP arrays are values, not references:
 * reading `$task->writes` at call time is what makes a `fresh` read see writes
 * the task produced *after* the read function was injected. A snapshot taken
 * when the closure was built would be frozen at task-preparation time and a
 * `fresh` read would never see anything.
 */
final class TaskWriteView implements WritesProtocol
{
    /**
     * @param list<string> $triggers
     */
    public function __construct(
        private readonly string $name,
        public readonly PregelExecutableTask $task,
        private readonly array $triggers,
        private readonly TaskPath $taskPath,
    ) {
    }

    public function writesName(): string
    {
        return $this->name;
    }

    public function triggers(): array
    {
        return $this->triggers;
    }

    public function writesList(): array
    {
        return $this->task->writes;
    }

    public function path(): ?TaskPath
    {
        return $this->taskPath;
    }
}
