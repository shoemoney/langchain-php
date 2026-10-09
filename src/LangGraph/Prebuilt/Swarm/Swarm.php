<?php

declare(strict_types=1);

namespace LangGraph\Prebuilt\Swarm;

use LangGraph\Graph\MessagesReducer;
use LangGraph\Pregel\Constants;
use LangGraph\State\Annotation;
use LangGraph\State\AnnotationRoot;
use LangGraph\State\StateGraph;

/**
 * A multi-agent swarm: agents hand off to each other and the last one to speak stays active.
 *
 * Port of `createSwarm`, `addActiveAgentRouter` and `SwarmState` from `langgraph-swarm/src/swarm.ts`.
 *
 * ```
 * $app = Swarm::create(['agents' => [$alice, $bob], 'defaultActiveAgent' => 'Alice'])
 *     ->compile(['checkpointer' => new MemorySaver()]);
 * ```
 *
 * Returns the UNCOMPILED {@see StateGraph}. Each agent should hold {@see Handoff::createHandoffTool()} tools;
 * the destinations they name become the `ends` of the agent's node.
 */
final class Swarm
{
    private function __construct()
    {
    }

    /** Port of `SwarmState`: `messages` plus the name of the active agent. */
    public static function state(): AnnotationRoot
    {
        return Annotation::root([
            'messages' => Annotation::withReducer(MessagesReducer::messagesStateReducer(...), static fn (): array => []),
            'activeAgent' => Annotation::last(),
        ]);
    }

    /**
     * Route START to the currently active agent, or to the default when none is.
     *
     * Port of `addActiveAgentRouter`.
     *
     * @param array{routeTo: list<string>, defaultActiveAgent: string} $params
     */
    public static function addActiveAgentRouter(StateGraph $builder, array $params): StateGraph
    {
        $routeTo = array_values($params['routeTo']);
        $defaultActiveAgent = $params['defaultActiveAgent'];

        if (!in_array($defaultActiveAgent, $routeTo, true)) {
            throw new \Exception(sprintf("Default active agent '%s' not found in routes %s", $defaultActiveAgent, implode(',', $routeTo)));
        }

        $builder->addConditionalEdges(
            Constants::START,
            static fn (array $state): string => ($state['activeAgent'] ?? null) ?: $defaultActiveAgent,
            $routeTo,
        );

        return $builder;
    }

    /**
     * Port of `createSwarm`.
     *
     * @param array{agents: list<\LangGraph\Pregel\CompiledStateGraph>, defaultActiveAgent: string, stateSchema?: AnnotationRoot|null} $params
     */
    public static function create(array $params): StateGraph
    {
        foreach (['agents', 'defaultActiveAgent'] as $required) {
            if (!array_key_exists($required, $params) || $params[$required] === null) {
                throw new \InvalidArgumentException(sprintf('Swarm::create() requires "%s".', $required));
            }
        }

        $stateSchema = $params['stateSchema'] ?? null;
        if ($stateSchema !== null && !array_key_exists('activeAgent', $stateSchema->spec)) {
            throw new \Exception("Missing required key 'activeAgent' in stateSchema");
        }

        $agents = array_values($params['agents']);
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

        $builder = new StateGraph($stateSchema ?? self::state());
        self::addActiveAgentRouter($builder, ['routeTo' => $agentNames, 'defaultActiveAgent' => $params['defaultActiveAgent']]);

        foreach ($agents as $agent) {
            $builder->addNode($agent->name, $agent, [
                'ends' => Handoff::getHandoffDestinations($agent),
                'subgraphs' => [$agent],
            ]);
        }

        return $builder;
    }
}
