<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk;

use LangGraph\Sdk\Utils\Signals;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `utils/signals.ts` has no upstream test. These pin the contract of `mergeSignals`, with a signal
 * being a `callable(): bool` ("aborted?") because PHP has no `AbortSignal`.
 */
#[CoversClass(Signals::class)]
final class SignalsTest extends TestCase
{
    public function testNothingToMergeIsNull(): void
    {
        $this->assertNull(Signals::mergeSignals());
        $this->assertNull(Signals::mergeSignals(null, null));
    }

    public function testASingleSignalIsReturnedAsIs(): void
    {
        $signal = static fn (): bool => false;

        $this->assertSame($signal, Signals::mergeSignals(null, $signal));
    }

    public function testMergedSignalFiresWhenAnyInputDoes(): void
    {
        $first = false;
        $second = false;
        $merged = Signals::mergeSignals(
            static function () use (&$first): bool {
                return $first;
            },
            null,
            static function () use (&$second): bool {
                return $second;
            },
        );

        $this->assertFalse($merged());

        $second = true;
        $this->assertTrue($merged());

        $second = false;
        $first = true;
        $this->assertTrue($merged());
    }

    public function testAnAlreadyAbortedInputAbortsTheMergeImmediately(): void
    {
        $merged = Signals::mergeSignals(static fn (): bool => true, static fn (): bool => false);

        $this->assertTrue($merged());
    }
}
