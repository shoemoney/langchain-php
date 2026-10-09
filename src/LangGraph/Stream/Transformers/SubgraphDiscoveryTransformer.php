<?php

declare(strict_types=1);

namespace LangGraph\Stream\Transformers;

use LangGraph\Stream\Mux;
use LangGraph\Stream\NativeStreamTransformer;
use LangGraph\Stream\ReplayableIterable;
use LangGraph\Stream\StreamChannel;
use LangGraph\Stream\StreamHandle;
use LangGraph\Stream\Types;

/**
 * Materialises a {@see StreamHandle} for each newly observed top-level subgraph namespace and announces it
 * on the mux's discovery channel.
 *
 * Port of `createSubgraphDiscoveryTransformer` / `filterSubgraphHandles` from
 * `stream/transformers/subgraphs.ts`. Only first-level namespace segments are announced; deeper ones
 * (`["researcher:uuid", "tools:uuid"]`) are internal checkpoint namespaces, not user-facing subgraphs.
 *
 * Native: the projection (`['_discoveries' => channel, 'subgraphs' => iterable]`) is internal wiring.
 */
final class SubgraphDiscoveryTransformer implements NativeStreamTransformer
{
    /** @var array<string, true> */
    private array $seen = [];

    /**
     * @param \Closure(list<string>, int, int): StreamHandle $createStream (path, discoveryStart, eventStart)
     */
    public function __construct(private readonly Mux $mux, private readonly \Closure $createStream)
    {
    }

    /**
     * @return array{_discoveries: StreamChannel, subgraphs: \IteratorAggregate<int, StreamHandle>}
     */
    public function init(): array
    {
        return [
            '_discoveries' => $this->mux->discoveries,
            'subgraphs' => self::filterHandles($this->mux->discoveries, [], 0),
        ];
    }

    public function process(array $event): bool
    {
        $ns = $event['params']['namespace'];
        if ($ns === []) {
            return true;
        }

        $topNs = \array_slice($ns, 0, 1);
        $topKey = Types::nsKey($topNs);
        if (isset($this->seen[$topKey])) {
            return true;
        }
        $this->seen[$topKey] = true;

        $stream = ($this->createStream)($topNs, $this->mux->discoveries->size(), $this->mux->events->size());
        $this->mux->register($topNs, $stream);
        $this->mux->discoveries->push(['ns' => $topNs, 'stream' => $stream]);

        return true;
    }

    /**
     * Only the direct children of `$path`, from discovery index `$startAt`.
     *
     * @param list<string> $path
     * @return \IteratorAggregate<int, StreamHandle>
     */
    public static function filterHandles(StreamChannel $log, array $path, int $startAt = 0): \IteratorAggregate
    {
        $targetDepth = \count($path) + 1;

        return new ReplayableIterable(static function () use ($log, $path, $startAt, $targetDepth): \Generator {
            foreach ($log->iterate($startAt) as ['ns' => $ns, 'stream' => $stream]) {
                if (\count($ns) === $targetDepth && Types::hasPrefix($ns, $path)) {
                    yield $stream;
                }
            }
        });
    }
}
