<?php

declare(strict_types=1);

namespace LangGraph\Prebuilt\Supervisor;

use LangChain\Messages\AIMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;
use LangChain\Utils\Uuid;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Utils\Config;

use function LangChain\Tools\tool;

/**
 * Handoff helpers of a multi-agent supervisor.
 *
 * Port of `langgraph-supervisor/src/handoff.ts` (`createHandoffTool`, `createHandoffBackMessages`).
 */
final class Handoff
{
    private function __construct()
    {
    }

    /** Port of `_normalizeAgentName`: trim, collapse whitespace runs to `_`, lower-case. */
    public static function normalizeAgentName(string $agentName): string
    {
        return strtolower(preg_replace('/\s+/', '_', trim($agentName)) ?? $agentName);
    }

    /**
     * Create a tool that hands control to the named agent.
     *
     * Port of `createHandoffTool`. The tool is called `transfer_to_<agent_name>`, takes no arguments and
     * returns a `Command(goto: agentName, graph: PARENT, update: {messages})`. `$params`:
     *
     *  - `agentName` (required): the agent's node name in the multi-agent graph;
     *  - `description`: the tool description (`agentDescription` is the deprecated spelling);
     *  - `addHandoffMessages` (default true): when false the supervisor `AIMessage` carrying the handoff
     *    call is dropped from the history forwarded to the agent, and no handoff `ToolMessage` is added.
     *
     * @param array{agentName: string, description?: string|null, agentDescription?: string|null, addHandoffMessages?: bool} $params
     */
    public static function createHandoffTool(array $params): StructuredTool
    {
        if (!isset($params['agentName']) || !is_string($params['agentName'])) {
            throw new \InvalidArgumentException('createHandoffTool() requires "agentName".');
        }

        $agentName = $params['agentName'];
        $addHandoffMessages = $params['addHandoffMessages'] ?? true;
        $toolName = 'transfer_to_' . self::normalizeAgentName($agentName);
        $description = $params['description'] ?? $params['agentDescription'] ?? 'Ask another agent for help.';

        return tool(
            static function (array $in, mixed $runManager, RunnableConfig $config) use ($agentName, $toolName, $addHandoffMessages): Command {
                $state = Config::getCurrentTaskInput($config);
                $messages = array_values((array) ($state['messages'] ?? []));

                if ($addHandoffMessages) {
                    $messages[] = new ToolMessage([
                        'content' => 'Successfully transferred to ' . $agentName,
                        'name' => $toolName,
                        'tool_call_id' => $config->toolCall['id'] ?? null,
                    ]);
                } else {
                    // Omit the supervisor AIMessage holding the handoff call from the agent's history.
                    $messages = array_slice($messages, 0, -1);
                }

                return new Command(
                    graph: Command::PARENT,
                    update: ['messages' => $messages],
                    goto: $agentName,
                );
            },
            [
                'name' => $toolName,
                'description' => $description,
                'schema' => Schema::object([]),
            ],
        );
    }

    /**
     * The (AIMessage, ToolMessage) pair recorded when control returns to the supervisor.
     *
     * Port of `createHandoffBackMessages`.
     *
     * @return array{0: AIMessage, 1: ToolMessage}
     */
    public static function createHandoffBackMessages(string $agentName, string $supervisorName): array
    {
        $toolCallId = Uuid::v4();
        $toolName = 'transfer_back_to_' . self::normalizeAgentName($supervisorName);

        return [
            new AIMessage([
                'content' => 'Transferring back to ' . $supervisorName,
                'tool_calls' => [['name' => $toolName, 'args' => [], 'id' => $toolCallId]],
                'name' => $agentName,
            ]),
            new ToolMessage([
                'content' => 'Successfully transferred back to ' . $supervisorName,
                'name' => $toolName,
                'tool_call_id' => $toolCallId,
            ]),
        ];
    }
}
