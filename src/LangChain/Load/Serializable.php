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

    /** Opt-in capture is independent of legacy kwargs and is empty until explicitly captured. */
    private mixed $sourceSerializationCapturedBag = [];

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
     * @return array{lc: int, type: string, id: list<string>, kwargs?: array|\stdClass}
     */
    public function toSerializedConstructor(): array
    {
        if ($this->sourceSerializationEnabled()) {
            $bag = $this->sourceSerializationCapturedBag;
            $eligible = $this->sourceSerializationEligible();
            if (!self::sourceContractCanSerialize($bag, $eligible)) {
                return self::serializeSourceContract($this, $bag, static::lcId(), $eligible, [], [], []);
            }
            $refreshState = self::sourceContractPublicState($this);
            $layers = self::sourceContractHookLayers($this, [
                'aliases' => 'sourceSerializationAliases',
                'secrets' => 'sourceSerializationSecrets',
                'attributes' => 'sourceSerializationAttributes',
            ]);

            return self::serializeSourceContract(
                $this, $bag, static::lcId(), $eligible, $layers,
                $refreshState, self::sourceContractPublicState($this),
            );
        }

        return [
            'lc' => self::LC_SCHEMA_VERSION,
            'type' => 'constructor',
            'id' => static::lcId(),
            'kwargs' => $this->filterKwargs(Validation::escapeKwargs($this->kwargs(), $this)),
        ];
    }

    /**
     * @return array{lc: int, type: string, id: list<string>, kwargs?: array|\stdClass}
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

    /** @internal Normalize constructor null and apply a capture whitelist exactly once. */
    public static function captureSourceContractBag(mixed $input, ?array $keys = null): mixed
    {
        $input ??= [];
        if ($keys === null) {
            return $input;
        }
        if (!is_array($input) || ($input !== [] && array_is_list($input))) {
            throw new \InvalidArgumentException('Source capture whitelist requires an associative constructor bag.');
        }
        $result = [];
        foreach ($input as $key => $value) {
            if (in_array($key, $keys, true)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /** @internal Immutable undefined token; ordinary strings are never treated as undefined. */
    public static function sourceContractUndefined(): SourceContractUndefined
    {
        return SourceContractUndefined::Value;
    }

    /** @internal Read only initialized public instance fields, without magic access. */
    public static function sourceContractPublicState(object $owner): array
    {
        $state = [];
        foreach ((new \ReflectionObject($owner))->getProperties() as $property) {
            if ($property->isPublic() && !$property->isStatic() && $property->isInitialized($owner)) {
                $state[$property->getName()] = $property->getValue($owner);
            }
        }

        return $state;
    }

    /**
     * @internal Evaluate receiver-aware hooks on every ancestor, including inherited repetitions.
     * Hooks may mutate their receiver. Call between pre-hook and post-hook public snapshots.
     *
     * @param array{aliases: string, secrets: string, attributes: string} $hookNames
     *
     * @return list<array{aliases: array, secrets: array, attributes: array}>
     */
    public static function sourceContractHookLayers(object $owner, array $hookNames): array
    {
        $layers = [];
        for ($class = new \ReflectionClass($owner); $class !== false; $class = $class->getParentClass()) {
            $layer = [];
            foreach (['aliases', 'secrets', 'attributes'] as $kind) {
                $name = $hookNames[$kind];
                $value = [];
                if ($class->hasMethod($name)) {
                    $method = $class->getMethod($name);
                    if ($method->isStatic() || !$method->getDeclaringClass()->isInstance($owner)) {
                        throw new \InvalidArgumentException('Source serialization hooks must be compatible instance methods.');
                    }
                    $value = $method->invoke($owner);
                    if (!is_array($value)) {
                        throw new \InvalidArgumentException('Source serialization hooks must return arrays.');
                    }
                }
                $layer[$kind] = $value;
            }
            $layers[] = $layer;
        }

        return $layers;
    }

    /** @internal PHP empty bags represent source object/no-arg input; object bags are unsupported. */
    public static function sourceContractCanSerialize(mixed $capturedBag, bool $eligible): bool
    {
        return $eligible && is_array($capturedBag) && ($capturedBag === [] || !array_is_list($capturedBag));
    }

    /**
     * @internal Stateless explicit-input core, pure modulo existing escape traversal calling user JsonSerializable.
     * No owner hooks are evaluated here and no caller/live/native object is written.
     * Numeric JS property ordering and arbitrary nested undefined are outside this typed contract.
     *
     * @param list<string> $id
     * @param list<array{aliases: array, secrets: array, attributes: array}> $layers child to parent
     *
     * @return array{lc: int, type: string, id: list<string>, kwargs?: array|\stdClass}
     */
    public static function serializeSourceContract(
        object $owner,
        mixed $capturedBag,
        array $id,
        bool $eligible,
        array $layers,
        array $refreshState,
        array $secretState,
    ): array {
        $record = ['lc' => self::LC_SCHEMA_VERSION, 'type' => 'not_implemented', 'id' => $id];
        if (!self::sourceContractCanSerialize($capturedBag, $eligible)) {
            return $record;
        }
        $kwargs = [];
        foreach ($capturedBag as $key => $value) {
            $kwargs[$key] = array_key_exists($key, $refreshState) ? $refreshState[$key] : $value;
        }
        $aliases = [];
        $secrets = [];
        foreach ($layers as $layer) {
            foreach ($layer['aliases'] as $key => $value) {
                $aliases[$key] = $value;
            }
            foreach ($layer['secrets'] as $key => $value) {
                $secrets[$key] = $value;
            }
            foreach ($layer['attributes'] as $key => $value) {
                $kwargs[$key] = $value;
            }
        }
        foreach ($secrets as $path => $secretId) {
            $parts = explode('.', $path);
            $read = self::sourcePathRead($secretState, $parts);
            if ($read === SourceContractUndefined::Value) {
                continue;
            }
            $written = self::sourcePathRead($kwargs, $parts);
            if (!self::sourceTruthy($written) && $written !== $read) {
                $kwargs = self::sourcePathWrite($kwargs, $parts, $read);
            }
        }
        $kwargs = Validation::escapeKwargs($kwargs, $owner);
        foreach ($secrets as $path => $secretId) {
            $parts = explode('.', $path);
            if (self::sourcePathRead($kwargs, $parts) !== SourceContractUndefined::Value) {
                $kwargs = self::sourcePathWrite($kwargs, $parts, [
                    'lc' => self::LC_SCHEMA_VERSION, 'type' => 'secret', 'id' => [$secretId],
                ]);
            }
        }
        $mapped = self::sourceOutboundMap($kwargs, $aliases);
        // Alias writes happen first: a later undefined alias may clobber an earlier defined key.
        foreach ($mapped as $key => $value) {
            if ($value === SourceContractUndefined::Value) {
                unset($mapped[$key]);
            }
        }

        return ['lc' => self::LC_SCHEMA_VERSION, 'type' => 'constructor', 'id' => $id,
            'kwargs' => $mapped === [] ? new \stdClass() : $mapped];
    }

    /** Copy only the traversed path; never write through caller references or live/native objects. */
    private static function sourcePathWrite(mixed $container, array $parts, mixed $value): mixed
    {
        if ($container === SourceContractUndefined::Value) {
            $container = [];
        }
        if (is_object($container)) {
            $copy = new \stdClass();
            foreach (self::sourceContractPublicState($container) as $key => $field) {
                $copy->{$key} = $field;
            }
        } else {
            $copy = [];
            if (is_array($container)) {
                foreach ($container as $key => $field) {
                    $copy[$key] = $field;
                }
            }
        }
        $key = array_shift($parts);
        $next = $parts === [] ? $value : self::sourcePathWrite(self::sourcePathRead($copy, [$key]), $parts, $value);
        if (is_object($copy)) {
            $copy->{$key} = $next;
        } else {
            $copy[$key] = $next;
        }

        return $copy;
    }

    private static function sourcePathRead(mixed $container, array $parts): mixed
    {
        foreach ($parts as $key) {
            if ($container === SourceContractUndefined::Value) {
                return SourceContractUndefined::Value;
            }
            $fields = is_object($container) ? self::sourceContractPublicState($container) : $container;
            if (!is_array($fields) || !array_key_exists($key, $fields)) {
                return SourceContractUndefined::Value;
            }
            $container = $fields[$key];
        }

        return $container;
    }

    private static function sourceTruthy(mixed $value): bool
    {
        return $value !== SourceContractUndefined::Value && $value !== null && $value !== false
            && $value !== 0 && $value !== 0.0 && $value !== '' && !(is_float($value) && is_nan($value));
    }

    /** Outbound mapper is deliberately independent of the loader's inverse mapper. */
    private static function sourceOutboundMap(array $kwargs, array $aliases): array
    {
        $mapped = [];
        foreach ($kwargs as $key => $value) {
            $alias = $aliases[$key] ?? '';
            $outbound = $alias !== '' ? $alias : mb_strtolower((string) preg_replace(
                ['/([A-Z]+)([A-Z][a-z0-9]+)/', '/([a-z0-9])([A-Z])/', '/[-_\s]+/'],
                ['$1_$2', '$1_$2', '_'], (string) $key,
            ), 'UTF-8');
            $mapped[$outbound] = $value;
        }

        return $mapped;
    }

    protected function sourceSerializationEnabled(): bool
    {
        return false;
    }

    protected function sourceSerializationEligible(): bool
    {
        return false;
    }

    protected function sourceSerializationCaptureKeys(): ?array
    {
        return null;
    }

    protected function sourceSerializationAliases(): array
    {
        return [];
    }

    protected function sourceSerializationSecrets(): array
    {
        return [];
    }

    protected function sourceSerializationAttributes(): array
    {
        return [];
    }

    protected function captureSourceSerializationBag(mixed $input): void
    {
        $this->sourceSerializationCapturedBag = self::captureSourceContractBag($input, $this->sourceSerializationCaptureKeys());
    }
}

/** @internal Fieldless, immutable source JSON-boundary undefined token. */
enum SourceContractUndefined
{
    case Value;
}
