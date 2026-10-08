<?php

declare(strict_types=1);

namespace LangGraph\Store;

/**
 * Abstract base class for persistent key-value stores.
 *
 * Port of `BaseStore` from `@langchain/langgraph-checkpoint`'s `store/base.ts`.
 *
 * Stores give a graph memory that outlives a thread and can be shared across
 * threads, scoped to a user id, an assistant id or any other namespace.
 *
 *  - hierarchical namespaces for organisation;
 *  - key-value storage with created/updated timestamps;
 *  - vector similarity search when the store is configured with embeddings;
 *  - filtering and pagination.
 *
 * A subclass implements only {@see self::batch()}; `get`, `search`, `put`,
 * `delete` and `listNamespaces` are single-operation batches. Upstream's methods
 * return promises; here they are synchronous, matching the rest of this port.
 */
abstract class BaseStore
{
    /**
     * Execute several operations in one go.
     *
     * Results line up with the operations by index: a {@see GetOperation} yields
     * an {@see Item} or null, a {@see SearchOperation} a list of
     * {@see SearchItem}, a {@see PutOperation} null and a
     * {@see ListNamespacesOperation} a list of namespaces.
     *
     * @param  list<Operation> $operations
     * @return list<mixed>
     */
    abstract public function batch(array $operations): array;

    /**
     * Retrieve a single item by namespace and key.
     *
     * @param list<string> $namespace
     */
    public function get(array $namespace, string $key): ?Item
    {
        return $this->batch([new GetOperation($namespace, $key)])[0];
    }

    /**
     * Search for items within a namespace prefix, by metadata filter and/or by
     * vector similarity to a natural language query.
     *
     * @param  list<string>                                                                  $namespacePrefix
     * @param  array{filter?: array<string, mixed>|null, limit?: int, offset?: int, query?: string|null} $options
     * @return list<SearchItem>
     */
    public function search(array $namespacePrefix, array $options = []): array
    {
        return $this->batch([new SearchOperation(
            $namespacePrefix,
            $options['filter'] ?? null,
            $options['limit'] ?? 10,
            $options['offset'] ?? 0,
            $options['query'] ?? null,
        )])[0];
    }

    /**
     * Store or update an item.
     *
     * @param list<string>            $namespace
     * @param array<string, mixed>    $value
     * @param false|list<string>|null $index     Field paths to index, `false` to skip indexing, null for the store default.
     *
     * @throws InvalidNamespaceError When the namespace is empty, has an empty or
     *                               dotted label, or starts with `langgraph`.
     */
    public function put(array $namespace, string $key, array $value, false|array|null $index = null): void
    {
        self::validateNamespace($namespace);
        $this->batch([new PutOperation($namespace, $key, $value, $index)]);
    }

    /**
     * Delete an item.
     *
     * @param list<string> $namespace
     */
    public function delete(array $namespace, string $key): void
    {
        $this->batch([new PutOperation($namespace, $key, null)]);
    }

    /**
     * List and filter the namespaces in the store.
     *
     * @param  array{prefix?: list<string>|null, suffix?: list<string>|null, maxDepth?: int|null, limit?: int, offset?: int} $options
     * @return list<list<string>>
     */
    public function listNamespaces(array $options = []): array
    {
        $matchConditions = [];
        if (isset($options['prefix'])) {
            $matchConditions[] = new MatchCondition(MatchCondition::PREFIX, $options['prefix']);
        }
        if (isset($options['suffix'])) {
            $matchConditions[] = new MatchCondition(MatchCondition::SUFFIX, $options['suffix']);
        }

        return $this->batch([new ListNamespacesOperation(
            $matchConditions === [] ? null : $matchConditions,
            $options['maxDepth'] ?? null,
            $options['limit'] ?? 100,
            $options['offset'] ?? 0,
        )])[0];
    }

    /** Start the store. Override if initialization is needed. */
    public function start(): void
    {
    }

    /** Stop the store. Override if cleanup is needed. */
    public function stop(): void
    {
    }

    /**
     * @param list<mixed> $namespace
     *
     * @throws InvalidNamespaceError
     */
    private static function validateNamespace(array $namespace): void
    {
        if ($namespace === []) {
            throw new InvalidNamespaceError('Namespace cannot be empty.');
        }
        $shown = implode(',', array_map(static fn (mixed $l): string => is_scalar($l) ? (string) $l : get_debug_type($l), $namespace));
        foreach ($namespace as $label) {
            if (!is_string($label)) {
                throw new InvalidNamespaceError(sprintf(
                    "Invalid namespace label '%s' found in %s. Namespace labels must be strings, but got %s.",
                    is_scalar($label) ? (string) $label : get_debug_type($label),
                    $shown,
                    get_debug_type($label),
                ));
            }
            if (str_contains($label, '.')) {
                throw new InvalidNamespaceError(sprintf(
                    "Invalid namespace label '%s' found in %s. Namespace labels cannot contain periods ('.').",
                    $label,
                    $shown,
                ));
            }
            if ($label === '') {
                throw new InvalidNamespaceError(sprintf(
                    'Namespace labels cannot be empty strings. Got %s in %s',
                    $label,
                    $shown,
                ));
            }
        }
        if ($namespace[0] === 'langgraph') {
            throw new InvalidNamespaceError(sprintf('Root label for namespace cannot be "langgraph". Got: %s', $shown));
        }
    }
}
