<?php

declare(strict_types=1);

namespace LangGraph\Stream;

/**
 * The run stream of a child subgraph within a parent execution.
 *
 * Port of `SubgraphRunStream`: a {@see RunStream} with a `name` and an `index` parsed from the last
 * namespace segment, which follows the `name:index` convention. With no numeric suffix the index is 0.
 */
class SubgraphRunStream extends RunStream
{
    public readonly string $name;

    public readonly int $index;

    /**
     * @param list<string> $path
     * @param array<string, mixed> $extensions
     */
    public function __construct(
        array $path,
        Mux $mux,
        int $discoveryStart = 0,
        int $eventStart = 0,
        array $extensions = [],
        ?AbortSignal $abortSignal = null,
    ) {
        parent::__construct($path, $mux, $discoveryStart, $eventStart, $extensions, $abortSignal);

        $lastSegment = $path === [] ? '' : $path[\count($path) - 1];
        $colon = strrpos($lastSegment, ':');
        if ($colon !== false) {
            $this->name = substr($lastSegment, 0, $colon);
            $suffix = substr($lastSegment, $colon + 1);
            $this->index = preg_match('/^\d+$/', $suffix) === 1 ? (int) $suffix : 0;
        } else {
            $this->name = $lastSegment;
            $this->index = 0;
        }
    }
}
