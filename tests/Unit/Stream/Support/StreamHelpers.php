<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Stream\Support;

use LangGraph\Stream\Types;

/**
 * The upstream `test-utils.ts` helpers (`makeProtocolEvent`, `collectIterator`, `collectAsyncIterable`).
 */
trait StreamHelpers
{
    /**
     * @param list<string> $namespace
     * @return array<string, mixed>
     */
    private static function makeEvent(string $method, array $namespace = [], mixed $data = [], ?string $node = null, int $seq = 0): array
    {
        return Types::protocolEvent($method, $namespace, $data, $node, $seq);
    }

    /**
     * @return list<mixed>
     */
    private static function collect(iterable $iterable): array
    {
        $items = [];
        foreach ($iterable as $item) {
            $items[] = $item;
        }

        return $items;
    }

    /**
     * A chunk source over `[namespace, mode, payload]` triples.
     *
     * @param list<array{0: list<string>, 1: string, 2: mixed}> $chunks
     */
    private static function makeSource(array $chunks): \Generator
    {
        yield from $chunks;
    }
}
