<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\Redis\RedisClientException;
use LangGraph\Checkpoint\Redis\RedisClientInterface;

/**
 * An in-memory {@see RedisClientInterface}.
 *
 * It models exactly the commands the savers issue, and only to the depth they rely
 * on. What it models, each checked against a live Redis Stack 7.4 (RedisJSON +
 * RediSearch) while writing it:
 *
 *  - `JSON.GET` / `JSON.SET` at the root path, with `NX`; a missing key is nil, an
 *    overwrite keeps the key's TTL.
 *  - `KEYS` with Redis glob syntax (`*`, `?`, `[...]`, `\` escapes).
 *  - `EXISTS`, `DEL`, `EXPIRE`, `ZADD`, `ZRANGE` (score, then member).
 *  - `FT.CREATE ... ON JSON PREFIX 1 p SCHEMA` and `FT.SEARCH index query SORTBY f
 *    [DESC] LIMIT o n` for the query shapes the savers and the store build: `*`, and an AND of
 *    `(@field:{tag})`, `(@field:[min max])` and `@field:(word word)` / `@field:word*` TEXT
 *    clauses (parentheses only group), plus a trailing `=>[KNN k @field $PARAM]` over a
 *    `VECTOR FLAT` field with `PARAMS`, `DIALECT` and `RETURN`. TAG matching is
 *    case-insensitive, TEXT matches lower-cased tokens split on RediSearch's default
 *    punctuation, an unindexed or missing field matches nothing (it is not an
 *    error), and the error texts are the server's: `Index already exists`,
 *    `No such index <name>`. KNN distances follow the server: cosine and inner product are
 *    `1 - similarity`, L2 is the SQUARED Euclidean distance, as measured on Redis 8.
 *  - `FT.INFO index`: the index definition and `num_docs`.
 *
 * What it does NOT model, and what therefore needs a real server to trust:
 *
 *  - Any other FT.SEARCH syntax (unions, negation, fuzzy, TAG separators), TEXT stemming and
 *    stop-words, SORTBY on anything but one numeric field, and the
 *    server's tokenisation of a TAG value that contains separators.
 *  - JSONPath beyond `$` and `$.field`; arrays of tags; `JSON.SET ... XX`.
 *  - Index lifecycle: documents written before `FT.CREATE` are indexed here
 *    immediately; a real server back-fills asynchronously.
 *  - Memory limits, eviction, replication, clustering, and the exact RESP types.
 *
 * `RedisSaverIntegrationTest` runs the same scenarios against a real server when
 * `LANGGRAPH_REDIS_URL` is set.
 */
final class FakeRedisClient implements RedisClientInterface
{
    /** @var array<string, string> key => JSON text */
    private array $json = [];

    /** @var array<string, array<string, float>> key => member => score */
    private array $zsets = [];

    /** @var array<string, float> key => absolute expiry time */
    private array $expiresAt = [];

    /** @var array<string, array{prefix: string, fields: array<string, array{path: string, type: string, attributes: array<string, string|int>}>}> */
    private array $indexes = [];

    /** @var list<array{method: string, args: array<int, mixed>}> */
    private array $calls = [];

    /** @var list<string> every key passed to DEL that existed, in order */
    private array $deleted = [];

    private float $now = 1_000_000.0;

    private bool $indexingDisabled = false;

    public function jsonGet(string $key): ?string
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->expireIfDue($key);

        return $this->json[$key] ?? null;
    }

    public function jsonSet(string $key, string $path, string $json, bool $onlyIfAbsent = false): bool
    {
        $this->record(__FUNCTION__, func_get_args());
        if ($path !== '$') {
            throw new RedisClientException("Only the root path is modelled, got {$path}");
        }
        json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        $this->expireIfDue($key);

        if ($onlyIfAbsent && $this->existsAny($key)) {
            return false;
        }
        // An overwrite replaces the value and keeps the TTL, as JSON.SET does.
        $this->json[$key] = $json;

        return true;
    }

    public function keys(string $pattern): array
    {
        $this->record(__FUNCTION__, func_get_args());
        $regex = self::globToRegex($pattern);
        $matched = [];
        foreach (array_unique([...array_keys($this->json), ...array_keys($this->zsets)]) as $key) {
            $this->expireIfDue((string) $key);
            if ($this->existsAny((string) $key) && preg_match($regex, (string) $key) === 1) {
                $matched[] = (string) $key;
            }
        }
        sort($matched, SORT_STRING);

        return $matched;
    }

    public function exists(string $key): int
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->expireIfDue($key);

        return $this->existsAny($key) ? 1 : 0;
    }

    public function del(array $keys): int
    {
        $this->record(__FUNCTION__, func_get_args());
        $removed = 0;
        foreach ($keys as $key) {
            $this->expireIfDue($key);
            if ($this->existsAny($key)) {
                unset($this->json[$key], $this->zsets[$key], $this->expiresAt[$key]);
                $this->deleted[] = $key;
                $removed++;
            }
        }

        return $removed;
    }

    public function expire(string $key, int $seconds): bool
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->expireIfDue($key);
        if (!$this->existsAny($key)) {
            return false;
        }
        $this->expiresAt[$key] = $this->now + $seconds;

        return true;
    }

    public function zAdd(string $key, array $members): int
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->expireIfDue($key);
        $added = 0;
        foreach ($members as $member) {
            if (!isset($this->zsets[$key][$member['value']])) {
                $added++;
            }
            $this->zsets[$key][$member['value']] = (float) $member['score'];
        }

        return $added;
    }

    public function zRange(string $key, int $start, int $stop): array
    {
        $this->record(__FUNCTION__, func_get_args());
        $this->expireIfDue($key);
        $members = $this->zsets[$key] ?? [];
        // Score first, then member bytes: Redis's order.
        uksort($members, static fn (string $a, string $b): int => [$members[$a], $a] <=> [$members[$b], $b]);
        $names = array_keys($members);
        $count = count($names);
        $start = $start < 0 ? max(0, $count + $start) : $start;
        $stop = $stop < 0 ? $count + $stop : min($stop, $count - 1);

        return $stop < $start ? [] : array_map('strval', array_slice($names, $start, $stop - $start + 1));
    }

    public function ftCreate(string $index, array $schema, string $prefix): void
    {
        $this->record(__FUNCTION__, func_get_args());
        if ($this->indexingDisabled) {
            return;
        }
        if (isset($this->indexes[$index])) {
            throw new RedisClientException('Index already exists');
        }
        $fields = [];
        foreach ($schema as $path => $field) {
            $fields[$field['as']] = ['path' => $path, 'type' => $field['type'], 'attributes' => $field['attributes'] ?? []];
        }
        $this->indexes[$index] = ['prefix' => $prefix, 'fields' => $fields];
    }

    public function ftSearch(
        string $index,
        string $query,
        int $offset,
        int $size,
        string $sortBy,
        bool $descending,
        ?array $return = null,
        array $params = [],
        ?int $dialect = null,
    ): array {
        $this->record(__FUNCTION__, func_get_args());
        if (!isset($this->indexes[$index])) {
            throw new RedisClientException("No such index {$index}");
        }
        $definition = $this->indexes[$index];
        [$filter, $knn] = self::splitKnn($query);
        $clauses = self::parseQuery($filter);

        $rows = [];
        foreach (array_keys($this->json) as $key) {
            $this->expireIfDue((string) $key);
            if (!isset($this->json[$key]) || !str_starts_with((string) $key, $definition['prefix'])) {
                continue;
            }
            $doc = json_decode($this->json[$key], true);
            if (!is_array($doc) || !$this->matchesAll($doc, $definition['fields'], $clauses)) {
                continue;
            }
            $rows[] = [
                'id' => (string) $key,
                'doc' => $doc,
                'value' => $this->json[$key],
                'sort' => $sortBy === '' ? null : $this->fieldValue($doc, $definition['fields'][$sortBy]['path'] ?? ''),
                'distance' => null,
            ];
        }

        if ($knn !== null) {
            $rows = $this->nearest($rows, $definition['fields'], $knn, $params);
        } else {
            usort($rows, static function (array $a, array $b) use ($descending): int {
                $cmp = ($a['sort'] ?? 0) <=> ($b['sort'] ?? 0);

                return ($descending ? -$cmp : $cmp) ?: strcmp($a['id'], $b['id']);
            });
        }

        $hits = [];
        foreach (array_slice($rows, $offset, max(0, $size)) as $row) {
            $hits[] = ['id' => $row['id'], 'value' => $return === null
                ? $row['value']
                : $this->returnFields($row, $definition['fields'], $return, $knn)];
        }

        return $hits;
    }

    public function ftInfo(string $index): array
    {
        $this->record(__FUNCTION__, func_get_args());
        if (!isset($this->indexes[$index])) {
            throw new RedisClientException("No such index {$index}");
        }
        $definition = $this->indexes[$index];
        $docs = 0;
        foreach (array_keys($this->json) as $key) {
            $this->expireIfDue((string) $key);
            $docs += isset($this->json[$key]) && str_starts_with((string) $key, $definition['prefix']) ? 1 : 0;
        }
        $attributes = [];
        foreach ($definition['fields'] as $alias => $field) {
            $attributes[] = ['identifier' => $field['path'], 'attribute' => $alias, 'type' => $field['type']];
        }

        return [
            'index_name' => $index,
            'index_definition' => ['key_type' => 'JSON', 'prefixes' => [$definition['prefix']]],
            'attributes' => $attributes,
            'num_docs' => $docs,
        ];
    }

    public function quit(): void
    {
        $this->record(__FUNCTION__, []);
    }

    // ---- test controls --------------------------------------------------

    /** Put a JSON document straight into the store, bypassing the call log. */
    public function seedJson(string $key, mixed $document): void
    {
        $this->json[$key] = json_encode($document, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
    }

    /**
     * @param array<string, float|int> $members member => score
     */
    public function seedZSet(string $key, array $members): void
    {
        foreach ($members as $member => $score) {
            $this->zsets[$key][(string) $member] = (float) $score;
        }
    }

    /** Move the fake's clock forward; keys whose TTL has passed disappear. */
    public function advance(float $seconds): void
    {
        $this->now += $seconds;
    }

    /** Seconds until a key expires, or null if it has no TTL or does not exist. */
    public function ttl(string $key): ?int
    {
        $this->expireIfDue($key);
        if (!$this->existsAny($key) || !isset($this->expiresAt[$key])) {
            return null;
        }

        return (int) ceil($this->expiresAt[$key] - $this->now);
    }

    /**
     * Make `FT.CREATE` a silent no-op, so a dropped index stays dropped and the savers' key-scan
     * fallback is reachable (they re-create their indexes before every `list()`).
     */
    public function disableIndexing(): void
    {
        $this->indexingDisabled = true;
    }

    /** Remove an index, as `FT.DROPINDEX` would. */
    public function dropIndex(string $index): void
    {
        unset($this->indexes[$index]);
    }

    /** @return list<string> */
    public function indexNames(): array
    {
        return array_keys($this->indexes);
    }

    /** @return list<string> every live key, sorted */
    public function allKeys(): array
    {
        return $this->keys('*');
    }

    /** @return list<string> keys removed by DEL, in order */
    public function deletedKeys(): array
    {
        return $this->deleted;
    }

    /**
     * The recorded calls to one method.
     *
     * @return list<array<int, mixed>> argument lists
     */
    public function callsTo(string $method): array
    {
        return array_values(array_map(
            static fn (array $call): array => $call['args'],
            array_filter($this->calls, static fn (array $call): bool => $call['method'] === $method),
        ));
    }

    // ---- internals ------------------------------------------------------

    /** @param array<int, mixed> $args */
    private function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
    }

    private function existsAny(string $key): bool
    {
        return isset($this->json[$key]) || isset($this->zsets[$key]);
    }

    private function expireIfDue(string $key): void
    {
        if (isset($this->expiresAt[$key]) && $this->expiresAt[$key] <= $this->now) {
            unset($this->json[$key], $this->zsets[$key], $this->expiresAt[$key]);
        }
    }

    /** A Redis glob as an anchored regex. */
    private static function globToRegex(string $glob): string
    {
        $regex = '';
        $length = strlen($glob);
        for ($i = 0; $i < $length; $i++) {
            $char = $glob[$i];
            if ($char === '*') {
                $regex .= '.*';
            } elseif ($char === '?') {
                $regex .= '.';
            } elseif ($char === '\\' && $i + 1 < $length) {
                $regex .= preg_quote($glob[++$i], '/');
            } elseif ($char === '[' && ($end = strpos($glob, ']', $i + 1)) !== false) {
                $regex .= '[' . str_replace(['\\', '^'], ['\\\\', '\\^'], substr($glob, $i + 1, $end - $i - 1)) . ']';
                $i = $end;
            } else {
                $regex .= preg_quote($char, '/');
            }
        }

        return '/^' . $regex . '$/s';
    }

    /**
     * Split a trailing `=>[KNN k @field $PARAM]` off a query.
     *
     * @return array{0: string, 1: array{k: int, field: string, param: string}|null}
     */
    private static function splitKnn(string $query): array
    {
        if (preg_match('/^(.*?)\s*=>\s*\[\s*KNN\s+(\d+)\s+@(\w+)\s+\$(\w+)\s*\]\s*$/s', $query, $m) !== 1) {
            return [$query, null];
        }

        return [$m[1], ['k' => (int) $m[2], 'field' => $m[3], 'param' => $m[4]]];
    }

    /**
     * @return list<array{field: string, kind: string, tag?: string, min?: float, max?: float, words?: list<string>}>
     */
    private static function parseQuery(string $query): array
    {
        $query = trim($query);
        $clauses = [];
        $length = strlen($query);
        $at = 0;
        while ($at < $length) {
            $char = $query[$at];
            if (ctype_space($char) || $char === '(' || $char === ')' || $char === '*') {
                $at++;

                continue;
            }
            if ($char !== '@' || preg_match('/\G@((?:\\\\.|[^:\\\\])+):/s', $query, $m, 0, $at) !== 1) {
                throw new RedisClientException('Syntax error in query: ' . $query);
            }
            $field = self::unescape($m[1]);
            $at += strlen($m[0]);
            if (preg_match('/\G\{((?:\\\\.|[^}\\\\])*)\}/s', $query, $b, 0, $at) === 1) {
                $clauses[] = ['field' => $field, 'kind' => 'tag', 'tag' => self::unescape($b[1])];
            } elseif (preg_match('/\G\[([^\]]*)\]/s', $query, $b, 0, $at) === 1) {
                [$min, $max] = array_map('floatval', preg_split('/\s+/', trim($b[1])) ?: ['0', '0']);
                $clauses[] = ['field' => $field, 'kind' => 'numeric', 'min' => $min, 'max' => $max];
            } elseif (preg_match('/\G\(([^()]*)\)/s', $query, $b, 0, $at) === 1) {
                $clauses[] = ['field' => $field, 'kind' => 'text', 'words' => self::words($b[1], true)];
            } elseif (preg_match('/\G[^\s()]+/', $query, $b, 0, $at) === 1) {
                $clauses[] = ['field' => $field, 'kind' => 'text', 'words' => self::words($b[0], true)];
            } else {
                throw new RedisClientException('Syntax error in query: ' . $query);
            }
            $at += strlen($b[0]);
        }

        return $clauses;
    }

    /**
     * Lower-cased TEXT tokens, split on RediSearch's default separators.
     *
     * @return list<string> a trailing `*` (prefix match) survives on query words
     */
    private static function words(string $text, bool $keepWildcard = false): array
    {
        $separators = $keepWildcard ? '/[\s,.<>{}\[\]"\':;!@#$%^&()\-+=~|]+/' : '/[\s,.<>{}\[\]"\':;!@#$%^&*()\-+=~|]+/';

        return array_values(array_filter(
            preg_split($separators, strtolower($text)) ?: [],
            static fn (string $word): bool => $word !== '',
        ));
    }

    private static function unescape(string $text): string
    {
        return (string) preg_replace('/\\\\(.)/s', '$1', $text);
    }

    /**
     * @param array<string, mixed>                                    $doc
     * @param array<string, array{path: string, type: string}>        $fields
     * @param list<array{field: string, kind: string, tag?: string, min?: float, max?: float, words?: list<string>}> $clauses
     */
    private function matchesAll(array $doc, array $fields, array $clauses): bool
    {
        foreach ($clauses as $clause) {
            // A field that is not in the schema matches nothing; the server does not error.
            if (!isset($fields[$clause['field']])) {
                return false;
            }
            $value = $this->fieldValue($doc, $fields[$clause['field']]['path']);
            if ($value === null) {
                return false;
            }
            if ($clause['kind'] === 'tag') {
                if ($fields[$clause['field']]['type'] !== 'TAG' || !is_scalar($value)
                    || strtolower((string) $value) !== strtolower($clause['tag'])) {
                    return false;
                }
            } elseif ($clause['kind'] === 'text') {
                if ($fields[$clause['field']]['type'] !== 'TEXT' || !is_string($value)
                    || !self::containsWords(self::words($value), $clause['words'] ?? [])) {
                    return false;
                }
            } elseif (!is_numeric($value) || $value < $clause['min'] || $value > $clause['max']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<string> $tokens the document's tokens
     * @param list<string> $words  every one must match; `word*` matches a token prefix
     */
    private static function containsWords(array $tokens, array $words): bool
    {
        foreach ($words as $word) {
            $prefix = str_ends_with($word, '*');
            $word = rtrim($word, '*');
            $found = false;
            foreach ($tokens as $token) {
                if ($prefix ? str_starts_with($token, $word) : $token === $word) {
                    $found = true;

                    break;
                }
            }
            if (!$found) {
                return false;
            }
        }

        return true;
    }

    /**
     * The `k` nearest rows by the vector field's distance metric, ties broken by id.
     *
     * @param list<array{id: string, doc: array<string, mixed>, value: string, sort: mixed, distance: float|null}> $rows
     * @param array<string, array{path: string, type: string, attributes: array<string, string|int>}>             $fields
     * @param array{k: int, field: string, param: string}                                                          $knn
     * @param array<string, string>                                                                                $params
     * @return list<array{id: string, doc: array<string, mixed>, value: string, sort: mixed, distance: float|null}>
     */
    private function nearest(array $rows, array $fields, array $knn, array $params): array
    {
        $field = $fields[$knn['field']] ?? null;
        if ($field === null || $field['type'] !== 'VECTOR') {
            throw new RedisClientException("Property `{$knn['field']}` is not a vector field");
        }
        if (!isset($params[$knn['param']])) {
            throw new RedisClientException("No such parameter `{$knn['param']}`");
        }
        $query = array_values(unpack('g*', $params[$knn['param']]) ?: []);
        $dims = (int) ($field['attributes']['DIM'] ?? count($query));
        if (count($query) !== $dims) {
            throw new RedisClientException('Error parsing vector similarity query: query vector blob size (' . strlen($params[$knn['param']]) . ") does not match index's expected size ({$dims}).");
        }
        $metric = strtoupper((string) ($field['attributes']['DISTANCE_METRIC'] ?? 'COSINE'));

        $scored = [];
        foreach ($rows as $row) {
            $vector = $this->fieldValue($row['doc'], $field['path']);
            // A document whose vector is missing or has the wrong size is simply not indexed.
            if (!is_array($vector) || count($vector) !== $dims) {
                continue;
            }
            $stored = array_values(unpack('g*', pack('g*', ...array_map('floatval', $vector))) ?: []);
            $row['distance'] = self::distance($metric, $query, $stored);
            $scored[] = $row;
        }
        usort($scored, static fn (array $a, array $b): int => ($a['distance'] <=> $b['distance']) ?: strcmp($a['id'], $b['id']));

        return array_slice($scored, 0, $knn['k']);
    }

    /**
     * @param list<float> $a
     * @param list<float> $b
     */
    private static function distance(string $metric, array $a, array $b): float
    {
        $dot = $normA = $normB = $squared = 0.0;
        foreach ($a as $i => $x) {
            $y = $b[$i];
            $dot += $x * $y;
            $normA += $x * $x;
            $normB += $y * $y;
            $squared += ($x - $y) ** 2;
        }

        return match ($metric) {
            'L2' => $squared,
            'IP' => 1 - $dot,
            default => $normA > 0 && $normB > 0 ? 1 - $dot / (sqrt($normA) * sqrt($normB)) : 1.0,
        };
    }

    /**
     * The RETURN projection: a JSON object of string values, like the server's field/value pairs.
     *
     * @param array{id: string, doc: array<string, mixed>, value: string, sort: mixed, distance: float|null} $row
     * @param array<string, array{path: string, type: string}>                                               $fields
     * @param list<string>                                                                                    $return
     * @param array{k: int, field: string, param: string}|null                                                $knn
     */
    private function returnFields(array $row, array $fields, array $return, ?array $knn): string
    {
        $picked = [];
        foreach ($return as $name) {
            if ($knn !== null && $name === '__' . $knn['field'] . '_score' && $row['distance'] !== null) {
                $picked[$name] = rtrim(rtrim(sprintf('%.9F', $row['distance']), '0'), '.');
            } elseif (isset($fields[$name])) {
                $value = $this->fieldValue($row['doc'], $fields[$name]['path']);
                if (is_scalar($value)) {
                    $picked[$name] = (string) $value;
                }
            }
        }

        return json_encode($picked, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT);
    }

    /** @param array<string, mixed> $doc */
    private function fieldValue(array $doc, string $path): mixed
    {
        return str_starts_with($path, '$.') ? ($doc[substr($path, 2)] ?? null) : null;
    }
}
