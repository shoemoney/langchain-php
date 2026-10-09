<?php

declare(strict_types=1);

namespace LangGraph\Store\Postgres\Modules;

use LangGraph\Store\Postgres\Sql;

/**
 * Core database access shared by every store module.
 *
 * Port of `store/modules/database-core.ts`. Upstream hands each operation a
 * client checked out of a connection pool; here there is one PDO connection and
 * {@see self::withClient()} simply passes it through.
 */
final class DatabaseCore
{
    public readonly string $textSearchLanguage;

    public function __construct(
        public readonly \PDO $pdo,
        public readonly string $schema,
        public readonly ?TtlConfig $ttlConfig = null,
        public readonly ?IndexConfig $indexConfig = null,
        ?string $textSearchLanguage = null,
    ) {
        $this->textSearchLanguage = $textSearchLanguage ?? 'english';
    }

    /**
     * @template T
     *
     * @param  callable(\PDO): T $operation
     * @return T
     */
    public function withClient(callable $operation): mixed
    {
        return $operation($this->pdo);
    }

    /** The schema-qualified `store` table, quoted. */
    public function storeTable(): string
    {
        return Sql::getStoreTablesWithSchema($this->schema)['store'];
    }

    /** The schema-qualified `store_vectors` table, quoted. */
    public function vectorsTable(): string
    {
        return Sql::getStoreTablesWithSchema($this->schema)['store_vectors'];
    }

    /**
     * Run a statement and return it for fetching.
     *
     * @param list<mixed> $params
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute(array_map(self::bindable(...), $params));

        return $statement;
    }

    /**
     * @param int|float|null $ttl Minutes; falls back to the configured default.
     */
    public function calculateExpiresAt(int|float|null $ttl = null): ?\DateTimeImmutable
    {
        $effective = $ttl ?? $this->ttlConfig?->defaultTtl;
        if ($effective === null || $effective == 0) {
            return null;
        }

        return (new \DateTimeImmutable())->modify(sprintf('+%d seconds', (int) round($effective * 60)));
    }

    public function refreshTtl(string $namespacePath, string $key): void
    {
        if ($this->ttlConfig === null || !$this->ttlConfig->refreshOnRead) {
            return;
        }

        $expiresAt = $this->calculateExpiresAt();
        if ($expiresAt !== null) {
            $this->query(
                'UPDATE ' . $this->storeTable() . ' SET expires_at = ?, updated_at = CURRENT_TIMESTAMP WHERE namespace_path = ? AND key = ?',
                [$expiresAt, $namespacePath, $key],
            );
        }
    }

    /** Parse a Postgres timestamp column into a date. */
    public static function toDate(mixed $value): \DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new \DateTimeImmutable($value) : new \DateTimeImmutable();
    }

    private static function bindable(mixed $param): mixed
    {
        if ($param instanceof \DateTimeInterface) {
            return $param->format('Y-m-d H:i:s.uP');
        }
        if (is_bool($param)) {
            return $param ? 'true' : 'false';
        }

        return $param;
    }
}
