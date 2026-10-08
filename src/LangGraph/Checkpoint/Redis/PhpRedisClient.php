<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint\Redis;

/**
 * {@see RedisClientInterface} over ext-redis (phpredis).
 *
 * phpredis has no RedisJSON or RediSearch methods, so those go through
 * `rawCommand`. Requires a server with both modules (Redis Stack, or Redis 8).
 */
final class PhpRedisClient implements RedisClientInterface
{
    public function __construct(private readonly \Redis $redis)
    {
    }

    /**
     * Connect from a `redis://[user:password@]host[:port][/db]` URL.
     *
     * Port of `createClient({ url })` plus `connect()`.
     */
    public static function fromUrl(string $url): self
    {
        if (!extension_loaded('redis')) {
            throw new \RuntimeException('ext-redis is required for RedisSaver::fromUrl(); pass a RedisClientInterface instead.');
        }

        $parts = parse_url($url);
        if ($parts === false || !isset($parts['host'])) {
            throw new \InvalidArgumentException("Invalid Redis URL: {$url}");
        }

        $redis = new \Redis();
        $scheme = $parts['scheme'] ?? 'redis';
        $host = ($scheme === 'rediss' ? 'tls://' : '') . $parts['host'];
        $redis->connect($host, $parts['port'] ?? 6379);

        if (isset($parts['pass'])) {
            $redis->auth(isset($parts['user']) && $parts['user'] !== ''
                ? [rawurldecode($parts['user']), rawurldecode($parts['pass'])]
                : rawurldecode($parts['pass']));
        }
        $database = isset($parts['path']) ? trim($parts['path'], '/') : '';
        if ($database !== '' && ctype_digit($database)) {
            $redis->select((int) $database);
        }

        return new self($redis);
    }

    public function jsonGet(string $key): ?string
    {
        $reply = $this->raw('JSON.GET', $key);

        return is_string($reply) ? $reply : null;
    }

    public function jsonSet(string $key, string $path, string $json, bool $onlyIfAbsent = false): bool
    {
        $args = ['JSON.SET', $key, $path, $json];
        if ($onlyIfAbsent) {
            $args[] = 'NX';
        }

        // phpredis turns the `OK` status reply into true; a declined NX is a nil reply (false).
        $reply = $this->raw(...$args);

        return $reply === true || $reply === 'OK';
    }

    public function keys(string $pattern): array
    {
        return $this->guard(fn (): array => array_map('strval', (array) $this->redis->keys($pattern)));
    }

    public function exists(string $key): int
    {
        return (int) $this->guard(fn (): mixed => $this->redis->exists($key));
    }

    public function del(array $keys): int
    {
        if ($keys === []) {
            return 0;
        }

        return (int) $this->guard(fn (): mixed => $this->redis->del($keys));
    }

    public function expire(string $key, int $seconds): bool
    {
        return (bool) $this->guard(fn (): mixed => $this->redis->expire($key, $seconds));
    }

    public function zAdd(string $key, array $members): int
    {
        if ($members === []) {
            return 0;
        }
        $args = [];
        foreach ($members as $member) {
            $args[] = $member['score'];
            $args[] = $member['value'];
        }

        return (int) $this->guard(fn (): mixed => $this->redis->zAdd($key, ...$args));
    }

    public function zRange(string $key, int $start, int $stop): array
    {
        return $this->guard(fn (): array => array_map('strval', (array) $this->redis->zRange($key, $start, $stop)));
    }

    public function ftCreate(string $index, array $schema, string $prefix): void
    {
        $args = ['FT.CREATE', $index, 'ON', 'JSON', 'PREFIX', '1', $prefix, 'SCHEMA'];
        foreach ($schema as $path => $field) {
            array_push($args, $path, 'AS', $field['as'], $field['type']);
        }
        $this->raw(...$args);
    }

    public function ftSearch(
        string $index,
        string $query,
        int $offset,
        int $size,
        string $sortBy,
        bool $descending,
    ): array {
        $reply = $this->raw(
            'FT.SEARCH',
            $index,
            $query,
            'SORTBY',
            $sortBy,
            $descending ? 'DESC' : 'ASC',
            'LIMIT',
            (string) $offset,
            (string) $size,
        );

        $hits = [];
        if (!is_array($reply)) {
            return $hits;
        }
        // [total, key, [field, value, ...], key, [...], ...]; a JSON index returns the whole
        // document under the field `$`.
        for ($i = 1; $i + 1 < count($reply); $i += 2) {
            $fields = (array) $reply[$i + 1];
            for ($f = 0; $f + 1 < count($fields); $f += 2) {
                if ($fields[$f] === '$') {
                    $hits[] = ['id' => (string) $reply[$i], 'value' => (string) $fields[$f + 1]];
                    break;
                }
            }
        }

        return $hits;
    }

    public function quit(): void
    {
        $this->redis->close();
    }

    /**
     * Run a command that phpredis does not model, turning error replies into exceptions.
     */
    private function raw(string ...$args): mixed
    {
        $reply = $this->guard(fn (): mixed => $this->redis->rawCommand(...$args));

        // A nil reply reaches PHP as false; keep `true` (a status reply) distinct from it.
        return $reply === false ? null : $reply;
    }

    /**
     * @template T
     * @param  callable(): T $call
     * @return T
     */
    private function guard(callable $call): mixed
    {
        try {
            $result = $call();
        } catch (\RedisException $e) {
            throw new RedisClientException($e->getMessage(), 0, $e);
        }

        $error = $this->redis->getLastError();
        if ($error !== null) {
            $this->redis->clearLastError();
            throw new RedisClientException($error);
        }

        return $result;
    }
}
