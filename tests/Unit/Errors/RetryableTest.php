<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Errors;

use LangChain\Errors\ContextOverflowError;
use LangChain\Runnables\RunnableLambda;
use LangChain\Utils\AsyncCaller;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `langchain-core/src/errors/tests/retryable.test.ts`.
 *
 * JS-only cases are mapped to what PHP can express: there are no enumerable own keys,
 * `JSON.stringify` shape, frozen objects or `Symbol.for` registry, so the "invisible mark"
 * cases assert that the mark leaves the error's public state and serialization untouched,
 * and the frozen-object case becomes a non-object case. `ModelAbortError` has no port, so its
 * case is covered by the ContextOverflowError cases alone.
 */
#[CoversClass(ContextOverflowError::class)]
#[CoversClass(AsyncCaller::class)]
final class RetryableTest extends TestCase
{
    public function testMarksAnErrorRetryable(): void
    {
        $this->assertTrue(AsyncCaller::getRetryable(AsyncCaller::stampRetryable(new \Exception('boom'), true)));
    }

    public function testMarksAnErrorNonRetryable(): void
    {
        $this->assertFalse(AsyncCaller::getRetryable(AsyncCaller::stampRetryable(new \Exception('boom'), false)));
    }

    public function testReturnsTheSameInstanceAndPreservesTheClass(): void
    {
        $original = new RetryableSdkError('boom');
        $stamped = AsyncCaller::stampRetryable($original, false);

        $this->assertSame($original, $stamped);
        $this->assertInstanceOf(RetryableSdkError::class, $stamped);
        $this->assertSame('boom', $stamped->getMessage());
    }

    public function testMarkIsInvisibleToStateAndSerialization(): void
    {
        $error = new RetryableSdkError('boom');
        $error->status = 429;
        $before = get_object_vars($error);
        $json = json_encode($error);

        AsyncCaller::stampRetryable($error, true);

        $this->assertSame($before, get_object_vars($error));
        $this->assertSame(['status' => 429], $before);
        $this->assertSame($json, json_encode($error));
    }

    public function testCanBeRestampedWithADifferentVerdict(): void
    {
        $error = AsyncCaller::stampRetryable(new \Exception('boom'), true);

        $this->assertFalse(AsyncCaller::getRetryable(AsyncCaller::stampRetryable($error, false)));
    }

    public function testDoesNotThrowOnNonObjects(): void
    {
        $this->assertNull(AsyncCaller::stampRetryable(null, true));
        $this->assertSame('boom', AsyncCaller::stampRetryable('boom', true));
        $this->assertSame(42, AsyncCaller::stampRetryable(42, false));
    }

    public function testNonObjectsStayUnclassifiedAfterStamping(): void
    {
        AsyncCaller::stampRetryable('boom', false);

        $this->assertNull(AsyncCaller::getRetryable('boom'));
    }

    public function testStampsAreKeptPerInstance(): void
    {
        $stamped = AsyncCaller::stampRetryable(new \Exception('boom'), true);
        $other = new \Exception('boom');

        $this->assertTrue(AsyncCaller::getRetryable($stamped));
        $this->assertNull(AsyncCaller::getRetryable($other));
    }

    public function testGetRetryableReturnsNullForAnUnclassifiedError(): void
    {
        $this->assertNull(AsyncCaller::getRetryable(new \Exception('boom')));
    }

    public function testGetRetryableReturnsNullForNonObjects(): void
    {
        $this->assertNull(AsyncCaller::getRetryable(null));
        $this->assertNull(AsyncCaller::getRetryable('boom'));
        $this->assertNull(AsyncCaller::getRetryable(429));
    }

    public function testContextOverflowErrorIsNonRetryable(): void
    {
        $this->assertFalse(AsyncCaller::getRetryable(new ContextOverflowError()));
    }

    public function testContextOverflowErrorFromErrorIsNonRetryable(): void
    {
        $this->assertFalse(AsyncCaller::getRetryable(ContextOverflowError::fromError(new \Exception('too long'))));
    }

    public function testFromErrorCopiesTheMessageAndKeepsTheCause(): void
    {
        $cause = new \Exception('too long');
        $wrapped = ContextOverflowError::fromError($cause);

        $this->assertSame('too long', $wrapped->getMessage());
        $this->assertSame($cause, $wrapped->getPrevious());
        $this->assertSame('ContextOverflowError', $wrapped->name);
        $this->assertSame("Input exceeded the model's context window.", (new ContextOverflowError())->getMessage());
    }

    public function testIsInstanceGuard(): void
    {
        $this->assertTrue(ContextOverflowError::isInstance(new ContextOverflowError()));
        $this->assertFalse(ContextOverflowError::isInstance(new \Exception('x')));
        $this->assertFalse(ContextOverflowError::isInstance('x'));
    }

    public function testARealChainStopsAfterOneAttemptOnContextOverflow(): void
    {
        $attempts = 0;
        $chain = RunnableLambda::from(static function (string $input) use (&$attempts): never {
            ++$attempts;

            throw new ContextOverflowError();
        });
        $caller = new AsyncCaller(maxRetries: 4, sleeper: static function (int|float $ms): void {});

        try {
            $caller->call(static fn (): mixed => $chain->invoke('x'));
            $this->fail('expected a ContextOverflowError');
        } catch (ContextOverflowError) {
            $this->assertSame(1, $attempts);
        }
    }
}

class RetryableSdkError extends \Exception
{
    public int $status = 0;
}
