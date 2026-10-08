<?php

declare(strict_types=1);

namespace LangGraph\Sdk;

/**
 * Port of `client/store/index.ts`: the server-side key/value store.
 *
 * @phpstan-import-type Item from Schema
 * @phpstan-import-type ListNamespaceResponse from Schema
 * @phpstan-import-type SearchItemsResponse from Schema
 */
class StoreClient extends BaseClient
{
    /**
     * Store or update an item.
     *
     * `index` controls search indexing: null (server defaults), false (disable) or a list of field
     * paths. `ttl` is minutes, or null for no expiry. Both are sent only when passed.
     *
     * @param list<string>                                                      $namespace
     * @param array<string, mixed>                                              $value
     * @param array{index?: false|list<string>|null, ttl?: int|float|null, signal?: mixed} $options
     */
    public function putItem(array $namespace, string $key, array $value, array $options = []): void
    {
        self::assertNamespace($namespace);

        $payload = ['namespace' => $namespace, 'key' => $key, 'value' => $value]
            + self::wireFields($options, ['index' => 'index', 'ttl' => 'ttl']);

        $this->fetch('/store/items', ['method' => 'PUT', 'json' => $payload] + self::signalOf($options));
    }

    /**
     * Retrieve a single item, or null if the server has none.
     *
     * @param list<string>                                     $namespace
     * @param array{refreshTtl?: bool|null, signal?: mixed} $options
     *
     * @return Item|null
     */
    public function getItem(array $namespace, string $key, array $options = []): ?array
    {
        self::assertNamespace($namespace);

        $params = ['namespace' => implode('.', $namespace), 'key' => $key];
        if (array_key_exists('refreshTtl', $options)) {
            $params['refresh_ttl'] = $options['refreshTtl'];
        }

        $response = $this->fetch('/store/items', ['params' => $params] + self::signalOf($options));

        return $response ? self::withCamelTimestamps($response) : null;
    }

    /**
     * Delete an item.
     *
     * @param list<string>          $namespace
     * @param array{signal?: mixed} $options
     */
    public function deleteItem(array $namespace, string $key, array $options = []): void
    {
        self::assertNamespace($namespace);

        $this->fetch('/store/items', [
            'method' => 'DELETE',
            'json' => ['namespace' => $namespace, 'key' => $key],
        ] + self::signalOf($options));
    }

    /**
     * Search for items within a namespace prefix.
     *
     * @param list<string>                                                                                                  $namespacePrefix
     * @param array{filter?: array<string, mixed>, limit?: int, offset?: int, query?: string, refreshTtl?: bool|null, signal?: mixed} $options
     *
     * @return SearchItemsResponse
     */
    public function searchItems(array $namespacePrefix, array $options = []): array
    {
        $payload = self::defined([
            'namespace_prefix' => $namespacePrefix,
            'filter' => $options['filter'] ?? null,
            'limit' => $options['limit'] ?? 10,
            'offset' => $options['offset'] ?? 0,
            'query' => $options['query'] ?? null,
            'refresh_ttl' => $options['refreshTtl'] ?? null,
        ]);

        $response = $this->fetch('/store/items/search', ['method' => 'POST', 'json' => $payload] + self::signalOf($options));

        return ['items' => array_map(self::withCamelTimestamps(...), $response['items'])];
    }

    /**
     * List namespaces with optional match conditions.
     *
     * @param array{prefix?: list<string>, suffix?: list<string>, maxDepth?: int, limit?: int, offset?: int, signal?: mixed} $options
     *
     * @return ListNamespaceResponse
     */
    public function listNamespaces(array $options = []): array
    {
        return $this->fetch('/store/namespaces', [
            'method' => 'POST',
            'json' => self::defined([
                'prefix' => $options['prefix'] ?? null,
                'suffix' => $options['suffix'] ?? null,
                'max_depth' => $options['maxDepth'] ?? null,
                'limit' => $options['limit'] ?? 100,
                'offset' => $options['offset'] ?? 0,
            ]),
        ] + self::signalOf($options));
    }

    /**
     * @param list<string> $namespace
     */
    private static function assertNamespace(array $namespace): void
    {
        foreach ($namespace as $label) {
            if (str_contains($label, '.')) {
                throw new \InvalidArgumentException(
                    "Invalid namespace label '{$label}'. Namespace labels cannot contain periods ('.')"
                );
            }
        }
    }

    /**
     * The server sends `created_at`/`updated_at`; the SDK also exposes `createdAt`/`updatedAt`.
     *
     * @param array<string, mixed> $item
     *
     * @return array<string, mixed>
     */
    private static function withCamelTimestamps(array $item): array
    {
        return $item + [
            'createdAt' => $item['created_at'] ?? null,
            'updatedAt' => $item['updated_at'] ?? null,
        ];
    }
}
