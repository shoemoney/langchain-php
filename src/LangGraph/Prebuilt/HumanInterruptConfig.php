<?php

declare(strict_types=1);

namespace LangGraph\Prebuilt;

/**
 * What a human may do when the graph pauses for them.
 *
 * Port of `HumanInterruptConfig` from `langgraph-core/src/prebuilt/interrupt.ts`.
 */
final class HumanInterruptConfig
{
    public function __construct(
        /** Whether the human can skip the current step. */
        public readonly bool $allowIgnore,
        /** Whether the human can answer with free text. */
        public readonly bool $allowRespond,
        /** Whether the human can edit the proposed action. */
        public readonly bool $allowEdit,
        /** Whether the human can approve the action as it stands. */
        public readonly bool $allowAccept,
    ) {
    }

    /** @return array{allow_ignore: bool, allow_respond: bool, allow_edit: bool, allow_accept: bool} */
    public function toArray(): array
    {
        return [
            'allow_ignore' => $this->allowIgnore,
            'allow_respond' => $this->allowRespond,
            'allow_edit' => $this->allowEdit,
            'allow_accept' => $this->allowAccept,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['allow_ignore', 'allow_respond', 'allow_edit', 'allow_accept'] as $key) {
            if (!array_key_exists($key, $data) || !is_bool($data[$key])) {
                throw new \InvalidArgumentException(sprintf('HumanInterruptConfig requires a boolean "%s".', $key));
            }
        }

        return new self($data['allow_ignore'], $data['allow_respond'], $data['allow_edit'], $data['allow_accept']);
    }
}
