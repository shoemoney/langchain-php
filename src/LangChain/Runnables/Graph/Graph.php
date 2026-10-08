<?php

declare(strict_types=1);

namespace LangChain\Runnables\Graph;

use LangChain\Runnables\RunnableInterface;
use LangChain\Utils\Uuid;

/**
 * A drawable graph of runnables: what `Runnable::getGraph()` returns.
 *
 * Port of `Graph` from `langchain-core/src/runnables/graph.ts`. Nodes are keyed by id; an id that is
 * a UUID marks an anonymous node, which {@see self::reid()} renames to its readable name and
 * {@see self::toJSON()} replaces with its position.
 */
class Graph
{
    private const JSON_SCHEMA_DRAFT = 'http://json-schema.org/draft-07/schema#';

    /** @var array<string, Node> */
    public array $nodes = [];

    /** @var list<Edge> */
    public array $edges = [];

    /**
     * @param array<string, Node>|null $nodes
     * @param list<Edge>|null          $edges
     */
    public function __construct(?array $nodes = null, ?array $edges = null)
    {
        $this->nodes = $nodes ?? $this->nodes;
        $this->edges = $edges ?? $this->edges;
    }

    /**
     * The default drawable graph of a runnable: input schema, the runnable, output schema.
     *
     * This is the body of `Runnable.getGraph` upstream.
     */
    public static function ofRunnable(RunnableInterface $runnable): self
    {
        $graph = new self();

        $inputNode = $graph->addNode(new RunnableIOSchema($runnable->getName() . 'Input'));
        $runnableNode = $graph->addNode($runnable);
        $outputNode = $graph->addNode(new RunnableIOSchema($runnable->getName() . 'Output'));

        $graph->addEdge($inputNode, $runnableNode);
        $graph->addEdge($runnableNode, $outputNode);

        return $graph;
    }

    /**
     * Convert the graph to a JSON-serializable array.
     *
     * Nodes with a UUID id are renumbered by position so the output is stable between runs.
     *
     * @return array{nodes: list<array<string, mixed>>, edges: list<array<string, mixed>>}
     */
    public function toJSON(): array
    {
        $stableNodeIds = [];
        foreach (array_values($this->nodes) as $i => $node) {
            $stableNodeIds[$node->id] = Uuid::validate($node->id) ? $i : $node->id;
        }

        $nodes = [];
        foreach ($this->nodes as $node) {
            $nodes[] = ['id' => $stableNodeIds[$node->id], ...self::nodeDataJson($node)];
        }

        $edges = [];
        foreach ($this->edges as $edge) {
            $item = [
                'source' => $stableNodeIds[$edge->source],
                'target' => $stableNodeIds[$edge->target],
            ];
            if ($edge->data !== null) {
                $item['data'] = $edge->data;
            }
            if ($edge->conditional !== null) {
                $item['conditional'] = $edge->conditional;
            }
            $edges[] = $item;
        }

        return ['nodes' => $nodes, 'edges' => $edges];
    }

    /**
     * @param array<string, mixed>|null $metadata
     */
    public function addNode(RunnableInterface|RunnableIOSchema $data, ?string $id = null, ?array $metadata = null): Node
    {
        if ($id !== null && isset($this->nodes[$id])) {
            throw new \InvalidArgumentException('Node with id ' . $id . ' already exists');
        }
        $nodeId = $id ?? Uuid::v4();
        $node = new Node($nodeId, $data, self::nodeDataStr($id, $data), $metadata);
        $this->nodes[$nodeId] = $node;

        return $node;
    }

    public function removeNode(Node $node): void
    {
        unset($this->nodes[$node->id]);

        $this->edges = array_values(array_filter(
            $this->edges,
            static fn (Edge $edge): bool => $edge->source !== $node->id && $edge->target !== $node->id,
        ));
    }

    public function addEdge(Node $source, Node $target, ?string $data = null, ?bool $conditional = null): Edge
    {
        if (!isset($this->nodes[$source->id])) {
            throw new \InvalidArgumentException('Source node ' . $source->id . ' not in graph');
        }
        if (!isset($this->nodes[$target->id])) {
            throw new \InvalidArgumentException('Target node ' . $target->id . ' not in graph');
        }
        $edge = new Edge($source->id, $target->id, $data, $conditional);
        $this->edges[] = $edge;

        return $edge;
    }

    public function firstNode(): ?Node
    {
        return self::findFirstNode($this);
    }

    public function lastNode(): ?Node
    {
        return self::findLastNode($this);
    }

    /**
     * Add all nodes and edges from another graph.
     *
     * Note this doesn't check for duplicates, nor does it connect the graphs.
     *
     * @return array{0: Node|null, 1: Node|null} The other graph's first and last node under their new ids.
     */
    public function extend(self $graph, string $prefix = ''): array
    {
        $finalPrefix = $prefix;
        $allUuids = true;
        foreach ($graph->nodes as $node) {
            $allUuids = $allUuids && Uuid::validate($node->id);
        }
        if ($allUuids) {
            $finalPrefix = '';
        }

        $prefixed = static fn (string $id): string => $finalPrefix !== '' ? $finalPrefix . ':' . $id : $id;

        foreach ($graph->nodes as $key => $value) {
            $copy = clone $value;
            $copy->id = $prefixed((string) $key);
            $this->nodes[$prefixed((string) $key)] = $copy;
        }

        $newEdges = [];
        foreach ($graph->edges as $edge) {
            $copy = clone $edge;
            $copy->source = $prefixed($edge->source);
            $copy->target = $prefixed($edge->target);
            $newEdges[] = $copy;
        }
        $this->edges = [...$this->edges, ...$newEdges];

        $first = $graph->firstNode();
        $last = $graph->lastNode();

        return [
            $first !== null ? self::reidentified($first, $prefixed($first->id)) : null,
            $last !== null ? self::reidentified($last, $prefixed($last->id)) : null,
        ];
    }

    public function trimFirstNode(): void
    {
        $firstNode = $this->firstNode();
        if ($firstNode !== null && self::findFirstNode($this, [$firstNode->id]) !== null) {
            $this->removeNode($firstNode);
        }
    }

    public function trimLastNode(): void
    {
        $lastNode = $this->lastNode();
        if ($lastNode !== null && self::findLastNode($this, [$lastNode->id]) !== null) {
            $this->removeNode($lastNode);
        }
    }

    /**
     * Return a new graph with all nodes re-identified, using their unique, readable names where possible.
     */
    public function reid(): self
    {
        $nodeLabels = [];
        foreach ($this->nodes as $node) {
            $nodeLabels[$node->id] = $node->name;
        }
        $nodeLabelCounts = [];
        foreach ($nodeLabels as $label) {
            $nodeLabelCounts[$label] = ($nodeLabelCounts[$label] ?? 0) + 1;
        }

        $getNodeId = static function (string $nodeId) use ($nodeLabels, $nodeLabelCounts): string {
            $label = $nodeLabels[$nodeId];
            if (Uuid::validate($nodeId) && ($nodeLabelCounts[$label] ?? 0) === 1) {
                return $label;
            }

            return $nodeId;
        };

        $nodes = [];
        foreach ($this->nodes as $id => $node) {
            $copy = clone $node;
            $copy->id = $getNodeId((string) $id);
            $nodes[$copy->id] = $copy;
        }
        $edges = [];
        foreach ($this->edges as $edge) {
            $copy = clone $edge;
            $copy->source = $getNodeId($edge->source);
            $copy->target = $getNodeId($edge->target);
            $edges[] = $copy;
        }

        return new self($nodes, $edges);
    }

    /**
     * Render the graph as Mermaid flowchart text.
     *
     * @param array<string, string>|null $nodeColors Class name to CSS, default `default`/`first`/`last`.
     */
    public function drawMermaid(
        ?bool $withStyles = null,
        ?string $curveStyle = null,
        ?array $nodeColors = null,
        ?int $wrapLabelNWords = null,
    ): string {
        $nodeColors ??= [
            'default' => 'fill:#f2f0ff,line-height:1.2',
            'first' => 'fill-opacity:0',
            'last' => 'fill:#bfb6fc',
        ];
        $graph = $this->reid();

        return Mermaid::drawMermaid($graph->nodes, $graph->edges, [
            'firstNode' => $graph->firstNode()?->id,
            'lastNode' => $graph->lastNode()?->id,
            'withStyles' => $withStyles,
            'curveStyle' => $curveStyle,
            'nodeColors' => $nodeColors,
            'wrapLabelNWords' => $wrapLabelNWords,
        ]);
    }

    /**
     * Render the graph through the Mermaid.INK API and return the image bytes.
     *
     * @param array<string, string>|null                                                    $nodeColors
     * @param (callable(string): array{ok: bool, status: int, statusText: string, body: string})|null $fetch
     */
    public function drawMermaidPng(
        ?bool $withStyles = null,
        ?string $curveStyle = null,
        ?array $nodeColors = null,
        ?int $wrapLabelNWords = null,
        ?string $backgroundColor = null,
        ?callable $fetch = null,
    ): string {
        $mermaidSyntax = $this->drawMermaid($withStyles, $curveStyle, $nodeColors, $wrapLabelNWords);

        return Mermaid::drawMermaidImage($mermaidSyntax, ['backgroundColor' => $backgroundColor], $fetch);
    }

    private static function reidentified(Node $node, string $id): Node
    {
        return new Node($id, $node->data, self::nodeDataStr($id, $node->data));
    }

    private static function nodeDataStr(?string $id, RunnableInterface|RunnableIOSchema $data): string
    {
        if ($id !== null && !Uuid::validate($id)) {
            return $id;
        }
        if ($data instanceof RunnableInterface) {
            $dataStr = $data->getName();

            return str_starts_with($dataStr, 'Runnable') ? substr($dataStr, strlen('Runnable')) : $dataStr;
        }

        return $data->name ?? 'UnknownSchema';
    }

    /**
     * @return array{type: string, data: array<string, mixed>}
     */
    private static function nodeDataJson(Node $node): array
    {
        $data = $node->data;
        if ($data instanceof RunnableInterface) {
            return [
                'type' => 'runnable',
                'data' => ['id' => self::lcId($data), 'name' => $data->getName()],
            ];
        }

        $schema = ['$schema' => self::JSON_SCHEMA_DRAFT, ...$data->schema];
        if ($data->name !== null) {
            $schema['title'] = $data->name;
        }

        return ['type' => 'schema', 'data' => $schema];
    }

    /**
     * The serialization id (`lc_id`) of a runnable: its `lcId()` when it has one, else its class path.
     *
     * @return list<string>
     */
    private static function lcId(RunnableInterface $runnable): array
    {
        if (method_exists($runnable, 'lcId')) {
            /** @var list<string> */
            return $runnable::lcId();
        }

        return explode('\\', $runnable::class);
    }

    /**
     * Find the single node that is not a target of any edge, ignoring `$exclude`.
     *
     * @param list<string> $exclude
     */
    private static function findFirstNode(self $graph, array $exclude = []): ?Node
    {
        $targets = [];
        foreach ($graph->edges as $edge) {
            if (!in_array($edge->source, $exclude, true)) {
                $targets[$edge->target] = true;
            }
        }

        $found = [];
        foreach ($graph->nodes as $node) {
            if (!in_array($node->id, $exclude, true) && !isset($targets[$node->id])) {
                $found[] = $node;
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }

    /**
     * Find the single node that is not a source of any edge, ignoring `$exclude`.
     *
     * @param list<string> $exclude
     */
    private static function findLastNode(self $graph, array $exclude = []): ?Node
    {
        $sources = [];
        foreach ($graph->edges as $edge) {
            if (!in_array($edge->target, $exclude, true)) {
                $sources[$edge->source] = true;
            }
        }

        $found = [];
        foreach ($graph->nodes as $node) {
            if (!in_array($node->id, $exclude, true) && !isset($sources[$node->id])) {
                $found[] = $node;
            }
        }

        return count($found) === 1 ? $found[0] : null;
    }
}
