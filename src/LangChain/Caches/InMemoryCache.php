<?php

declare(strict_types=1);

namespace LangChain\Caches;

/**
 * A cache for LLM generations that stores data in memory.
 *
 * Port of `InMemoryCache` from `@langchain/core/caches`. The backing map is an
 * `\ArrayObject` because upstream shares a `Map` between instances by reference
 * (see {@see self::global()}) and a plain PHP array would be copied.
 *
 * @template T
 * @template-extends BaseCache<T>
 */
class InMemoryCache extends BaseCache
{
    private static ?\ArrayObject $globalMap = null;

    private \ArrayObject $cache;

    public function __construct(?\ArrayObject $map = null)
    {
        parent::__construct();
        $this->cache = $map ?? new \ArrayObject();
    }

    public function lookup(string $prompt, string $llmKey): mixed
    {
        return $this->cache[($this->keyEncoder)($prompt, $llmKey)] ?? null;
    }

    public function update(string $prompt, string $llmKey, mixed $value): void
    {
        $this->cache[($this->keyEncoder)($prompt, $llmKey)] = $value;
    }

    /**
     * An instance over the process-wide map. `cache: true` on a model uses this.
     */
    public static function global(): self
    {
        self::$globalMap ??= new \ArrayObject();

        return new self(self::$globalMap);
    }
}
