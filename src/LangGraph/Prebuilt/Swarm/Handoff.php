<?php

declare(strict_types=1);

namespace LangGraph\Prebuilt\Swarm;

use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\CompiledStateGraph;
use LangGraph\Pregel\Utils\Config;
use LangGraph\Prebuilt\ReactAgent;
use LangGraph\Prebuilt\ToolNode;

use function LangChain\Tools\tool;

/**
 * Handoff helpers of a swarm.
 *
 * Port of `langgraph-swarm/src/handoff.ts` (`createHandoffTool`, `getHandoffDestinations`).
 */
final class Handoff
{
    public const METADATA_KEY_HANDOFF_DESTINATION = '__handoff_destination';

    private function __construct()
    {
    }

    /** Port of `_normalizeAgentName`: trim, collapse whitespace runs to `_`, lower-case. */
    public static function normalizeAgentName(string $agentName): string
    {
        return strtolower(preg_replace('/\s+/', '_', trim($agentName)) ?? $agentName);
    }

    /**
     * Create a tool that hands control to the named agent and makes it the swarm's active agent.
     *
     * Port of `createHandoffTool`. The tool is `transfer_to_<agent_name>`, and its metadata records the
     * destination so {@see self::getHandoffDestinations()} can find it.
     *
     * @param array{agentName: string, description?: string|null} $params
     */
    public static function createHandoffTool(array $params): StructuredTool
    {
        if (!isset($params['agentName']) || !is_string($params['agentName'])) {
            throw new \InvalidArgumentException('createHandoffTool() requires "agentName".');
        }

        $agentName = $params['agentName'];
        $toolName = 'transfer_to_' . self::normalizeAgentName($agentName);
        $description = ($params['description'] ?? '') !== '' ? $params['description'] : "Ask agent '" . $agentName . "' for help";

        return tool(
            static function (array $in, mixed $runManager, RunnableConfig $config) use ($agentName, $toolName): Command {
                $toolMessage = new ToolMessage([
                    'content' => 'Successfully transferred to ' . $agentName,
                    'name' => $toolName,
                    'tool_call_id' => $config->toolCall['id'] ?? null,
                ]);

                $state = Config::getCurrentTaskInput($config);
                $messages = array_values((array) ($state['messages'] ?? []));
                $messages[] = $toolMessage;

                return new Command(
                    graph: Command::PARENT,
                    update: ['messages' => $messages, 'activeAgent' => $agentName],
                    goto: $agentName,
                );
            },
            [
                'name' => $toolName,
                'description' => $description,
                'schema' => Schema::object([]),
                'metadata' => [self::METADATA_KEY_HANDOFF_DESTINATION => $agentName],
            ],
        );
    }

    /**
     * The agents an agent can hand off to, read from the metadata of the tools on its `tools` node.
     *
     * Port of `getHandoffDestinations`. Upstream goes through `agent.getGraph()`, which this port does not
     * have yet (WP-06), so the node is read from `$agent->nodes` directly.
     *
     * @return list<string>
     */
    public static function getHandoffDestinations(CompiledStateGraph $agent, string $toolNodeName = ReactAgent::NODE_TOOLS): array
    {
        $node = $agent->nodes[$toolNodeName] ?? null;
        $toolNode = $node?->bound;
        if (!$toolNode instanceof ToolNode) {
            return [];
        }

        $destinations = [];
        foreach ($toolNode->tools as $tool) {
            if ($tool instanceof StructuredTool && isset($tool->metadata[self::METADATA_KEY_HANDOFF_DESTINATION])) {
                $destinations[] = (string) $tool->metadata[self::METADATA_KEY_HANDOFF_DESTINATION];
            }
        }

        return $destinations;
    }
}
