<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Utils;

use LangChain\Utils\Await;
use LangChain\Utils\Promise;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Promise::class)]
#[CoversClass(Await::class)]
final class PromiseTest extends TestCase
{
    public function testResolvedIsFulfilledAndCarriesValue(): void
    {
        $p = Promise::resolved(42);

        $this->assertTrue($p->isFulfilled());
        $this->assertSame(42, $p->value());
    }

    public function testRejectedCarriesReasonAndThrowsOnValue(): void
    {
        $err = new \RuntimeException('boom');
        $p = Promise::rejected($err);

        $this->assertTrue($p->isRejected());

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $p->value();
    }

    public function testThenChainsValues(): void
    {
        $result = Promise::resolved(2)
            ->then(static fn (int $v): int => $v * 3)
            ->then(static fn (int $v): int => $v + 1)
            ->value();

        $this->assertSame(7, $result);
    }

    public function testThenPassesThroughWhenNoHandler(): void
    {
        $this->assertSame('x', Promise::resolved('x')->then(null)->value());
    }

    public function testRejectionPropagatesDownChainWhenUnhandled(): void
    {
        $err = new \LogicException('nope');

        $caught = null;
        Promise::rejected($err)
            ->then(static fn ($v) => $v)
            ->catch(static function (\Throwable $e) use (&$caught) {
                $caught = $e;

                return 'recovered';
            })
            ->value();

        $this->assertSame($err, $caught);
    }

    public function testCatchRecoversToNewValue(): void
    {
        $v = Promise::rejected(new \RuntimeException('x'))
            ->catch(static fn (\Throwable $e): string => 'recovered')
            ->value();

        $this->assertSame('recovered', $v);
    }

    public function testThrowingInHandlerBecomesRejection(): void
    {
        $p = Promise::resolved(1)->then(static function (): void {
            throw new \DomainException('handler blew up');
        });

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('handler blew up');
        $p->value();
    }

    public function testFinallyRunsOnFulfillment(): void
    {
        $ran = false;
        $v = Promise::resolved(5)
            ->finally(static function () use (&$ran): void { $ran = true; })
            ->value();

        $this->assertTrue($ran);
        $this->assertSame(5, $v);
    }

    public function testFinallyRunsOnRejectionAndDoesNotSwallow(): void
    {
        $ran = false;
        $p = Promise::rejected(new \RuntimeException('r'))
            ->finally(static function () use (&$ran): void { $ran = true; });

        $this->assertTrue($ran);
        $this->assertTrue($p->isRejected());
    }

    public function testNestedPromisesAreFlattened(): void
    {
        $v = Promise::resolved(Promise::resolved('inner'))->value();
        $this->assertSame('inner', $v);
    }

    public function testFromIsIdentityForPromise(): void
    {
        $p = Promise::resolved(1);
        $this->assertSame($p, Promise::from($p));
    }

    public function testFromWrapsPlainValue(): void
    {
        $this->assertSame(7, Promise::from(7)->value());
    }

    public function testAwaitSyncUnwrapsPromise(): void
    {
        $this->assertSame(9, Await::sync(Promise::resolved(9)));
        $this->assertSame('plain', Await::sync('plain'));
    }

    public function testAwaitAsyncWrapsValue(): void
    {
        $this->assertSame(3, Await::async(3)->value());
    }

    public function testAsyncTurnsThrowableIntoRejection(): void
    {
        // A Throwable handed to async() is surfaced as a rejection, not a value.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('as rejection');
        Await::async(new \RuntimeException('as rejection'))->value();
    }

    public function testRunFiberCompletesAndReturnsValue(): void
    {
        $v = Await::runFiber(static function (): string {
            $x = 1;
            $x++;

            return "done:$x";
        });

        $this->assertSame('done:2', $v);
    }

    public function testCallInvokesClosure(): void
    {
        $this->assertSame(3, Await::call(static fn (): int => 3));
        $this->assertSame(4, Await::call(4));
    }

    public function testSettledPromiseValueIsCachedAcrossReads(): void
    {
        $p = Promise::resolved('once');
        $this->assertSame($p->value(), $p->value());
    }
}
