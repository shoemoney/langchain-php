<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\Redis;

use LangGraph\Store\GetOperation;
use LangGraph\Store\ListNamespacesOperation;
use LangGraph\Store\MatchCondition;
use LangGraph\Store\Operation;
use LangGraph\Store\PutOperation;
use LangGraph\Store\Redis\RedisStore;
use LangGraph\Store\SearchOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `Operation Type Guards` from `tests/store.int.test.ts`.
 *
 * Upstream tells operations apart by which keys a plain object has; the port's operations are
 * classes, so the guards are `instanceof` checks and the property that matters is that each
 * operation is claimed by exactly one of them. A delete is a put with a null value, so it is a
 * put and not a get.
 */
#[CoversClass(RedisStore::class)]
final class RedisStoreOperationGuardsTest extends TestCase
{
    /** @return array{bool, bool, bool, bool} put, get, search, list */
    private static function guards(Operation $op): array
    {
        return [
            RedisStore::isPutOperation($op),
            RedisStore::isGetOperation($op),
            RedisStore::isSearchOperation($op),
            RedisStore::isListNamespacesOperation($op),
        ];
    }

    public function testShouldCorrectlyIdentifyPutOperation(): void
    {
        self::assertSame([true, false, false, false], self::guards(new PutOperation(['test'], 'key1', ['data' => 'test'])));
    }

    public function testAPutWithANullValueIsStillAPutOperation(): void
    {
        self::assertSame([true, false, false, false], self::guards(new PutOperation(['test'], 'key1', null)));
    }

    public function testShouldCorrectlyIdentifyGetOperation(): void
    {
        self::assertSame([false, true, false, false], self::guards(new GetOperation(['test'], 'key1')));
    }

    public function testShouldCorrectlyIdentifySearchOperation(): void
    {
        self::assertSame([false, false, true, false], self::guards(new SearchOperation(['test'], ['category' => 'electronics'], 10)));
    }

    public function testShouldCorrectlyIdentifyListNamespacesOperation(): void
    {
        $op = new ListNamespacesOperation([new MatchCondition(MatchCondition::PREFIX, ['test'])], 2, 10, 0);

        self::assertSame([false, false, false, true], self::guards($op));
    }

    public function testBatchRejectsAnOperationItDoesNotKnow(): void
    {
        $store = new RedisStore(new \LangChain\Tests\Unit\Checkpoint\FakeRedisClient());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown operation type');
        $store->batch([new class () implements Operation {
        }]);
    }
}
