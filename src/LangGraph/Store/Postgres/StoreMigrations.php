<?php

declare(strict_types=1);

namespace LangGraph\Store\Postgres;

use LangGraph\Store\Postgres\Modules\IndexConfig;

/**
 * The ordered schema migrations for the Postgres store.
 *
 * Port of `store/store-migrations.ts`. A migration's position in the list is its
 * version number: add a new migration by appending to the list.
 *
 * Migrations 0 to 3 always run (migrations table, store table, indexes, the
 * `updated_at` trigger). When an index config is given, 4 enables pgvector,
 * 5 creates the vectors table and 6 onward create one vector index per metric.
 */
final class StoreMigrations
{
    private function __construct()
    {
    }

    /**
     * @return list<string>
     */
    public static function getStoreMigrations(string $schema, ?IndexConfig $indexConfig = null): array
    {
        $tables = Sql::getStoreTablesWithSchema($schema);
        $quoted = Sql::quoteIdentifier($schema);
        $migrations = [];

        $migrations[] = "CREATE TABLE IF NOT EXISTS {$tables['store_migrations']} (
    v INTEGER PRIMARY KEY
  );";

        $migrations[] = "CREATE TABLE IF NOT EXISTS {$tables['store']} (
    namespace_path TEXT NOT NULL,
    key TEXT NOT NULL,
    value JSONB NOT NULL,
    created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
    expires_at TIMESTAMPTZ,
    PRIMARY KEY (namespace_path, key)
  );";

        $migrations[] = "
    CREATE INDEX IF NOT EXISTS idx_store_namespace_path
    ON {$tables['store']} USING btree (namespace_path);

    CREATE INDEX IF NOT EXISTS idx_store_value_gin
    ON {$tables['store']} USING gin (value);

    CREATE INDEX IF NOT EXISTS idx_store_expires_at
    ON {$tables['store']} USING btree (expires_at)
    WHERE expires_at IS NOT NULL;
  ";

        $migrations[] = "
    CREATE OR REPLACE FUNCTION {$quoted}.update_updated_at_column()
    RETURNS TRIGGER AS \$\$
    BEGIN
      NEW.updated_at = CURRENT_TIMESTAMP;
      RETURN NEW;
    END;
    \$\$ language 'plpgsql';

    DROP TRIGGER IF EXISTS update_store_updated_at ON {$tables['store']};

    CREATE TRIGGER update_store_updated_at
    BEFORE UPDATE ON {$tables['store']}
    FOR EACH ROW EXECUTE FUNCTION {$quoted}.update_updated_at_column();
  ";

        if ($indexConfig === null) {
            return $migrations;
        }

        $migrations[] = 'CREATE EXTENSION IF NOT EXISTS vector;';

        $dims = $indexConfig->dims;
        $migrations[] = "CREATE TABLE IF NOT EXISTS {$tables['store_vectors']} (
      namespace_path TEXT NOT NULL,
      key TEXT NOT NULL,
      field_path TEXT NOT NULL,
      text_content TEXT NOT NULL,
      embedding vector({$dims}) NOT NULL,
      created_at TIMESTAMPTZ DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (namespace_path, key, field_path),
      FOREIGN KEY (namespace_path, key) REFERENCES {$tables['store']}(namespace_path, key) ON DELETE CASCADE
    );";

        $indexType = $indexConfig->indexType;
        $metrics = $indexConfig->createAllMetricIndexes ? ['cosine', 'l2', 'inner_product'] : [$indexConfig->distanceMetric];
        $operatorClasses = ['cosine' => 'vector_cosine_ops', 'l2' => 'vector_l2_ops', 'inner_product' => 'vector_ip_ops'];
        $suffixes = ['cosine' => 'cosine', 'l2' => 'l2', 'inner_product' => 'ip'];

        foreach ($metrics as $metric) {
            $indexName = "idx_store_vectors_embedding_{$suffixes[$metric]}_{$indexType}";
            $operatorClass = $operatorClasses[$metric];

            if ($indexType === 'hnsw') {
                $m = (int) ($indexConfig->hnsw['m'] ?? 0) ?: 16;
                $efConstruction = (int) ($indexConfig->hnsw['efConstruction'] ?? 0) ?: 200;
                $migrations[] = "CREATE INDEX IF NOT EXISTS {$indexName}
          ON {$tables['store_vectors']} USING hnsw (embedding {$operatorClass})
          WITH (m = {$m}, ef_construction = {$efConstruction});";
            } elseif ($indexType === 'ivfflat') {
                $lists = (int) ($indexConfig->ivfflat['lists'] ?? 0) ?: 100;
                $migrations[] = "CREATE INDEX IF NOT EXISTS {$indexName}
          ON {$tables['store_vectors']} USING ivfflat (embedding {$operatorClass})
          WITH (lists = {$lists});";
            } else {
                throw new \InvalidArgumentException("Unsupported vector index type: {$indexType}");
            }
        }

        return $migrations;
    }
}
