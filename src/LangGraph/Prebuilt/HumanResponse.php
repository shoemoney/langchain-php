<?php

declare(strict_types=1);

namespace LangGraph\Prebuilt;

/**
 * The human's answer to a {@see HumanInterrupt}, returned from `interrupt()` when the graph resumes.
 *
 * Port of the `HumanResponse` type from `langgraph-core/src/prebuilt/interrupt.ts`.
 *
 *  - `accept`   approves the proposed action unchanged; `args` is null.
 *  - `ignore`   skips the step; `args` is null.
 *  - `response` gives free-text feedback; `args` is a string.
 *  - `edit`     replaces the proposed action; `args` is an {@see ActionRequest}.
 */
final class HumanResponse
{
    public const ACCEPT = 'accept';
    public const IGNORE = 'ignore';
    public const RESPONSE = 'response';
    public const EDIT = 'edit';

    private const TYPES = [self::ACCEPT, self::IGNORE, self::RESPONSE, self::EDIT];

    public function __construct(
        /** @var 'accept'|'ignore'|'response'|'edit' */
        public readonly string $type,
        public readonly string|ActionRequest|null $args = null,
    ) {
        if (!in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException(sprintf('Unknown human response type "%s".', $type));
        }
    }

    /** @return array{type: string, args: null|string|array{action: string, args: array<string, mixed>}} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'args' => $this->args instanceof ActionRequest ? $this->args->toArray() : $this->args,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $type = $data['type'] ?? null;
        if (!is_string($type)) {
            throw new \InvalidArgumentException('HumanResponse requires a string "type".');
        }

        $args = $data['args'] ?? null;
        if (is_array($args)) {
            $args = ActionRequest::fromArray($args);
        }
        if ($args !== null && !is_string($args) && !$args instanceof ActionRequest) {
            throw new \InvalidArgumentException('HumanResponse "args" must be null, a string, or an action request.');
        }

        return new self($type, $args);
    }
}
