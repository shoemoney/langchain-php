<?php

declare(strict_types=1);

namespace LangGraph\Prebuilt;

/**
 * A request for human action: what is being asked and the arguments it needs.
 *
 * Port of `ActionRequest` from `langgraph-core/src/prebuilt/interrupt.ts`.
 */
final class ActionRequest
{
    /**
     * @param string               $action The kind of action requested, e.g. "Approve XYZ action".
     * @param array<string, mixed> $args   The arguments the action needs.
     */
    public function __construct(
        public readonly string $action,
        public readonly array $args = [],
    ) {
    }

    /** @return array{action: string, args: array<string, mixed>} */
    public function toArray(): array
    {
        return ['action' => $this->action, 'args' => $this->args];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (!isset($data['action']) || !is_string($data['action'])) {
            throw new \InvalidArgumentException('ActionRequest requires a string "action".');
        }
        if (!isset($data['args']) || !is_array($data['args'])) {
            throw new \InvalidArgumentException('ActionRequest requires an "args" map.');
        }

        return new self($data['action'], $data['args']);
    }
}
