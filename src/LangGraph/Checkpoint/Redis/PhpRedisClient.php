<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint\Redis;

/**
 * {@see RedisClientInterface} over ext-redis (phpredis).
 *
 * phpredis has no RedisJSON or RediSearch methods, so those go through
 * `rawCommand`. Requires a server with both modules (Redis Stack, or Redis 8).
 * Cluster JSON commands route by key; Search commands share one discovered coordinator.
 * Distributed Search must be provided by the server: this adapter does not merge shard results.
 * Unlike native keyed commands, phpredis rawCommand does not follow MOVED/ASK redirects.
 * RedisJSON during resharding/failover therefore remains a separate multi-node verification gap.
 * Borrowed clients must leave OPT_PREFIX unset: raw JSON keys are sent without that prefix.
 * Native cluster KEYS can return incomplete results or raise warnings/fatal errors for orphaned
 * keys; this adapter does not make key discovery/deletion complete under those conditions.
 */
final class PhpRedisClient implements RedisClientInterface
{
    /** @var array{string, int}|null */
    private ?array $searchNode = null;

    public function __construct(private readonly \Redis|\RedisCluster $redis)
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

    /**
     * Port of createCluster({rootNodes}) and connect(). ext-redis discovers slots in construction.
     * Its single auth/TLS configuration requires consistent root-node credentials and schemes.
     * The optional connector has RedisCluster's constructor signature and is a socket-free test seam.
     *
     * @param list<array{url: string}> $rootNodes
     * @param callable(?string, list<string>, int, int, bool, string|array|null, ?array): \RedisCluster|null $connector
     */
    public static function fromCluster(array $rootNodes, ?callable $connector = null): self
    {
        if ($rootNodes === [] || !array_is_list($rootNodes)) {
            throw new \InvalidArgumentException('Redis cluster requires a non-empty list of root nodes.');
        }
        $seeds = [];
        $common = null;
        foreach ($rootNodes as $node) {
            $url = is_array($node) ? ($node['url'] ?? null) : null;
            if (!is_string($url) || preg_match('/[\x00-\x20\x7f]/', $url)) {
                throw new \InvalidArgumentException('Invalid Redis cluster root-node URL.');
            }
            try {
                $parts = parse_url($url);
            } catch (\ValueError) {
                $parts = false;
            }
            if ($parts === false || !isset($parts['host']) || $parts['host'] === ''
                || !in_array($parts['scheme'] ?? null, ['redis', 'rediss'], true)
                || isset($parts['query']) || isset($parts['fragment'])
                || ($parts['port'] ?? 6379) < 1
                || !in_array($parts['path'] ?? '', ['', '/', '/0'], true)
                || (isset($parts['user']) && $parts['user'] !== '' && !isset($parts['pass']))) {
                throw new \InvalidArgumentException('Invalid Redis cluster root-node URL; only redis/rediss database 0 is supported.');
            }
            $auth = isset($parts['pass'])
                ? (($parts['user'] ?? '') !== ''
                    ? [rawurldecode($parts['user']), rawurldecode($parts['pass'])]
                    : rawurldecode($parts['pass']))
                : null;
            $config = [$parts['scheme'] === 'rediss', $auth];
            if ($common !== null && $common !== $config) {
                throw new \InvalidArgumentException('Redis cluster root nodes must use identical authentication and TLS configuration.');
            }
            $common = $config;
            $seeds[] = $parts['host'] . ':' . ($parts['port'] ?? 6379);
        }
        if (!class_exists(\RedisCluster::class, false)) {
            throw new \RuntimeException('ext-redis with RedisCluster support is required; pass a RedisClientInterface instead.');
        }
        // An array, including [], enables TLS on discovered nodes as well as the initial seeds.
        $arguments = [null, $seeds, 0, 0, false, $common[1], $common[0] ? [] : null];
        try {
            $redis = $connector === null ? new \RedisCluster(...$arguments) : $connector(...$arguments);
        } catch (\RedisException|\RedisClusterException $e) {
            throw new RedisClientException($e->getMessage(), 0, $e);
        }
        if (!$redis instanceof \RedisCluster) {
            throw new \InvalidArgumentException('Redis cluster connector must return RedisCluster.');
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
        return array_map('strval', (array) $this->guard(fn (): mixed => $this->redis->keys($pattern)));
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
        return array_map('strval', (array) $this->guard(fn (): mixed => $this->redis->zRange($key, $start, $stop)));
    }

    public function ftCreate(string $index, array $schema, string $prefix): void
    {
        $args = ['FT.CREATE', $index, 'ON', 'JSON', 'PREFIX', '1', $prefix, 'SCHEMA'];
        foreach ($schema as $path => $field) {
            array_push($args, $path, 'AS', $field['as'], $field['type']);
            if (isset($field['algorithm'])) {
                $attributes = $field['attributes'] ?? [];
                $args[] = $field['algorithm'];
                $args[] = (string) (count($attributes) * 2);
                foreach ($attributes as $name => $value) {
                    array_push($args, (string) $name, (string) $value);
                }
            }
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
        ?array $return = null,
        array $params = [],
        ?int $dialect = null,
    ): array {
        $args = ['FT.SEARCH', $index, $query];
        if ($sortBy !== '') {
            array_push($args, 'SORTBY', $sortBy, $descending ? 'DESC' : 'ASC');
        }
        array_push($args, 'LIMIT', (string) $offset, (string) $size);
        if ($return !== null) {
            array_push($args, 'RETURN', (string) count($return), ...$return);
        }
        if ($params !== []) {
            $args[] = 'PARAMS';
            $args[] = (string) (count($params) * 2);
            foreach ($params as $name => $value) {
                array_push($args, (string) $name, $value);
            }
        }
        if ($dialect !== null) {
            array_push($args, 'DIALECT', (string) $dialect);
        }
        $reply = $this->raw(...$args);

        $hits = [];
        if (!is_array($reply)) {
            return $hits;
        }
        // [total, key, [field, value, ...], key, [...], ...]; a JSON index returns the whole
        // document under the field `$`, and only the named fields under RETURN.
        for ($i = 1; $i + 1 < count($reply); $i += 2) {
            $fields = (array) $reply[$i + 1];
            if ($return !== null) {
                $picked = [];
                for ($f = 0; $f + 1 < count($fields); $f += 2) {
                    $picked[(string) $fields[$f]] = (string) $fields[$f + 1];
                }
                $hits[] = ['id' => (string) $reply[$i], 'value' => json_encode($picked, JSON_THROW_ON_ERROR | JSON_FORCE_OBJECT)];
                continue;
            }
            for ($f = 0; $f + 1 < count($fields); $f += 2) {
                if ($fields[$f] === '$') {
                    $hits[] = ['id' => (string) $reply[$i], 'value' => (string) $fields[$f + 1]];
                    break;
                }
            }
        }

        return $hits;
    }

    public function ftInfo(string $index): array
    {
        // FT.INFO labels its fields with simple-string replies, which phpredis flattens to `true`
        // unless asked for the literal text. Restore the option so no other command is affected.
        $previous = $this->redis->getOption(\Redis::OPT_REPLY_LITERAL);
        $this->redis->setOption(\Redis::OPT_REPLY_LITERAL, true);
        try {
            $reply = $this->raw('FT.INFO', $index);
        } finally {
            $this->redis->setOption(\Redis::OPT_REPLY_LITERAL, $previous);
        }

        $info = [];
        if (is_array($reply)) {
            for ($i = 0; $i + 1 < count($reply); $i += 2) {
                $info[(string) $reply[$i]] = $reply[$i + 1];
            }
        }

        return $info;
    }

    public function quit(): void
    {
        $this->guard(fn (): bool => $this->redis->close());
    }

    /**
     * Run a command that phpredis does not model, turning error replies into exceptions.
     */
    private function raw(string ...$args): mixed
    {
        $searchCommand = str_starts_with($args[0], 'FT.');
        try {
            $reply = $this->guard(function () use ($args): mixed {
                if ($this->redis instanceof \RedisCluster) {
                    if (str_starts_with($args[0], 'JSON.')) {
                        $route = $args[1];
                    } else {
                        if ($this->searchNode === null) {
                            $masters = $this->redis->_masters();
                            sort($masters);
                            if ($masters === []) {
                                throw new RedisClientException('Redis cluster has no Search coordinator.');
                            }
                            $this->searchNode = $masters[0];
                        }
                        $route = $this->searchNode;
                    }

                    return $this->clusterRaw($route, $args);
                }

                return $this->redis->rawCommand(...$args);
            });
            // FT commands never return nil. Standalone phpredis returns false for
            // ordinary ERR replies without throwing, so retain its last-error text.
            if ($searchCommand && $reply === false) {
                $error = $this->redis->getLastError();
                $this->redis->clearLastError();
                throw new RedisClientException($error !== null && $error !== ''
                    ? $error
                    : $args[0] . ' returned false without a server error.');
            }
        } catch (\Throwable $e) {
            if ($searchCommand && $this->redis instanceof \RedisCluster) {
                // A directed-node failure must not poison every subsequent Search operation.
                $this->searchNode = null;
            }

            throw $e;
        }

        // A nil reply reaches PHP as false; keep `true` (a status reply) distinct from it.
        return $reply === false ? null : $reply;
    }

    /**
     * Directed commands can warn and return false without setting lastError (Unknown node).
     * Preserve that failure as an exception, restoring the caller's error handler in all paths.
     *
     * @param array{string, int}|string $route
     * @param list<string> $args
     */
    private function clusterRaw(array|string $route, array $args): mixed
    {
        $warning = null;
        $previous = null;
        $previous = set_error_handler(static function (int $severity, string $message, string $file, int $line) use (&$warning, &$previous): mixed {
            if (!in_array($severity, [E_WARNING, E_USER_WARNING], true)) {
                return $previous !== null ? $previous($severity, $message, $file, $line) : false;
            }
            $warning = $message;

            return true;
        });
        try {
            $reply = $this->redis->rawCommand($route, ...$args);
        } finally {
            restore_error_handler();
        }
        if ($warning !== null) {
            throw new RedisClientException($warning);
        }

        return $reply;
    }

    /**
     * @template T
     * @param  callable(): T $call
     * @return T
     */
    private function guard(callable $call): mixed
    {
        try {
            if ($this->redis instanceof \RedisCluster) {
                $this->redis->clearLastError();
            }
            $reply = $call();
            if ($this->redis instanceof \RedisCluster) {
                $error = $this->redis->getLastError();
                if ($error !== null && $error !== '') {
                    $this->redis->clearLastError();
                    throw new RedisClientException($error);
                }
            }

            return $reply;
        } catch (\RedisException|\RedisClusterException $e) {
            // phpredis keeps the last error string after throwing; clear it so it cannot be
            // mistaken for the outcome of a later, successful command.
            $message = $this->redis instanceof \RedisCluster
                ? ($this->redis->getLastError() ?? $e->getMessage())
                : $e->getMessage();
            $this->redis->clearLastError();

            throw new RedisClientException($message, 0, $e);
        }
    }
}
