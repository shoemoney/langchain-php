<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\Postgres;

use LangGraph\Store\Postgres\Modules\IndexConfig;
use LangGraph\Store\Postgres\Modules\QueryBuilder;
use LangGraph\Store\Postgres\Modules\VectorOperations;
use LangGraph\Store\Postgres\Sql;
use LangGraph\Store\Postgres\StoreMigrations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for the SQL the store builds, runnable without a server.
 */
#[CoversClass(QueryBuilder::class)]
#[CoversClass(StoreMigrations::class)]
#[CoversClass(Sql::class)]
#[CoversClass(VectorOperations::class)]
final class PostgresStoreQueryBuilderTest extends TestCase
{
    public function testSimpleValuesCompareTheTextOfTheField(): void
    {
        $params = [];
        $conditions = QueryBuilder::buildFilterConditions(['category' => 'programming', 'ok' => true], $params);

        self::assertSame(['value ->> ?::text = ?', 'value ->> ?::text = ?'], $conditions);
        self::assertSame(['category', 'programming', 'ok', 'true'], $params);
    }

    public function testEveryComparisonOperatorBecomesACondition(): void
    {
        $params = [];
        $conditions = QueryBuilder::buildFilterConditions([
            'a' => ['$eq' => 'x'],
            'b' => ['$ne' => 'y'],
            'n' => ['$gt' => 1, '$gte' => 2, '$lt' => 3, '$lte' => 4],
            'c' => ['$in' => ['p', 'q']],
            'd' => ['$nin' => ['r']],
            'e' => ['$exists' => false],
            'f' => ['$bogus' => 1],
            'g' => ['$in' => []],
        ], $params);

        self::assertSame([
            'value ->> ?::text = ?',
            'value ->> ?::text != ?',
            '(value ->> ?::text)::numeric > ?',
            '(value ->> ?::text)::numeric >= ?',
            '(value ->> ?::text)::numeric < ?',
            '(value ->> ?::text)::numeric <= ?',
            'value ->> ?::text = ANY(ARRAY[?,?])',
            'value ->> ?::text != ALL(ARRAY[?])',
            'NOT jsonb_exists(value, ?)',
        ], $conditions);
        self::assertSame(['a', 'x', 'b', 'y', 'n', 1, 'n', 2, 'n', 3, 'n', 4, 'c', 'p', 'q', 'd', 'r', 'e'], $params);
        self::assertSame(count($params), array_sum(array_map(static fn (string $c): int => substr_count($c, '?'), $conditions)));
    }

    public function testAnObjectWithoutOperatorsIsAContainmentQuery(): void
    {
        $params = [];
        $conditions = QueryBuilder::buildFilterConditions(['meta' => ['author' => 'Ada']], $params);

        self::assertSame(['value @> ?::jsonb'], $conditions);
        self::assertSame(['{"meta":{"author":"Ada"}}'], $params);
    }

    public function testBasicMigrationsAreFourAndVectorMigrationsAddThree(): void
    {
        self::assertCount(4, StoreMigrations::getStoreMigrations('s'));

        $vector = StoreMigrations::getStoreMigrations('s', new IndexConfig(8, static fn (array $t): array => []));
        self::assertCount(7, $vector);
        self::assertSame('CREATE EXTENSION IF NOT EXISTS vector;', $vector[4]);
        self::assertStringContainsString('embedding vector(8) NOT NULL', $vector[5]);
        self::assertStringContainsString('USING hnsw (embedding vector_cosine_ops)', $vector[6]);
        self::assertStringContainsString('m = 16, ef_construction = 200', $vector[6]);
    }

    public function testEveryMetricGetsAnIndexWhenRequestedAndIvfflatIsSupported(): void
    {
        $all = StoreMigrations::getStoreMigrations('s', new IndexConfig(8, static fn (array $t): array => [], createAllMetricIndexes: true, indexType: 'ivfflat'));
        self::assertCount(9, $all);
        self::assertStringContainsString('idx_store_vectors_embedding_ip_ivfflat', $all[8]);
        self::assertStringContainsString('vector_ip_ops', $all[8]);
        self::assertStringContainsString('lists = 100', $all[8]);
    }

    public function testAnUnknownIndexTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        StoreMigrations::getStoreMigrations('s', new IndexConfig(8, static fn (array $t): array => [], indexType: 'btree'));
    }

    public function testSchemaNamesAreQuoted(): void
    {
        self::assertSame('"we""ird".store', Sql::getStoreTablesWithSchema('we"ird')['store']);
    }

    public function testVectorLiteralsUseJsonNumbers(): void
    {
        self::assertSame('[1,0.5,-2]', VectorOperations::toVectorLiteral([1.0, 0.5, -2]));
    }
}
