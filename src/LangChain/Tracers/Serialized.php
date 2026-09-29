<?php

declare(strict_types=1);

namespace LangChain\Tracers;

/**
 * The serialized form of a component, as it appears inside a trace run.
 *
 * Port of the `Serialized` type from `@langchain/core/load/serializable`. Every
 * LangChain object that reports to a tracer passes `toJSON()` here, so a run
 * records *which class and configuration* produced it rather than just its
 * inputs and outputs.
 */
final class Serialized
{
    /**
     * @param list<string>         $id     The class path, e.g. `['langchain_core', 'tools', 'DynamicTool']`.
     * @param array<string, mixed> $kwargs The constructor arguments.
     * @param int                  $lc     Schema version. Always 1 today.
     * @param string               $type   `'constructor'`, or `'not_implemented'`.
     */
    public function __construct(
        public array $id,
        public array $kwargs = [],
        public int $lc = 1,
        public string $type = 'constructor',
    ) {
    }

    /**
     * Build from the array shape {@see \LangChain\Load\Serializable::toJson()} produces.
     *
     * @param array<string, mixed> $serialized
     */
    public static function fromArray(array $serialized): self
    {
        $id = $serialized['id'] ?? [];

        return new self(
            id: array_values(array_map(strval(...), is_array($id) ? $id : [])),
            kwargs: is_array($serialized['kwargs'] ?? null) ? $serialized['kwargs'] : [],
            lc: is_int($serialized['lc'] ?? null) ? $serialized['lc'] : 1,
            type: is_string($serialized['type'] ?? null) ? $serialized['type'] : 'constructor',
        );
    }

    /**
     * The last segment of {@see self::$id} — the component's name in a trace.
     */
    public function name(): string
    {
        return $this->id === [] ? 'unknown' : (string) end($this->id);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'lc' => $this->lc,
            'type' => $this->type,
            'id' => $this->id,
            'kwargs' => $this->kwargs,
        ];
    }
}
