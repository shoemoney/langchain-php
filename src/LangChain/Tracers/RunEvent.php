<?php

declare(strict_types=1);

namespace LangChain\Tracers;

/**
 * One entry in a run's event log.
 *
 * Port of the inline `{ name, time, kwargs }` records the TypeScript tracers
 * push onto `run.events`. Kept as a class rather than an array because the
 * tracer tests assert on the exact shape and a named type documents which
 * events exist.
 */
final class RunEvent
{
    /**
     * @param array<string, mixed> $kwargs Event-specific payload: `{token, idx}`,
     *                                      `{chunk}`, `{text}`, or `{action}`.
     */
    public function __construct(
        public string $name,
        public string $time,
        public array $kwargs = [],
    ) {
    }

    /** @return array{name: string, time: string, kwargs?: array<string, mixed>} */
    public function toArray(): array
    {
        $out = ['name' => $this->name, 'time' => $this->time];
        if ($this->kwargs !== []) {
            $out['kwargs'] = $this->kwargs;
        }

        return $out;
    }
}
