<?php

declare(strict_types=1);

namespace LangGraph\Errors;

/**
 * Raised by `interrupt()` to pause a graph and ask the caller a question.
 *
 * Port of `GraphInterrupt`.
 *
 * An interrupt is a *bubble-up*: it unwinds the node stack to the loop, which
 * converts it into pending writes on the `__interrupt__` channel, checkpoints
 * that state, and returns control to the caller. The run is not failed; it is
 * suspended, and a later `invoke(Command(['resume' => ...]))` continues it.
 *
 * Interrupt *values* live in {@see self::$interrupts}, not in the message. Each
 * one is `['value' => mixed, 'id' => ?string]`. The message is the JSON
 * rendering, kept only so a bare `getMessage()` is still informative.
 */
class GraphInterrupt extends GraphBubbleUp
{
    public const UNMINIFIABLE_NAME = 'GraphInterrupt';

    /**
     * The pending interrupt payloads, in the order they were raised.
     *
     * @var list<array{id: string|null, value: mixed, response_schema?: array<string, mixed>}>
     */
    public array $interrupts;

    /**
     * @param list<array{id?: string|null, value: mixed, response_schema?: array<string, mixed>}>|null $interrupts
     * @param array<string, mixed>                              $fields
     */
    public function __construct(?array $interrupts = null, array $fields = [])
    {
        $this->interrupts = [];
        foreach ($interrupts ?? [] as $interrupt) {
            $entry = [
                'id' => $interrupt['id'] ?? null,
                'value' => $interrupt['value'] ?? null,
            ];
            if (isset($interrupt['response_schema'])) {
                $entry['response_schema'] = $interrupt['response_schema'];
            }
            $this->interrupts[] = $entry;
        }

        parent::__construct(
            (string) json_encode($this->interrupts, JSON_PRETTY_PRINT),
            $fields
        );
    }
}
