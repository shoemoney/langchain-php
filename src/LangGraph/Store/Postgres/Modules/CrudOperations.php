<?php

declare(strict_types=1);

namespace LangGraph\Store\Postgres\Modules;

use LangGraph\Store\GetOperation;
use LangGraph\Store\Item;
use LangGraph\Store\PutOperation;

/**
 * Basic operations: get, put, delete.
 *
 * Port of `store/modules/crud-operations.ts`.
 */
final class CrudOperations
{
    public function __construct(
        private readonly DatabaseCore $core,
        private readonly VectorOperations $vectorOps,
    ) {
    }

    public function executeGet(GetOperation $operation): ?Item
    {
        Utils::validateNamespace($operation->namespace);

        $namespacePath = implode(':', $operation->namespace);

        $row = $this->core->query(
            'SELECT namespace_path, key, value, created_at, updated_at FROM ' . $this->core->storeTable()
            . ' WHERE namespace_path = ? AND key = ? AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)',
            [$namespacePath, $operation->key],
        )->fetch();

        if ($row === false) {
            return null;
        }

        if ($this->core->ttlConfig?->refreshOnRead) {
            $this->core->refreshTtl($namespacePath, $operation->key);
        }

        return new Item(
            self::decode($row['value']),
            $row['key'],
            explode(':', $row['namespace_path']),
            DatabaseCore::toDate($row['created_at']),
            DatabaseCore::toDate($row['updated_at']),
        );
    }

    /**
     * Store, update or (for a null value) delete an item.
     *
     * @param array{ttl?: int|float}|null $options
     */
    public function executePut(PutOperation $operation, ?array $options = null): void
    {
        Utils::validateNamespace($operation->namespace);

        $namespacePath = implode(':', $operation->namespace);

        if ($operation->value === null) {
            $this->core->query(
                'DELETE FROM ' . $this->core->storeTable() . ' WHERE namespace_path = ? AND key = ?',
                [$namespacePath, $operation->key],
            );

            return;
        }

        $expiresAt = $this->core->calculateExpiresAt($options['ttl'] ?? null);
        $json = json_encode($operation->value === [] ? new \stdClass() : $operation->value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $this->core->query(
            'INSERT INTO ' . $this->core->storeTable() . ' (namespace_path, key, value, expires_at) VALUES (?, ?, ?::jsonb, ?)'
            . ' ON CONFLICT (namespace_path, key) DO UPDATE SET value = EXCLUDED.value, expires_at = EXCLUDED.expires_at, updated_at = CURRENT_TIMESTAMP',
            [$namespacePath, $operation->key, $json, $expiresAt],
        );

        if ($this->core->indexConfig !== null && $operation->index !== false) {
            $this->vectorOps->indexItemVectors($namespacePath, $operation->key, $operation->value, $operation->index);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(mixed $json): array
    {
        $decoded = json_decode((string) $json, true);

        return is_array($decoded) ? $decoded : [];
    }
}
