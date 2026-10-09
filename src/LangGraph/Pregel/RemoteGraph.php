<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

use LangChain\Messages\BaseMessage;
use LangChain\Runnables\Graph\Edge;
use LangChain\Runnables\Graph\Graph;
use LangChain\Runnables\Graph\Node;
use LangChain\Runnables\Graph\RunnableIOSchema;
use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableBinding;
use LangChain\Runnables\RunnableConfig;
use LangGraph\Checkpoint\CheckpointListOptions;
use LangGraph\Errors\GraphInterrupt;
use LangGraph\Errors\RemoteException;
use LangGraph\Pregel\Utils\Config;
use LangGraph\Sdk\Client;

/**
 * A client for calling a remote graph that implements the LangGraph Server API.
 *
 * Port of `RemoteGraph` from `langgraph-core/src/pregel/remote.ts`. It behaves like a compiled graph
 * (`invoke`, `stream`, `getState`, `updateState`, `getStateHistory`, `getGraph`, `getSubgraphs`) and can
 * be used directly as a node in another graph.
 *
 * ```php
 * $remote = new RemoteGraph(graphId: 'agent', url: 'https://my.deployment', apiKey: '...');
 * $remote->invoke(['messages' => [['role' => 'human', 'content' => 'hi']]],
 *     new RunnableConfig(configurable: ['thread_id' => 't-1']));
 * ```
 *
 * ## Per-call options
 *
 * Upstream reads `streamMode`, `subgraphs`, `interruptBefore` and `interruptAfter` from the call
 * options. A PHP {@see RunnableConfig} has no such fields, so they are read from `$config->options`
 * (where `Pregel` also reads `interruptBefore`/`interruptAfter`).
 *
 * ## Chunk shape
 *
 * Unlike a local {@see Pregel}, which always yields `[mode, payload]`, `stream()` keeps upstream's four
 * shapes: `data` for one requested mode, `[mode, data]` for a list of modes, and with
 * `options['subgraphs']` `[namespace, data]` / `[namespace, mode, data]`.
 *
 * ## Why this extends RunnableBinding
 *
 * `Runnable::withConfig()` is declared to return a {@see RunnableBinding}, and upstream's
 * `RemoteGraph.withConfig()` returns a copy of the graph itself (with `config` merged). PHP cannot
 * narrow that return type to a class outside the binding hierarchy, so a RemoteGraph IS a binding whose
 * `bound` is itself. Every method that would delegate to `bound` is overridden here.
 *
 * ## Not ported
 *
 * `streamEvents` v1/v2 (upstream throws "Not implemented" too), the stream-protocol hook
 * (`CONFIG_KEY_STREAM`, which this engine never installs), and the `error`/`state`/`result` fields of a
 * task description (see {@see self::createStateSnapshot()}). The v3 event stream is
 * {@see self::streamEventsV3()}.
 */
class RemoteGraph extends RunnableBinding
{
    private const RESERVED_CONFIGURABLE_KEYS = ['callbacks', 'checkpoint_map', 'checkpoint_id', 'checkpoint_ns'];

    private const DEFAULT_RECURSION_LIMIT = 25;

    private const MAX_SANITIZE_DEPTH = 64;

    public string $graphId;

    protected Client $client;

    /** @var list<string>|string|null */
    protected array|string|null $interruptBefore;

    /** @var list<string>|string|null */
    protected array|string|null $interruptAfter;

    protected ?bool $streamResumable;

    /**
     * @param array<string, mixed>|null $config          Standing config, merged under every call's config.
     * @param array<string, string>|null $headers        Sent with every request (`url` mode only).
     * @param list<string>|string|null  $interruptBefore Node names, or `'*'`.
     * @param list<string>|string|null  $interruptAfter  Node names, or `'*'`.
     */
    public function __construct(
        string $graphId,
        ?Client $client = null,
        ?string $url = null,
        ?string $apiKey = null,
        ?array $headers = null,
        ?array $config = null,
        array|string|null $interruptBefore = null,
        array|string|null $interruptAfter = null,
        ?bool $streamResumable = null,
    ) {
        parent::__construct($this, [], $config);

        $this->graphId = $graphId;
        $this->client = $client ?? new Client(array_filter([
            'apiUrl' => $url,
            'apiKey' => $apiKey,
            'defaultHeaders' => $headers,
        ], static fn (mixed $v): bool => $v !== null));
        $this->interruptBefore = $interruptBefore;
        $this->interruptAfter = $interruptAfter;
        $this->streamResumable = $streamResumable;
    }

    public function getName(): string
    {
        return 'RemoteGraph';
    }

    /**
     * A copy of this graph whose standing config has `$config` merged over it.
     *
     * `metadata` and `configurable` merge key by key, `tags` are a de-duplicated union, and any other key
     * is replaced.
     *
     * @param array<string, mixed> $config
     */
    public function withConfig(array $config): static
    {
        $copy = clone $this;
        $copy->bound = $copy;
        $copy->config = self::mergeArrayConfigs($this->config ?? [], $config);

        return $copy;
    }

    /**
     * Run the graph to the end and return its final `values` chunk.
     */
    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        $config ??= new RunnableConfig();
        $config = $config->with(['options' => ['streamMode' => 'values'] + $config->options]);

        $last = null;
        foreach ($this->stream($input, $config) as $chunk) {
            $last = $chunk;
        }

        return $last;
    }

    public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array
    {
        return self::batchEachFor($this, $inputs, $config, $options);
    }

    /**
     * Start a run on the server and yield its chunks.
     *
     * An `updates` chunk carrying `__interrupt__` is rethrown as a {@see GraphInterrupt}; an `error` event
     * becomes a {@see RemoteException}.
     * `streamResumable` and the reconnect-by-Location-header behaviour are the SDK's.
     *
     * @return \Generator<int, mixed>
     */
    public function stream(mixed $input, ?RunnableConfig $config = null): \Generator
    {
        $merged = RunnableConfig::mergeConfigs($this->config, $config);
        $options = $merged->options;

        ['threadId' => $threadId, 'context' => $context, 'config' => $runConfig] = $this->prepareRunRequest($merged);

        $streamSubgraphs = $options['subgraphs'] ?? false;
        $interruptBefore = $options['interruptBefore'] ?? $this->interruptBefore;
        $interruptAfter = $options['interruptAfter'] ?? $this->interruptAfter;

        ['updatedStreamModes' => $streamModes, 'reqSingle' => $reqSingle, 'reqUpdates' => $reqUpdates] = self::getStreamModes($options['streamMode'] ?? null);

        $sentModes = array_values(array_unique(array_map(
            static fn (string $mode): string => $mode === 'messages' ? 'messages-tuple' : $mode,
            $streamModes,
        )));

        $payload = [
            'config' => $runConfig,
            'streamMode' => $sentModes,
            'streamSubgraphs' => (bool) $streamSubgraphs,
            'ifNotExists' => 'create',
        ];
        if (Command::isCommand($input)) {
            $payload['command'] = self::commandToJson($input);
        } else {
            $payload['input'] = self::serializeInputs($input);
        }
        if ($context !== null) {
            $payload['context'] = $context;
        }
        if ($interruptBefore !== null) {
            $payload['interruptBefore'] = $interruptBefore;
        }
        if ($interruptAfter !== null) {
            $payload['interruptAfter'] = $interruptAfter;
        }
        if (is_callable($merged->signal)) {
            $payload['signal'] = $merged->signal;
        }
        if ($this->streamResumable !== null) {
            $payload['streamResumable'] = $this->streamResumable;
        }

        $callerNamespace = $merged->configurable[Constants::CONFIG_KEY_CHECKPOINT_NS] ?? null;

        foreach ($this->client->runs->stream($threadId, $this->graphId, $payload) as $chunk) {
            $event = (string) $chunk['event'];
            $data = $chunk['data'];

            [$mode, $namespace] = self::splitEvent($event);
            if (is_string($callerNamespace)) {
                $namespace = [...explode(Constants::CHECKPOINT_NAMESPACE_SEPARATOR, $callerNamespace), ...$namespace];
            }

            if (str_starts_with($event, 'updates')) {
                if (is_array($data) && ($data[Constants::INTERRUPT] ?? null) !== null) {
                    throw new GraphInterrupt($data[Constants::INTERRUPT]);
                }
                if (!$reqUpdates) {
                    continue;
                }
            } elseif (str_starts_with($event, 'error')) {
                throw new RemoteException(is_string($data) ? $data : (string) json_encode($data), ['data' => $data]);
            }

            if (!in_array($mode, $streamModes, true)) {
                continue;
            }

            if (($options['subgraphs'] ?? false) !== false) {
                yield $reqSingle ? [$namespace, $data] : [$namespace, $mode, $data];
            } else {
                yield $reqSingle ? $data : [$mode, $data];
            }
        }
    }

    /**
     * Start a run and return a handle on its protocol events.
     *
     * Port of `streamEvents({ version: "v3" })`. Upstream drives a `ThreadStream`, which this port's
     * SDK does not have, so the run is created over REST (`runs->create`) and then followed with
     * `runs->joinStream`; see {@see RemoteRunStream}. A thread is created first when the config names
     * none. Rejects `transformers`, `control` and `interruptBefore`/`interruptAfter`, as upstream does.
     */
    public function streamEventsV3(mixed $input, ?RunnableConfig $config = null): RemoteRunStream
    {
        $merged = RunnableConfig::mergeConfigs($this->config, $config);
        $options = $merged->options;

        if (isset($options['transformers'])) {
            throw new \InvalidArgumentException('RemoteGraph.streamEvents({ version: "v3" }) does not support `transformers`.');
        }
        if (isset($options['control'])) {
            throw new \InvalidArgumentException('RemoteGraph.streamEvents({ version: "v3" }) does not support `control`.');
        }
        if (isset($options['interruptBefore']) || $this->interruptBefore !== null) {
            throw new \InvalidArgumentException('RemoteGraph.streamEvents({ version: "v3" }) does not support `interruptBefore`.');
        }
        if (isset($options['interruptAfter']) || $this->interruptAfter !== null) {
            throw new \InvalidArgumentException('RemoteGraph.streamEvents({ version: "v3" }) does not support `interruptAfter`.');
        }

        ['threadId' => $threadId, 'context' => $context, 'config' => $runConfig] = $this->prepareRunRequest($merged);
        $threadId ??= (string) ($this->client->threads->create()['thread_id'] ?? '');

        $payload = [
            'config' => $runConfig,
            'streamMode' => RemoteRunStream::STREAM_MODES,
        ];
        if (Command::isCommand($input)) {
            $payload['command'] = self::commandToJson($input);
        } else {
            $payload['input'] = self::serializeInputs($input);
        }
        if ($context !== null) {
            $payload['context'] = $context;
        }

        $run = $this->client->runs->create($threadId, $this->graphId, $payload);

        $signal = is_callable($merged->signal) ? $merged->signal : null;
        $stream = new RemoteRunStream($this->client, $threadId, $run['run_id'] ?? null, $signal);
        if ($signal !== null && $signal()) {
            $stream->abort();
        }

        return $stream;
    }

    /**
     * Update the thread's state as if `$asNode` had produced `$values`.
     *
     * @param RunnableConfig|array<string, mixed> $config
     *
     * @return RunnableConfig The config of the checkpoint the update wrote.
     */
    public function updateState(RunnableConfig|array $config, mixed $values, ?string $asNode = null): RunnableConfig
    {
        $merged = RunnableConfig::mergeConfigs($this->config, $config);

        $options = ['values' => $values];
        if ($asNode !== null) {
            $options['asNode'] = $asNode;
        }
        $checkpoint = self::getCheckpoint($merged->configurable);
        if ($checkpoint !== null) {
            $options['checkpoint'] = $checkpoint;
        }

        $response = $this->client->threads->updateState(self::requireThreadId($merged), $options);
        $written = $response['checkpoint'] ?? [];

        return new RunnableConfig(configurable: [
            'thread_id' => $written['thread_id'] ?? null,
            'checkpoint_ns' => $written['checkpoint_ns'] ?? null,
            'checkpoint_id' => $written['checkpoint_id'] ?? null,
            'checkpoint_map' => $written['checkpoint_map'] ?? [],
        ]);
    }

    /**
     * The thread's checkpoints, newest first (`limit` defaults to 10).
     *
     * Lazy: the request is made when iteration starts.
     *
     * @param RunnableConfig|array<string, mixed>                                                                                       $config
     * @param CheckpointListOptions|array{filter?: array<string, mixed>, before?: RunnableConfig|array<string, mixed>, limit?: int}|null $options
     *
     * @return \Generator<int, StateSnapshot>
     */
    public function getStateHistory(RunnableConfig|array $config, CheckpointListOptions|array|null $options = null): \Generator
    {
        $merged = RunnableConfig::mergeConfigs($this->config, $config);

        if ($options instanceof CheckpointListOptions) {
            $limit = $options->limit;
            $before = $options->before;
            $filter = $options->filter;
        } else {
            $limit = $options['limit'] ?? null;
            $before = $options['before'] ?? null;
            $filter = $options['filter'] ?? null;
        }
        if ($before instanceof RunnableConfig) {
            $before = $before->configurable;
        } elseif (is_array($before) && isset($before['configurable']) && is_array($before['configurable'])) {
            $before = $before['configurable'];
        }

        $request = ['limit' => $limit ?? 10];
        $beforeCheckpoint = self::getCheckpoint($before);
        if ($beforeCheckpoint !== null) {
            $request['before'] = $beforeCheckpoint;
        }
        if ($filter !== null) {
            $request['metadata'] = $filter;
        }
        $checkpoint = self::getCheckpoint($merged->configurable);
        if ($checkpoint !== null) {
            $request['checkpoint'] = $checkpoint;
        }

        foreach ($this->client->threads->getHistory(self::requireThreadId($merged), $request) as $state) {
            yield $this->createStateSnapshot($state, $merged->configurable);
        }
    }

    /**
     * The thread's current state.
     *
     * A thread that exists but has no checkpoint yet (`checkpoint: null`) yields a snapshot whose
     * config falls back to the thread/checkpoint keys of the config passed in.
     *
     * @param RunnableConfig|array<string, mixed> $config
     * @param array{subgraphs?: bool}             $options
     */
    public function getState(RunnableConfig|array $config, array $options = []): StateSnapshot
    {
        $merged = RunnableConfig::mergeConfigs($this->config, $config);

        $state = $this->client->threads->getState(
            self::requireThreadId($merged),
            self::getCheckpoint($merged->configurable),
            $options,
        );

        return $this->createStateSnapshot($state, $merged->configurable);
    }

    /**
     * The remote graph as a drawable graph.
     *
     * Port of `getGraphAsync` (the synchronous upstream `getGraph` throws, because JS cannot block on
     * the request; PHP can). `$xray` includes subgraphs, an int limits the depth.
     */
    public function getGraph(?RunnableConfig $config = null, bool|int|null $xray = null): Graph
    {
        $options = $xray !== null ? ['xray' => $xray] : [];
        $remote = $this->client->assistants->getAssistantGraph($this->graphId, $options);

        $graph = new Graph();
        foreach ($remote['nodes'] ?? [] as $node) {
            $id = (string) $node['id'];
            $data = $node['data'] ?? [];
            $name = is_string($data) ? $data : (string) ($data['name'] ?? '');
            $metadata = is_string($data) ? [] : (array) ($data['metadata'] ?? []);
            $graph->nodes[$id] = new Node($id, new RunnableIOSchema($name, is_array($data) ? $data : []), $name, $metadata);
        }
        foreach ($remote['edges'] ?? [] as $edge) {
            $graph->edges[] = new Edge(
                (string) $edge['source'],
                (string) $edge['target'],
                isset($edge['data']) ? (string) $edge['data'] : null,
                isset($edge['conditional']) ? (bool) $edge['conditional'] : null,
            );
        }

        return $graph;
    }

    /**
     * The graphs nested inside this one, each as a RemoteGraph addressing that subgraph's id.
     *
     * Port of `getSubgraphsAsync`. Yields `[namespace, RemoteGraph]`.
     *
     * @return \Generator<int, array{0: string, 1: self}>
     */
    public function getSubgraphs(?string $namespace = null, bool $recurse = false): \Generator
    {
        $options = ['recurse' => $recurse];
        if ($namespace !== null) {
            $options['namespace'] = $namespace;
        }

        foreach ($this->client->assistants->getSubgraphs($this->graphId, $options) as $ns => $schema) {
            $subgraph = clone $this;
            $subgraph->bound = $subgraph;
            $subgraph->graphId = (string) $schema['graph_id'];

            yield [(string) $ns, $subgraph];
        }
    }

    /**
     * What goes in the request's `config`, and the pieces that travel beside it.
     *
     * `thread_id` is carried by the URL, not the config, so the server can accept a separate `context`.
     *
     * @return array{threadId: string|null, context: mixed, config: array<string, mixed>}
     */
    protected function prepareRunRequest(RunnableConfig $merged): array
    {
        $sanitized = $this->sanitizeConfig($merged);
        $configurable = $sanitized['configurable'];
        $threadId = $configurable['thread_id'] ?? null;
        unset($configurable['thread_id']);

        $config = [
            'tags' => $sanitized['tags'],
            'metadata' => $sanitized['metadata'] === [] ? new \stdClass() : $sanitized['metadata'],
            'configurable' => $configurable === [] ? new \stdClass() : $configurable,
        ];
        if ($sanitized['recursion_limit'] !== null) {
            $config['recursion_limit'] = $sanitized['recursion_limit'];
        }

        return [
            'threadId' => is_string($threadId) ? $threadId : null,
            'context' => $merged->context,
            'config' => $config,
        ];
    }

    /**
     * Reduce a config to what can travel as JSON.
     *
     * Only `tags`, `metadata` and `configurable` can be sent, so only those are walked. A value that
     * will not encode (an object cycle, NaN, a resource) is replaced rather than failing the call: a
     * repeated object becomes `"[Circular]"`. `thread_id` and the other propagated keys are copied into
     * `metadata`, and the reserved and `__pregel_*` keys are dropped from `configurable`.
     *
     * `recursion_limit` is omitted at the default (25) so the server's own default is not overridden by
     * a value the caller never chose.
     *
     * @return array{tags: list<mixed>, metadata: array<string, mixed>, configurable: array<string, mixed>, recursion_limit: int|null}
     */
    protected function sanitizeConfig(RunnableConfig $config): array
    {
        $metadata = $config->metadata;
        if ($config->configurable !== []) {
            $metadata = Config::propagateConfigurableToMetadata($config->configurable, $metadata) ?? $metadata;
        }

        $parts = ['tags' => $config->tags, 'metadata' => $metadata, 'configurable' => $config->configurable];

        try {
            $safe = json_decode(json_encode($parts, \JSON_THROW_ON_ERROR), true, 512, \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $seen = new \SplObjectStorage();
            $safe = json_decode((string) json_encode(self::jsonSafe($parts, $seen, 0), \JSON_INVALID_UTF8_SUBSTITUTE | \JSON_PARTIAL_OUTPUT_ON_ERROR), true);
        }

        $configurable = [];
        foreach ((array) ($safe['configurable'] ?? []) as $key => $value) {
            $key = (string) $key;
            if (in_array($key, self::RESERVED_CONFIGURABLE_KEYS, true) || str_starts_with($key, '__pregel_')) {
                continue;
            }
            $configurable[$key] = $value;
        }

        return [
            'tags' => array_values((array) ($safe['tags'] ?? [])),
            'metadata' => (array) ($safe['metadata'] ?? []),
            'configurable' => $configurable,
            'recursion_limit' => $config->recursionLimit !== self::DEFAULT_RECURSION_LIMIT ? $config->recursionLimit : null,
        ];
    }

    /**
     * @param \SplObjectStorage<object, true> $seen
     */
    private static function jsonSafe(mixed $value, \SplObjectStorage $seen, int $depth): mixed
    {
        if ($depth > self::MAX_SANITIZE_DEPTH) {
            return '[Circular]';
        }
        if (is_float($value) && !is_finite($value)) {
            return null;
        }
        if (is_resource($value)) {
            return null;
        }
        if (is_array($value)) {
            return array_map(fn (mixed $v): mixed => self::jsonSafe($v, $seen, $depth + 1), $value);
        }
        if (is_object($value)) {
            if ($seen->offsetExists($value)) {
                return '[Circular]';
            }
            $seen->offsetSet($value, true);

            if ($value instanceof \JsonSerializable) {
                return self::jsonSafe($value->jsonSerialize(), $seen, $depth + 1);
            }
            $vars = $value instanceof \Closure ? [] : get_object_vars($value);

            return $vars === [] ? new \stdClass() : array_map(fn (mixed $v): mixed => self::jsonSafe($v, $seen, $depth + 1), $vars);
        }

        return $value;
    }

    /**
     * Port of `_serializeInputs`: messages become `{...data, role: type}`; everything else is walked.
     */
    protected static function serializeInputs(mixed $value): mixed
    {
        if ($value instanceof BaseMessage) {
            return $value->toDict()['data'] + ['role' => $value->getType()];
        }
        if (is_array($value)) {
            return array_map(static fn (mixed $v): mixed => self::serializeInputs($v), $value);
        }

        return $value;
    }

    /**
     * A command as the server expects it: absent `update`/`resume` are left out, and a `Send` carries no
     * null timeout.
     *
     * @return array<string, mixed>
     */
    private static function commandToJson(mixed $input): array
    {
        $command = Command::fromMixed($input) ?? throw new \InvalidArgumentException('Not a Command.');
        $json = $command->toArray();

        $json['goto'] = array_map(
            static fn (mixed $target): mixed => is_array($target) ? array_filter($target, static fn (mixed $v): bool => $v !== null) : $target,
            $json['goto'],
        );

        return array_filter($json, static fn (mixed $v, string $k): bool => $v !== null || !in_array($k, ['update', 'resume'], true), \ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Port of `getStreamModes`: the modes to request, whether exactly one was asked for, and whether
     * `updates` was among them. `updates` is always requested so interrupts can be seen.
     *
     * @return array{updatedStreamModes: list<string>, reqUpdates: bool, reqSingle: bool}
     */
    private static function getStreamModes(mixed $streamMode, string $defaultStreamMode = 'updates'): array
    {
        $modes = [];
        $reqSingle = true;

        if (is_string($streamMode) || (is_array($streamMode) && $streamMode !== [])) {
            $reqSingle = is_string($streamMode);
            $modes = array_values(array_map(strval(...), (array) $streamMode));
        } else {
            $modes[] = $defaultStreamMode;
        }

        $reqUpdates = in_array('updates', $modes, true);
        if (!$reqUpdates) {
            $modes[] = 'updates';
        }

        return ['updatedStreamModes' => $modes, 'reqUpdates' => $reqUpdates, 'reqSingle' => $reqSingle];
    }

    /**
     * `values`, `updates|a|b` -> `[mode, [namespace segments]]`.
     *
     * @return array{0: string, 1: list<string>}
     */
    private static function splitEvent(string $event): array
    {
        if (!str_contains($event, Constants::CHECKPOINT_NAMESPACE_SEPARATOR)) {
            return [$event, []];
        }
        $parts = explode(Constants::CHECKPOINT_NAMESPACE_SEPARATOR, $event);

        return [$parts[0], array_slice($parts, 1)];
    }

    /**
     * @param array<string, mixed>|null $configurable
     *
     * @return array<string, mixed>|null the checkpoint keys that are set, or null when none is
     */
    protected static function getCheckpoint(?array $configurable): ?array
    {
        if ($configurable === null) {
            return null;
        }

        $checkpoint = [];
        foreach (['thread_id', 'checkpoint_ns', 'checkpoint_id', 'checkpoint_map'] as $key) {
            if (($configurable[$key] ?? null) !== null) {
                $checkpoint[$key] = $configurable[$key];
            }
        }

        return $checkpoint === [] ? null : $checkpoint;
    }

    /**
     * Port of `_checkpointToConfig`: `{configurable: {...}}` from a checkpoint, else from the fallback.
     *
     * @param array<string, mixed>|null $checkpoint
     * @param array<string, mixed>|null $fallbackConfigurable
     *
     * @return array{configurable: array<string, mixed>}
     */
    protected static function checkpointToConfig(?array $checkpoint, ?array $fallbackConfigurable = null): array
    {
        $resolved = $checkpoint ?? self::getCheckpoint($fallbackConfigurable);
        if ($resolved === null) {
            return ['configurable' => []];
        }

        $configurable = [];
        foreach (['thread_id', 'checkpoint_ns', 'checkpoint_id'] as $key) {
            if (($resolved[$key] ?? null) !== null) {
                $configurable[$key] = $resolved[$key];
            }
        }
        if (($resolved['checkpoint_ns'] ?? null) !== null || ($resolved['checkpoint_id'] ?? null) !== null || ($resolved['checkpoint_map'] ?? null) !== null) {
            $configurable['checkpoint_map'] = $resolved['checkpoint_map'] ?? [];
        }

        return ['configurable' => $configurable];
    }

    /**
     * Port of `_createStateSnapshot`.
     *
     * A task keeps its `id`, `name` and `interrupts`. Upstream also carries `error`, the nested
     * subgraph `state` and `result`, which {@see PregelTaskDescription} has no field for, so they are
     * dropped.
     *
     * @param array<string, mixed>      $state
     * @param array<string, mixed>|null $fallbackConfigurable
     */
    protected function createStateSnapshot(array $state, ?array $fallbackConfigurable = null): StateSnapshot
    {
        $tasks = [];
        foreach ($state['tasks'] ?? [] as $task) {
            $tasks[] = new PregelTaskDescription(
                id: (string) ($task['id'] ?? ''),
                name: (string) ($task['name'] ?? ''),
                interrupts: array_values((array) ($task['interrupts'] ?? [])),
            );
        }

        $parent = $state['parent_checkpoint'] ?? null;

        return new StateSnapshot(
            values: (array) ($state['values'] ?? []),
            next: array_values((array) ($state['next'] ?? [])),
            config: self::checkpointToConfig($state['checkpoint'] ?? null, $fallbackConfigurable),
            metadata: (array) ($state['metadata'] ?? []),
            createdAt: $state['created_at'] ?? null,
            parentConfig: $parent ? self::checkpointToConfig($parent) : null,
            tasks: $tasks,
        );
    }

    private static function requireThreadId(RunnableConfig $config): string
    {
        $threadId = $config->configurable['thread_id'] ?? null;
        if (!is_string($threadId) || $threadId === '') {
            throw new \InvalidArgumentException('RemoteGraph requires `configurable.thread_id` for this call.');
        }

        return $threadId;
    }

    /**
     * Upstream `mergeConfigs` over plain arrays: maps merge, tags union, anything else is replaced.
     *
     * @param array<string, mixed> $base
     * @param array<string, mixed> $over
     *
     * @return array<string, mixed>
     */
    private static function mergeArrayConfigs(array $base, array $over): array
    {
        $merged = $base;
        foreach ($over as $key => $value) {
            if (in_array($key, ['metadata', 'configurable'], true) && is_array($value)) {
                $merged[$key] = array_merge((array) ($base[$key] ?? []), $value);
            } elseif ($key === 'tags' && is_array($value)) {
                $merged[$key] = array_values(array_unique(array_merge((array) ($base[$key] ?? []), $value)));
            } else {
                $merged[$key] = $value;
            }
        }

        return $merged;
    }
}
