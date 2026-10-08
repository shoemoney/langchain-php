<?php

declare(strict_types=1);

namespace LangGraph\Prebuilt;

/**
 * An interrupt that asks a human to act: the value handed to `interrupt()` when the graph pauses.
 *
 * Port of `HumanInterrupt` from `langgraph-core/src/prebuilt/interrupt.ts`. Upstream types are plain objects
 * with snake_case keys, which is also the form that crosses the checkpoint and the wire, so {@see self::toArray()}
 * and {@see self::fromArray()} are the bridge: pass `$interrupt->toArray()` to `interrupt()` and rebuild with
 * `fromArray()` on the side that reads it.
 */
final class HumanInterrupt
{
    public function __construct(
        public readonly ActionRequest $actionRequest,
        public readonly HumanInterruptConfig $config,
        /** Optional detail on what input is needed. */
        public readonly ?string $description = null,
    ) {
    }

    /** @return array{action_request: array{action: string, args: array<string, mixed>}, config: array<string, bool>, description?: string} */
    public function toArray(): array
    {
        $out = [
            'action_request' => $this->actionRequest->toArray(),
            'config' => $this->config->toArray(),
        ];
        if ($this->description !== null) {
            $out['description'] = $this->description;
        }

        return $out;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        if (!isset($data['action_request']) || !is_array($data['action_request'])) {
            throw new \InvalidArgumentException('HumanInterrupt requires an "action_request".');
        }
        if (!isset($data['config']) || !is_array($data['config'])) {
            throw new \InvalidArgumentException('HumanInterrupt requires a "config".');
        }

        return new self(
            ActionRequest::fromArray($data['action_request']),
            HumanInterruptConfig::fromArray($data['config']),
            isset($data['description']) && is_string($data['description']) ? $data['description'] : null,
        );
    }
}
