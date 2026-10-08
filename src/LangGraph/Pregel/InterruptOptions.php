<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

/**
 * Optional settings for {@see interrupt()}.
 *
 * Port of `InterruptOptions` from `langgraph-core/src/interrupt.ts`.
 *
 * `responseSchema` describes the value the graph expects on resume. It is
 * surfaced to clients on the interrupt as `response_schema` so they can render a
 * typed input form. It is a JSON Schema array and is passed through untouched:
 * upstream additionally parses the resume value when handed a Zod schema, and
 * this port has no Zod, so the resume value is never validated.
 */
final class InterruptOptions
{
    /**
     * @param array<string, mixed>|null $responseSchema A JSON Schema.
     */
    public function __construct(
        public readonly ?array $responseSchema = null,
    ) {
    }
}
