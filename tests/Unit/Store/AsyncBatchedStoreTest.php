<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store;

use LangGraph\Store\AsyncBatchedStore;
use LangGraph\Store\GetOperation;
use LangGraph\Store\InMemoryStore;
use LangGraph\Store\PutOperation;
use LangGraph\Store\SearchOperation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of the `AsyncBatchedStore` describe block in `store.test.ts`.
 *
 * Upstream's one test asserts that two concurrent searches reach the wrapped
 * store as a single two-operation batch. This port runs tasks sequentially, so
 * there is no second concurrent call to coalesce with; the test asserts what
 * remains true, that every call is forwarded to the wrapped store with the same
 * operation and result.
 */
#[CoversClass(AsyncBatchedStore::class)]
final class AsyncBatchedStoreTest extends TestCase
{
    private SpyStore $inner;

    private AsyncBatchedStore $store;

    protected function setUp(): void
    {
        $this->inner = new SpyStore();
        $this->store = new AsyncBatchedStore($this->inner);
        $this->store->start();
    }

    protected function tearDown(): void
    {
        $this->store->stop();
    }

    public function testForwardsEachCallToTheWrappedStore(): void
    {
        $a = $this->store->search(['a', 'b']);
        $c = $this->store->search(['c', 'd']);

        self::assertSame([], $a);
        self::assertSame([], $c);
        self::assertCount(2, $this->inner->batches);
        foreach ([['a', 'b'], ['c', 'd']] as $i => $prefix) {
            $op = $this->inner->batches[$i][0];
            self::assertInstanceOf(SearchOperation::class, $op);
            self::assertSame($prefix, $op->namespacePrefix);
            self::assertSame(10, $op->limit);
            self::assertSame(0, $op->offset);
        }
    }

    public function testGetPutAndDeleteReachTheWrappedStore(): void
    {
        $this->store->put(['t'], 'k', ['v' => 1]);
        self::assertSame(['v' => 1], $this->store->get(['t'], 'k')?->value);
        $this->store->delete(['t'], 'k');
        self::assertNull($this->store->get(['t'], 'k'));

        self::assertInstanceOf(PutOperation::class, $this->inner->batches[0][0]);
        self::assertInstanceOf(GetOperation::class, $this->inner->batches[1][0]);
        $delete = $this->inner->batches[2][0];
        self::assertInstanceOf(PutOperation::class, $delete);
        self::assertNull($delete->value);
    }

    public function testBatchItselfIsRefused(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('not implemented on `AsyncBatchedStore`');

        $this->store->batch([new GetOperation(['t'], 'k')]);
    }

    public function testWrappingAWrapperWrapsTheOriginalStore(): void
    {
        $twice = new AsyncBatchedStore($this->store);

        self::assertSame($this->inner, $twice->unwrap());
        $twice->put(['t'], 'k', ['v' => 1]);
        self::assertSame(['v' => 1], $this->inner->get(['t'], 'k')?->value);
    }

    public function testStartAndStopToggleIsRunning(): void
    {
        $store = new AsyncBatchedStore(new InMemoryStore());
        self::assertFalse($store->isRunning());

        $store->start();
        self::assertTrue($store->isRunning());

        $store->stop();
        self::assertFalse($store->isRunning());
        self::assertSame('AsyncBatchedStore', $store->lgName);
    }
}

/**
 * An {@see InMemoryStore} that records the batches it receives.
 *
 * @internal
 */
final class SpyStore extends InMemoryStore
{
    /** @var list<list<\LangGraph\Store\Operation>> */
    public array $batches = [];

    public function batch(array $operations): array
    {
        $this->batches[] = $operations;

        return parent::batch($operations);
    }
}
