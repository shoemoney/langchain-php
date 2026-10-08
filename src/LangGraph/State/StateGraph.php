<?php

declare(strict_types=1);

namespace LangGraph\State;

use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableInterface;
use LangChain\Runnables\RunnableLambda;
use LangGraph\Channels\BaseChannel;
use LangGraph\Channels\EphemeralValue;
use LangGraph\Channels\LastValue;
use LangGraph\Channels\LastValueAfterFinish;
use LangGraph\Channels\NamedBarrierValue;
use LangGraph\Errors\Guard;
use LangGraph\Errors\InvalidUpdateError;
use LangGraph\Errors\NodeError;
use LangGraph\Graph\Branch;
use LangGraph\Graph\Graph;
use LangGraph\Pregel\ChannelWrite;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\CompiledStateGraph;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\PregelNode;
use LangGraph\Pregel\Retry\RetryPolicy;
use LangGraph\Pregel\Send;
use LangGraph\Pregel\Utils\Subgraph;
use LangGraph\Utils\RunnableCallable;

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
 * ## Schemas
 *
 * A graph has up to three schemas: the **state** schema (every channel), an **input** schema (what
 * `invoke()` accepts) and an **output** schema (what `invoke()` returns). Each is an
 * {@see AnnotationRoot}, a channel map, or a JSON Schema object (`type: object` with `properties`;
 * each property becomes a last-value channel). The graph's channels are the union of all of them.
 * A node may also name its own `input` schema, narrowing what it reads.
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
 *
 * ## Node policies and error handlers
 *
 * `addNode` takes `retryPolicy`, `cachePolicy`, `timeout` and `errorHandler`; {@see self::setNodeDefaults()}
 * sets graph-wide defaults that apply at compile time to every node without its own value (a node's
 * own value always wins, and `cachePolicy: false` opts a node out of a default).
 *
 * An error handler runs only after the node's retry policy is exhausted, and never for a
 * {@see \LangGraph\Errors\GraphBubbleUp} (interrupts and parent commands are control flow, not
 * failures). **Divergence:** upstream schedules the handler as a separate checkpointed task; this
 * engine has no such scheduling, so the compiled node runs its retries and then its handler inline
 * (see {@see self::withErrorHandler()}). The hidden `__error_handler__<node>` nodes are still
 * registered, so the graph's shape matches upstream's.
 */
class StateGraph extends Graph
{
    public const SELF = Constants::SELF;

    /** The sentinel channel a whole-value return is written to. */
    public const ROOT = '__root__';

    /** Reserved node name of the single shared handler created by `setNodeDefaults(['errorHandler' => ...])`. */
    public const DEFAULT_ERROR_HANDLER_NODE = '__default_error_handler__';

    /** The channels of the whole graph: the union of the state, input and output schemas. */
    public array $channels = [];

    /**
     * Joins registered with `addEdge([a, b], c)`: `c` waits for every start.
     *
     * @var list<array{0: list<string>, 1: string}>
     */
    public array $waitingEdges = [];

    /** @var array<string, BaseChannel> */
    public array $schemaDefinition = [];

    /** @var array<string, BaseChannel> */
    public array $inputDefinition = [];

    /** @var array<string, BaseChannel> */
    public array $outputDefinition = [];

    /** The context schema channels, when one was given. */
    public ?array $contextSchema = null;

    /**
     * Per-node extras a {@see PregelNode} cannot hold.
     *
     * @var array<string, StateGraphNodeSpec>
     */
    public array $nodeSpecs = [];

    /**
     * Graph-wide defaults captured by {@see self::setNodeDefaults()}.
     *
     * @var array{retryPolicy?: RetryPolicy, cachePolicy?: array<string, mixed>, timeout?: mixed, errorHandler?: callable}
     */
    public array $nodeDefaults = [];

    protected AnnotationRoot $schema;

    /**
     * Upstream's accepted constructor shapes, as PHP arrays:
     *
     *  - `new StateGraph($annotationRoot)` or a channel map (`['key' => 'int']`, `['key' => null]`,
     *    `['key' => ['reducer' => $op]]`);
     *  - `new StateGraph($state, ['input' => $in, 'output' => $out, 'context' => $ctx])`;
     *  - `new StateGraph(['stateSchema' => $s, 'input' => $in, 'output' => $out])` (also `state`),
     *    or `['input' => $in, 'output' => $out]` alone, in which case `input` is the state;
     *  - `new StateGraph($state, $contextAnnotationRoot)`;
     *  - the deprecated `['channels' => ['key' => ['reducer' => $op, 'default' => $fn]]]`.
     *
     * @param AnnotationRoot|array<string, mixed>      $schema
     * @param AnnotationRoot|array<string, mixed>|null $options
     */
    public function __construct(AnnotationRoot|array $schema, AnnotationRoot|array|null $options = null)
    {
        [$state, $input, $output, $context] = self::normalizeInit($schema, $options);

        if ($state === null) {
            throw new StateGraphInputError();
        }

        $this->schemaDefinition = self::channelsFromSchema($state);
        $this->inputDefinition = $input !== null ? self::channelsFromSchema($input) : $this->schemaDefinition;
        $this->outputDefinition = $output !== null ? self::channelsFromSchema($output) : $this->schemaDefinition;
        $this->contextSchema = $context !== null ? self::channelsFromSchema($context) : null;

        $this->addSchema($this->schemaDefinition);
        $this->addSchema($this->inputDefinition);
        $this->addSchema($this->outputDefinition);

        // Assigned on the PROPERTY: with constructor promotion the parameter would be a local copy.
        $this->schema = new AnnotationRoot($this->schemaDefinition);
    }

    /**
     * Sort the constructor's arguments into state / input / output / context.
     *
     * @param AnnotationRoot|array<string, mixed>      $schema
     * @param AnnotationRoot|array<string, mixed>|null $options
     *
     * @return array{0: mixed, 1: mixed, 2: mixed, 3: mixed}
     */
    private static function normalizeInit(AnnotationRoot|array $schema, AnnotationRoot|array|null $options): array
    {
        $opts = is_array($options) && self::isOptionsArray($options) ? $options : [];
        $context = $options instanceof AnnotationRoot ? $options : ($opts['context'] ?? null);

        if (is_array($schema) && self::isLegacyChannelsArgs($schema)) {
            return [Annotation::root($schema['channels'])->spec, null, null, $context];
        }

        if (is_array($schema) && self::isInitArray($schema)) {
            $state = $schema['state'] ?? $schema['stateSchema'] ?? $schema['input'] ?? null;

            return [
                $state,
                $schema['input'] ?? $opts['input'] ?? null,
                $schema['output'] ?? $opts['output'] ?? null,
                $schema['context'] ?? $context,
            ];
        }

        return [$schema, $opts['input'] ?? null, $opts['output'] ?? null, $context];
    }

    /** Whether an array is a `StateGraphInit` (`state`/`stateSchema`/`input`/`output`/`context`) rather than a channel map. */
    private static function isInitArray(array $value): bool
    {
        $reserved = ['state', 'stateSchema', 'input', 'output', 'context'];
        if ($value === [] || array_diff(array_keys($value), $reserved) !== []) {
            return false;
        }
        foreach ($value as $v) {
            if (!AnnotationRoot::isInstance($v) && !self::isJsonSchema($v) && !self::isChannelMap($v)) {
                return false;
            }
        }

        return true;
    }

    private static function isLegacyChannelsArgs(array $value): bool
    {
        return array_keys($value) === ['channels'] && is_array($value['channels']);
    }

    /** Whether the second constructor argument is an options array rather than a context channel map. */
    private static function isOptionsArray(array $value): bool
    {
        return $value !== [] && array_diff(array_keys($value), ['input', 'output', 'context', 'interrupt', 'writer', 'nodes']) === [];
    }

    private static function isChannelMap(mixed $value): bool
    {
        if (!is_array($value) || $value === []) {
            return false;
        }
        foreach ($value as $channel) {
            if (!$channel instanceof BaseChannel) {
                return false;
            }
        }

        return true;
    }

    /** A JSON Schema object: `type: object` with a `properties` map. */
    private static function isJsonSchema(mixed $value): bool
    {
        return is_array($value)
            && ($value['type'] ?? null) === 'object'
            && isset($value['properties'])
            && is_array($value['properties']);
    }

    /**
     * The channels a schema declares.
     *
     * Port of `_getChannelsFromSchema`. A JSON Schema's properties each become a last-value channel,
     * because JSON Schema has no notion of a reducer; use an {@see AnnotationRoot} for one.
     *
     * @return array<string, BaseChannel>
     */
    private static function channelsFromSchema(mixed $schema): array
    {
        if (AnnotationRoot::isInstance($schema)) {
            return $schema->spec;
        }

        if (self::isJsonSchema($schema)) {
            $channels = [];
            foreach (array_keys($schema['properties']) as $property) {
                $channels[(string) $property] = new LastValue();
            }

            return $channels;
        }

        if (is_array($schema) && $schema !== [] && !array_is_list($schema)) {
            return Annotation::root($schema)->spec;
        }

        throw new StateGraphInputError();
    }

    /**
     * Register a schema's channels in the graph.
     *
     * Port of `_addSchema`. Two schemas may share a key only if their channels are equal; the one
     * exception is a plain last-value channel, which yields to whichever channel got there first
     * (an input schema that lists a key as plain does not override the state's reducer for it).
     *
     * @param array<string, BaseChannel> $definition
     */
    private function addSchema(array $definition): void
    {
        foreach ($definition as $key => $channel) {
            $key = (string) $key;
            if (!isset($this->channels[$key])) {
                $this->channels[$key] = $channel;
                continue;
            }
            if (!$this->channels[$key]->equals($channel) && $channel->lcGraphName !== 'LastValue') {
                throw new \InvalidArgumentException('Channel "' . $key . '" already exists with a different type.');
            }
        }
    }

    /**
     * Set graph-wide node defaults, applied at compile time to every node lacking its own value.
     *
     * Port of `setNodeDefaults`. May be called before or after `addNode`; repeated calls merge,
     * the later value winning per field. `cachePolicy: true` means "cache with default settings"
     * (`[]`) and `false` means "no default cache policy".
     *
     * `errorHandler` is a shared handler for every regular node without its own. It is never applied to
     * an error handler itself, and does not reach into subgraphs (a compiled subgraph keeps whatever
     * it was compiled with).
     *
     * @param array{retryPolicy?: RetryPolicy, cachePolicy?: array<string, mixed>|bool, timeout?: mixed, errorHandler?: callable} $defaults
     */
    public function setNodeDefaults(array $defaults): static
    {
        if (($defaults['retryPolicy'] ?? null) !== null) {
            $this->nodeDefaults['retryPolicy'] = $defaults['retryPolicy'];
        }
        if (array_key_exists('cachePolicy', $defaults) && $defaults['cachePolicy'] !== null) {
            $cache = $defaults['cachePolicy'];
            if ($cache === true) {
                $this->nodeDefaults['cachePolicy'] = [];
            } elseif ($cache === false) {
                unset($this->nodeDefaults['cachePolicy']);
            } else {
                $this->nodeDefaults['cachePolicy'] = $cache;
            }
        }
        if (($defaults['timeout'] ?? null) !== null) {
            $this->nodeDefaults['timeout'] = $defaults['timeout'];
        }
        if (($defaults['errorHandler'] ?? null) !== null) {
            $this->nodeDefaults['errorHandler'] = $defaults['errorHandler'];
        }

        return $this;
    }

    protected function allowsMultipleEdges(): bool
    {
        return true;
    }

    protected function reservedNodeNames(): array
    {
        return [Constants::END, Constants::START];
    }

    protected function assertNodeKeyIsValid(string $key): void
    {
        if (array_key_exists($key, $this->channels)) {
            throw new \InvalidArgumentException(
                $key . ' is already being used as a state attribute (a.k.a. a channel), '
                . 'cannot also be used as a node name.'
            );
        }
    }

    /**
     * Every edge, with a join `[a, b] -> c` expanded to `a -> c` and `b -> c`.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function allEdges(): array
    {
        $edges = $this->edges;
        foreach ($this->waitingEdges as [$starts, $end]) {
            foreach ($starts as $start) {
                $edges[] = [$start, $end];
            }
        }

        return $edges;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected function buildNode(RunnableInterface $runnable, array $options): PregelNode
    {
        $cache = $options['cachePolicy'] ?? null;

        return new PregelNode(
            bound: $runnable,
            metadata: $options['metadata'] ?? [],
            tags: $options['tags'] ?? [],
            retryPolicy: $options['retryPolicy'] ?? null,
            cachePolicy: $cache === true ? [] : (is_array($cache) ? $cache : null),
            timeout: $options['timeout'] ?? null,
            subgraphs: Subgraph::isPregelLike($runnable) ? [$runnable] : ($options['subgraphs'] ?? []),
            ends: $options['ends'] ?? [],
        );
    }

    /**
     * Add one node, or several.
     *
     * Port of `StateGraph.addNode`. Beyond {@see Graph::addNode()}'s `metadata`, `subgraphs` and
     * `ends`, the options are:
     *
     *  - `input` — this node's own input schema (what it reads from the state);
     *  - `retryPolicy` — a {@see RetryPolicy};
     *  - `cachePolicy` — an array of cache settings, `true` for the defaults, or `false` to opt out of
     *    a {@see self::setNodeDefaults()} cache policy;
     *  - `timeout` — recorded on the node (this engine does not enforce it yet);
     *  - `defer` — run only when the rest of the graph has finished;
     *  - `errorHandler` — `fn(mixed $state, NodeError $error, ?RunnableConfig $config)`, run after the
     *    retry policy is exhausted; it may return a state update or a {@see Command}.
     *
     * @param string|array<array-key, mixed>  $key
     * @param callable|RunnableInterface|null $action
     * @param array<string, mixed>            $options
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

            $handlerName = null;
            if (($nodeOptions['errorHandler'] ?? null) !== null) {
                $handlerName = '__error_handler__' . $name;
                if (isset($this->nodes[$handlerName])) {
                    throw new \InvalidArgumentException(
                        'Cannot add error handler to node `' . $name . '`: the reserved name `' . $handlerName
                        . '` is already in use. StateGraph registers `__error_handler__<nodeName>` when you pass '
                        . '`errorHandler` in addNode options. Remove or rename the existing node with that name '
                        . '(for example, you may have added it manually).'
                    );
                }
            }

            parent::addNode($name, $body, $nodeOptions);

            $inputSpec = isset($nodeOptions['input'])
                ? self::channelsFromSchema($nodeOptions['input'])
                : $this->schemaDefinition;
            $this->addSchema($inputSpec);

            $cache = $nodeOptions['cachePolicy'] ?? null;
            $this->nodeSpecs[$name] = new StateGraphNodeSpec(
                input: $inputSpec,
                cachePolicy: $cache === true ? [] : ($cache === false ? false : (is_array($cache) ? $cache : null)),
                timeout: $nodeOptions['timeout'] ?? null,
                defer: (bool) ($nodeOptions['defer'] ?? false),
                errorHandler: $nodeOptions['errorHandler'] ?? null,
            );

            if ($handlerName !== null) {
                $this->nodes[$name]->errorHandlerNode = $handlerName;
                $this->nodes[$handlerName] = new PregelNode(
                    bound: self::errorHandlerRunnable($nodeOptions['errorHandler'], $handlerName),
                    isErrorHandler: true,
                );
                $this->nodeSpecs[$handlerName] = new StateGraphNodeSpec(input: $inputSpec, isErrorHandler: true);
            }
        }

        return $this;
    }

    /**
     * The runnable behind an error-handler node: it reads the {@see NodeError} the engine injects into
     * the config and hands it to the user's handler.
     */
    private static function errorHandlerRunnable(callable $handler, string $name): RunnableInterface
    {
        return new RunnableCallable(
            func: static function (mixed $state, RunnableConfig $config) use ($handler): mixed {
                $nodeError = $config->configurable[Constants::CONFIG_KEY_NODE_ERROR] ?? null;

                return $handler($state, $nodeError, $config);
            },
            name: $name,
            trace: false,
            recurse: false,
        );
    }

    /**
     * Add several nodes at once.
     *
     * @param array<string, callable|RunnableInterface> $actions
     */
    public function addNodes(array $actions): static
    {
        foreach ($actions as $key => $action) {
            $this->addNode((string) $key, $action);
        }

        return $this;
    }

    /**
     * Add nodes and chain them with edges, in order: `a -> b -> c`.
     *
     * Port of `addSequence`. Accepts a list of `[name, action, options?]` tuples or a `name => action`
     * map. Every name must be new.
     *
     * @param array<array-key, mixed> $nodes
     */
    public function addSequence(array $nodes): static
    {
        $parsed = [];
        if (array_is_list($nodes)) {
            foreach ($nodes as $tuple) {
                $parsed[] = [(string) $tuple[0], $tuple[1], $tuple[2] ?? []];
            }
        } else {
            foreach ($nodes as $name => $action) {
                $parsed[] = [(string) $name, $action, []];
            }
        }

        if ($parsed === []) {
            throw new \InvalidArgumentException('Sequence requires at least one node.');
        }

        $previous = null;
        foreach ($parsed as [$name, $action, $options]) {
            if (isset($this->nodes[$name])) {
                throw new \InvalidArgumentException(
                    'Node names must be unique: node with the name "' . $name . '" already exists.'
                );
            }

            $this->addNode($name, $action, $options);
            if ($previous !== null) {
                $this->addEdge($previous, $name);
            }
            $previous = $name;
        }

        return $this;
    }

    /**
     * Connect two nodes unconditionally, or join several into one.
     *
     * Port of `addEdge`. A plain edge is the cheapest routing there is: the
     * target subscribes to the source's channel, so completing the source
     * *is* the trigger. No runnable runs at routing time.
     *
     * An edge to `__end__` becomes a write to the reserved `__end__` channel
     * instead, because Pregel has no stop node — reaching the end is an event
     * that the loop reads, not an edge.
     *
     * Given a list of starts, the end node waits until **every** start has finished (a join). `END`
     * may be neither a start nor the end of a join.
     *
     * @param string|list<string> $start
     */
    public function addEdge(string|array $start, string $end): static
    {
        if (is_array($start)) {
            $this->warnIfCompiled(
                'Adding an edge to a graph that has already been compiled. This will not be reflected in the compiled graph.'
            );

            foreach ($start as $from) {
                if ($from === Constants::END) {
                    throw new \InvalidArgumentException('END cannot be a start node');
                }
                if (!isset($this->nodes[$from])) {
                    throw new \InvalidArgumentException('Need to add a node named "' . $from . '" first');
                }
            }
            if ($end === Constants::END) {
                throw new \InvalidArgumentException('END cannot be an end node');
            }
            if (!isset($this->nodes[$end])) {
                throw new \InvalidArgumentException('Need to add a node named "' . $end . '" first');
            }

            $this->waitingEdges[] = [array_values(array_map(strval(...), $start)), $end];

            return $this;
        }

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
        } elseif (!isset($this->nodes[$end])) {
            throw new \InvalidArgumentException('Node `' . $end . '` not found');
        }

        return parent::addEdge($start, $end);
    }

    /**
     * Conditional edges are keyed by the first `pathMap` value (or `SELF` without one), so two edges
     * from one node stay distinct when they declare different maps.
     */
    protected function branchName(Branch $branch, ?array $pathMap): string
    {
        return $pathMap === null ? Constants::SELF : (string) (array_values($pathMap)[0] ?? Constants::SELF);
    }

    /** The channels this graph's state is made of. */
    public function channels(): array
    {
        return $this->channels;
    }

    /**
     * Route to one of several nodes based on state.
     *
     * Port of `addConditionalEdges`. `$path` is a runnable or callable from the
     * source node's output to a destination name, a list of names, or a list of
     * {@see Send} packets. Returning `null` or `[]` routes nowhere, which is how
     * a node declines to continue — a normal outcome, not an error.
     *
     * @param array<string, string>|list<string>|null $pathMap Maps the path's answers to destinations; also names the branch.
     */
    public function addConditionalEdges(
        string|array $source,
        callable|RunnableInterface|null $path = null,
        ?array $pathMap = null,
    ): static {
        $start = is_array($source) ? (string) $source['source'] : $source;

        // START is permitted here exactly as addEdge() permits it, and exactly as
        // upstream's own fixtures use it — `addConditionalEdges(START, fanOut,
        // ["review"])` appears in langgraph-js' multi-interrupt-graph.ts:48 and
        // mock-server.ts:540. Guarding on `$this->nodes` alone rejected a call
        // pattern upstream exercises, while the sibling addEdge() accepted it, so
        // the two edge-adding methods disagreed about START.
        if ($start !== Constants::START && !isset($this->nodes[$start])) {
            throw new \InvalidArgumentException('Node `' . $start . '` not found');
        }

        // Upstream REFUSES a duplicate condition name rather than overwriting it
        // (langgraph-core/src/graph/graph.ts:488-495); {@see Graph::addConditionalEdges()}
        // does the refusing, at registration time, which is the only moment the caller can
        // still do anything about it.
        return parent::addConditionalEdges($source, $path, $pathMap);
    }

    /**
     * Check the graph is well formed (unknown or unreachable nodes, bad interrupt names) and mark it
     * compiled.
     *
     * {@see Graph::validate()} does the work over {@see self::allEdges()}, which includes joins; this
     * override adds the check that every join still names registered nodes, since `$waitingEdges` is
     * public and a join can be edited after `addEdge()` checked it.
     *
     * @param list<string>|null $interrupt
     */
    public function validate(?array $interrupt = null): void
    {
        foreach ($this->waitingEdges as [$starts, $end]) {
            foreach ([...$starts, $end] as $name) {
                if (!isset($this->nodes[$name])) {
                    throw new \InvalidArgumentException('Found edge ending at unknown node `' . $name . '`');
                }
            }
        }

        parent::validate($interrupt);
    }

    /**
     * Turn the builder into a runnable graph.
     *
     * Port of `StateGraph.compile`, which resolves {@see self::setNodeDefaults()} and, when a default
     * error handler is set, materialises the shared `__default_error_handler__` node for the duration
     * of the compile (the builder is left as it was).
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
     * @param array{checkpointer?: \LangGraph\Pregel\Checkpoint\BaseCheckpointSaver|bool, interruptBefore?: list<string>, interruptAfter?: list<string>, name?: string, description?: string, streamMode?: list<string>, retryPolicy?: \LangGraph\Pregel\Retry\RetryPolicy, store?: \LangGraph\Store\BaseStore|null, cache?: \LangGraph\Cache\BaseCache|null} $options
     */
    public function compile(array $options = []): CompiledStateGraph
    {
        $defaultHandler = $this->nodeDefaults['errorHandler'] ?? null;
        $defaultName = null;

        if ($defaultHandler !== null) {
            if (isset($this->nodes[self::DEFAULT_ERROR_HANDLER_NODE])) {
                throw new \InvalidArgumentException(
                    'Cannot apply a default error handler: the reserved node name `' . self::DEFAULT_ERROR_HANDLER_NODE
                    . '` is already in use. setNodeDefaults([\'errorHandler\' => ...]) registers a node with that name; '
                    . 'rename the conflicting node.'
                );
            }
            $defaultName = self::DEFAULT_ERROR_HANDLER_NODE;
            $this->nodes[$defaultName] = new PregelNode(
                bound: self::errorHandlerRunnable($defaultHandler, $defaultName),
                isErrorHandler: true,
            );
            $this->nodeSpecs[$defaultName] = new StateGraphNodeSpec(input: $this->schemaDefinition, isErrorHandler: true);
        }

        try {
            return $this->compileResolved($options, $defaultName);
        } finally {
            if ($defaultName !== null) {
                unset($this->nodes[$defaultName], $this->nodeSpecs[$defaultName]);
            }
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function compileResolved(array $options, ?string $defaultErrorHandlerNode): CompiledStateGraph
    {
        $interruptBefore = $options['interruptBefore'] ?? [];
        $interruptAfter = $options['interruptAfter'] ?? [];
        // Upstream runs the full `validate()` here, which also throws for an unreachable node. This port
        // does not: `PregelLoopTest::testGraphWithNoReachableNodeReturnsEmptyState` pins that a graph
        // whose only node has no edge compiles and returns its input. Call `validate()` explicitly to
        // get the reachability check; compile still rejects an interrupt naming an unknown node.
        foreach ([...(is_array($interruptBefore) ? $interruptBefore : []), ...(is_array($interruptAfter) ? $interruptAfter : [])] as $node) {
            if (!isset($this->nodes[$node])) {
                throw new \InvalidArgumentException('Interrupt node `' . $node . '` is not present');
            }
        }
        $this->compiled = true;

        $stateKeys = array_map(strval(...), array_keys($this->channels));
        $outputKeys = array_map(strval(...), array_keys($this->outputDefinition));
        $inputKeys = array_map(strval(...), array_keys($this->inputDefinition));

        $checkpointer = $options['checkpointer'] ?? null;
        $checkpointerDisabled = $checkpointer === false;
        if ($checkpointer === true) {
            $checkpointer = new \LangGraph\Pregel\Checkpoint\MemorySaver();
        } elseif ($checkpointer === false) {
            $checkpointer = null;
        }

        $channels = $this->channels;
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
        // A deferred node waits for the rest of the graph to finish instead.
        foreach ($this->nodeNames() as $key) {
            $channels[self::branchTo($key)] = ($this->nodeSpecs[$key]->defer ?? false)
                ? new LastValueAfterFinish()
                : new EphemeralValue(guard: false);
        }

        // Ordinary nodes: read their input channels, write their return value back.
        $defaults = $this->nodeDefaults;
        foreach ($this->nodes as $rawKey => $spec) {
            $key = (string) $rawKey;
            $nodeSpec = $this->nodeSpecs[$key];
            $branchChannel = self::branchTo($key);
            $isHandler = $nodeSpec->isErrorHandler;

            // A node's own value wins; `cachePolicy: false` opts out of the default; handlers are never cached.
            $retryPolicy = $spec->retryPolicy ?? ($defaults['retryPolicy'] ?? null);
            $cachePolicy = $isHandler
                ? null
                : ($nodeSpec->cachePolicy === false ? null : ($nodeSpec->cachePolicy ?? ($defaults['cachePolicy'] ?? null)));
            $timeout = $spec->timeout ?? $nodeSpec->timeout ?? ($defaults['timeout'] ?? null);
            $errorHandlerNode = !$isHandler && $defaultErrorHandlerNode !== null && $spec->errorHandlerNode === null
                ? $defaultErrorHandlerNode
                : $spec->errorHandlerNode;

            $bound = $spec->bound;
            $compiledRetry = $retryPolicy;
            if ($errorHandlerNode !== null && !$isHandler && $bound !== null) {
                $handlerNode = $this->nodes[$errorHandlerNode];
                $bound = $this->withErrorHandler(
                    $key,
                    $bound,
                    $retryPolicy,
                    $handlerNode->bound,
                    $handlerNode->retryPolicy ?? ($defaults['retryPolicy'] ?? null),
                );
                // The retries now run inside the wrapper, so the engine must not retry a second time.
                $compiledRetry = null;
            }

            $inputKeysOfNode = array_map(strval(...), array_keys($nodeSpec->input));
            $isSingleInput = $inputKeysOfNode === [self::ROOT];

            $compiledNodes[$key] = new PregelNode(
                triggers: [$branchChannel],
                // Reading state is a *map* of state key -> channel. The map
                // shape is what lets `_procInput` distinguish a trigger channel
                // (empty means "not scheduled") from an ordinary one (empty
                // means "absent from this input"). A whole-value (`__root__`)
                // input is read as the bare value, not a one-key map.
                channels: $isSingleInput ? [self::ROOT] : array_combine($inputKeysOfNode, $inputKeysOfNode),
                bound: $bound,
                metadata: $spec->metadata,
                tags: $spec->tags,
                retryPolicy: $compiledRetry,
                cachePolicy: $cachePolicy,
                timeout: $timeout,
                subgraphs: $spec->subgraphs,
                ends: $spec->ends,
                isErrorHandler: $isHandler,
                errorHandlerNode: $errorHandlerNode,
                writers: [new ChannelWrite(
                    [[
                        'value' => ChannelWrite::passthrough(),
                        'mapper' => $this->makeStateWriter($key, $stateKeys),
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
                    'mapper' => $this->makeStateWriter(Constants::START, $inputKeys),
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

        // A join: the end node waits on a barrier channel that every start writes its own name to.
        foreach ($this->waitingEdges as [$starts, $end]) {
            $joinChannel = 'join:' . implode('+', $starts) . ':' . $end;
            $channels[$joinChannel] = new NamedBarrierValue($starts);
            $compiledNodes[$end]->triggers[] = $joinChannel;
            $triggerToNodes[$joinChannel] = [$end];
            foreach ($starts as $start) {
                $compiledNodes[$start]->writers[] = new ChannelWrite(
                    [['channel' => $joinChannel, 'value' => $start]],
                    [Constants::TAG_HIDDEN],
                );
            }
        }

        // Conditional edges: evaluate a path at run time and write to whichever
        // destinations it names. Same target channel as an edge — which is the
        // point: routing is a write, and there is only one kind of write.
        foreach ($this->branches as $rawStart => $branches) {
            $start = (string) $rawStart;
            foreach ($branches as $name => $branch) {
                $writer = $this->makeBranchWriter($branch, $start === Constants::START, $start);
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

        $streamKeys = array_map(strval(...), array_keys($this->channels));
        $streamChannels = count($streamKeys) === 1 && $streamKeys[0] === self::ROOT ? [self::ROOT] : $streamKeys;

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
            streamChannels: $streamChannels,
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
            store: $options['store'] ?? null,
            cache: $options['cache'] ?? null,
        );
    }

    /**
     * Run a node under its retry policy, then its error handler.
     *
     * The handler runs only when the node has failed for good: after the retry policy is exhausted
     * (or declined the error). A {@see \LangGraph\Errors\GraphBubbleUp} — an interrupt, a drain, a
     * command for the parent graph — is control flow and passes straight through, so a handler can
     * never swallow an `interrupt()`. If the handler itself fails, that failure is the run's failure.
     *
     * This stands in for upstream's scheduled handler task; see the class docblock.
     */
    private function withErrorHandler(
        string $nodeName,
        RunnableInterface $bound,
        ?RetryPolicy $retryPolicy,
        ?RunnableInterface $handler,
        ?RetryPolicy $handlerRetryPolicy,
    ): RunnableInterface {
        if ($handler === null) {
            return $bound;
        }

        return new RunnableCallable(
            func: static function (mixed $input, RunnableConfig $config) use ($nodeName, $bound, $retryPolicy, $handler, $handlerRetryPolicy): mixed {
                try {
                    return self::runWithRetry(
                        static fn (RunnableConfig $c): mixed => $bound->invoke($input, $c),
                        $config,
                        $retryPolicy,
                        $nodeName,
                    );
                } catch (\Throwable $error) {
                    if (Guard::isGraphBubbleUp($error)) {
                        throw $error;
                    }

                    $handlerConfig = clone $config;
                    $handlerConfig->configurable[Constants::CONFIG_KEY_NODE_ERROR] = new NodeError($nodeName, $error);

                    return self::runWithRetry(
                        static fn (RunnableConfig $c): mixed => $handler->invoke($input, $c),
                        $handlerConfig,
                        $handlerRetryPolicy,
                        $handler->getName(),
                    );
                }
            },
            name: $bound->getName(),
            trace: false,
            recurse: false,
        );
    }

    /**
     * Call `$attempt` until it succeeds or the policy gives up, rethrowing the last error.
     *
     * The same loop the runner applies to a task (`PregelRunner::runWithRetry`): bubble-ups are never
     * retried, `maxAttempts` counts the first attempt, and a retry marks the config as resuming so a
     * subgraph continues instead of restarting.
     *
     * @param callable(RunnableConfig): mixed $attempt
     */
    private static function runWithRetry(callable $attempt, RunnableConfig $config, ?RetryPolicy $policy, string $name): mixed
    {
        $attempts = 0;

        while (true) {
            try {
                return $attempt($config);
            } catch (\Throwable $e) {
                if ($policy === null || Guard::isGraphBubbleUp($e)) {
                    throw $e;
                }

                $attempts += 1;
                if ($attempts >= $policy->effectiveMaxAttempts() || !$policy->shouldRetry($e)) {
                    throw $e;
                }

                if ($policy->shouldLogWarning()) {
                    trigger_error(
                        sprintf(
                            'Retrying task "%s" after %dms (attempt %d) after %s: %s',
                            $name,
                            $policy->intervalFor($attempts, 0.5),
                            $attempts,
                            $e::class,
                            $e->getMessage()
                        ),
                        E_USER_NOTICE
                    );
                }

                $config = clone $config;
                $config->configurable[Constants::CONFIG_KEY_RESUMING] = true;
            }
        }
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

        // A whole-value state (`MessageGraph`): the return value IS the state update.
        if ($outputKeys === [self::ROOT]) {
            return [[self::ROOT, $input]];
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
    private function makeBranchWriter(callable|RunnableInterface|Branch $path, bool $isStart = false, ?string $start = null): \LangGraph\Pregel\RunnableBranchWriter
    {
        return new \LangGraph\Pregel\RunnableBranchWriter(
            path: $path,
            isStart: $isStart,
            start: $start,
        );
    }
}
