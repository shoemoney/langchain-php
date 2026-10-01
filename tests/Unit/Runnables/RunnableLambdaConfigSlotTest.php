<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables;

use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `RunnableLambda` decides whether to hand a lambda the `RunnableConfig` by counting parameters:
 * `getNumberOfParameters() >= 2`. That counts OPTIONAL parameters, so
 *
 *     fn(array $xs, string $sep = ' ') => implode($sep, $xs)
 *
 * — ordinary, legal PHP — received the config in `$sep` and died with a TypeError. Upstream's
 * `(input, config?)` typing makes the two cases unambiguous at the type level; PHP reflection
 * distinguishes them just as precisely through `ReflectionParameter::isOptional()`, and the gate did
 * not ask.
 *
 * The config slot is for a REQUIRED second parameter. A defaulted second parameter is the caller's own
 * data, and the port has no business filling it.
 *
 * `testARequiredSecondParameterStillReceivesTheConfig` is the control that matters most: it is the case
 * the fix must not break, and it is how a lambda gets tags and callbacks at all.
 */
#[CoversClass(RunnableLambda::class)]
final class RunnableLambdaConfigSlotTest extends TestCase
{
    /** RED before the fix: the config lands in `$sep`. */
    public function testADefaultedSecondParameterIsTheCallersOwnData(): void
    {
        $lambda = new RunnableLambda(fn(array $xs, string $sep = ' ') => implode($sep, $xs));

        self::assertSame('1 2 3', $lambda->invoke([1, 2, 3]));
    }

    public function testADefaultedSecondParameterKeepsItsDefaultWhenNoArgumentFits(): void
    {
        // A wider default must survive too — the defect is the same for any defaulted type.
        $lambda = new RunnableLambda(fn(array $xs, string $sep = ', ') => implode($sep, $xs));

        self::assertSame('1, 2, 3', $lambda->invoke([1, 2, 3]));
    }

    /** CONTROL: a REQUIRED second parameter IS the config channel, and must keep receiving it. */
    public function testARequiredSecondParameterStillReceivesTheConfig(): void
    {
        $seen = null;
        $lambda = new RunnableLambda(function (array $xs, $config) use (&$seen) {
            $seen = $config;

            return $xs;
        });

        $lambda->invoke([1, 2, 3]);

        self::assertInstanceOf(RunnableConfig::class, $seen);
    }

    /** CONTROL: a single-parameter lambda is called with the input alone. */
    public function testASingleParameterLambdaIsCalledWithTheInputAlone(): void
    {
        $lambda = new RunnableLambda(fn(array $xs): array => $xs);

        self::assertSame([1, 2, 3], $lambda->invoke([1, 2, 3]));
    }

    /** CONTROL: a SECOND defaulted parameter on a two-arg lambda must not make it variadic-like. */
    public function testTwoDefaultedParametersStillGetOnlyTheInput(): void
    {
        $lambda = new RunnableLambda(fn(array $xs, string $a = 'x', string $b = 'y'): string => $a . $b);

        self::assertSame('xy', $lambda->invoke([1]));
    }
}
