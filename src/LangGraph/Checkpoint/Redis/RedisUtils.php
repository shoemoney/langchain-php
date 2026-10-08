<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint\Redis;

use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\Serde\BaseCheckpointSerializer;
use LangGraph\Pregel\Checkpoint\Checkpoint as PregelCheckpoint;

/**
 * Helpers shared by {@see RedisSaver} and {@see ShallowRedisSaver}.
 *
 * Port of `utils.ts` and `constants.ts` from `@langchain/langgraph-checkpoint-redis`,
 * plus the helpers the two upstream savers each carry a private copy of
 * (`ensureIndexes`, `applyTTL`, `addSearchableMetadataFields`, the RediSearch
 * query builder and `deterministicStringify`).
 */
final class RedisUtils
{
    /** Key prefix of the sorted set that indexes a checkpoint's write keys. */
    public const WRITE_KEYS_ZSET_PREFIX = 'write_keys_zset';

    /** Matches a Redis glob/escape metacharacter. */
    private const REDIS_KEY_FORBIDDEN = '/[*?\[\]\\\\]/';

    /** Last `global_idx` handed out, so successive `putWrites` calls never interleave. */
    private static float $lastWriteIndex = 0.0;

    /** Last `checkpoint_ts` issued; keeps it strictly increasing within a process. */
    private static int $lastTimestamp = 0;

    private function __construct()
    {
    }

    /**
     * Escape a string for use inside a RediSearch TAG query `{...}`.
     *
     * Port of `escapeRediSearchTagValue`. RediSearch treats
     * `, . < > { } [ ] " ' : ; ! @ # $ % ^ & * ( ) - + = ~ | \ ? /` and whitespace as
     * syntax inside a tag clause, so a caller-supplied filter value must have all of
     * them neutralised or it can close the clause and inject its own query. The empty
     * string has no valid escaped form and becomes a placeholder.
     */
    public static function escapeRediSearchTagValue(string $value): string
    {
        if ($value === '') {
            return '__EMPTY_STRING__';
        }

        // Backslashes first, so the escapes added below are not themselves doubled.
        $escaped = str_replace('\\', '\\\\', $value);
        $pattern = '/[-\s,.:<>{}\[\]"\';!@#$%^&*()+=~|?\/]/';

        return preg_replace($pattern . 'u', '\\\\$0', $escaped)
            ?? preg_replace($pattern, '\\\\$0', $escaped)
            ?? $escaped;
    }

    /**
     * Refuse a value that is unsafe to embed in a Redis key or `KEYS` pattern.
     *
     * Port of `assertSafeKeyComponent`. `thread_id`, `checkpoint_ns`, `checkpoint_id`
     * and `task_id` come from `configurable`, which in a multi-tenant deployment is
     * request input. Interpolated unchecked, a `thread_id` of `*` turns `deleteThread`
     * into `KEYS checkpoint:*:*` plus `DEL` — a wipe of every tenant (CWE-77/CWE-943).
     *
     * The `:` delimiter is deliberately allowed: LangGraph builds subgraph namespaces
     * as `name:taskId|name:taskId`, and a literal colon cannot widen a glob.
     *
     * @param string $field      The configurable key, named in the error.
     * @param bool   $allowEmpty Accept `''` (the documented root `checkpoint_ns`).
     *
     * @throws \InvalidArgumentException
     */
    public static function assertSafeKeyComponent(string $field, mixed $value, bool $allowEmpty = false): void
    {
        if (!is_string($value)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid configurable value for key "%s": expected a string identifier (got %s). '
                . 'This guard protects Redis keys and KEYS/SCAN patterns from glob and command injection.',
                $field,
                self::observedType($value),
            ));
        }
        if (!$allowEmpty && $value === '') {
            throw new \InvalidArgumentException(sprintf(
                'Invalid configurable value for key "%s": empty string is not permitted as a Redis key component.',
                $field,
            ));
        }
        if (preg_match(self::REDIS_KEY_FORBIDDEN, $value) === 1) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid configurable value for key "%s": value contains a Redis pattern meta-character '
                . '(one of * ? [ ] \\). This guard protects Redis keys and KEYS/SCAN patterns from glob and '
                . 'command injection.',
                $field,
            ));
        }
    }

    /** JavaScript falsiness for the values a config can hold. */
    public static function isFalsy(mixed $value): bool
    {
        return $value === null || $value === false || $value === '' || $value === 0 || $value === 0.0;
    }

    /**
     * A value as it is stored inside a JSON document: serialised by the saver's serde,
     * then embedded as a JSON subtree so the document stays readable and searchable.
     *
     * Payloads the serde tags `bytes` are not valid JSON text and are stored as base64.
     *
     * @return array{0: string, 1: mixed} The type tag (`json` or `bytes`) and the embeddable value.
     */
    public static function embed(BaseCheckpointSerializer $serde, mixed $value): array
    {
        [$type, $payload] = $serde->dumpsTyped($value);
        if ($type === 'bytes') {
            return ['bytes', base64_encode($payload)];
        }

        // Objects stay objects (`false`), so an empty `{}` is not turned into `[]`.
        return ['json', json_decode($payload, false, 512, JSON_THROW_ON_ERROR)];
    }

    /** Reverse of {@see self::embed()}. */
    public static function unembed(BaseCheckpointSerializer $serde, string $type, mixed $embedded): mixed
    {
        if ($type === 'bytes') {
            return $serde->loadsTyped('bytes', (string) base64_decode((string) $embedded, true));
        }

        return $serde->loadsTyped('json', self::encode($embedded));
    }

    /** Encode a document or fragment as the JSON text Redis stores. */
    public static function encode(mixed $value): string
    {
        return json_encode(
            $value,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION,
        );
    }

    /**
     * The wire record for a checkpoint, whichever checkpoint class was handed in.
     *
     * @return array<string, mixed>
     */
    public static function wireCheckpoint(PregelCheckpoint $checkpoint): array
    {
        if ($checkpoint instanceof Checkpoint) {
            return $checkpoint->toArray();
        }

        return [
            'v' => $checkpoint->v,
            'id' => $checkpoint->id,
            'ts' => $checkpoint->ts,
            'channel_values' => $checkpoint->channelValues,
            'channel_versions' => (object) $checkpoint->channelVersions,
            'versions_seen' => (object) array_map(
                static fn (array $inner): object => (object) $inner,
                $checkpoint->versionsSeen,
            ),
        ];
    }

    /**
     * Whether a search failed because the index does not exist.
     *
     * Upstream matches the lowercase text `no such index`. RediSearch 2.x words it
     * `No such index <name>` (and 1.x `Unknown index name`), so a case-sensitive match
     * never fires against a current server and the key-scan fallback is dead code.
     */
    public static function isMissingIndex(RedisClientException $e): bool
    {
        $message = strtolower($e->getMessage());

        return str_contains($message, 'no such index') || str_contains($message, 'unknown index name');
    }

    /**
     * Whether an `FT.CREATE` failed because the index is already there.
     */
    public static function isIndexExists(RedisClientException $e): bool
    {
        return str_contains(strtolower($e->getMessage()), 'index already exists');
    }

    /**
     * Create the RediSearch indexes, tolerating "already exists".
     *
     * Port of `ensureIndexes`. Any other failure is logged and swallowed, as
     * upstream does: the savers degrade to key scans when an index is missing.
     *
     * @param list<array{index: string, prefix: string, schema: array<string, array{type: string, as: string}>}> $schemas
     */
    public static function ensureIndexes(RedisClientInterface $client, array $schemas): void
    {
        foreach ($schemas as $schema) {
            try {
                $client->ftCreate($schema['index'], $schema['schema'], $schema['prefix']);
            } catch (RedisClientException $e) {
                if (!self::isIndexExists($e)) {
                    error_log(sprintf('Failed to create index %s: %s', $schema['index'], $e->getMessage()));
                }
            }
        }
    }

    /**
     * Arm the TTL on each key. Best effort: a failure is logged, never thrown.
     *
     * Port of `applyTTL`.
     */
    public static function applyTtl(RedisClientInterface $client, ?TtlConfig $ttl, string ...$keys): void
    {
        if ($ttl === null || !$ttl->enabled()) {
            return;
        }

        $seconds = $ttl->seconds();
        foreach ($keys as $key) {
            try {
                $client->expire($key, $seconds);
            } catch (\Throwable $e) {
                error_log(sprintf('Failed to set TTL for key %s: %s', $key, $e->getMessage()));
            }
        }
    }

    /**
     * Copy the metadata fields RediSearch indexes up to the top level of a document.
     *
     * Port of `addSearchableMetadataFields`. `writes` is indexed as JSON text, since a
     * TAG field cannot hold an object.
     *
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $metadata
     */
    public static function addSearchableMetadataFields(array &$doc, array $metadata): void
    {
        if (array_key_exists('source', $metadata)) {
            $doc['source'] = $metadata['source'];
        }
        if (array_key_exists('step', $metadata)) {
            $doc['step'] = $metadata['step'];
        }
        if (array_key_exists('writes', $metadata)) {
            $writes = $metadata['writes'];
            $doc['writes'] = is_array($writes) || is_object($writes) || $writes === null
                ? self::encode($writes)
                : $writes;
        }
        if (array_key_exists('score', $metadata)) {
            $doc['score'] = $metadata['score'];
        }
    }

    /**
     * The RediSearch clauses for a metadata filter.
     *
     * Port of the query-building loop shared by both savers' `list()`. Strings become
     * TAG clauses and numbers become single-point NUMERIC ranges; null, booleans and
     * containers have no RediSearch form and are left to {@see self::metadataMatches()}.
     * Both key and value are escaped, so a filter cannot break out of its clause.
     *
     * @param  array<string, mixed> $filter
     * @return list<string>
     */
    public static function filterQueryParts(array $filter): array
    {
        $parts = [];
        foreach ($filter as $key => $value) {
            if (is_string($value)) {
                $parts[] = sprintf(
                    '(@%s:{%s})',
                    self::escapeRediSearchTagValue((string) $key),
                    self::escapeRediSearchTagValue($value),
                );
            } elseif (is_int($value) || (is_float($value) && is_finite($value))) {
                $parts[] = sprintf('(@%s:[%s %s])', self::escapeRediSearchTagValue((string) $key), $value, $value);
            }
        }

        return $parts;
    }

    /** Whether a filter holds a value {@see self::filterQueryParts()} cannot express. */
    public static function hasInexpressibleFilter(array $filter): bool
    {
        foreach ($filter as $value) {
            if (!is_string($value) && !is_int($value) && !(is_float($value) && is_finite($value))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether decoded metadata satisfies every filter entry.
     *
     * Port of the manual filter pass in `list()` / `checkMetadataFilterMatch`. A null
     * filter value matches only an explicit null (a missing key does not match), and
     * containers compare by {@see self::deterministic()} so key order is irrelevant.
     *
     * @param array<string, mixed> $metadata
     * @param array<string, mixed> $filter
     */
    public static function metadataMatches(array $metadata, array $filter): bool
    {
        foreach ($filter as $key => $expected) {
            $present = array_key_exists($key, $metadata);
            $actual = $metadata[$key] ?? null;
            if ($expected === null) {
                if (!$present || $actual !== null) {
                    return false;
                }
            } elseif (is_array($expected) || is_object($expected)) {
                if (self::deterministic($actual) !== self::deterministic($expected)) {
                    return false;
                }
            } elseif (is_int($expected) || is_float($expected)) {
                if (!(is_int($actual) || is_float($actual)) || $actual != $expected) {
                    return false;
                }
            } elseif ($actual !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * Canonical JSON with object keys sorted at every depth.
     *
     * Port of `deterministicStringify`. List order is significant and preserved.
     */
    public static function deterministic(mixed $value): string
    {
        return self::encode(self::sortKeys($value));
    }

    /**
     * A strictly increasing microsecond clock for `global_idx`.
     *
     * Port of `performance.now() * 1000`. A reserved range of `$count` slots is returned
     * so that two `putWrites` calls inside the same microsecond still order by call.
     */
    public static function reserveWriteIndexes(int $count): float
    {
        $base = max(microtime(true) * 1_000_000, self::$lastWriteIndex);
        self::$lastWriteIndex = $base + max(1, $count);

        return $base;
    }

    /**
     * The `checkpoint_ts` for a new document: microseconds since the epoch, strictly
     * increasing within a process.
     *
     * Upstream uses `Date.now()` (milliseconds). Two checkpoints written inside one
     * millisecond then tie, and `SORTBY checkpoint_ts` orders a tie arbitrarily — which
     * returns a thread's history out of order. A tie-free clock is the same field with
     * more resolution.
     */
    public static function nextTimestamp(): int
    {
        self::$lastTimestamp = max((int) (microtime(true) * 1_000_000), self::$lastTimestamp + 1);

        return self::$lastTimestamp;
    }

    private static function sortKeys(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = (array) $value;
        }
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(self::sortKeys(...), $value);
        }

        ksort($value, SORT_STRING);

        return (object) array_map(self::sortKeys(...), $value);
    }

    private static function observedType(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_array($value) => 'array',
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            is_object($value) => 'object',
            default => get_debug_type($value),
        };
    }
}
