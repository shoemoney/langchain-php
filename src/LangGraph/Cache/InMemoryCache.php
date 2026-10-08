<?php

declare(strict_types=1);

namespace LangGraph\Cache;

/**
 * A process-local {@see BaseCache} with per-entry expiry.
 *
 * Port of `InMemoryCache` from `cache/memory.ts`. Values are serialized on the
 * way in and deserialized on the way out, so a caller can never mutate a cached
 * value through a reference it still holds. Namespaces are keyed by their
 * labels joined with `,`.
 *
 * @template V
 * @extends BaseCache<V>
 */
class InMemoryCache extends BaseCache
{
    /** @var array<string, array<string, array{enc: string, val: string, exp: float|null}>> */
    private array $cache = [];

    public function get(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $now = self::nowMs();
        $found = [];
        foreach ($keys as $fullKey) {
            [$namespace, $key] = $fullKey;
            $strNamespace = implode(',', $namespace);
            $cached = $this->cache[$strNamespace][$key] ?? null;
            if ($cached === null) {
                continue;
            }

            if ($cached['exp'] === null || $now < $cached['exp']) {
                $found[] = ['key' => $fullKey, 'value' => $this->serde->loadsTyped($cached['enc'], $cached['val'])];
            } else {
                unset($this->cache[$strNamespace][$key]);
            }
        }

        return $found;
    }

    public function set(array $pairs): void
    {
        $now = self::nowMs();
        foreach ($pairs as $pair) {
            [$namespace, $key] = $pair['key'];
            $strNamespace = implode(',', $namespace);
            [$enc, $val] = $this->serde->dumpsTyped($pair['value']);
            $ttl = $pair['ttl'] ?? null;

            $this->cache[$strNamespace][$key] = [
                'enc' => $enc,
                'val' => $val,
                'exp' => $ttl !== null ? $ttl * 1000 + $now : null,
            ];
        }
    }

    public function clear(array $namespaces): void
    {
        if ($namespaces === []) {
            $this->cache = [];

            return;
        }

        foreach ($namespaces as $namespace) {
            unset($this->cache[implode(',', $namespace)]);
        }
    }

    private static function nowMs(): float
    {
        return microtime(true) * 1000;
    }
}
