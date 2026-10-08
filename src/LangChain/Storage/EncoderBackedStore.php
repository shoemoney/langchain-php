<?php

declare(strict_types=1);

namespace LangChain\Storage;

use LangChain\Schema\Document;
use LangChain\Stores\BaseStore;

/**
 * A layer over another store that encodes keys and (de)serializes values.
 *
 * Port of `EncoderBackedStore` from `langchain/storage/encoder_backed`.
 *
 * @template K
 * @template V
 * @template SerializedType
 * @template-extends BaseStore<K, V>
 */
class EncoderBackedStore extends BaseStore
{
    /** @var BaseStore<string, SerializedType> */
    public BaseStore $store;

    /** @var \Closure(K): string */
    public \Closure $keyEncoder;

    /** @var \Closure(V): SerializedType */
    public \Closure $valueSerializer;

    /** @var \Closure(SerializedType): V */
    public \Closure $valueDeserializer;

    /**
     * @param array{store: BaseStore, keyEncoder: callable, valueSerializer: callable, valueDeserializer: callable} $fields
     */
    public function __construct(array $fields)
    {
        $this->store = $fields['store'];
        $this->keyEncoder = \Closure::fromCallable($fields['keyEncoder']);
        $this->valueSerializer = \Closure::fromCallable($fields['valueSerializer']);
        $this->valueDeserializer = \Closure::fromCallable($fields['valueDeserializer']);
    }

    /** @return list<string> */
    public static function lcNamespace(): array
    {
        return ['langchain', 'storage'];
    }

    /** @return list<string> */
    public static function lcId(): array
    {
        return array_merge(static::lcNamespace(), ['EncoderBackedStore']);
    }

    /**
     * @param list<K> $keys
     * @return list<V|null>
     */
    public function mget(array $keys): array
    {
        $encodedKeys = array_map($this->keyEncoder, array_values($keys));

        return array_map(
            fn (mixed $value): mixed => $value === null ? null : ($this->valueDeserializer)($value),
            $this->store->mget($encodedKeys),
        );
    }

    /** @param list<array{0: K, 1: V}> $keyValuePairs */
    public function mset(array $keyValuePairs): void
    {
        $encodedPairs = [];
        foreach ($keyValuePairs as [$key, $value]) {
            $encodedPairs[] = [($this->keyEncoder)($key), ($this->valueSerializer)($value)];
        }
        $this->store->mset($encodedPairs);
    }

    /** @param list<K> $keys */
    public function mdelete(array $keys): void
    {
        $this->store->mdelete(array_map($this->keyEncoder, array_values($keys)));
    }

    /** @return \Generator<int, string|K> */
    public function yieldKeys(?string $prefix = null): \Generator
    {
        yield from $this->store->yieldKeys($prefix);
    }

    /**
     * Upstream's `createDocumentStoreFromByteStore`: documents stored as JSON
     * `{pageContent, metadata}` bytes over a byte store.
     *
     * @param BaseStore<string, string> $store
     * @return self<string, Document, string>
     */
    public static function createDocumentStoreFromByteStore(BaseStore $store): self
    {
        return new self([
            'store' => $store,
            'keyEncoder' => static fn (string $key): string => $key,
            'valueSerializer' => static fn (Document $doc): string => json_encode(
                ['pageContent' => $doc->pageContent, 'metadata' => (object) $doc->metadata],
                \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
            ),
            'valueDeserializer' => static function (string $bytes): Document {
                /** @var array{pageContent?: string, metadata?: array<string, mixed>} $data */
                $data = json_decode($bytes, true, 512, \JSON_THROW_ON_ERROR);

                return new Document((string) ($data['pageContent'] ?? ''), $data['metadata'] ?? []);
            },
        ]);
    }
}
