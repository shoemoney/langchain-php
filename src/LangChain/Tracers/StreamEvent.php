<?php

declare(strict_types=1);

namespace LangChain\Tracers;

/**
 * One event produced by `Runnable::streamEvents()`.
 *
 * Port of the `StreamEvent` type from `@langchain/core/tracers/event_stream`.
 *
 * Event names have the shape `on_[runnable_type]_(start|stream|end)`. The runnable type is one of
 * `llm` (non-chat models), `chat_model`, `prompt`, `tool`, `retriever` or `chain`.
 *
 * `data` is an associative array carrying whichever of `input`, `output`, `chunk` and `error` the
 * event type defines — an absent key means "not available", which is upstream's `undefined`. Custom
 * events (`on_custom_event`) carry whatever payload the caller dispatched, so `data` is `mixed`.
 */
final class StreamEvent
{
    /**
     * @param string               $event    `on_[runnable_type]_(start|stream|end)`.
     * @param string               $name     The name of the runnable that generated the event.
     * @param string               $runId    Identifies one execution of that runnable.
     * @param list<string>         $tags     Always inherited from parent runnables.
     * @param array<string, mixed> $metadata
     * @param mixed                $data
     */
    public function __construct(
        public string $event,
        public string $name,
        public string $runId,
        public array $tags = [],
        public array $metadata = [],
        public mixed $data = [],
    ) {
    }

    /**
     * The wire shape upstream emits (`run_id`, not `runId`).
     *
     * @return array{event: string, name: string, run_id: string, tags: list<string>, metadata: array<string, mixed>, data: mixed}
     */
    public function toArray(): array
    {
        return [
            'event' => $this->event,
            'name' => $this->name,
            'run_id' => $this->runId,
            'tags' => $this->tags,
            'metadata' => $this->metadata,
            'data' => $this->data,
        ];
    }

    /**
     * A copy with a different payload; the events themselves are otherwise immutable by convention.
     */
    public function withData(mixed $data): self
    {
        $copy = clone $this;
        $copy->data = $data;

        return $copy;
    }
}
