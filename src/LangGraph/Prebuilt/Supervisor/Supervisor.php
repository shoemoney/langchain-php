<?php

declare(strict_types=1);

namespace LangGraph\Prebuilt\Supervisor;

use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableInterface;
use LangGraph\Pregel\Constants;
use LangGraph\Prebuilt\AgentName;
use LangGraph\Prebuilt\AgentState;
use LangGraph\Prebuilt\ReactAgent;
use LangGraph\State\StateGraph;

/**
 * A multi-agent supervisor.
 *
 * Port of `createSupervisor` from `langgraph-supervisor/src/supervisor.ts`.
 *
 * ```
 * $workflow = Supervisor::create(['agents' => [$math, $research], 'llm' => $model]);
 * $app = $workflow->compile();
 * ```
 *
 * Returns the UNCOMPILED {@see StateGraph}: a supervisor `ReactAgent` node whose tools are one
 * `transfer_to_<agent>` handoff tool per agent (each returns a `Command(graph: PARENT)`), plus one node per
 * agent that always returns to the supervisor. `$params`:
 *
 *  - `agents` (required): compiled graphs, each with a unique name (not the default `LangGraph`);
 *  - `llm` (required): the supervisor's model;
 *  - `tools`, `prompt`, `responseFormat`, `stateSchema`, `contextSchema`, `preModelHook`, `postModelHook`;
 *  - `outputMode`: `last_message` (default) adds only an agent's final message to the history, `full_history`
 *    adds all of it;
 *  - `addHandoffMessages` (default true) and `addHandoffBackMessages` (defaults to `addHandoffMessages`);
 *  - `supervisorName` (default `supervisor`);
 *  - `includeAgentName`: `inline` to fold agent names into the text the supervisor's model sees.
 *
 * Not ported: remote graphs (`supervisorRemote.test.ts` is skipped; the port has no `RemoteGraph` yet).
 */
final class Supervisor
{
    public const OUTPUT_MODE_FULL_HISTORY = 'full_history';
    public const OUTPUT_MODE_LAST_MESSAGE = 'last_message';

    private const PROVIDERS_WITH_PARALLEL_TOOL_CALLS_PARAM = ['ChatOpenAI'];

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $params
     */
    public static function create(array $params): StateGraph
    {
        foreach (['agents', 'llm'] as $required) {
            if (!array_key_exists($required, $params) || $params[$required] === null) {
                throw new \InvalidArgumentException(sprintf('Supervisor::create() requires "%s".', $required));
            }
        }

        $agents = array_values($params['agents']);
        $llm = $params['llm'];
        $outputMode = $params['outputMode'] ?? self::OUTPUT_MODE_LAST_MESSAGE;
        $addHandoffMessages = $params['addHandoffMessages'] ?? true;
        $addHandoffBackMessages = $params['addHandoffBackMessages'] ?? $addHandoffMessages;
        $supervisorName = $params['supervisorName'] ?? 'supervisor';
        $includeAgentName = $params['includeAgentName'] ?? null;

        $agentNames = [];
        foreach ($agents as $agent) {
            if ($agent->name === null || $agent->name === '' || $agent->name === 'LangGraph') {
                throw new \Exception(
                    'Please specify a name when you create your agent, either via `ReactAgent::create([..., \'name\' => $agentName])` '
                    . 'or via `$graph->compile([\'name\' => $agentName])`.'
                );
            }
            if (in_array($agent->name, $agentNames, true)) {
                throw new \Exception(sprintf("Agent with name '%s' already exists. Agent names must be unique.", $agent->name));
            }
            $agentNames[] = $agent->name;
        }

        $callAgents = [];
        foreach ($agents as $agent) {
            $callAgents[$agent->name] = self::makeCallAgent($agent, $outputMode, (bool) $addHandoffBackMessages, $supervisorName);
        }

        $handoffTools = array_map(
            static fn ($agent) => Handoff::createHandoffTool([
                'agentName' => $agent->name,
                'description' => $agent->description,
                'addHandoffMessages' => $addHandoffMessages,
            ]),
            $agents,
        );
        $allTools = [...array_values($params['tools'] ?? []), ...$handoffTools];

        $supervisorLlm = $llm;
        // A chat model with `bindTools()`; duck-typed because LangGraph must not import a provider namespace.
        if ($llm instanceof RunnableInterface && method_exists($llm, 'bindTools')) {
            $supervisorLlm = in_array($llm->getName(), self::PROVIDERS_WITH_PARALLEL_TOOL_CALLS_PARAM, true)
                ? $llm->bindTools($allTools, ['parallel_tool_calls' => false])
                : $llm->bindTools($allTools);
        }

        if ($includeAgentName !== null) {
            $supervisorLlm = AgentName::withAgentName($supervisorLlm, $includeAgentName);
        }

        $schema = $params['stateSchema'] ?? AgentState::annotation();
        $supervisorAgent = ReactAgent::create(array_filter([
            'name' => $supervisorName,
            'llm' => $supervisorLlm,
            'tools' => $allTools,
            'prompt' => $params['prompt'] ?? null,
            'responseFormat' => $params['responseFormat'] ?? null,
            'stateSchema' => $schema,
            'preModelHook' => $params['preModelHook'] ?? null,
            'postModelHook' => $params['postModelHook'] ?? null,
        ], static fn (mixed $value): bool => $value !== null));

        $builder = new StateGraph($schema, $params['contextSchema'] ?? null);
        $builder
            ->addNode($supervisorName, $supervisorAgent, ['ends' => $agentNames, 'subgraphs' => [$supervisorAgent]])
            ->addEdge(Constants::START, $supervisorName);

        foreach ($agents as $agent) {
            $builder->addNode($agent->name, $callAgents[$agent->name], ['subgraphs' => [$agent]]);
            $builder->addEdge($agent->name, $supervisorName);
        }

        return $builder;
    }

    /**
     * Port of `makeCallAgent`: run the agent on the supervisor's state, trim its output per `outputMode`
     * and append the handoff-back pair.
     */
    private static function makeCallAgent(object $agent, string $outputMode, bool $addHandoffBackMessages, string $supervisorName): \Closure
    {
        if (!in_array($outputMode, [self::OUTPUT_MODE_FULL_HISTORY, self::OUTPUT_MODE_LAST_MESSAGE], true)) {
            throw new \Exception(sprintf(
                'Invalid agent output mode: %s. Needs to be one of ["full_history", "last_message"]',
                $outputMode,
            ));
        }

        return static function (array $state, RunnableConfig $config) use ($agent, $outputMode, $addHandoffBackMessages, $supervisorName): array {
            /** @var RunnableInterface $agent */
            $output = $agent->invoke($state, $config);
            $messages = array_values($output['messages']);

            if ($outputMode === self::OUTPUT_MODE_LAST_MESSAGE) {
                $messages = array_slice($messages, -1);
            }

            if ($addHandoffBackMessages) {
                array_push($messages, ...Handoff::createHandoffBackMessages($agent->name, $supervisorName));
            }

            return [...$output, 'messages' => $messages];
        };
    }
}
