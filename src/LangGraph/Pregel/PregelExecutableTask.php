<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangChain\Runnables\RunnableInterface;
use LangChain\Runnables\RunnableConfig;
use LangGraph\Pregel\Retry\RetryPolicy;

/**
 * One unit of work scheduled for a superstep.
 *
 * Port of `PregelTaskDescription` / `PregelExecutableTask` from
 * `langgraph-core/src/pregel/types.ts`.
 *
 * The two TS types are the same object at different stages: a *description* is
 * what a caller can see about a task (its id, name, interrupts, path), while an
 * *executable* task additionally carries the runnable and the config that will
 * run it. They are unified here because PHP's dynamic typing makes the
 * distinction unobservable — and, more importantly, because a partially
 * described task must not be executable. {@see self::isExecutable()} makes that
 * boundary explicit rather than relying on a missing property throwing.
 */
class PregelExecutableTask implements WritesProtocol
{
    /**
     * @param string                   $id       Deterministic id; stable across re-preparation.
     * @param string                   $name     The node's name.
     * @param mixed                    $input    The input handed to the runnable.
     * @param list<array{0: string, 1: mixed}> $writes Channel writes accumulated so far.
     * @param list<string>             $triggers Channels whose versions this task consumed.
     * @param TaskPath|null            $path     Where this task sits in the step.
     * @param RunnableInterface|null   $proc     The runnable, once resolved.
     * @param RunnableConfig|null      $config   The config the runnable runs with.
     * @param list<RunnableInterface>  $writers  Post-node runnables (ChannelWrite etc.).
     * @param RetryPolicy|null         $retryPolicy Per-node retry policy.
     * @param array{ns: list<string>, key: string, ttl: int|null}|null $cacheKey
     * @param list<array{id: string|null, value: mixed}> $interrupts
     */
    public function __construct(
        public string $id = '',
        public string $name = '',
        public mixed $input = null,
        public array $writes = [],
        public array $triggers = [],
        public ?TaskPath $path = null,
        public ?RunnableInterface $proc = null,
        public ?RunnableConfig $config = null,
        public array $writers = [],
        public ?RetryPolicy $retryPolicy = null,
        public ?array $cacheKey = null,
        public array $interrupts = [],
        public mixed $timeout = null,
        public array $metadata = [],
        public array $subgraphs = [],
        public ?string $errorHandlerNode = null,
        public bool $isErrorHandler = false,
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
        return $this->writes;
    }

    public function path(): ?TaskPath
    {
        return $this->path;
    }

    /**
     * True once a runnable has been resolved.
     *
     * A task with no runnable is a description the scheduler could not turn into
     * work — {@see Algorithm::prepareSingleTask()} returns `null` rather than
     * half-built tasks, so in practice this is always true for a task that
     * reached the runner.
     */
    public function isExecutable(): bool
    {
        return $this->proc instanceof RunnableInterface;
    }

    /**
     * A copy carrying only the descriptive fields.
     *
     * Used by the `tasks` stream mode, which must not leak a task's runnable or
     * config into a stream payload.
     */
    public function toDescription(): PregelTaskDescription
    {
        return new PregelTaskDescription(
            id: $this->id,
            name: $this->name,
            interrupts: $this->interrupts,
            path: $this->path,
        );
    }
}
