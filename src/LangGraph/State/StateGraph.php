<?php

declare(strict_types=1);

namespace LangGraph\State;

use LangChain\Runnables\RunnableInterface;
use LangChain\Runnables\RunnableLambda;
use LangGraph\Channels\EphemeralValue;
use LangGraph\Channels\Missing;
use LangGraph\Errors\InvalidUpdateError;
use LangGraph\Pregel\ChannelWrite;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\CompiledStateGraph;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\PregelNode;
use LangGraph\Pregel\Send;

/**
 * A graph builder whose state is declared as a set of channels.
 *
 * Port of `StateGraph` from `langgraph-core/src/graph/state.ts`.
 *
 * `StateGraph` is a builder, not a runner: it accumulates nodes, edges, and
 * conditional edges, and {@see self::compile()} turns them into a
 * {@see CompiledStateGraph}. The separation matters because a graph is compiled
 * once and then run many times, possibly concurrently — and a builder that
 * could be mutated mid-run would not be safe to share.
 *
 * ## What compile() does
 *
 * Compiling is where a declarative graph becomes a Pregel graph, and almost all
 * of the work is *plumbing*:
 *
 *  - **Write-back.** Every node's return value is mapped to `[channel, value]`
 *    pairs and written, so a node that returns `['items' => [...]]` publishes
 *    that without saying so. Keys not in the schema are dropped — the schema is
 *    the state, and a stray key is a typo the graph should not silently accept.
 *  - **Per-node channels.** Each node gets an `EphemeralValue` named after it.
 *    That is how an *edge* works: writing to a node's channel schedules it, and
 *    the value is consumed rather than accumulating — so an edge is a trigger,
 *    not a merge.
 *  - **The hidden `SELF` branch.** Every node also gets a branch that reads a
 *    returned `Command`'s `goto` and writes to the destination's branch channel.
 *    This is what lets a node route without a declared edge.
 *  - **`Command` updates.** A `Command` returned by a node has its `update`
 *    folded into the same write-back path, so returning a `Command` updates
 *    state *and* routes in one return.
 *
 * The `branch:` channels are the trick that makes dynamic routing work. A
 * conditional edge's result is data, not control flow — the engine cannot jump.
 * So the branch *writes* to a channel named after its destination, and the
 * destination node subscribes to that channel. Routing becomes a write, and
 * writes are what Pregel already knows how to schedule.
 */
class StateGraph
{
    public const SELF = Constants::SELF;

    /** The sentinel channel a whole-value return is written to. */
    public const ROOT = '__root__';

    /** @var array<string, PregelNode> */
    protected array $nodes = [];

    /** @var list<array{0: string, 1: string}> */
    protected array $edges = [];

    /** @var array<string, array<string, callable|RunnableInterface>> */
    protected array $branches = [];

    /**
     * @param AnnotationRoot|array<string, mixed> $schema A channel map, or the
     *        shorthand `['key' => 'int']` / `['key' => null]` / `['key' => ['reducer' => $op]]`.
     */
    public function __construct(
        protected AnnotationRoot|array $schema,
    ) {
        // Must assign to the PROPERTY, not the parameter: with constructor
        // property promotion the parameter is a local copy, so reassigning it
        // here would leave `$this->schema` as the raw array and every later
        // `$this->schema->spec` would fatal.
        if (is_array($schema)) {
            $this->schema = Annotation::root($schema);
        }
    }

    /** The channels this graph's state is made of. */
    public function channels(): array
    {
        return $this->schema->spec;
    }

    /**
     * Add a node.
     *
     * Port of `StateGraph.addNode`. The name doubles as the channel the node's
     * completion is published to, which is why it may not collide with a state
     * key: a node called `items` and a state key called `items` would make
     * "did this node run" and "what is this field" the same question. Rejected
     * here rather than left to produce a graph whose behaviour depends on
     * write ordering.
     *
     * @param array{retryPolicy?: \LangGraph\Pregel\Retry\RetryPolicy, cachePolicy?: array|false, metadata?: array<string, mixed>, tags?: list<string>, errorHandler?: string, ends?: list<string>} $options
     */
    public function addNode(
        string $key,
        callable|RunnableInterface $action,
        array $options = [],
    ): self {
        if (array_key_exists($key, $this->schema->spec)) {
            throw new \InvalidArgumentException(
                $key . ' is already being used as a state attribute (a.k.a. a channel), '
                . 'cannot also be used as a node name.'
            );
        }

        foreach ([Constants::CHECKPOINT_NAMESPACE_SEPARATOR, Constants::CHECKPOINT_NAMESPACE_END] as $reserved) {
            if (str_contains($key, $reserved)) {
                throw new \InvalidArgumentException(
                    '"' . $reserved . '" is a reserved character and is not allowed in node names.'
                );
            }
        }

        if (isset($this->nodes[$key])) {
            throw new \InvalidArgumentException('Node `' . $key . '` already present.');
        }

        if ($key === Constants::END || $key === Constants::START) {
            throw new \InvalidArgumentException('Node `' . $key . '` is reserved.');
        }

        $runnable = \LangChain\Runnables\coerceToRunnable($action, $key);

        $this->nodes[(string) $key] = new PregelNode(
            bound: $runnable,
            metadata: $options['metadata'] ?? [],
            tags: $options['tags'] ?? [],
            retryPolicy: $options['retryPolicy'] ?? null,
            cachePolicy: ($options['cachePolicy'] ?? null) === false ? null : ($options['cachePolicy'] ?? null),
            ends: $options['ends'] ?? [],
            errorHandlerNode: $options['errorHandler'] ?? null,
        );

        return $this;
    }

    /**
     * Add several nodes at once.
     *
     * @param array<string, callable|RunnableInterface> $actions
     */
    public function addNodes(array $actions): self
    {
        foreach ($actions as $key => $action) {
            $this->addNode((string) $key, $action);
        }

        return $this;
    }

    /**
     * Connect two nodes unconditionally.
     *
     * Port of `addEdge`. A plain edge is the cheapest routing there is: the
     * target subscribes to the source's channel, so completing the source
     * *is* the trigger. No runnable runs at routing time.
     *
     * An edge to `__end__` becomes a write to the reserved `__end__` channel
     * instead, because Pregel has no stop node — reaching the end is an event
     * that the loop reads, not an edge.
     */
    public function addEdge(string $start, string $end): self
    {
        if ($start !== Constants::START && !isset($this->nodes[$start])) {
            throw new \InvalidArgumentException('Node `' . $start . '` not found');
        }

        if ($end === Constants::END) {
            if ($start === Constants::START) {
                throw new \InvalidArgumentException('Cannot have an edge from START to END');
            }
            $this->nodes[$start]->writers[] = new ChannelWrite(
                [['channel' => Constants::END, 'value' => ChannelWrite::passthrough()]],
                [Constants::TAG_HIDDEN],
            );
        } else {
            if (!isset($this->nodes[$end])) {
                throw new \InvalidArgumentException('Node `' . $end . '` not found');
            }
            $this->edges[] = [$start, $end];
        }

        return $this;
    }

    /**
     * Route to one of several nodes based on state.
     *
     * Port of `addConditionalEdges`. `$path` is a runnable or callable from the
     * source node's output to a destination name, a list of names, or a list of
     * {@see Send} packets. Returning `null` or `[]` routes nowhere, which is how
     * a node declines to continue — a normal outcome, not an error.
     *
     * @param list<string>|null $pathMap Optional display names per branch.
     */
    public function addConditionalEdges(string $start, callable|RunnableInterface $path, ?array $pathMap = null): self
    {
        // START is permitted here exactly as addEdge() permits it, and exactly as
        // upstream's own fixtures use it — `addConditionalEdges(START, fanOut,
        // ["review"])` appears in langgraph-js' multi-interrupt-graph.ts:48 and
        // mock-server.ts:540. Guarding on `$this->nodes` alone rejected a call
        // pattern upstream exercises, while the sibling addEdge() accepted it, so
        // the two edge-adding methods disagreed about START.
        if ($start !== Constants::START && !isset($this->nodes[$start])) {
            throw new \InvalidArgumentException('Node `' . $start . '` not found');
        }

        $branchName = $pathMap === null ? Constants::SELF : (array_values($pathMap)[0] ?? Constants::SELF);

        // Upstream REFUSES a duplicate condition name rather than overwriting it
        // (langgraph-core/src/graph/graph.ts:488-495):
        //
        //     if (this.branches[source] && this.branches[source][name]) {
        //       throw new Error(`Condition \`${name}\` already present for node \`${source}\``);
        //     }
        //     this.branches[source] ??= {};
        //     this.branches[source][name] = new Branch(options);
        //
        // The keyed-by-name storage is the same; the guard is what was missing. Two
        // conditional edges from one node with no pathMap both resolve to SELF, and
        // this port silently kept the second — a branch a caller registered and
        // could see accepted simply disappearing, with nothing thrown. Upstream makes
        // it loud at REGISTRATION time, which is the only moment the caller can
        // still do anything about it.
        if (isset($this->branches[$start][$branchName])) {
            throw new \InvalidArgumentException(sprintf(
                'Condition `%s` already present for node `%s`',
                $branchName,
                $start,
            ));
        }

        $this->branches[$start][$branchName] = $path;

        return $this;
    }

    /** Set the graph's start node. */
    public function setEntryPoint(string $node): self
    {
        return $this->addEdge(Constants::START, $node);
    }

    /** Set the graph's finish node. */
    public function setFinishPoint(string $node): self
    {
        return $this->addEdge($node, Constants::END);
    }

    /**
     * Turn the builder into a runnable graph.
     *
     * Port of `StateGraph.compile`.
     *
     * Order within a node's writer chain is the whole contract:
     *
     *  1. **write-back** — publish the node's return value to the state;
     *  2. **the hidden `SELF` branch** — honour a `Command`'s `goto`;
     *  3. **declared edges** — fire the unconditional edges.
     *
     * A node that returns both state and a `goto` therefore does both, and a
     * node that returns only state just updates. The write-back comes first
     * because a `Command` carries its update *and* its routing, and the update
     * must land before anything downstream is scheduled to read it.
     *
     * @param array{checkpointer?: \LangGraph\Pregel\Checkpoint\BaseCheckpointSaver|bool, interruptBefore?: list<string>, interruptAfter?: list<string>, name?: string, description?: string, streamMode?: list<string>, retryPolicy?: \LangGraph\Pregel\Retry\RetryPolicy} $options
     */
    public function compile(array $options = []): CompiledStateGraph
    {
        $stateChannels = $this->schema->spec;
        $outputKeys = array_keys($stateChannels);

        $checkpointer = $options['checkpointer'] ?? null;
        $checkpointerDisabled = $checkpointer === false;
        if ($checkpointer === true) {
            $checkpointer = new \LangGraph\Pregel\Checkpoint\MemorySaver();
        } elseif ($checkpointer === false) {
            $checkpointer = null;
        }

        $channels = $stateChannels;
        $triggerToNodes = [];
        $compiledNodes = [];

        // One routing channel per node, named `branch:to:<node>`.
        //
        // Every way of reaching a node — an edge, a conditional edge, a
        // `Command`'s `goto` — writes to this one channel, and the node
        // subscribes to it. That uniformity is what makes routing composable:
        // a destination cannot tell (or need to know) whether it was reached by
        // a declared edge or a dynamic instruction, and the engine has exactly
        // one kind of thing to schedule.
        //
        // `guard: false` because a node's routing channel legitimately receives
        // several writes in one superstep: two `Send`s to the same node in one
        // step is the map-reduce pattern, not an error. The guard exists to
        // catch ambiguous *state* writes, and a routing channel holds no state.
        foreach ($this->nodeNames() as $key) {
            $channels[self::branchTo($key)] = new EphemeralValue(guard: false);
        }

        // Ordinary nodes: read every state key, write their return value back.
        foreach ($this->nodes as $rawKey => $spec) {
            $key = (string) $rawKey;
            $branchChannel = self::branchTo($key);

            $compiledNodes[$key] = new PregelNode(
                triggers: [$branchChannel],
                // Reading state is a *map* of state key -> channel. The map
                // shape is what lets `_procInput` distinguish a trigger channel
                // (empty means "not scheduled") from an ordinary one (empty
                // means "absent from this input").
                channels: array_combine($outputKeys, $outputKeys),
                bound: $spec->bound,
                metadata: $spec->metadata,
                tags: $spec->tags,
                retryPolicy: $spec->retryPolicy,
                cachePolicy: $spec->cachePolicy,
                ends: $spec->ends,
                errorHandlerNode: $spec->errorHandlerNode,
                writers: [new ChannelWrite(
                    [[
                        'value' => ChannelWrite::passthrough(),
                        'mapper' => $this->makeStateWriter($key, $outputKeys),
                    ]],
                    [Constants::TAG_HIDDEN],
                )],
            );

            $triggerToNodes[$branchChannel] = [$key];
        }

        // The start node publishes the run's input to the state channels and is
        // triggered by `__start__` itself, which the loop writes the input to.
        $startNode = new PregelNode(
            tags: [Constants::TAG_HIDDEN],
            triggers: [Constants::START],
            channels: [Constants::START],
            writers: [new ChannelWrite(
                [[
                    'value' => ChannelWrite::passthrough(),
                    'mapper' => $this->makeStateWriter(Constants::START, $outputKeys),
                ]],
                [Constants::TAG_HIDDEN],
            )],
        );
        $channels[Constants::START] = new EphemeralValue();
        $triggerToNodes[Constants::START] = [Constants::START];

        // Unconditional edges become a hidden write to the destination's routing
        // channel, appended to the source's writers. Appending matters: routing
        // must happen *after* the source's state update, so the destination
        // reads a state that already includes the source's contribution.
        foreach ($this->edges as [$rawStart, $rawEnd]) {
            $start = (string) $rawStart;
            $end = (string) $rawEnd;
            if ($end === Constants::END) {
                continue;
            }
            $writer = new ChannelWrite(
                [['channel' => self::branchTo($end), 'value' => $start]],
                [Constants::TAG_HIDDEN],
            );

            if ($start === Constants::START) {
                $startNode->writers[] = $writer;
            } else {
                $compiledNodes[$start]->writers[] = $writer;
            }
        }

        // Conditional edges: evaluate a path at run time and write to whichever
        // destinations it names. Same target channel as an edge — which is the
        // point: routing is a write, and there is only one kind of write.
        foreach ($this->branches as $rawStart => $branches) {
            $start = (string) $rawStart;
            foreach ($branches as $name => $path) {
                $writer = $this->makeBranchWriter($path, $start === Constants::START, $start);
                if ($start === Constants::START) {
                    $startNode->writers[] = $writer;
                } else {
                    $compiledNodes[$start]->writers[] = $writer;
                }
            }
        }

        // The hidden SELF branch on every node: lets a `Command` returned by a
        // node route with no declared edge. Appended last so a node's own
        // declared routing has already fired.
        foreach ($this->nodeNames() as $key) {
            $compiledNodes[$key]->writers[] = $this->makeBranchWriter($this->controlBranch(...), false, $key);
        }

        $compiledNodes[Constants::START] = $startNode;

        $outputChannels = count($outputKeys) === 1 && $outputKeys[0] === self::ROOT
            ? self::ROOT
            : $outputKeys;

        return new CompiledStateGraph(
            nodes: $compiledNodes,
            channels: $channels,
            // The run's input lands on `__start__`, not on the state channels
            // directly. That is what makes the start node a node: it is
            // triggered by `__start__` advancing, and its write-back is what
            // seeds the state. Writing straight to the state channels would
            // skip the routing step entirely, so a graph with an edge out of
            // `__start__` would never reach its first node.
            inputChannels: Constants::START,
            outputChannels: $outputChannels,
            streamChannels: $outputChannels,
            checkpointer: $checkpointer,
            interruptBefore: $options['interruptBefore'] ?? [],
            interruptAfter: $options['interruptAfter'] ?? [],
            name: $options['name'] ?? null,
            description: $options['description'] ?? null,
            streamMode: $options['streamMode'] ?? ['updates', 'values'],
            retryPolicy: $options['retryPolicy'] ?? null,
            triggerToNodes: $triggerToNodes,
            builder: $this,
            checkpointerDisabled: $checkpointerDisabled,
        );
    }

    /**
     * Every node name, as strings.
     *
     * PHP coerces a numeric string array key to an int, so a node named `'1'`
     * comes back out of the map as `1`. Node names are compared against
     * `Send::node` and `Command::goto` as strings, so they are normalised here
     * once rather than being cast at every comparison.
     *
     * @return list<string>
     */
    private function nodeNames(): array
    {
        return array_map(strval(...), array_keys($this->nodes));
    }

    /** The routing channel for a node. */
    public static function branchTo(string $node): string
    {
        return 'branch:to:' . $node;
    }

    /**
     * The mapper that turns a node's return value into `[channel, value]` pairs.
     *
     * Port of `_getUpdates` / `_getRoot`.
     *
     * Three return shapes are accepted, and which one is allowed depends on the
     * graph:
     *
     *  - **An object** — the normal case. Its keys are state channels; keys not
     *    in the schema are dropped, because the schema *is* the state and an
     *    unrecognised key is a typo that should not become state.
     *  - **A `Command`** — its `update` is folded in, and its `goto` handled by
     *    the self branch. A `Command` addressed to the parent graph contributes
     *    nothing here; the parent handles it.
     *  - **A list containing a `Command`** — the "fan-in" shape, where several
     *    tasks return independently and the graph merges them. Non-`Command`
     *    entries become whole-value updates.
     *
     * Anything else throws. A node returning a bare scalar in a schema-shaped
     * graph is a mistake worth surfacing: it would otherwise be written to a
     * channel the user did not name, and the graph would appear to work.
     *
     * The `__start__` node uses the same mapper as every other node. It differs
     * only in what it *reads* — the raw run input, from the `__start__` channel —
     * so mapping its value onto the state channels is the identical operation.
     *
     * @param list<string> $outputKeys
     */
    private function makeStateWriter(string $nodeKey, array $outputKeys): RunnableLambda
    {
        return new RunnableLambda(function (mixed $input) use ($nodeKey, $outputKeys): ?array {
            return $this->getUpdates($input, $nodeKey, $outputKeys);
        });
    }

    /**
     * Write-back for an ordinary node.
     *
     * @param  list<string> $outputKeys
     * @return list<array{0: string, 1: mixed}>|null
     */
    private function getUpdates(mixed $input, string $nodeKey, array $outputKeys): ?array
    {
        if ($input === null || $input === []) {
            return null;
        }

        if (Command::isCommand($input)) {
            $command = Command::fromMixed($input);
            if ($command === null || $command->graph === Command::PARENT) {
                return null;
            }

            return array_values(array_filter(
                $command->updateAsTuples(),
                static fn (array $t): bool => in_array($t[0], $outputKeys, true)
            ));
        }

        if (is_array($input) && array_filter($input, static fn ($i): bool => Command::isCommand($i)) !== []) {
            $updates = [];
            foreach ($input as $item) {
                if (Command::isCommand($item)) {
                    $command = Command::fromMixed($item);
                    if ($command === null || $command->graph === Command::PARENT) {
                        continue;
                    }
                    foreach ($command->updateAsTuples() as $tuple) {
                        if (in_array($tuple[0], $outputKeys, true)) {
                            $updates[] = $tuple;
                        }
                    }
                } else {
                    foreach ($this->getUpdates($item, $nodeKey, $outputKeys) ?? [] as $tuple) {
                        $updates[] = $tuple;
                    }
                }
            }

            return $updates;
        }

        if (is_array($input) && !array_is_list($input)) {
            $updates = [];
            foreach ($input as $key => $value) {
                if (in_array((string) $key, $outputKeys, true)) {
                    $updates[] = [(string) $key, $value];
                }
            }

            return $updates;
        }

        $typeOf = is_array($input) ? 'array' : get_debug_type($input);
        throw new InvalidUpdateError(
            'Expected node "' . $nodeKey . '" to return an object or an array containing '
            . 'at least one Command object, received ' . $typeOf,
            ['lc_error_code' => 'INVALID_GRAPH_NODE_RETURN_VALUE']
        );
    }

    /**
     * Read a returned value's destinations out of any `Command`s it contains.
     *
     * Port of `_controlBranch`. A `Command` addressed to the parent graph raises
     * a {@see \LangGraph\Errors\ParentCommand} rather than being dropped, so
     * the retry layer can re-address it upward instead of the graph quietly
     * ignoring an instruction.
     *
     * @return list<string|Send>
     */
    private function controlBranch(mixed $value): array
    {
        if ($value instanceof Send) {
            return [$value];
        }

        $commands = [];
        if (Command::isCommand($value)) {
            $commands[] = Command::fromMixed($value);
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                if (Command::isCommand($item)) {
                    $commands[] = Command::fromMixed($item);
                }
            }
        }

        $destinations = [];
        foreach ($commands as $command) {
            if ($command === null) {
                continue;
            }
            if ($command->graph === Command::PARENT) {
                throw new \LangGraph\Errors\ParentCommand($command);
            }
            foreach ($command->gotoList() as $target) {
                $destinations[] = $target;
            }
        }

        return $destinations;
    }

    /**
     * Wrap a branch callable as a writer on its source node.
     */
    private function makeBranchWriter(callable|RunnableInterface $path, bool $isStart = false, ?string $start = null): \LangGraph\Pregel\RunnableBranchWriter
    {
        return new \LangGraph\Pregel\RunnableBranchWriter(
            path: $path,
            isStart: $isStart,
            start: $start,
        );
    }
}
