<?php

declare(strict_types=1);

namespace LangChain\Load;

/**
 * Base class for every LangChain object that can round-trip through JSON.
 *
 * Port of `@langchain/core/load/serializable`. The JS original records the
 * constructor arguments in `lc_kwargs` and rehydrates via `lc_namespace` +
 * `lc_id`. PHP cannot read a constructor's arguments by reflection at runtime
 * in a way that is faithful across inheritance, so the port captures them
 * explicitly through {@see self::kwargs()} — which subclasses override, or which
 * the `SerializableTrait` fills in from the constructor.
 *
 * The serialized shape is intentionally byte-compatible with the JS one, so a
 * checkpoint written by either runtime is readable by the other:
 *
 * ```json
 * {
 *   "lc": 1,
 *   "type": "constructor",
 *   "id": ["langchain_core", "messages", "HumanMessage"],
 *   "kwargs": {"content": "hi", "additional_kwargs": {}, "response_metadata": {}}
 * }
 * ```
 */
abstract class Serializable implements \JsonSerializable
{
    /** Schema version for the serialized payload. */
    public const LC_SCHEMA_VERSION = 1;

    /** The kwargs this object was constructed with. */
    protected array $kwargs = [];

    /**
     * The serialization id: a path identifying the class.
     *
     * @return list<string>
     */
    abstract public static function lcId(): array;

    /**
     * Alternate ids under which this class may appear in a payload.
     *
     * @return array<string, string>
     */
    public static function lcAliases(): array
    {
        return [];
    }

    /** Whether this class participates in serialization at all. */
    public static function lcSerializable(): bool
    {
        return true;
    }

    /**
     * The constructor kwargs, used to build the serialized payload.
     */
    public function kwargs(): array
    {
        return $this->kwargs;
    }

    /**
     * Hook for subclasses to strip or rewrite kwargs before serializing.
     */
    protected function filterKwargs(array $kwargs): array
    {
        return $kwargs;
    }

    /**
     * Produce the `SerializedConstructor` shape for this object.
     *
     * @return array{id: list<string>, kwargs: array, lc: int}
     */
    public function toSerializedConstructor(): array
    {
        return [
            'lc' => self::LC_SCHEMA_VERSION,
            'type' => 'constructor',
            'id' => static::lcId(),
            'kwargs' => $this->filterKwargs($this->kwargs()),
        ];
    }

    /**
     * @return array{lc: int, type: string, id: list<string>, kwargs: array}
     */
    public function toJson(): array
    {
        return $this->toSerializedConstructor();
    }

    public function jsonSerialize(): mixed
    {
        return $this->toJson();
    }

    /**
     * The value used when a payload is not serializable.
     */
    public function lcNonSerializable(): string
    {
        return static::class;
    }
}
