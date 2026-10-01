<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use LangGraph\Channels\LastValue;
use LangGraph\Pregel\Pregel;
use LangGraph\Pregel\CompiledStateGraph;
use LangGraph\Pregel\PregelLoop;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\TestCase;

/**
 * A stream subscription must be honoured exactly as asked.
 *
 * Two defects, one root cause: `values` chunks were written to the buffer
 * DIRECTLY, bypassing the mode gate every other mode goes through.
 *
 *     PregelLoop::emitValues()   foreach ($payload as $item)
 *                                     $this->streamBuffer[] = ['values', $item];
 *
 * `PregelLoop::emit()` gates on the subscription —
 * `if (!in_array($mode, $modes, true)) { return; }` — and `emitValues()` never
 * called it. The consequence was not a missing chunk but an INVERTED one. Measured
 * before the fix:
 *
 *     streamMode=["updates"]  ->  modes emitted: ["values"]
 *     streamMode=["values"]   ->  modes emitted: ["values"]
 *
 * A graph asking for `updates` received only `values`, and a caller cannot tell
 * that from a graph that legitimately produced no updates — the two demand
 * opposite responses.
 *
 * WHY 4354 TESTS MISSED IT: no test in this repository passes `streamMode` at all.
 * The default is `['updates', 'values']` for `CompiledStateGraph`, which contains
 * `values`, so the ungated path and the gated path produced identical output for
 * every existing test. The fixture used the default, and the default cannot
 * distinguish a gate from no gate. That is this project's recurring shape — a test
 * that mirrors the shape the defect cannot affect — and it is why these tests build
 * their own graph rather than reusing a default-mode one.
 *
 * Upstream is unambiguous (`pregel/index.ts:2511`): `streamMode.includes(mode)`,
 * with no unconditional channel. `values` is gated like everything else there.
 */
final class StreamModeSubscriptionTest extends TestCase
{
    /**
     * A one-node graph over a single `LastValue` channel.
     *
     * Built per-test rather than shared so nothing inherits a default mode, which
     * is precisely the blindness described above.
     *
     * @param list<string> $streamMode
     */
    private function graph(array $streamMode): Pregel
    {
        $builder = new StateGraph(['messages' => new LastValue()]);
        $builder->addNode('n', static fn (array $state): array => ['messages' => 'done']);
        $builder->addEdge('__start__', 'n');

        return $builder->compile(['streamMode' => $streamMode]);
    }

    /**
     * @param list<string> $streamMode
     *
     * @return list<string> the distinct modes the stream actually produced
     */
    private function modesFrom(array $streamMode): array
    {
        $chunks = iterator_to_array($this->graph($streamMode)->stream(['messages' => 'hi']));

        return array_values(array_unique(array_column($chunks, 0)));
    }

    public function testValuesAreNotEmittedWhenOnlyUpdatesWasAskedFor(): void
    {
        self::assertSame(
            ['updates'],
            $this->modesFrom(['updates']),
            'a graph subscribed to updates must not receive values chunks — that is the leak',
        );
    }

    public function testUpdatesAreNotEmittedWhenOnlyValuesWasAskedFor(): void
    {
        self::assertSame(
            ['values'],
            $this->modesFrom(['values']),
            'a graph subscribed to values must not receive updates chunks',
        );
    }

    /**
     * Asking for both must yield both, or the gate is dropping a subscribed mode.
     *
     * The other direction of the same defect: a fix that gated too eagerly would
     * satisfy the two tests above by emitting nothing at all.
     */
    public function testBothModesAreDeliveredWhenBothAreSubscribed(): void
    {
        $chunks = iterator_to_array($this->graph(['updates', 'values'])->stream(['messages' => 'hi']));
        $modes = array_values(array_unique(array_column($chunks, 0)));
        sort($modes);

        self::assertSame(['updates', 'values'], $modes);
        self::assertNotSame([], $chunks, 'subscribing to both modes must actually produce chunks');
    }

    /**
     * `emitValues()` must route through the gate, not around it.
     *
     * Asserted on the source because the behaviour is what the other three tests
     * check, and this names the cause. A future edit that inlines the buffer write
     * back for speed reintroduces the leak while leaving every behavioural test
     * green under the default mode.
     */
    public function testEmitValuesIsGatedRatherThanWritingTheBufferDirectly(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/LangGraph/Pregel/PregelLoop.php',
        );

        preg_match(
            '/public function emitValues\(array \$payload\): void\s*\{(.*?)\n    \}/s',
            $source,
            $m,
        );
        self::assertNotEmpty($m, 'emitValues() must remain present and findable');

        self::assertStringContainsString(
            "\$this->emit(\$payload, 'values')",
            $m[1],
            'emitValues() must delegate to emit() so the subscription applies',
        );
        self::assertStringNotContainsString(
            'streamBuffer[]',
            $m[1],
            'emitValues() must not write the buffer directly; that is what bypassed the gate',
        );
    }

    /**
     * A mode the port cannot emit must be REFUSED, not silently dropped.
     *
     * Upstream declares eight `StreamMode` values and this port emits two
     * (`updates`, `values`). Accepting `debug` and then producing nothing is a
     * silent no-op indistinguishable from a graph that had nothing to say, so
     * `Pregel::resolveStreamModes()` refuses at the boundary instead.
     */
    public function testAnUnsupportedStreamModeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unsupported stream mode/');

        iterator_to_array($this->graph(['debug'])->stream(['messages' => 'hi']));
    }

    /**
     * The refusal must name what IS supported.
     *
     * A bare "unsupported mode" leaves the caller to guess which subset works; the
     * whole point of refusing is that the message is actionable.
     */
    public function testTheRefusalNamesTheSupportedModes(): void
    {
        try {
            iterator_to_array($this->graph(['tasks', 'messages'])->stream(['messages' => 'hi']));
            self::fail('expected an unsupported mode to be refused');
        } catch (\InvalidArgumentException $e) {
            $message = $e->getMessage();

            self::assertStringContainsString('tasks', $message);
            self::assertStringContainsString('messages', $message);
            self::assertStringContainsString(
                'updates',
                $message,
                'the message must say which modes do work, or refusing is not actionable',
            );
            self::assertStringContainsString('values', $message);
        }
    }

    /**
     * The supported set is a declared constant, not a list repeated in the guard.
     *
     * If a mode is ever implemented the constant grows, and a test that hardcoded
     * the old pair would then demand a refusal for something that works.
     */
    public function testSupportedModesAreDeclaredOnPregel(): void
    {
        self::assertSame(
            ['updates', 'values'],
            Pregel::SUPPORTED_STREAM_MODES,
            'the supported set is a declaration on Pregel; update it when a mode is implemented',
        );
    }

    /**
     * A default subscription must not silently hide a mode the port can emit.
     *
     * NOT "every default must contain every mode" — that premise was wrong and the
     * test caught it. `Pregel` defaults to `['updates']`, which correctly means
     * "do not emit values", and asserting that a default must include `values`
     * would have demanded the opposite of the subscription's meaning.
     *
     * The property that actually matters is the one that made the bug invisible: the
     * DEFAULT must contain every mode the loop emits WITHOUT the gate doing any
     * work, so that gated and ungated output are identical under the default. That
     * is why no existing test caught `emitValues()` skipping the gate.
     *
     * Measured on this tree: `CompiledStateGraph` (the class every test builds)
     * defaults to `['updates', 'values']` — both emitted modes — and `Pregel` itself
     * defaults to `['updates']`. Both are consistent with the gate.
     */
    public function testTheDefaultSubscriptionIsConsistentWithTheGate(): void
    {
        $default = (array) $this->streamModeDefaultOf(CompiledStateGraph::class);

        self::assertSame(
            ['updates', 'values'],
            $default,
            'CompiledStateGraph is what tests build; its default must cover every emitted '
            . 'mode, which is exactly why the ungated emitValues() was invisible',
        );

        // Pregel's own narrower default is a deliberate choice: "updates only".
        // Asserted so a change to it is deliberate rather than accidental.
        $pregelDefault = (array) $this->streamModeDefaultOf(Pregel::class);
        self::assertSame(['updates'], $pregelDefault);
    }

    /**
     * The emitted mode set must match what `emitValues()` claims to publish.
     *
     * Measured by running a graph, so it fails if a new mode is emitted somewhere
     * without being declared in `SUPPORTED_STREAM_MODES` — which would otherwise
     * make `emit()` silently drop it.
     */
    public function testEveryModeTheLoopCanEmitIsDeclaredSupported(): void
    {
        $seen = [];
        foreach ([['updates'], ['values'], ['updates', 'values']] as $mode) {
            $seen = [...$seen, ...$this->modesFrom($mode)];
        }

        self::assertSame(
            [],
            array_values(array_diff(array_unique($seen), Pregel::SUPPORTED_STREAM_MODES)),
            'the loop emitted a mode that SUPPORTED_STREAM_MODES does not declare; emit() would drop it',
        );
    }

    /**
 * `PregelLoop::emit()` must remain the single gate.
 *
 * Driven through the public surface only. `streamBuffer` is private and `drain()`
 * is private, so a test that reached for either would assert against internals a
 * refactor may legitimately rename — and adding a `drainForTest()` accessor to
 * make it reachable would be adding production API for a test's convenience.
 *
 * `PregelLoop::__construct` takes 11 required arguments, so it is built through
 * `newInstanceWithoutConstructor()`: the gate under test reads two properties and
 * touches nothing the constructor computes, and a test that supplied eleven
 * arguments to reach a two-line method would be asserting its own fixture.
 */
    public function testEmitDropsAnUnsubscribedMode(): void
    {
        $loop = (new \ReflectionClass(PregelLoop::class))->newInstanceWithoutConstructor();
        $loop->streamModes = ['updates'];

        $loop->emit([['a' => 1]], 'values');
        self::assertSame(
            [],
            $this->streamBufferOf($loop),
            'an unsubscribed mode must not reach the buffer',
        );

        $loop->emit([['b' => 2]], 'updates');
        self::assertSame(
            [['updates', ['b' => 2]]],
            $this->streamBufferOf($loop),
            'a subscribed mode must reach the buffer',
        );
    }

    /**
     * A class's `$streamMode` constructor default, found BY NAME.
     *
     * Positional lookup (`getParameters()[13]`) would break the moment a parameter
     * is inserted above it, and would break silently by reading a different
     * parameter's default — the same failure this project has already recorded for a
     * mutation that quietly matched nothing.
     */
    private function streamModeDefaultOf(string $class): mixed
    {
        foreach ((new \ReflectionMethod($class, '__construct'))->getParameters() as $p) {
            if ($p->getName() === 'streamMode') {
                return $p->getDefaultValue();
            }
        }

        self::fail($class . ' has no $streamMode constructor parameter');
    }

    /** @return list<mixed> */
    private function streamBufferOf(PregelLoop $loop): array
    {
        // No setAccessible(): it is a no-op since PHP 8.1 and deprecated in 8.5,
        // and this suite runs on 8.5. Reading a private property needs none.
        $prop = new \ReflectionProperty(PregelLoop::class, 'streamBuffer');

        return array_values((array) $prop->getValue($loop));
    }

    /**
     * The stream MUST yield a properly-keyed list.
     *
     * This is the highest-consequence defect the stream mode work uncovered, and it
     * was invisible to `foreach` — which is exactly why it survived. Every chunk
     * was yielded with key `0`, so `iterator_to_array($stream)` — the DEFAULT,
     * `preserve_keys: true` — collapsed a multi-chunk stream to its last chunk.
     * Measured on a graph streamed with `['updates','values']`: `foreach` yielded 3
     * chunks, `iterator_to_array()` returned 1.
     *
     * `PregelLoop::run()` delegates to `drain()` from five places via `yield from`,
     * and a sub-generator's auto-keys restart at 0 on every call. The counter in
     * `drain()` is what makes the outer sequence a list again.
     *
     * Asserted with the DEFAULT `iterator_to_array` on purpose: the defect is
     * invisible to any consumer that ignores keys, so a test that avoided it would
     * be unable to see the bug it exists for.
     */
    public function testTheStreamIsAListUnderIteratorToArrayDefaults(): void
    {
        $chunks = iterator_to_array($this->graph(['updates', 'values'])->stream(['messages' => 'hi']));

        self::assertGreaterThan(
            1,
            count($chunks),
            'a multi-mode graph must yield more than one chunk, or this test proves nothing',
        );
        self::assertSame(
            range(0, count($chunks) - 1),
            array_keys($chunks),
            'stream keys must be a monotonic 0..n-1 list; duplicate keys collapse '
            . 'iterator_to_array($stream) to the last chunk with no error',
        );

        // The two consumers must agree — that is the whole property.
        $viaForeach = [];
        foreach ($this->graph(['updates', 'values'])->stream(['messages' => 'hi']) as $c) {
            $viaForeach[] = $c;
        }
        self::assertCount(count($viaForeach), $chunks, 'foreach and iterator_to_array must see the same chunks');
    }
}