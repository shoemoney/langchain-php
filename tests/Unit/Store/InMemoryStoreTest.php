<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store;

use LangGraph\Store\InMemoryStore;
use LangGraph\Store\InvalidNamespaceError;
use LangGraph\Store\MemoryStore;
use LangGraph\Store\PutOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of the `InMemoryStore` describe block in `store.test.ts` and of
 * `namespace.test.ts` (`InMemoryStore Namespace Operations`).
 */
#[CoversClass(InMemoryStore::class)]
#[CoversClass(MemoryStore::class)]
final class InMemoryStoreTest extends TestCase
{
    private InMemoryStore $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryStore();
    }

    /**
     * @param list<list<string>> $namespaces
     */
    private function putAll(array $namespaces): void
    {
        foreach ($namespaces as $i => $namespace) {
            $this->store->put($namespace, "id_{$i}", ['data' => sprintf('value_%02d', $i)]);
        }
    }

    /**
     * @param list<list<string>> $list
     * @return list<list<string>>
     */
    private static function sorted(array $list): array
    {
        usort($list, static fn (array $a, array $b): int => strcmp(implode(',', $a), implode(',', $b)));

        return $list;
    }

    // ---- store.test.ts -------------------------------------------------------

    public function testGetReturnsAStoredItemWithTimestamps(): void
    {
        $this->store->put(['test'], '123', ['value' => 1]);

        $result = $this->store->get(['test'], '123');

        self::assertNotNull($result);
        self::assertSame(['value' => 1], $result->value);
        self::assertSame('123', $result->key);
        self::assertSame(['test'], $result->namespace);
        self::assertInstanceOf(\DateTimeImmutable::class, $result->createdAt);
        self::assertInstanceOf(\DateTimeImmutable::class, $result->updatedAt);
    }

    public function testGetOfAMissingItemIsNull(): void
    {
        self::assertNull($this->store->get(['test'], 'nope'));
    }

    public function testSearchReturnsItemsInInsertionOrder(): void
    {
        $this->store->put(['test'], '123', ['value' => 1]);
        $this->store->put(['test'], '456', ['value' => 2]);

        $result = $this->store->search(['test']);

        self::assertCount(2, $result);
        self::assertSame(['value' => 1], $result[0]->value);
        self::assertSame('123', $result[0]->key);
        self::assertSame(['value' => 2], $result[1]->value);
        self::assertSame('456', $result[1]->key);
        self::assertNull($result[0]->score);
    }

    public function testDeleteRemovesTheItem(): void
    {
        $this->store->put(['test'], '123', ['value' => 1]);
        $this->store->delete(['test'], '123');

        self::assertNull($this->store->get(['test'], '123'));
    }

    public function testUpdatingKeepsCreatedAtAndMovesUpdatedAt(): void
    {
        $this->store->put(['test'], '123', ['value' => 1]);
        $first = $this->store->get(['test'], '123');
        self::assertNotNull($first);
        $created = $first->createdAt;
        usleep(2000);

        $this->store->put(['test'], '123', ['value' => 2]);
        $second = $this->store->get(['test'], '123');

        self::assertNotNull($second);
        self::assertSame(['value' => 2], $second->value);
        self::assertEquals($created, $second->createdAt);
        self::assertGreaterThan($created, $second->updatedAt);
    }

    public function testSearchFiltersByExactMatch(): void
    {
        $this->store->put(['docs'], 'a', ['status' => 'active', 'score' => 5]);
        $this->store->put(['docs'], 'b', ['status' => 'inactive', 'score' => 1]);

        $result = $this->store->search(['docs'], ['filter' => ['status' => 'active']]);

        self::assertSame(['a'], array_map(static fn ($i) => $i->key, $result));
    }

    public function testSearchFiltersByOperators(): void
    {
        $this->store->put(['docs'], 'a', ['score' => 5, 'color' => 'red']);
        $this->store->put(['docs'], 'b', ['score' => 3, 'color' => 'red']);
        $this->store->put(['docs'], 'c', ['score' => 4, 'color' => 'blue']);

        $keys = static fn (array $items): array => array_map(static fn ($i) => $i->key, $items);

        self::assertSame(['a', 'c'], $keys($this->store->search(['docs'], ['filter' => ['score' => ['$gt' => 3]]])));
        self::assertSame(['a', 'b'], $keys($this->store->search(['docs'], ['filter' => ['score' => ['$gte' => 3], 'color' => 'red']])));
        self::assertSame(['c'], $keys($this->store->search(['docs'], ['filter' => ['score' => ['$gte' => 3, '$lt' => 5], 'color' => 'blue']])));
        self::assertSame(['b'], $keys($this->store->search(['docs'], ['filter' => ['score' => ['$lt' => 4]]])));
        self::assertSame(['a', 'b'], $keys($this->store->search(['docs'], ['filter' => ['color' => ['$in' => ['red']]]])));
        self::assertSame(['c'], $keys($this->store->search(['docs'], ['filter' => ['color' => ['$ne' => 'red']]])));
        self::assertSame(['c'], $keys($this->store->search(['docs'], ['filter' => ['color' => ['$nin' => ['red']]]])));
    }

    public function testSearchPaginates(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->store->put(['docs'], "k{$i}", ['i' => $i]);
        }

        $page = $this->store->search(['docs'], ['limit' => 2, 'offset' => 2]);

        self::assertSame(['k2', 'k3'], array_map(static fn ($i) => $i->key, $page));
    }

    public function testSearchPrefixIsAStringPrefixOfTheJoinedNamespace(): void
    {
        $this->store->put(['a', 'b'], 'k1', ['v' => 1]);
        $this->store->put(['a', 'c'], 'k2', ['v' => 2]);
        $this->store->put(['x'], 'k3', ['v' => 3]);

        self::assertCount(2, $this->store->search(['a']));
        self::assertCount(3, $this->store->search([]));
    }

    public function testNumericNamespaceLabelsAndKeysSurviveArrayKeyCoercion(): void
    {
        $this->store->put(['users', '123'], '456', ['v' => 1]);
        $this->store->put(['789'], '1', ['v' => 2]);

        $item = $this->store->get(['users', '123'], '456');
        self::assertNotNull($item);
        self::assertSame('456', $item->key);
        self::assertSame([['789'], ['users', '123']], $this->store->listNamespaces());
        self::assertSame('1', $this->store->get(['789'], '1')?->key);
    }

    public function testListNamespaces(): void
    {
        $this->store->put(['a', 'b', 'c'], '1', ['value' => 1]);
        $this->store->put(['a', 'b', 'd'], '2', ['value' => 2]);
        $this->store->put(['x', 'y', 'z'], '3', ['value' => 3]);

        self::assertSame([['a', 'b', 'c'], ['a', 'b', 'd'], ['x', 'y', 'z']], $this->store->listNamespaces());
        self::assertSame([['a', 'b', 'c'], ['a', 'b', 'd']], $this->store->listNamespaces(['prefix' => ['a']]));
        self::assertSame([['a', 'b'], ['x', 'y']], $this->store->listNamespaces(['maxDepth' => 2]));
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
    public function testPutBlocksInvalidNamespaces(array $namespace): void
    {
        $this->expectException(InvalidNamespaceError::class);

        $this->store->put($namespace, 'foo', ['foo' => 'bar']);
    }

    public function testInvalidNamespaceMessagesNameTheProblem(): void
    {
        foreach ([
            [[], 'Namespace cannot be empty.'],
            [['the', 'thing.about'], "cannot contain periods ('.')"],
            [['some', 'fun', ''], 'cannot be empty strings'],
            [['langgraph', 'foo'], 'Root label for namespace cannot be "langgraph"'],
        ] as [$namespace, $message]) {
            try {
                $this->store->put($namespace, 'foo', []);
                self::fail('expected InvalidNamespaceError');
            } catch (InvalidNamespaceError $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testNamespaceRulesApplyToPutButNotToBatchOrReads(): void
    {
        $doc = ['foo' => 'bar'];

        $this->store->put(['foo', 'langgraph', 'foo'], 'bar', $doc);
        self::assertSame($doc, $this->store->get(['foo', 'langgraph', 'foo'], 'bar')?->value);
        self::assertSame($doc, $this->store->search(['foo', 'langgraph', 'foo'])[0]->value);
        $this->store->delete(['foo', 'langgraph', 'foo'], 'bar');
        self::assertNull($this->store->get(['foo', 'langgraph', 'foo'], 'bar'));

        $this->store->batch([new PutOperation(['langgraph', 'foo'], 'bar', $doc)]);
        self::assertSame($doc, $this->store->get(['langgraph', 'foo'], 'bar')?->value);
        self::assertSame($doc, $this->store->search(['langgraph', 'foo'])[0]->value);
        $this->store->delete(['langgraph', 'foo'], 'bar');
        self::assertNull($this->store->get(['langgraph', 'foo'], 'bar'));
    }

    public function testBatchReturnsResultsInOperationOrder(): void
    {
        $this->store->put(['t'], 'k', ['v' => 1]);

        $results = $this->store->batch([
            new PutOperation(['t'], 'k2', ['v' => 2]),
            new \LangGraph\Store\GetOperation(['t'], 'k'),
            new \LangGraph\Store\SearchOperation(['t']),
        ]);

        self::assertCount(3, $results);
        self::assertNull($results[0]);
        self::assertSame(['v' => 1], $results[1]->value);
        // A search sees the store as it was before the batch's puts are applied.
        self::assertCount(1, $results[2]);
        self::assertNotNull($this->store->get(['t'], 'k2'));
    }

    public function testMemoryStoreIsAnAliasOfInMemoryStore(): void
    {
        $store = new MemoryStore();
        $store->put(['t'], 'k', ['v' => 1]);

        self::assertInstanceOf(InMemoryStore::class, $store);
        self::assertSame(['v' => 1], $store->get(['t'], 'k')?->value);
    }

    // ---- namespace.test.ts ---------------------------------------------------

    public function testBasicNamespaceOperations(): void
    {
        $this->putAll([
            ['a', 'b', 'c'],
            ['a', 'b', 'd', 'e'],
            ['a', 'b', 'd', 'i'],
            ['a', 'b', 'f'],
            ['a', 'c', 'f'],
            ['b', 'a', 'f'],
            ['users', '123'],
            ['users', '456', 'settings'],
            ['admin', 'users', '789'],
        ]);

        self::assertSame(
            self::sorted([['a', 'b', 'c'], ['a', 'b', 'd', 'e'], ['a', 'b', 'd', 'i'], ['a', 'b', 'f']]),
            self::sorted($this->store->listNamespaces(['prefix' => ['a', 'b']])),
        );
        self::assertSame(
            self::sorted([['a', 'b', 'f'], ['a', 'c', 'f'], ['b', 'a', 'f']]),
            self::sorted($this->store->listNamespaces(['suffix' => ['f']])),
        );
        self::assertSame(
            self::sorted([['a', 'b', 'f'], ['a', 'c', 'f']]),
            self::sorted($this->store->listNamespaces(['prefix' => ['a'], 'suffix' => ['f']])),
        );
        self::assertSame(
            self::sorted([['a', 'b', 'c'], ['a', 'b', 'd'], ['a', 'b', 'f']]),
            self::sorted($this->store->listNamespaces(['prefix' => ['a', 'b'], 'maxDepth' => 3])),
        );
        self::assertSame(
            [['a', 'b', 'c'], ['a', 'b', 'd', 'e']],
            $this->store->listNamespaces(['prefix' => ['a', 'b'], 'limit' => 2]),
        );
        self::assertSame(
            [['a', 'b', 'd', 'i'], ['a', 'b', 'f']],
            $this->store->listNamespaces(['prefix' => ['a', 'b'], 'offset' => 2]),
        );
    }

    public function testWildcardsInNamespaceOperations(): void
    {
        $this->putAll([
            ['users', '123'],
            ['users', '456'],
            ['users', '789', 'settings'],
            ['admin', 'users', '789'],
            ['guests', '123'],
            ['guests', '456', 'preferences'],
        ]);

        self::assertSame(
            self::sorted([['users', '123'], ['users', '456'], ['users', '789', 'settings']]),
            self::sorted($this->store->listNamespaces(['prefix' => ['users', '*']])),
        );
        self::assertSame(
            [['guests', '456', 'preferences']],
            $this->store->listNamespaces(['suffix' => ['*', 'preferences']]),
        );
        self::assertSame(
            [],
            $this->store->listNamespaces(['prefix' => ['*', 'users'], 'suffix' => ['*', 'settings']]),
        );

        $this->store->put(['admin', 'users', 'settings', '789'], 'foo', ['data' => 'some_val']);
        self::assertSame(
            [['admin', 'users', 'settings', '789']],
            $this->store->listNamespaces(['prefix' => ['*', 'users'], 'suffix' => ['settings', '*']]),
        );
    }

    public function testPaginationInNamespaceOperations(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->store->put(['namespace', sprintf('sub_%02d', $i)], sprintf('id_%02d', $i), ['data' => sprintf('value_%02d', $i)]);
        }

        foreach ([0, 5, 15] as $offset) {
            $expected = [];
            for ($i = 0; $i < 5; $i++) {
                $expected[] = ['namespace', sprintf('sub_%02d', $i + $offset)];
            }
            self::assertSame(
                $expected,
                $this->store->listNamespaces(['prefix' => ['namespace'], 'limit' => 5, 'offset' => $offset]),
            );
        }
    }

    public function testMaxDepthInNamespaceOperations(): void
    {
        $this->putAll([
            ['a', 'b', 'c', 'd'],
            ['a', 'b', 'c', 'e'],
            ['a', 'b', 'f'],
            ['a', 'g'],
            ['h', 'i', 'j', 'k'],
        ]);

        self::assertSame(
            self::sorted([['a', 'b'], ['a', 'g'], ['h', 'i']]),
            self::sorted($this->store->listNamespaces(['maxDepth' => 2])),
        );
    }

    public function testAnEmptyStoreListsNothing(): void
    {
        self::assertSame([], $this->store->listNamespaces());
    }

    public function testBlockInvalidNamespacesThenBatchPastTheRules(): void
    {
        $doc = ['foo' => 'bar'];

        $this->store->batch([new PutOperation(['valid', 'namespace'], 'key', $doc)]);
        self::assertSame($doc, $this->store->get(['valid', 'namespace'], 'key')?->value);
        self::assertSame($doc, $this->store->search(['valid', 'namespace'])[0]->value);
        $this->store->delete(['valid', 'namespace'], 'key');
        self::assertNull($this->store->get(['valid', 'namespace'], 'key'));
    }
}
