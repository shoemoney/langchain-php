<?php

declare(strict_types=1);

namespace LangGraph\Graph;

use LangGraph\Cache\BaseCache;
use LangGraph\Channels\BaseChannel;
use LangGraph\Channels\EphemeralValue;
use LangGraph\Pregel\ChannelWrite;
use LangGraph\Pregel\Checkpoint\BaseCheckpointSaver;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use LangGraph\Pregel\PregelNode;
use LangGraph\Pregel\Retry\RetryPolicy;
use LangGraph\Pregel\Send;
use LangGraph\Store\BaseStore;

/**
 * A {@see Graph} compiled into a runnable {@see Pregel} graph.
 *
 * Port of `CompiledGraph` from `langgraph-core/src/graph/graph.ts`.
 *
 * Structurally this is a Pregel graph that remembers the builder it came from. Upstream mutates the
 * compiled graph as it attaches nodes, edges and branches; {@see Pregel} here holds its node map as
 * `readonly`, so the `attach*` steps are static functions over the arrays that the constructor then
 * receives. They are the same three steps in the same order, applied before construction instead of
 * after.
 */
class CompiledGraph extends Pregel
{
    /**
     * @param array<string, PregelNode>   $nodes
     * @param array<string, BaseChannel>  $channels
     * @param string|list<string>         $inputChannels
     * @param string|list<string>         $outputChannels
     * @param list<string>                $streamChannels
     * @param list<string>                $streamMode
     * @param array<string, list<string>> $triggerToNodes
     */
    public function __construct(
        array $nodes = [],
        array $channels = [],
        string|array $inputChannels = [],
        string|array $outputChannels = [],
        array $streamChannels = [],
        ?BaseCheckpointSaver $checkpointer = null,
        array $streamMode = ['updates'],
        ?RetryPolicy $retryPolicy = null,
        bool $debug = false,
        array $triggerToNodes = [],
        ?string $name = null,
        array $interruptBefore = [],
        array $interruptAfter = [],
        ?int $stepTimeout = null,
        ?string $description = null,
        bool $checkpointerDisabled = false,
        ?BaseStore $store = null,
        ?BaseCache $cache = null,
        public readonly ?Graph $builder = null,
    ) {
        parent::__construct(
            nodes: $nodes,
            channels: $channels,
            inputChannels: $inputChannels,
            outputChannels: $outputChannels,
            streamChannels: $streamChannels,
            checkpointer: $checkpointer,
            streamMode: $streamMode,
            retryPolicy: $retryPolicy,
            debug: $debug,
            triggerToNodes: $triggerToNodes,
            name: $name,
            interruptBefore: $interruptBefore,
            interruptAfter: $interruptAfter,
            stepTimeout: $stepTimeout,
            description: $description,
            checkpointerDisabled: $checkpointerDisabled,
            store: $store,
            cache: $cache,
        );
    }

    /**
     * Add a node: its own ephemeral channel, and a writer publishing its output there.
     *
     * Port of `CompiledGraph.attachNode`.
     *
     * @param array<string, PregelNode>  $nodes
     * @param array<string, BaseChannel> $channels
     * @param list<string>               $streamChannels
     */
    public static function attachNode(array &$nodes, array &$channels, array &$streamChannels, string $key, PregelNode $spec): void
    {
        $channels[$key] = new EphemeralValue();
        $nodes[$key] = new PregelNode(
            channels: [],
            triggers: [],
            bound: $spec->bound,
            writers: [
                ...$spec->writers,
                new ChannelWrite([['channel' => $key, 'value' => ChannelWrite::passthrough()]], [Constants::TAG_HIDDEN]),
            ],
            mapper: $spec->mapper,
            tags: $spec->tags,
            metadata: $spec->metadata,
            kwargs: $spec->kwargs,
            retryPolicy: $spec->retryPolicy,
            cachePolicy: $spec->cachePolicy,
            timeout: $spec->timeout,
            subgraphs: $spec->subgraphs,
            ends: $spec->ends,
            isErrorHandler: $spec->isErrorHandler,
            errorHandlerNode: $spec->errorHandlerNode,
            config: $spec->config,
        );
        $streamChannels[] = $key;
    }

    /**
     * Wire `$start` to `$end`: the end node subscribes to the start node's channel.
     *
     * Port of `CompiledGraph.attachEdge`. An edge to `__end__` instead makes the start node publish to
     * the `__end__` channel.
     *
     * @param array<string, PregelNode> $nodes
     */
    public static function attachEdge(array &$nodes, string $start, string $end): void
    {
        if ($end === Constants::END) {
            if ($start === Constants::START) {
                throw new \InvalidArgumentException('Cannot have an edge from START to END');
            }
            $nodes[$start]->writers[] = new ChannelWrite(
                [['channel' => Constants::END, 'value' => ChannelWrite::passthrough()]],
                [Constants::TAG_HIDDEN],
            );

            return;
        }

        $nodes[$end]->triggers[] = $start;
        $nodes[$end]->channels[] = $start;
    }

    /**
     * Attach a conditional edge: a writer on the source, and a trigger channel per destination.
     *
     * Port of `CompiledGraph.attachBranch`. Each possible destination gets its own
     * `branch:<start>:<name>:<dest>` channel; the branch writes to the one it chose and the destination
     * node, subscribed to all of its incoming branch channels, wakes.
     *
     * @param array<string, PregelNode>  $nodes
     * @param array<string, BaseChannel> $channels
     */
    public static function attachBranch(array &$nodes, array &$channels, string $start, string $name, Branch $branch): void
    {
        if ($start === Constants::START && !isset($nodes[Constants::START])) {
            $nodes[Constants::START] = new PregelNode(
                channels: [Constants::START],
                triggers: [Constants::START],
                tags: [Constants::TAG_HIDDEN],
            );
        }

        $nodes[$start]->writers[] = $branch->run(
            static function (array $dests) use ($start, $name): ChannelWrite {
                $writes = array_map(
                    static fn (string|Send $dest): array|Send => $dest instanceof Send
                        ? $dest
                        : [
                            'channel' => $dest === Constants::END ? Constants::END : 'branch:' . $start . ':' . $name . ':' . $dest,
                            'value' => ChannelWrite::passthrough(),
                        ],
                    $dests,
                );

                return new ChannelWrite($writes, [Constants::TAG_HIDDEN]);
            },
        );

        $ends = $branch->ends !== null
            ? array_values($branch->ends)
            : array_values(array_filter(
                array_map(strval(...), array_keys($nodes)),
                static fn (string $node): bool => $node !== Constants::START,
            ));

        foreach ($ends as $end) {
            if ($end === Constants::END) {
                continue;
            }
            $channelName = 'branch:' . $start . ':' . $name . ':' . $end;
            $channels[$channelName] = new EphemeralValue();
            $nodes[$end]->triggers[] = $channelName;
            $nodes[$end]->channels[] = $channelName;
        }
    }
}
