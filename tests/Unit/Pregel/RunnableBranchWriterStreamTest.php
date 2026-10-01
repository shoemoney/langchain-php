<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\RunnableBranchWriter;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * `RunnableBranchWriter::stream()` must not fatal on an ordinary scalar node value.
 *
 * It read `yield from $this->invoke($input, $config)`, and `invoke()` returns `$input` — a string for a
 * normal node value. `yield from 'a'` raises `Error: Can use "yield from" only with arrays and
 * Traversables`, so streaming a conditional edge killed the process. Reproduced in 434 before the fix.
 *
 * The assertion is on the absence of a fatal and on the yielded value, deliberately NOT on the
 * `[channel, value]` pair shape: `Runnable::stream()` yields a pair while `ChannelWrite::stream()` yields
 * the raw value, and upstream's `Runnable._streamIterator` (`base.ts:297-302`) yields raw. Which shape is
 * correct for this class is an open question recorded in PORT_STATUS; pinning it here would freeze a guess.
 * What is not a guess is that `yield from` on a scalar is wrong under every convention.
 */
#[CoversNothing]
final class RunnableBranchWriterStreamTest extends TestCase
{
    public function testStreamingAScalarNodeValueYieldsTheValueAndDoesNotFatal(): void
    {
        $config = RunnableConfig::fromArray([
            'configurable' => [
                Constants::CONFIG_KEY_SEND => static function (array $writes): void {},
            ],
        ]);
        $writer = new RunnableBranchWriter(static fn (): ?string => 'dest');

        $chunks = iterator_to_array($writer->stream('a', $config), false);

        self::assertCount(1, $chunks, 'invoke() returns the input, so the stream yields exactly one chunk');
        self::assertSame('a', $chunks[0]);
    }
}
