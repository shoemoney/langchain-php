<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store;

use LangGraph\Store\BaseStore;
use LangGraph\Store\GetOperation;
use LangGraph\Store\InvalidNamespaceError;
use LangGraph\Store\Item;
use LangGraph\Store\ListNamespacesOperation;
use LangGraph\Store\MatchCondition;
use LangGraph\Store\PutOperation;
use LangGraph\Store\SearchItem;
use LangGraph\Store\SearchOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of the `BaseStore` describe block in `checkpoint/src/tests/store.test.ts`.
 */
#[CoversClass(BaseStore::class)]
#[CoversClass(GetOperation::class)]
#[CoversClass(SearchOperation::class)]
#[CoversClass(PutOperation::class)]
#[CoversClass(ListNamespacesOperation::class)]
#[CoversClass(MatchCondition::class)]
#[CoversClass(InvalidNamespaceError::class)]
final class BaseStoreTest extends TestCase
{
    private function makeStore(): RecordingStore
    {
        return new RecordingStore();
    }

    public function testGetReturnsTheStoresItem(): void
    {
        $result = $this->makeStore()->get(['test'], '123');

        self::assertInstanceOf(Item::class, $result);
        self::assertSame(['value' => 1], $result->value);
        self::assertSame('123', $result->key);
        self::assertSame(['test'], $result->namespace);
    }

    public function testSearchReturnsTheStoresItems(): void
    {
        $result = $this->makeStore()->search(['test']);

        self::assertCount(1, $result);
        self::assertSame('test', $result[0]->key);
        self::assertSame(['test'], $result[0]->namespace);
    }

    public function testPutAndDeleteResolveToNothing(): void
    {
        $store = $this->makeStore();
        $store->put(['test'], '123', ['value' => 2]);
        $store->delete(['test'], '123');

        self::assertCount(2, $store->calls);
        $put = $store->calls[0][0];
        self::assertInstanceOf(PutOperation::class, $put);
        self::assertSame(['value' => 2], $put->value);
        $delete = $store->calls[1][0];
        self::assertInstanceOf(PutOperation::class, $delete);
        self::assertNull($delete->value);
    }

    public function testSearchPassesItsOptionsToBatch(): void
    {
        $store = $this->makeStore();
        $store->search(['test'], ['limit' => 20, 'offset' => 5, 'filter' => ['key' => 'value']]);

        $op = $store->calls[0][0];
        self::assertInstanceOf(SearchOperation::class, $op);
        self::assertSame(['test'], $op->namespacePrefix);
        self::assertSame(20, $op->limit);
        self::assertSame(5, $op->offset);
        self::assertSame(['key' => 'value'], $op->filter);
        self::assertNull($op->query);
    }

    public function testSearchDefaultsToTenItemsFromTheStart(): void
    {
        $store = $this->makeStore();
        $store->search(['test']);

        $op = $store->calls[0][0];
        self::assertInstanceOf(SearchOperation::class, $op);
        self::assertSame(10, $op->limit);
        self::assertSame(0, $op->offset);
        self::assertNull($op->filter);
    }

    /**
     * @return iterable<string, array{0: list<string>}>
     */
    public static function invalidNamespaces(): iterable
    {
        yield 'empty' => [[]];
        yield 'dotted label' => [['the', 'thing.about']];
        yield 'empty label' => [['some', 'fun', '']];
        yield 'langgraph root' => [['langgraph', 'foo']];
    }

    /**
     * @param list<string> $namespace
     */
    #[DataProvider('invalidNamespaces')]
    public function testPutRejectsAnInvalidNamespaceBeforeTouchingTheStore(array $namespace): void
    {
        $store = $this->makeStore();

        try {
            $store->put($namespace, 'foo', ['foo' => 'bar']);
            self::fail('expected InvalidNamespaceError');
        } catch (InvalidNamespaceError) {
            self::assertSame([], $store->calls);
        }
    }

    public function testALanggraphLabelIsFineBelowTheRoot(): void
    {
        $store = $this->makeStore();
        $store->put(['foo', 'langgraph', 'foo'], 'bar', ['foo' => 'bar']);

        self::assertCount(1, $store->calls);
    }

    public function testDeleteAndBatchDoNotValidateTheNamespace(): void
    {
        $store = $this->makeStore();
        $store->delete(['langgraph', 'foo'], 'bar');
        $store->batch([new PutOperation(['langgraph', 'foo'], 'bar', ['foo' => 'bar'])]);

        self::assertCount(2, $store->calls);
    }

    public function testStartAndStopAreNoOpsByDefault(): void
    {
        $store = $this->makeStore();
        $store->start();
        $store->stop();

        self::assertSame([], $store->calls);
    }

    // ---- listNamespaces ----------------------------------------------------

    private function listing(): ListingStore
    {
        return new ListingStore();
    }

    public function testListsAllNamespacesWithDefaultOptions(): void
    {
        self::assertSame(
            [['a', 'b', 'c'], ['a', 'b', 'd'], ['a', 'c', 'e'], ['x', 'y', 'z']],
            $this->listing()->listNamespaces(),
        );
    }

    public function testFiltersNamespacesByPrefix(): void
    {
        self::assertSame(
            [['a', 'b', 'c'], ['a', 'b', 'd'], ['a', 'c', 'e']],
            $this->listing()->listNamespaces(['prefix' => ['a']]),
        );
    }

    public function testFiltersNamespacesBySuffix(): void
    {
        self::assertSame([['a', 'b', 'd']], $this->listing()->listNamespaces(['suffix' => ['d']]));
    }

    public function testAppliesMaxDepth(): void
    {
        self::assertSame(
            [['a', 'b'], ['a', 'b'], ['a', 'c'], ['x', 'y']],
            $this->listing()->listNamespaces(['maxDepth' => 2]),
        );
    }

    public function testAppliesLimitAndOffset(): void
    {
        self::assertSame(
            [['a', 'b', 'd'], ['a', 'c', 'e']],
            $this->listing()->listNamespaces(['limit' => 2, 'offset' => 1]),
        );
    }

    public function testCombinesPrefixSuffixAndMaxDepth(): void
    {
        self::assertSame(
            [['a', 'b']],
            $this->listing()->listNamespaces(['prefix' => ['a'], 'suffix' => ['c'], 'maxDepth' => 2]),
        );
    }

    public function testHandlesAWildcardInThePrefix(): void
    {
        self::assertSame([['a', 'b', 'c']], $this->listing()->listNamespaces(['prefix' => ['a', '*', 'c']]));
    }

    public function testHandlesAWildcardInTheSuffix(): void
    {
        self::assertSame([['x', 'y', 'z']], $this->listing()->listNamespaces(['suffix' => ['*', 'z']]));
    }

    public function testReturnsNothingWhenNoNamespaceMatches(): void
    {
        self::assertSame([], $this->listing()->listNamespaces(['prefix' => ['non', 'existent']]));
    }

    public function testListNamespacesPassesItsOptionsToBatch(): void
    {
        $store = $this->listing();
        $store->listNamespaces(['prefix' => ['a'], 'suffix' => ['c'], 'maxDepth' => 2, 'limit' => 5, 'offset' => 1]);

        $op = $store->calls[0][0];
        self::assertInstanceOf(ListNamespacesOperation::class, $op);
        self::assertCount(2, $op->matchConditions ?? []);
        self::assertSame('prefix', $op->matchConditions[0]->matchType);
        self::assertSame(['a'], $op->matchConditions[0]->path);
        self::assertSame('suffix', $op->matchConditions[1]->matchType);
        self::assertSame(['c'], $op->matchConditions[1]->path);
        self::assertSame(2, $op->maxDepth);
        self::assertSame(5, $op->limit);
        self::assertSame(1, $op->offset);
    }

    public function testNoPrefixOrSuffixMeansNoMatchConditions(): void
    {
        $store = $this->listing();
        $store->listNamespaces();

        $op = $store->calls[0][0];
        self::assertInstanceOf(ListNamespacesOperation::class, $op);
        self::assertNull($op->matchConditions);
        self::assertSame(100, $op->limit);
        self::assertSame(0, $op->offset);
    }
}

/**
 * Answers every operation with a canned result and records what it was asked.
 *
 * @internal
 */
class RecordingStore extends BaseStore
{
    /** @var list<list<\LangGraph\Store\Operation>> */
    public array $calls = [];

    public function batch(array $operations): array
    {
        $this->calls[] = $operations;
        $now = new \DateTimeImmutable();
        $results = [];

        foreach ($operations as $op) {
            $results[] = match (true) {
                $op instanceof SearchOperation => [new SearchItem(['value' => 1], $op->namespacePrefix[0] ?? '', $op->namespacePrefix, $now, $now)],
                $op instanceof GetOperation => new Item(['value' => 1], $op->key, $op->namespace, $now, $now),
                default => null,
            };
        }

        return $results;
    }
}

/**
 * Answers a namespace listing from a fixed set, honouring the match conditions.
 *
 * @internal
 */
final class ListingStore extends RecordingStore
{
    public function batch(array $operations): array
    {
        parent::batch($operations);
        $results = [];

        foreach ($operations as $op) {
            if (!$op instanceof ListNamespacesOperation) {
                $results[] = null;
                continue;
            }

            $namespaces = [['a', 'b', 'c'], ['a', 'b', 'd'], ['a', 'c', 'e'], ['x', 'y', 'z']];
            foreach ($op->matchConditions ?? [] as $condition) {
                $namespaces = array_values(array_filter($namespaces, static function (array $ns) use ($condition): bool {
                    $window = $condition->matchType === 'prefix'
                        ? array_slice($ns, 0, count($condition->path))
                        : array_slice($ns, -count($condition->path));
                    foreach ($window as $i => $part) {
                        if ($condition->path[$i] !== '*' && $part !== $condition->path[$i]) {
                            return false;
                        }
                    }

                    return true;
                }));
            }
            if ($op->maxDepth !== null) {
                $namespaces = array_map(static fn (array $ns): array => array_slice($ns, 0, $op->maxDepth), $namespaces);
            }
            $results[] = array_slice($namespaces, $op->offset, $op->limit);
        }

        return $results;
    }
}
