<?php

declare(strict_types=1);

namespace LangChain\Stores;

/**
 * In-memory implementation of {@see BaseStore} using an array.
 *
 * Port of `InMemoryStore` from `@langchain/core/stores`.
 *
 * @template T
 * @template-extends BaseStore<string, T>
 */
class InMemoryStore extends BaseStore
{
    /** @var array<string, T> */
    protected array $store = [];

    /** @return list<string> */
    public static function lcNamespace(): array
    {
        return ['langchain', 'storage'];
    }

    /** @return list<string> */
    public static function lcId(): array
    {
        return array_merge(static::lcNamespace(), ['InMemoryStore']);
    }

    /**
     * @param list<string> $keys
     * @return list<T|null>
     */
    public function mget(array $keys): array
    {
        return array_map(fn (string $key): mixed => $this->store[$key] ?? null, array_values($keys));
    }

    /** @param list<array{0: string, 1: T}> $keyValuePairs */
    public function mset(array $keyValuePairs): void
    {
        foreach ($keyValuePairs as [$key, $value]) {
            $this->store[(string) $key] = $value;
        }
    }

    /** @param list<string> $keys */
    public function mdelete(array $keys): void
    {
        foreach ($keys as $key) {
            unset($this->store[$key]);
        }
    }

    /** @return \Generator<int, string> */
    public function yieldKeys(?string $prefix = null): \Generator
    {
        // Snapshot first: PHP casts numeric-string keys to int, and a caller that
        // deletes while iterating must not skip entries.
        foreach (array_keys($this->store) as $key) {
            $key = (string) $key;
            if ($prefix === null || str_starts_with($key, $prefix)) {
                yield $key;
            }
        }
    }
}
