<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\Postgres\Migrations;
use LangGraph\Checkpoint\Postgres\Sql;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `tests/sql.test.ts` from `@langchain/langgraph-checkpoint-postgres`.
 *
 * Pure string tests: no database. The `getStoreTablesWithSchema` and
 * `getStoreMigrations` cases belong to the Postgres store port (WP-10) and are
 * not converted here.
 */
#[CoversClass(Sql::class)]
#[CoversClass(Migrations::class)]
final class PostgresSqlTest extends TestCase
{
    public function testGetTablesWithSchemaQuotesTheSchemaIdentifierForSimpleSchemas(): void
    {
        $tables = Sql::getTablesWithSchema('public');

        self::assertSame('"public".checkpoints', $tables['checkpoints']);
        self::assertSame('"public".checkpoint_blobs', $tables['checkpoint_blobs']);
        self::assertSame('"public".checkpoint_writes', $tables['checkpoint_writes']);
        self::assertSame('"public".checkpoint_migrations', $tables['checkpoint_migrations']);
    }

    public function testGetTablesWithSchemaQuotesSchemasWithDashes(): void
    {
        $tables = Sql::getTablesWithSchema('my-schema');

        self::assertSame('"my-schema".checkpoints', $tables['checkpoints']);
        self::assertSame('"my-schema".checkpoint_blobs', $tables['checkpoint_blobs']);
        self::assertSame('"my-schema".checkpoint_writes', $tables['checkpoint_writes']);
        self::assertSame('"my-schema".checkpoint_migrations', $tables['checkpoint_migrations']);
    }

    public function testGetTablesWithSchemaQuotesSchemasWithSpecialCharacters(): void
    {
        self::assertSame('"my schema".checkpoints', Sql::getTablesWithSchema('my schema')['checkpoints']);
    }

    public function testAnEmbeddedDoubleQuoteInTheSchemaIsEscaped(): void
    {
        self::assertSame('"a""b".checkpoints', Sql::getTablesWithSchema('a"b')['checkpoints']);
    }

    public function testGetSqlStatementsProducesValidSqlWithADashedSchema(): void
    {
        $statements = Sql::getSQLStatements('my-schema');

        self::assertStringContainsString('"my-schema".checkpoint_blobs', $statements['SELECT_SQL']);
        self::assertStringContainsString('"my-schema".checkpoint_writes', $statements['SELECT_SQL']);
        self::assertStringContainsString('"my-schema".checkpoints', $statements['SELECT_SQL']);

        self::assertStringContainsString('"my-schema".checkpoint_writes', $statements['SELECT_PENDING_SENDS_SQL']);
        self::assertStringContainsString('"my-schema".checkpoint_blobs', $statements['UPSERT_CHECKPOINT_BLOBS_SQL']);
        self::assertStringContainsString('"my-schema".checkpoints', $statements['UPSERT_CHECKPOINTS_SQL']);
        self::assertStringContainsString('"my-schema".checkpoint_writes', $statements['UPSERT_CHECKPOINT_WRITES_SQL']);
        self::assertStringContainsString('"my-schema".checkpoint_writes', $statements['INSERT_CHECKPOINT_WRITES_SQL']);

        self::assertStringContainsString('"my-schema".checkpoints', $statements['DELETE_CHECKPOINTS_SQL']);
        self::assertStringContainsString('"my-schema".checkpoint_blobs', $statements['DELETE_CHECKPOINT_BLOBS_SQL']);
        self::assertStringContainsString('"my-schema".checkpoint_writes', $statements['DELETE_CHECKPOINT_WRITES_SQL']);
    }

    public function testGetSqlStatementsDoesNotContainUnquotedDashedSchemaReferences(): void
    {
        $all = implode("\n", Sql::getSQLStatements('my-schema'));

        self::assertDoesNotMatchRegularExpression('/(?<!")my-schema\./', $all);
    }

    public function testNewestFirstOrderingIsAStringComparisonOnCheckpointId(): void
    {
        // uuid6 ids sort as strings; casting the column would reorder them.
        $sql = Sql::getSQLStatements('public');

        self::assertStringNotContainsString('::uuid', $sql['SELECT_SQL']);
        self::assertStringNotContainsString('::int', $sql['SELECT_SQL']);
    }

    public function testGetMigrationsProducesValidMigrationSqlWithADashedSchema(): void
    {
        $migrations = Migrations::getMigrations('my-schema');

        foreach ($migrations as $migration) {
            self::assertDoesNotMatchRegularExpression('/(?<!")my-schema\./', $migration);
        }

        self::assertStringContainsString('"my-schema".checkpoint_migrations', $migrations[0]);
        self::assertStringContainsString('"my-schema".checkpoints', $migrations[1]);
        self::assertStringContainsString('"my-schema".checkpoint_blobs', $migrations[2]);
        self::assertStringContainsString('"my-schema".checkpoint_writes', $migrations[3]);
        self::assertStringContainsString('"my-schema".checkpoint_blobs', $migrations[4]);
        self::assertCount(5, $migrations);
    }

    public function testTableExistsSqlUsesTheSchemaAsAStringValueInTheWhereClause(): void
    {
        $sql = Sql::tableExistsSQL('my-schema', 'my-schema.checkpoints');

        // The schema is a string value here, not an identifier, so single quotes.
        self::assertStringContainsString("table_schema = 'my-schema'", $sql);
        self::assertStringContainsString("table_name   = 'checkpoints'", $sql);
    }
}
