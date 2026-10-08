<?php

declare(strict_types=1);

namespace LangGraph\Graph;

use LangChain\Runnables\RunnableInterface;
use LangChain\Utils\Notice;
use LangGraph\Errors\GraphValueError;
use LangGraph\Pregel\Checkpoint\BaseCheckpointSaver;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use LangGraph\Pregel\PregelNode;

use function LangChain\Runnables\coerceToRunnable;

/**
 * A graph builder: nodes, edges and conditional edges, compiled into a runnable {@see CompiledGraph}.
 *
 * Port of `Graph` from `langgraph-core/src/graph/graph.ts`.
 *
 * `Graph` is the schema-less base. Nodes receive whatever the previous node produced, and a node may
 * have at most one outgoing edge; fan-out needs the channel semantics of {@see \LangGraph\State\StateGraph},
 * which extends this class and overrides the few places the two differ (the `allowsMultipleEdges()`,
 * `reservedNodeNames()`, `assertNodeKeyIsValid()`, `buildNode()` and `branchName()` hooks).
 *
 * A node is stored as a {@see PregelNode} holding the user's runnable in `bound`; that is this port's
 * `NodeSpec`.
 */
class Graph
{
    /** @var array<string, PregelNode> */
    public array $nodes = [];

    /** @var list<array{0: string, 1: string}> */
    public array $edges = [];

    /** @var array<string, array<string, Branch>> */
    public array $branches = [];

    public ?string $entryPoint = null;

    public bool $compiled = false;

    /**
     * Recorded rather than printed: a user warning fails this suite (see {@see Notice}).
     */
    protected function warnIfCompiled(string $message): void
    {
        if ($this->compiled) {
            Notice::record($message);
        }
    }

    /**
     * Every edge, as `[start, end]` pairs.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function allEdges(): array
    {
        return $this->edges;
    }

    /**
     * Add one node, or several.
     *
     * Port of `Graph.addNode`, which has two call shapes:
     *
     *  - `addNode('key', $action, ['metadata' => ..., 'subgraphs' => [...], 'ends' => [...]])`
     *  - `addNode(['a' => $actionA, 'b' => $actionB])` (a record) or
     *    `addNode([['a', $actionA, $optionsA], ['b', $actionB]])` (a list of tuples).
     *
     * @param string|array<array-key, mixed>        $key     A node name, or the record / tuple list.
     * @param callable|RunnableInterface|null       $action  The node body (single-node form only).
     * @param array<string, mixed>                  $options
     */
    public function addNode(string|array $key, callable|RunnableInterface|null $action = null, array $options = []): static
    {
        if (is_string($key)) {
            $entries = [[$key, $action, $options]];
        } elseif (array_is_list($key)) {
            $entries = array_map(static fn (array $tuple): array => [$tuple[0], $tuple[1], $tuple[2] ?? []], $key);
        } else {
            $entries = [];
            foreach ($key as $name => $body) {
                $entries[] = [(string) $name, $body, []];
            }
        }

        if ($entries === []) {
            throw new \InvalidArgumentException('No nodes provided in `addNode`');
        }

        foreach ($entries as [$name, $body, $nodeOptions]) {
            $name = (string) $name;
            $this->assertNodeKeyIsValid($name);

            foreach ([Constants::CHECKPOINT_NAMESPACE_SEPARATOR, Constants::CHECKPOINT_NAMESPACE_END] as $reserved) {
                if (str_contains($name, $reserved)) {
                    throw new \InvalidArgumentException(
                        '"' . $reserved . '" is a reserved character and is not allowed in node names.'
                    );
                }
            }

            $this->warnIfCompiled(
                'Adding a node to a graph that has already been compiled. This will not be reflected in the compiled graph.'
            );

            if (isset($this->nodes[$name])) {
                throw new \InvalidArgumentException('Node `' . $name . '` already present.');
            }
            if (in_array($name, $this->reservedNodeNames(), true)) {
                throw new \InvalidArgumentException('Node `' . $name . '` is reserved.');
            }

            $this->nodes[$name] = $this->buildNode(coerceToRunnable($body, $name), $nodeOptions);
        }

        return $this;
    }

    /**
     * Connect two nodes.
     *
     * Port of `Graph.addEdge`. On a plain `Graph` a node may have only one outgoing edge.
     */
    public function addEdge(string $start, string $end): static
    {
        $this->warnIfCompiled(
            'Adding an edge to a graph that has already been compiled. This will not be reflected in the compiled graph.'
        );

        if ($start === Constants::END) {
            throw new \InvalidArgumentException('END cannot be a start node');
        }
        if ($end === Constants::START) {
            throw new \InvalidArgumentException('START cannot be an end node');
        }
        if (!$this->allowsMultipleEdges()) {
            foreach ($this->edges as [$existing]) {
                if ($existing === $start) {
                    throw new \InvalidArgumentException(
                        'Already found path for ' . $start . '. For multiple edges, use StateGraph.'
                    );
                }
            }
        }

        $this->edges[] = [$start, $end];

        return $this;
    }

    /**
     * Route from `$source` to a destination chosen at run time.
     *
     * Port of `Graph.addConditionalEdges`, which takes either `(source, path, pathMap)` or one
     * `BranchOptions` array (`['source' => ..., 'path' => ..., 'pathMap' => ...]`).
     *
     * @param string|array{source: string, path: callable|RunnableInterface, pathMap?: array<string, string>|list<string>|null} $source
     * @param array<string, string>|list<string>|null $pathMap
     */
    public function addConditionalEdges(
        string|array $source,
        callable|RunnableInterface|null $path = null,
        ?array $pathMap = null,
    ): static {
        if (is_array($source)) {
            $options = $source;
            $source = (string) $options['source'];
            $path = $options['path'];
            $pathMap = $options['pathMap'] ?? null;
        }
        if ($path === null) {
            throw new \InvalidArgumentException('addConditionalEdges requires a path.');
        }

        $this->warnIfCompiled(
            'Adding an edge to a graph that has already been compiled. This will not be reflected in the compiled graph.'
        );

        $branch = new Branch($path, $pathMap);
        $name = $this->branchName($branch, $pathMap);

        if (isset($this->branches[$source][$name])) {
            throw new \InvalidArgumentException(sprintf(
                'Condition `%s` already present for node `%s`',
                $name,
                $source,
            ));
        }

        $this->branches[$source][$name] = $branch;

        return $this;
    }

    /** @deprecated use `addEdge(START, $key)` instead */
    public function setEntryPoint(string $key): static
    {
        $this->warnIfCompiled(
            'Setting the entry point of a graph that has already been compiled. This will not be reflected in the compiled graph.'
        );

        return $this->addEdge(Constants::START, $key);
    }

    /** @deprecated use `addEdge($key, END)` instead */
    public function setFinishPoint(string $key): static
    {
        $this->warnIfCompiled(
            'Setting a finish point of a graph that has already been compiled. This will not be reflected in the compiled graph.'
        );

        return $this->addEdge($key, Constants::END);
    }

    /**
     * Validate the graph and turn it into a {@see CompiledGraph}.
     *
     * Port of `Graph.compile`. Unlike `StateGraph`, a plain graph is wired the way upstream wires it:
     * one ephemeral channel per node, `branch:<source>:<name>:<dest>` channels for conditional edges,
     * input read from `__start__` and output read from `__end__`.
     *
     * @param array{checkpointer?: BaseCheckpointSaver|false|null, interruptBefore?: list<string>|string, interruptAfter?: list<string>|string, name?: string, store?: \LangGraph\Store\BaseStore|null, cache?: \LangGraph\Cache\BaseCache|null} $options
     */
    public function compile(array $options = []): CompiledGraph
    {
        $interruptBefore = $options['interruptBefore'] ?? [];
        $interruptAfter = $options['interruptAfter'] ?? [];

        $this->validate([
            ...(is_array($interruptBefore) ? $interruptBefore : []),
            ...(is_array($interruptAfter) ? $interruptAfter : []),
        ]);

        $nodes = [];
        $channels = [
            Constants::START => new \LangGraph\Channels\EphemeralValue(),
            Constants::END => new \LangGraph\Channels\EphemeralValue(),
        ];
        $streamChannels = [];

        foreach ($this->nodes as $key => $node) {
            CompiledGraph::attachNode($nodes, $channels, $streamChannels, (string) $key, $node);
        }
        foreach ($this->edges as [$start, $end]) {
            CompiledGraph::attachEdge($nodes, $start, $end);
        }
        foreach ($this->branches as $start => $branches) {
            foreach ($branches as $name => $branch) {
                CompiledGraph::attachBranch($nodes, $channels, (string) $start, (string) $name, $branch);
            }
        }

        $checkpointer = $options['checkpointer'] ?? null;

        return new CompiledGraph(
            nodes: $nodes,
            channels: $channels,
            inputChannels: Constants::START,
            outputChannels: Constants::END,
            streamChannels: $streamChannels,
            checkpointer: $checkpointer === false ? null : $checkpointer,
            streamMode: ['values'],
            name: $options['name'] ?? null,
            interruptBefore: (array) $interruptBefore,
            interruptAfter: (array) $interruptAfter,
            checkpointerDisabled: $checkpointer === false,
            store: $options['store'] ?? null,
            cache: $options['cache'] ?? null,
            builder: $this,
        );
    }

    /**
     * Check the graph is well formed, and mark it compiled.
     *
     * Port of `Graph.validate`.
     *
     * @param list<string>|null $interrupt Node names named by `interruptBefore` / `interruptAfter`.
     */
    public function validate(?array $interrupt = null): void
    {
        $allSources = [];
        foreach ($this->allEdges() as [$src]) {
            $allSources[$src] = true;
        }
        foreach (array_keys($this->branches) as $start) {
            $allSources[(string) $start] = true;
        }

        foreach (array_keys($allSources) as $source) {
            $source = (string) $source;
            if ($source !== Constants::START && !isset($this->nodes[$source])) {
                throw new \InvalidArgumentException('Found edge starting at unknown node `' . $source . '`');
            }
        }

        $allTargets = [];
        foreach ($this->allEdges() as [, $target]) {
            $allTargets[$target] = true;
        }
        foreach ($this->branches as $start => $branches) {
            foreach ($branches as $branch) {
                if ($branch->ends !== null) {
                    foreach ($branch->ends as $end) {
                        $allTargets[$end] = true;
                    }
                } else {
                    $allTargets[Constants::END] = true;
                    foreach (array_keys($this->nodes) as $node) {
                        if ((string) $node !== (string) $start) {
                            $allTargets[(string) $node] = true;
                        }
                    }
                }
            }
        }
        foreach ($this->nodes as $node) {
            foreach ($node->ends as $target) {
                $allTargets[$target] = true;
            }
        }

        $hasErrorHandler = false;
        foreach ($this->nodes as $node) {
            $hasErrorHandler = $hasErrorHandler || $node->isErrorHandler;
        }
        if ($hasErrorHandler) {
            foreach (array_keys($this->nodes) as $node) {
                $allTargets[(string) $node] = true;
            }
        }

        foreach ($this->nodes as $key => $node) {
            $key = (string) $key;
            if ($node->isErrorHandler) {
                continue;
            }
            if (!isset($allTargets[$key])) {
                throw new GraphValueError(
                    implode("\n", [
                        'Node `' . $key . '` is not reachable.',
                        '',
                        'If you are returning Command objects from your node,',
                        'make sure you are passing names of potential destination nodes as an "ends" array',
                        'into ".addNode(..., { ends: ["node1", "node2"] })".',
                    ]),
                    ['lc_error_code' => 'UNREACHABLE_NODE'],
                );
            }
        }
        foreach (array_keys($allTargets) as $target) {
            $target = (string) $target;
            if ($target !== Constants::END && !isset($this->nodes[$target])) {
                throw new \InvalidArgumentException('Found edge ending at unknown node `' . $target . '`');
            }
        }

        foreach ($interrupt ?? [] as $node) {
            if (!isset($this->nodes[$node])) {
                throw new \InvalidArgumentException('Interrupt node `' . $node . '` is not present');
            }
        }

        $this->compiled = true;
    }

    /** Whether a node may have several outgoing edges. Only channel-based graphs may. */
    protected function allowsMultipleEdges(): bool
    {
        return false;
    }

    /** @return list<string> Names a node may not take. */
    protected function reservedNodeNames(): array
    {
        return [Constants::END];
    }

    /** Hook for subclasses to veto a node name before the generic checks run. */
    protected function assertNodeKeyIsValid(string $key): void
    {
    }

    /**
     * Build the stored form of a node.
     *
     * @param array<string, mixed> $options
     */
    protected function buildNode(RunnableInterface $runnable, array $options): PregelNode
    {
        return new PregelNode(
            bound: $runnable,
            metadata: $options['metadata'] ?? [],
            subgraphs: $runnable instanceof Pregel ? [$runnable] : ($options['subgraphs'] ?? []),
            ends: $options['ends'] ?? [],
        );
    }

    /**
     * The key a conditional edge is stored under, unique per source node.
     *
     * @param array<string, string>|list<string>|null $pathMap
     */
    protected function branchName(Branch $branch, ?array $pathMap): string
    {
        $name = $branch->path->getName();

        return $name === 'RunnableLambda' ? 'condition' : $name;
    }
}
