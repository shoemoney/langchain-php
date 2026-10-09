<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint\Redis;

/**
 * The Redis commands the checkpoint savers issue, and nothing else.
 *
 * Port of the slice of the `redis` (node-redis) client that
 * `@langchain/langgraph-checkpoint-redis` touches: `client.json.get/set`,
 * `client.keys`, `exists`, `del`, `expire`, `zAdd`, `zRange`, `ft.create`,
 * `ft.search`, `ft.info` and `quit`. The store backend ({@see \LangGraph\Store\Redis\RedisStore})
 * adds vector fields to `ft.create`, and `RETURN`/`PARAMS`/`DIALECT` to `ft.search`.
 *
 * It is a seam, not an abstraction over Redis. The savers depend on RedisJSON
 * (`JSON.GET`/`JSON.SET`) and RediSearch (`FT.CREATE`/`FT.SEARCH`), so a backing
 * server must have both modules (Redis Stack, or Redis 8). Documents cross this
 * boundary as raw JSON text, exactly as they cross the wire, so that an empty
 * object stays `{}` instead of collapsing into PHP's one empty-array type.
 *
 * Every method throws {@see RedisClientException} on a server error reply; the
 * message is the server's, because the savers branch on it
 * (`Index already exists`, `no such index`).
 */
interface RedisClientInterface
{
    /**
     * `JSON.GET key` — the whole document as JSON text, or null when the key is absent.
     */
    public function jsonGet(string $key): ?string;

    /**
     * `JSON.SET key path value [NX]`.
     *
     * @param  string $json         The value as JSON text.
     * @param  bool   $onlyIfAbsent `NX`: write only if the key does not exist.
     * @return bool   False when `NX` declined to write, true otherwise.
     */
    public function jsonSet(string $key, string $path, string $json, bool $onlyIfAbsent = false): bool;

    /**
     * `KEYS pattern` — every key matching a glob.
     *
     * @return list<string>
     */
    public function keys(string $pattern): array;

    /** `EXISTS key` — 1 when the key exists, else 0. */
    public function exists(string $key): int;

    /**
     * `DEL key [key ...]`.
     *
     * @param  list<string> $keys
     * @return int          How many keys were removed.
     */
    public function del(array $keys): int;

    /** `EXPIRE key seconds` — false when the key does not exist. */
    public function expire(string $key, int $seconds): bool;

    /**
     * `ZADD key score member [score member ...]`.
     *
     * @param  list<array{score: float|int, value: string}> $members
     * @return int      How many members were newly added.
     */
    public function zAdd(string $key, array $members): int;

    /**
     * `ZRANGE key start stop`.
     *
     * @return list<string>
     */
    public function zRange(string $key, int $start, int $stop): array;

    /**
     * `FT.CREATE index ON JSON PREFIX 1 prefix SCHEMA ...`.
     *
     * @param array<string, array{type: string, as: string, algorithm?: string, attributes?: array<string, string|int>}> $schema
     *        JSONPath => field definition. A `VECTOR` field also names its `algorithm` (`FLAT`) and
     *        `attributes` (`TYPE`, `DIM`, `DISTANCE_METRIC`), emitted as `VECTOR FLAT <2n> k v ...`.
     *
     * @throws RedisClientException `Index already exists` when the index is present.
     */
    public function ftCreate(string $index, array $schema, string $prefix): void;

    /**
     * `FT.SEARCH index query [SORTBY field [DESC]] LIMIT offset size [RETURN ..] [PARAMS ..] [DIALECT n]`.
     *
     * Without `$return` each hit's `value` is the JSON document text. With it, `value` is a JSON
     * object of just those fields (every value a string, as the server replies), which is how a
     * KNN query's `__<field>_score` reaches the caller. An empty `$sortBy` omits `SORTBY` (a
     * KNN query is ordered by its distance).
     *
     * @param  list<string>          $return  Fields to return, or null for the whole document.
     * @param  array<string, string> $params  `PARAMS` name => value (a vector blob is a binary string).
     * @return list<array{id: string, value: string}> Each hit's key and its JSON text.
     *
     * @throws RedisClientException `no such index` when the index is absent.
     */
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
    ): array;

    /**
     * `FT.INFO index` — the index description as a name => value map (`num_docs`, `attributes`, ...).
     *
     * @return array<string, mixed>
     *
     * @throws RedisClientException When the index is absent.
     */
    public function ftInfo(string $index): array;

    /** `QUIT` — close the connection. */
    public function quit(): void;
}
