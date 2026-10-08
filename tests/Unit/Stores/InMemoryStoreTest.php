<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Stores;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Stores\BaseStore;
use LangChain\Stores\InMemoryStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Own tests for `stores.ts` (upstream has none), following the class docblock example.
 */
#[CoversClass(InMemoryStore::class)]
#[CoversClass(BaseStore::class)]
final class InMemoryStoreTest extends TestCase
{
    public function testMsetThenMgetReturnsValuesInKeyOrder(): void
    {
        $store = new InMemoryStore();
        $store->mset([['a', 1], ['b', 2]]);

        self::assertSame([2, 1], $store->mget(['b', 'a']));
    }

    public function testMissingKeysReadBackAsNull(): void
    {
        $store = new InMemoryStore();
        $store->mset([['a', 1]]);

        self::assertSame([1, null], $store->mget(['a', 'missing']));
    }

    public function testMsetOverwrites(): void
    {
        $store = new InMemoryStore();
        $store->mset([['a', 1]]);
        $store->mset([['a', 2]]);

        self::assertSame([2], $store->mget(['a']));
    }

    public function testMdeleteRemovesKeysAndIgnoresMissingOnes(): void
    {
        $store = new InMemoryStore();
        $store->mset([['a', 1], ['b', 2]]);

        $store->mdelete(['a', 'never-there']);

        self::assertSame([null, 2], $store->mget(['a', 'b']));
    }

    public function testYieldKeysFiltersByPrefix(): void
    {
        $store = new InMemoryStore();
        $store->mset([['message:id:0', 'x'], ['message:id:1', 'y'], ['other', 'z']]);

        self::assertSame(['message:id:0', 'message:id:1'], iterator_to_array($store->yieldKeys('message:id:'), false));
        self::assertSame(['message:id:0', 'message:id:1', 'other'], iterator_to_array($store->yieldKeys(), false));
    }

    public function testDocblockExampleStoresMessagesAndDeletesByPrefix(): void
    {
        $store = new InMemoryStore();
        $pairs = [];
        for ($i = 0; $i < 5; $i++) {
            $pairs[] = ["message:id:{$i}", $i % 2 === 0 ? new AIMessage('ai stuff...') : new HumanMessage('human stuff...')];
        }
        $store->mset($pairs);

        [$first, $second] = $store->mget(['message:id:0', 'message:id:1']);
        self::assertInstanceOf(AIMessage::class, $first);
        self::assertInstanceOf(HumanMessage::class, $second);

        $store->mdelete(iterator_to_array($store->yieldKeys('message:id:'), false));
        self::assertSame([], iterator_to_array($store->yieldKeys(), false));
    }

    public function testNumericStringKeysComeBackAsStrings(): void
    {
        $store = new InMemoryStore();
        $store->mset([['123', 'v']]);

        self::assertSame(['123'], iterator_to_array($store->yieldKeys(), false));
    }

    public function testSerializedId(): void
    {
        self::assertSame(['langchain', 'storage', 'InMemoryStore'], InMemoryStore::lcId());
    }
}
