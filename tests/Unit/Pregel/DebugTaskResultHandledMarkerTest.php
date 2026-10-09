<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangGraph\Cache\InMemoryCache;
use LangGraph\Errors\NodeError;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\PregelRunner;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port-only: upstream has no `__handled__` marker. The marker that tells the cache to skip a handled
 * task must not leak into the `debug` stream's `task_result` payload.
 */
#[CoversClass(PregelRunner::class)]
final class DebugTaskResultHandledMarkerTest extends TestCase
{
    private static function graph(bool $handled, string $mode = 'debug'): \LangGraph\Pregel\Pregel
    {
        $node = $handled
            ? static function (): array {
                throw new \RuntimeException('x');
            }
            : static fn (): array => ['foo' => 'plain'];
        $config = $handled
            ? ['errorHandler' => static fn (array $s, NodeError $e): Command => new Command(update: ['foo' => 'h'])]
            : [];

        return (new StateGraph(Annotation::root(['foo' => Annotation::last()])))
            ->addNode('boom', $node, $config)
            ->addEdge(Constants::START, 'boom')
            ->compile(['streamMode' => [$mode]]);
    }

    /** @return list<array<string, mixed>> */
    private static function taskResults(\LangGraph\Pregel\Pregel $graph): array
    {
        $out = [];
        foreach ($graph->stream(['foo' => '']) as $chunk) {
            $p = $chunk[1] ?? $chunk;
            if (is_array($p) && ($p['type'] ?? null) === 'task_result' && ($p['payload']['name'] ?? null) === 'boom') {
                $out[] = $p['payload']['result'];
            }
        }

        return $out;
    }

    public function testAHandledNodesDebugTaskResultHasNoMarker(): void
    {
        $results = self::taskResults(self::graph(true));

        self::assertSame([['foo' => 'h']], $results);
    }

    public function testANodeWithoutAHandlerIsUnchanged(): void
    {
        $results = self::taskResults(self::graph(false));

        self::assertSame([['foo' => 'plain']], $results);
    }

    public function testUpdatesAndValuesModesNeverSeeTheMarker(): void
    {
        foreach (['updates', 'values'] as $mode) {
            $graph = self::graph(true, $mode);
            $seen = 0;
            foreach ($graph->stream(['foo' => '']) as $chunk) {
                self::assertStringNotContainsString(Constants::HANDLED, json_encode($chunk, JSON_THROW_ON_ERROR), $mode);
                ++$seen;
            }
            self::assertGreaterThan(0, $seen, $mode);
        }
    }

    public function testTheMarkerStillReachesTheWritesSoTheCacheSkipsTheHandledTask(): void
    {
        $calls = 0;
        $graph = (new StateGraph(Annotation::root(['foo' => Annotation::last()])))
            ->addNode('flaky', static function () use (&$calls): array {
                $calls += 1;
                if ($calls === 1) {
                    throw new \RuntimeException('transient');
                }

                return ['foo' => 'ok'];
            }, [
                'cachePolicy' => true,
                'errorHandler' => static fn (): array => ['foo' => 'handled'],
            ])
            ->addEdge(Constants::START, 'flaky')
            ->compile(['cache' => new InMemoryCache(), 'streamMode' => ['debug']]);

        $first = [];
        foreach ($graph->stream(['foo' => 'x']) as $chunk) {
            $p = $chunk[1] ?? $chunk;
            if (is_array($p) && ($p['type'] ?? null) === 'task_result' && ($p['payload']['name'] ?? null) === 'flaky') {
                $first[] = $p['payload']['result'];
            }
        }
        self::assertSame([['foo' => 'handled']], $first);

        // Had the marker been dropped from the writes, the handled result would be cached and the node skipped.
        iterator_to_array($graph->stream(['foo' => 'x']));
        self::assertSame(2, $calls);
    }
}
