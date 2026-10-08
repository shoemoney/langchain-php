<?php

declare(strict_types=1);

namespace LangGraph\Agents;

use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableConfig;
use LangGraph\Agents\Middleware\Utils as MiddlewareUtils;
use LangGraph\Agents\Nodes\AfterAgentNode;
use LangGraph\Agents\Nodes\AfterModelNode;
use LangGraph\Agents\Nodes\AgentNode;
use LangGraph\Agents\Nodes\BeforeAgentNode;
use LangGraph\Agents\Nodes\BeforeModelNode;
use LangGraph\Agents\Nodes\MiddlewareNode;
use LangGraph\Agents\Nodes\ToolNode;
use LangGraph\Agents\Nodes\Utils as NodeUtils;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\CompiledStateGraph;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Send;
use LangGraph\State\AnnotationRoot;
use LangGraph\State\StateGraph;

/**
 * A ReAct (Reasoning + Acting) agent that combines a language model with tools and middleware.
 *
 * Port of `ReactAgent` from `langchain/src/agents/ReactAgent.ts`. Build one with {@see Agent::create()}.
 *
 * The graph has three kinds of node. `model_request` calls the model, `tools` runs the tool calls, and each
 * middleware contributes `<name>.before_agent`, `<name>.before_model`, `<name>.after_model` and
 * `<name>.after_agent` nodes for the hooks it defines:
 *
 * ```
 * START -> [before_agent...] -> [before_model...] -> model_request -> [after_model... (reverse)]
 *       -> tools -> (loop back to the first before_model, or model_request) | [after_agent... (reverse)] -> END
 * ```
 *
 * `before_agent` and `after_agent` run once per invocation; `before_model` and `after_model` run around every
 * model call. `after_*` hooks run in reverse order, so the first middleware is the outermost layer on the way
 * in and on the way out. A hook may jump (`jumpTo` of `model`, `tools` or `end`) when its `canJumpTo` allows it.
 *
 * Reading the compiled graph: `$agent->graph`, `$agent->checkpointer`, `$agent->store` and `$agent->builder`
 * are available as properties. Assigning `checkpointer` recompiles the graph with the new saver (a compiled
 * graph holds its saver read-only here); assigning `store` sets it on the compiled graph.
 *
 * Known differences from upstream:
 *
 *  - upstream stamps the static config (`withConfig` defaults) onto the compiled graph with
 *    `graph.withConfig(...)` and strips the callbacks from the per-call merge so they fire once; a compiled
 *    graph here has no config of its own, so the defaults (callbacks included) are merged into every `invoke` /
 *    `stream` / `streamEvents` call instead, and a handler present in both is delivered once;
 *  - a graph run reports chain callbacks (`handleChainStart`/`End`) only through `streamEvents`;
 *  - `version: "v3"` event streaming and stream transformers have no PHP counterpart (there is no transformer
 *    protocol), so `streamTransformers` are accepted and ignored;
 *  - structured responses (`responseFormat`) belong to WP-21c;
 *  - config passed as a {@see RunnableConfig} object overwrites the defaults field by field (an object cannot say
 *    which fields it set), so pass a config array (`['recursionLimit' => 100]`) to override only some.
 */
final class ReactAgent
{
    private const TOOL_BEHAVIOR_V1 = 'v1';
    private const TOOL_BEHAVIOR_V2 = 'v2';

    /** @var array<string, mixed> The `createAgent` options. */
    public array $options;

    private CompiledStateGraph $compiled;

    private StateGraph $stateGraph;

    /** @var array<string, mixed> What the graph was compiled with. */
    private array $compileOptions;

    private string $toolBehaviorVersion = self::TOOL_BEHAVIOR_V2;

    private AgentNode $agentNode;

    private RunnableConfig $defaultConfig;

    /**
     * @param array<string, mixed>                 $options       `model`, `tools`, `systemPrompt`, `responseFormat`, `stateSchema`,
     *                                                            `contextSchema`, `middleware`, `checkpointer`, `store`, `signal`, `name`,
     *                                                            `description`, `includeAgentName`, `version`, `streamTransformers`
     * @param RunnableConfig|array<string, mixed>|null $defaultConfig
     */
    public function __construct(array $options, RunnableConfig|array|null $defaultConfig = null)
    {
        $this->options = $options;

        $this->defaultConfig = self::mergeConfigs(
            $defaultConfig instanceof RunnableConfig ? $defaultConfig : (RunnableConfig::fromArray($defaultConfig) ?? new RunnableConfig()),
            ['metadata' => ['ls_integration' => 'langchain_create_agent'], 'configurable' => ['ls_agent_type' => 'root']],
        );
        if (isset($options['name']) && $options['name'] !== '') {
            $this->defaultConfig = self::mergeConfigs(
                $this->defaultConfig,
                ['metadata' => ['lc_agent_name' => $options['name']]],
            );
        }
        $this->toolBehaviorVersion = $options['version'] ?? $this->toolBehaviorVersion;

        // Validate that the model option is provided.
        if (!isset($options['model']) || $options['model'] === '') {
            throw new \Exception('`model` option is required to create an agent.');
        }

        // Check if the LLM already has bound tools and throw if it does.
        if (!\is_string($options['model'])) {
            Utils::validateLLMHasNoBoundTools($options['model']);
        }

        /** @var list<array<string, mixed>|object> $middleware */
        $middleware = array_values((array) ($options['middleware'] ?? []));

        // The complete list of tools: the options' and the middleware's.
        $middlewareTools = [];
        foreach ($middleware as $m) {
            $tools = Utils::middlewareValue($m, 'tools');
            if ($tools) {
                array_push($middlewareTools, ...array_values((array) $tools));
            }
        }
        $toolClasses = [...array_values((array) ($options['tools'] ?? [])), ...$middlewareTools];

        // If any of the tools return directly after running, the graph needs to check whether they were called.
        $shouldReturnDirect = [];
        foreach ($toolClasses as $tool) {
            if (Utils::isClientTool($tool) && ($tool->returnDirect ?? false)) {
                $shouldReturnDirect[] = $tool instanceof \LangChain\Tools\StructuredTool ? $tool->name : $tool->getName();
            }
        }
        $shouldReturnDirect = array_values(array_unique($shouldReturnDirect));

        // A schema that merges the agent's base schema with the middleware state schemas.
        $hasDynamicStructuredResponse = false;
        foreach ($middleware as $m) {
            if (Utils::middlewareValue($m, 'wrapModelCall') !== null) {
                $hasDynamicStructuredResponse = true;
                break;
            }
        }
        ['state' => $state, 'input' => $input, 'output' => $output] = Annotation::createAgentState(
            ($options['responseFormat'] ?? null) !== null || $hasDynamicStructuredResponse,
            $options['stateSchema'] ?? null,
            $middleware,
        );

        // createAgentState() builds a separate `messages` channel for each of the three schemas, and the state
        // graph only accepts two schemas sharing a key when their channels are equal (identical reducer
        // closures here). Give input and output the state's own channel.
        $messagesChannel = $state->spec['messages'];
        $input = new AnnotationRoot([...$input->spec, 'messages' => $messagesChannel]);
        $output = new AnnotationRoot([...$output->spec, 'messages' => $messagesChannel]);

        $graphOptions = ['input' => $input, 'output' => $output];
        $contextSchema = self::normalizeContextSchema($options['contextSchema'] ?? null);
        if ($contextSchema !== null) {
            $graphOptions['context'] = $contextSchema;
        }
        $workflow = new StateGraph($state, $graphOptions);

        // Node names for the middleware nodes that have hooks.
        $wrapModelCallHookMiddleware = [];

        $middlewareNames = [];
        foreach ($middleware as $m) {
            $name = Utils::middlewareName($m);
            if (isset($middlewareNames[$name])) {
                throw new \Exception(sprintf('Middleware %s is defined multiple times', $name));
            }
            $middlewareNames[$name] = true;

            if (Utils::middlewareValue($m, 'wrapModelCall') !== null) {
                $wrapModelCallHookMiddleware[] = $m;
            }
        }

        $this->agentNode = new AgentNode([
            'model' => $options['model'],
            'systemMessage' => Utils::normalizeSystemPrompt($options['systemPrompt'] ?? null),
            'includeAgentName' => $options['includeAgentName'] ?? null,
            'name' => $options['name'] ?? null,
            'responseFormat' => $options['responseFormat'] ?? null,
            'middleware' => $middleware,
            'toolClasses' => $toolClasses,
            'shouldReturnDirect' => $shouldReturnDirect,
            'signal' => $options['signal'] ?? null,
            'wrapModelCallHookMiddleware' => $wrapModelCallHookMiddleware,
        ]);

        /** @var array<string, array{0: class-string<MiddlewareNode>, 1: string}> $hookNodeKinds */
        $hookNodeKinds = [
            'beforeAgent' => [BeforeAgentNode::class, 'before_agent'],
            'beforeModel' => [BeforeModelNode::class, 'before_model'],
            'afterModel' => [AfterModelNode::class, 'after_model'],
            'afterAgent' => [AfterAgentNode::class, 'after_agent'],
        ];
        /** @var array<string, list<array{index: int, name: string, allowed: list<string>|null}>> $hookNodes */
        $hookNodes = ['beforeAgent' => [], 'beforeModel' => [], 'afterModel' => [], 'afterAgent' => []];
        foreach ($middleware as $i => $m) {
            foreach ($hookNodeKinds as $hook => [$nodeClass, $suffix]) {
                $hookValue = Utils::middlewareValue($m, $hook);
                if ($hookValue === null) {
                    continue;
                }

                $node = new $nodeClass($m);
                $nodeName = Utils::middlewareName($m) . '.' . $suffix;
                $hookNodes[$hook][] = [
                    'index' => $i,
                    'name' => $nodeName,
                    'allowed' => MiddlewareUtils::getHookConstraint($hookValue),
                ];
                $workflow->addNode($nodeName, $node, $node->nodeOptions());
            }
        }
        $beforeAgentNodes = $hookNodes['beforeAgent'];
        $beforeModelNodes = $hookNodes['beforeModel'];
        $afterModelNodes = $hookNodes['afterModel'];
        $afterAgentNodes = $hookNodes['afterAgent'];

        // Add nodes.
        $workflow->addNode(AgentNode::AGENT_NODE_NAME, $this->agentNode);

        // If any middleware has wrapToolCall, a ToolNode is needed even without pre-registered tools, to let the
        // middleware handle dynamically registered tools.
        $hasWrapToolCallMiddleware = false;
        foreach ($middleware as $m) {
            if (Utils::middlewareValue($m, 'wrapToolCall') !== null) {
                $hasWrapToolCallMiddleware = true;
                break;
            }
        }
        $clientTools = array_values(array_filter($toolClasses, Utils::isClientTool(...)));

        if ($clientTools !== [] || $hasWrapToolCallMiddleware) {
            $wrapToolCall = Utils::wrapToolCall($middleware);
            $workflow->addNode(ToolNode::TOOLS_NODE_NAME, new ToolNode($clientTools, [
                'signal' => $options['signal'] ?? null,
                ...($wrapToolCall !== null ? ['wrapToolCall' => $wrapToolCall] : []),
            ]));
        }

        // Add edges.
        // The entry node runs once at the start: before_agent -> before_model -> model_request.
        $entryNode = $beforeAgentNodes[0]['name'] ?? $beforeModelNodes[0]['name'] ?? AgentNode::AGENT_NODE_NAME;

        // The loop entry node is the beginning of the agent loop (excluding before_agent): where tools loop back to.
        $loopEntryNode = $beforeModelNodes[0]['name'] ?? AgentNode::AGENT_NODE_NAME;

        // The exit node runs once at the end: after_agent or END.
        $exitNode = $afterAgentNodes !== [] ? $afterAgentNodes[array_key_last($afterAgentNodes)]['name'] : Constants::END;

        $workflow->addEdge(Constants::START, $entryNode);

        // Whether tools are available for routing: registered client tools AND dynamic tools via middleware.
        $hasToolsAvailable = $clientTools !== [] || $hasWrapToolCallMiddleware;

        // Connect the beforeAgent nodes (run once at the start).
        foreach ($beforeAgentNodes as $i => $node) {
            $isLast = $i === \count($beforeAgentNodes) - 1;
            $nextDefault = $isLast ? $loopEntryNode : $beforeAgentNodes[$i + 1]['name'];

            if ($node['allowed'] !== null && $node['allowed'] !== []) {
                $allowedMapped = self::mapAllowed($node['allowed'], $hasToolsAvailable);
                // Replace END with exitNode (which could be an afterAgent node).
                $destinations = array_values(array_unique([
                    $nextDefault,
                    ...array_map(static fn (?string $dest): string => $dest === Constants::END ? $exitNode : (string) $dest, $allowedMapped),
                ]));

                $workflow->addConditionalEdges(
                    $node['name'],
                    $this->createBeforeAgentRouter($nextDefault, $exitNode, $hasToolsAvailable),
                    $destinations,
                );
            } else {
                $workflow->addEdge($node['name'], $nextDefault);
            }
        }

        // Connect the beforeModel nodes; add conditional routing ONLY if allowed jumps are specified.
        foreach ($beforeModelNodes as $i => $node) {
            $isLast = $i === \count($beforeModelNodes) - 1;
            $nextDefault = $isLast ? AgentNode::AGENT_NODE_NAME : $beforeModelNodes[$i + 1]['name'];

            if ($node['allowed'] !== null && $node['allowed'] !== []) {
                $allowedMapped = self::mapAllowed($node['allowed'], $hasToolsAvailable);
                $destinations = array_values(array_unique([$nextDefault, ...$allowedMapped]));

                $workflow->addConditionalEdges(
                    $node['name'],
                    $this->createBeforeModelRouter($nextDefault, $hasToolsAvailable),
                    $destinations,
                );
            } else {
                $workflow->addEdge($node['name'], $nextDefault);
            }
        }

        // Connect the agent to the last afterModel node (for reverse order execution).
        if ($afterModelNodes !== []) {
            $workflow->addEdge(AgentNode::AGENT_NODE_NAME, $afterModelNodes[array_key_last($afterModelNodes)]['name']);
        } else {
            // No afterModel nodes: connect model_request directly to the model paths.
            $modelPaths = $this->getModelPaths(false, $hasToolsAvailable);
            // Replace END with exitNode in the destinations, since exitNode might be an afterAgent node.
            $destinations = array_map(static fn (string $p): string => $p === Constants::END ? $exitNode : $p, $modelPaths);
            if (\count($destinations) === 1) {
                $workflow->addEdge(AgentNode::AGENT_NODE_NAME, $destinations[0]);
            } else {
                $workflow->addConditionalEdges(AgentNode::AGENT_NODE_NAME, $this->createModelRouter($exitNode), $destinations);
            }
        }

        // Connect the afterModel nodes in reverse sequence; conditional routing ONLY if allowed jumps are specified per node.
        for ($i = \count($afterModelNodes) - 1; $i > 0; $i--) {
            $node = $afterModelNodes[$i];
            $nextDefault = $afterModelNodes[$i - 1]['name'];

            if ($node['allowed'] !== null && $node['allowed'] !== []) {
                $allowedMapped = self::mapAllowed($node['allowed'], $hasToolsAvailable);
                $destinations = array_values(array_unique([$nextDefault, ...$allowedMapped]));

                $workflow->addConditionalEdges(
                    $node['name'],
                    $this->createAfterModelSequenceRouter($node['allowed'], $nextDefault, $hasToolsAvailable),
                    $destinations,
                );
            } else {
                $workflow->addEdge($node['name'], $nextDefault);
            }
        }

        // Connect the first afterModel node (last to execute) to the model paths with jumpTo support.
        if ($afterModelNodes !== []) {
            $firstAfterModel = $afterModelNodes[0];

            // Include exitNode in the paths since afterModel should be able to route to after_agent or END.
            $modelPaths = array_values(array_filter(
                $this->getModelPaths(true, $hasToolsAvailable),
                static fn (string $p): bool => $p !== ToolNode::TOOLS_NODE_NAME || $hasToolsAvailable,
            ));

            $allowJump = $firstAfterModel['allowed'] !== null && $firstAfterModel['allowed'] !== [];

            $destinations = array_map(static fn (string $p): string => $p === Constants::END ? $exitNode : $p, $modelPaths);

            $workflow->addConditionalEdges(
                $firstAfterModel['name'],
                $this->createAfterModelRouter($allowJump, $exitNode, $hasToolsAvailable),
                $destinations,
            );
        }

        // Connect the afterAgent nodes (run once at the end, in reverse order like afterModel).
        for ($i = \count($afterAgentNodes) - 1; $i > 0; $i--) {
            $node = $afterAgentNodes[$i];
            $nextDefault = $afterAgentNodes[$i - 1]['name'];

            if ($node['allowed'] !== null && $node['allowed'] !== []) {
                $allowedMapped = self::mapAllowed($node['allowed'], $hasToolsAvailable);
                $destinations = array_values(array_unique([$nextDefault, ...$allowedMapped]));

                $workflow->addConditionalEdges(
                    $node['name'],
                    $this->createAfterModelSequenceRouter($node['allowed'], $nextDefault, $hasToolsAvailable),
                    $destinations,
                );
            } else {
                $workflow->addEdge($node['name'], $nextDefault);
            }
        }

        // Connect the first afterAgent node (last to execute) to END.
        if ($afterAgentNodes !== []) {
            $firstAfterAgent = $afterAgentNodes[0];

            if ($firstAfterAgent['allowed'] !== null && $firstAfterAgent['allowed'] !== []) {
                $allowedMapped = self::mapAllowed($firstAfterAgent['allowed'], $hasToolsAvailable);

                // For after_agent only the explicitly allowed destinations are used (no loopEntryNode): the
                // default destination, when no jump occurs, is END.
                $destinations = array_values(array_unique([Constants::END, ...$allowedMapped]));

                $workflow->addConditionalEdges(
                    $firstAfterAgent['name'],
                    $this->createAfterModelSequenceRouter($firstAfterAgent['allowed'], Constants::END, $hasToolsAvailable),
                    $destinations,
                );
            } else {
                $workflow->addEdge($firstAfterAgent['name'], Constants::END);
            }
        }

        // Add the edges of the tools node (registered tools and dynamic tools via middleware).
        if ($hasToolsAvailable) {
            // Tools return to the loop entry node (not including before_agent).
            $toolReturnTarget = $loopEntryNode;

            if ($shouldReturnDirect !== []) {
                $workflow->addConditionalEdges(
                    ToolNode::TOOLS_NODE_NAME,
                    $this->createToolsRouter(array_fill_keys($shouldReturnDirect, true), $exitNode, $toolReturnTarget),
                    array_values(array_unique([$toolReturnTarget, $exitNode])),
                );
            } else {
                $workflow->addEdge(ToolNode::TOOLS_NODE_NAME, $toolReturnTarget);
            }
        }

        $this->stateGraph = $workflow;
        $this->compileOptions = array_filter([
            'checkpointer' => $options['checkpointer'] ?? null,
            'store' => $options['store'] ?? null,
            'name' => $options['name'] ?? null,
            'description' => $options['description'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);
        $this->compiled = $workflow->compile($this->compileOptions);
    }

    /**
     * The compiled state graph.
     */
    public function __get(string $name): mixed
    {
        return match ($name) {
            'graph' => $this->compiled,
            'checkpointer' => $this->compiled->checkpointer,
            'store' => $this->compiled->store,
            'builder' => $this->compiled->builder,
            default => throw new \Error(sprintf('Undefined property: %s::$%s', self::class, $name)),
        };
    }

    public function __isset(string $name): bool
    {
        return \in_array($name, ['graph', 'checkpointer', 'store', 'builder'], true);
    }

    public function __set(string $name, mixed $value): void
    {
        if ($name === 'checkpointer') {
            // The compiled graph holds its saver read-only: compile again with the new one.
            $this->compileOptions['checkpointer'] = $value;
            if ($value === null) {
                unset($this->compileOptions['checkpointer']);
            }
            $this->compileOptions['store'] = $this->compiled->store;
            if ($this->compileOptions['store'] === null) {
                unset($this->compileOptions['store']);
            }
            $this->compiled = $this->stateGraph->compile($this->compileOptions);

            return;
        }

        if ($name === 'store') {
            $this->compiled->store = $value;
            if ($value === null) {
                unset($this->compileOptions['store']);
            } else {
                $this->compileOptions['store'] = $value;
            }

            return;
        }

        throw new \Error(sprintf('Cannot create dynamic property %s::$%s', self::class, $name));
    }

    /**
     * A new agent with the given config merged into the existing config.
     *
     * The merged config is applied as a default that gets merged with any config passed at invocation time
     * (`invoke` / `stream`); invocation-time config takes precedence.
     *
     * @param RunnableConfig|array<string, mixed> $config
     */
    public function withConfig(RunnableConfig|array $config): self
    {
        return new self($this->options, self::mergeConfigs($this->defaultConfig, $config));
    }

    /**
     * The config for a graph call: the defaults under the call's.
     *
     * @param RunnableConfig|array<string, mixed>|null $config
     */
    private function configForRun(RunnableConfig|array|null $config = null): RunnableConfig
    {
        return self::mergeConfigs($this->defaultConfig, $config);
    }

    /**
     * `mergeConfigs` with the callbacks of both sides kept (the plain merge lets a later list replace an
     * earlier one; upstream's combines them), without delivering the same handler twice.
     *
     * @param RunnableConfig|array<string, mixed>|null $override
     */
    private static function mergeConfigs(RunnableConfig $base, RunnableConfig|array|null $override): RunnableConfig
    {
        $merged = RunnableConfig::mergeConfigs($base, $override);

        $incoming = $override instanceof RunnableConfig ? $override->callbacks : (array) ($override['callbacks'] ?? []);
        $callbacks = $base->callbacks;
        foreach ($incoming as $handler) {
            if (!\in_array($handler, $callbacks, true)) {
                $callbacks[] = $handler;
            }
        }
        $merged->callbacks = $callbacks;

        return $merged;
    }

    /**
     * Get the possible edge destinations from the model node.
     *
     * @param bool $includeModelRequest whether to include "model_request" as a valid path (for jumpTo routing)
     * @return list<string>
     */
    private function getModelPaths(bool $includeModelRequest, bool $hasToolsAvailable): array
    {
        $paths = [];
        if ($hasToolsAvailable) {
            $paths[] = ToolNode::TOOLS_NODE_NAME;
        }
        if ($includeModelRequest) {
            $paths[] = AgentNode::AGENT_NODE_NAME;
        }
        $paths[] = Constants::END;

        return $paths;
    }

    /**
     * The graph destinations of a hook's `canJumpTo`, minus `tools` when no tool is available.
     *
     * @param list<string> $allowed
     * @return list<string|null>
     */
    private static function mapAllowed(array $allowed, bool $hasToolsAvailable): array
    {
        return array_values(array_filter(
            array_map(NodeUtils::parseJumpToTarget(...), $allowed),
            static fn (?string $dest): bool => $dest !== ToolNode::TOOLS_NODE_NAME || $hasToolsAvailable,
        ));
    }

    /**
     * The routing function of the tools node's conditional edge.
     *
     * @param array<string, true> $shouldReturnDirect
     */
    private function createToolsRouter(array $shouldReturnDirect, string $exitNode, string $toolReturnTarget): \Closure
    {
        return function (array $state) use ($shouldReturnDirect, $exitNode, $toolReturnTarget): string {
            $messages = $state['messages'] ?? [];
            $lastMessage = $messages === [] ? null : $messages[array_key_last($messages)];

            // Did a returnDirect tool just run successfully? A failed call goes back to the model so it can
            // correct itself and retry.
            if ($lastMessage instanceof ToolMessage
                && $lastMessage->name !== null && $lastMessage->name !== ''
                && ($lastMessage->additional_kwargs['status'] ?? null) !== 'error'
                && isset($shouldReturnDirect[$lastMessage->name])
            ) {
                // With a response format, route to the agent to generate the structured response; otherwise
                // return directly to the exit node (after_agent or END).
                return ($this->options['responseFormat'] ?? null) !== null ? $toolReturnTarget : $exitNode;
            }

            // For other tools, route back to the loop entry node (a middleware node or the agent).
            return $toolReturnTarget;
        };
    }

    /**
     * The routing function of the model node's conditional edge.
     */
    private function createModelRouter(string $exitNode = Constants::END): \Closure
    {
        // Determine if the agent should continue or not.
        return function (array $state) use ($exitNode): string|array {
            $messages = $state['messages'] ?? [];
            $lastMessage = $messages === [] ? null : $messages[array_key_last($messages)];
            $toolCalls = self::toolCallsOf($lastMessage);

            if ($toolCalls === []) {
                return $exitNode;
            }

            // Are all the tool calls for structured response extraction?
            if (self::allExtractCalls($toolCalls)) {
                // The AgentNode handles these internally and returns the structured response.
                return $exitNode;
            }

            // v1: the tool node processes the whole message.
            if ($this->toolBehaviorVersion === self::TOOL_BEHAVIOR_V1) {
                return ToolNode::TOOLS_NODE_NAME;
            }

            // Route to the tools node, filtering out the structured response tool calls.
            $regularToolCalls = self::regularCalls($toolCalls);
            if ($regularToolCalls === []) {
                return $exitNode;
            }

            return array_map(
                static fn (array $toolCall): Send => new Send(ToolNode::TOOLS_NODE_NAME, [...$state, 'lg_tool_call' => $toolCall]),
                $regularToolCalls,
            );
        };
    }

    /**
     * The routing function for jumpTo after the first afterModel hook (the last to run).
     *
     * It checks whether `jumpTo` is set in the state after afterModel middleware ran. If set (and jumping is
     * allowed), it routes to the target; otherwise it falls back to the normal model routing logic. The jumpTo
     * is cleared when the target is entered, which prevents infinite loops.
     */
    private function createAfterModelRouter(bool $allowJump, string $exitNode, bool $hasToolsAvailable): \Closure
    {
        $hasStructuredResponse = ($this->options['responseFormat'] ?? null) !== null;

        return function (array $state) use ($allowJump, $exitNode, $hasToolsAvailable, $hasStructuredResponse): string|Send|array {
            $messages = $state['messages'] ?? [];
            $lastMessage = $messages === [] ? null : $messages[array_key_last($messages)];

            // First, check whether the last message is a final answer. If so, ignore any existing jumpTo and
            // go to the exit node.
            if (self::isAi($lastMessage) && self::toolCallsOf($lastMessage) === []) {
                return $exitNode;
            }

            // Check if jumpTo is set in the state and allowed.
            $jumpTo = $state['jumpTo'] ?? null;
            if ($allowJump && $jumpTo) {
                $destination = NodeUtils::parseJumpToTarget($jumpTo);
                if ($destination === Constants::END) {
                    return $exitNode;
                }
                if ($destination === ToolNode::TOOLS_NODE_NAME) {
                    // Jumping to tools without any tool available goes to the exit node.
                    if (!$hasToolsAvailable) {
                        return $exitNode;
                    }

                    return new Send(ToolNode::TOOLS_NODE_NAME, [...$state, 'jumpTo' => null]);
                }

                // destination === "model_request"
                return new Send(AgentNode::AGENT_NODE_NAME, [...$state, 'jumpTo' => null]);
            }

            // Check if there are pending tool calls.
            $toolMessageIds = [];
            $lastAiMessage = null;
            foreach ($messages as $message) {
                if ($message instanceof ToolMessage) {
                    $toolMessageIds[$message->toolCallId] = true;
                }
                if (self::isAi($message)) {
                    $lastAiMessage = $message;
                }
            }
            $pendingToolCalls = $lastAiMessage === null ? null : array_values(array_filter(
                self::toolCallsOf($lastAiMessage),
                static fn (array $call): bool => !isset($toolMessageIds[(string) ($call['id'] ?? '')]),
            ));

            if ($pendingToolCalls !== null && $pendingToolCalls !== []) {
                if ($this->toolBehaviorVersion === self::TOOL_BEHAVIOR_V1) {
                    return ToolNode::TOOLS_NODE_NAME;
                }

                return array_map(
                    static fn (array $toolCall): Send => new Send(ToolNode::TOOLS_NODE_NAME, [...$state, 'lg_tool_call' => $toolCall]),
                    $pendingToolCalls,
                );
            }

            // All tool calls are answered, but there is no structured response call yet: back to model_request.
            $hasStructuredResponseCalls = $lastAiMessage !== null && self::anyExtractCall(self::toolCallsOf($lastAiMessage));

            if ($pendingToolCalls !== null && $pendingToolCalls === [] && !$hasStructuredResponseCalls && $hasStructuredResponse) {
                return AgentNode::AGENT_NODE_NAME;
            }

            $toolCalls = self::toolCallsOf($lastMessage);
            if ($toolCalls === []) {
                return $exitNode;
            }

            // Check if all the tool calls are for structured response extraction, or none is a regular call.
            $hasOnlyStructuredResponseCalls = self::allExtractCalls($toolCalls);
            $regularToolCalls = self::regularCalls($toolCalls);

            if ($hasOnlyStructuredResponseCalls || $regularToolCalls === []) {
                return $exitNode;
            }

            if ($this->toolBehaviorVersion === self::TOOL_BEHAVIOR_V1) {
                return ToolNode::TOOLS_NODE_NAME;
            }

            return array_map(
                static fn (array $toolCall): Send => new Send(ToolNode::TOOLS_NODE_NAME, [...$state, 'lg_tool_call' => $toolCall]),
                $regularToolCalls,
            );
        };
    }

    /**
     * The routing function for jumpTo in the afterModel / afterAgent sequence (reverse order) hooks.
     *
     * @param list<string> $allowed
     */
    private function createAfterModelSequenceRouter(array $allowed, string $nextDefault, bool $hasToolsAvailable): \Closure
    {
        $allowedSet = array_map(NodeUtils::parseJumpToTarget(...), $allowed);

        return static function (array $state) use ($allowedSet, $nextDefault, $hasToolsAvailable): string|Send {
            $jumpTo = $state['jumpTo'] ?? null;
            if ($jumpTo) {
                $dest = NodeUtils::parseJumpToTarget($jumpTo);
                if ($dest === Constants::END && \in_array(Constants::END, $allowedSet, true)) {
                    return Constants::END;
                }
                if ($dest === ToolNode::TOOLS_NODE_NAME && \in_array(ToolNode::TOOLS_NODE_NAME, $allowedSet, true)) {
                    if (!$hasToolsAvailable) {
                        return Constants::END;
                    }

                    return new Send(ToolNode::TOOLS_NODE_NAME, [...$state, 'jumpTo' => null]);
                }
                if ($dest === AgentNode::AGENT_NODE_NAME && \in_array(AgentNode::AGENT_NODE_NAME, $allowedSet, true)) {
                    return new Send(AgentNode::AGENT_NODE_NAME, [...$state, 'jumpTo' => null]);
                }
            }

            return $nextDefault;
        };
    }

    /**
     * The routing function for jumpTo after the beforeAgent hooks.
     */
    private function createBeforeAgentRouter(string $nextDefault, string $exitNode, bool $hasToolsAvailable): \Closure
    {
        return static function (array $state) use ($nextDefault, $exitNode, $hasToolsAvailable): string|Send {
            $jumpTo = $state['jumpTo'] ?? null;
            if (!$jumpTo) {
                return $nextDefault;
            }

            $destination = NodeUtils::parseJumpToTarget($jumpTo);
            if ($destination === Constants::END) {
                return $exitNode;
            }
            if ($destination === ToolNode::TOOLS_NODE_NAME) {
                if (!$hasToolsAvailable) {
                    return $exitNode;
                }

                return new Send(ToolNode::TOOLS_NODE_NAME, [...$state, 'jumpTo' => null]);
            }

            return new Send(AgentNode::AGENT_NODE_NAME, [...$state, 'jumpTo' => null]);
        };
    }

    /**
     * The routing function for jumpTo after the beforeModel hooks.
     */
    private function createBeforeModelRouter(string $nextDefault, bool $hasToolsAvailable): \Closure
    {
        return static function (array $state) use ($nextDefault, $hasToolsAvailable): string|Send {
            $jumpTo = $state['jumpTo'] ?? null;
            if (!$jumpTo) {
                return $nextDefault;
            }

            $destination = NodeUtils::parseJumpToTarget($jumpTo);
            if ($destination === Constants::END) {
                return Constants::END;
            }
            if ($destination === ToolNode::TOOLS_NODE_NAME) {
                if (!$hasToolsAvailable) {
                    return Constants::END;
                }

                return new Send(ToolNode::TOOLS_NODE_NAME, [...$state, 'jumpTo' => null]);
            }

            return new Send(AgentNode::AGENT_NODE_NAME, [...$state, 'jumpTo' => null]);
        };
    }

    /**
     * Initialize the middleware states if they are not already present in the input state.
     *
     * The state of the thread (when there is a checkpoint) is merged under the input, and a default only fills
     * a key that is still missing.
     */
    private function initializeMiddlewareStates(mixed $state, RunnableConfig $config): mixed
    {
        $middleware = (array) ($this->options['middleware'] ?? []);
        if ($middleware === [] || $state instanceof Command || $state === null || !\is_array($state)) {
            return $state;
        }

        $defaultStates = NodeUtils::initializeMiddlewareStates($middleware, $state);

        try {
            $threadValues = $this->compiled->getState($config)->values;
        } catch (\Throwable) {
            $threadValues = [];
        }
        $updatedState = [...(\is_array($threadValues) ? $threadValues : []), ...$state];

        // Only add defaults for keys that don't exist in the current state.
        foreach ($defaultStates as $key => $value) {
            if (!\array_key_exists($key, $updatedState)) {
                $updatedState[$key] = $value;
            }
        }

        return $updatedState;
    }

    /**
     * Execute the agent with the given state and return the final state.
     *
     * This runs the agent's entire workflow: processing the input messages through any middleware, calling
     * the model, executing the tool calls the model makes, and running all the middleware hooks.
     *
     * @param array<string, mixed>|Command|null        $state  `messages` plus any state the agent and middleware declare, or a Command
     * @param RunnableConfig|array<string, mixed>|null $config `context`, `configurable` (`thread_id`, ...), `signal`, `recursionLimit`, ...
     * @return mixed the final state: `messages`, `structuredResponse` (when configured) and the middleware state
     */
    public function invoke(mixed $state, RunnableConfig|array|null $config = null): mixed
    {
        $mergedConfig = $this->configForRun($config);
        $initializedState = $this->initializeMiddlewareStates($state, $mergedConfig);

        return $this->compiled->invoke($initializedState, $mergedConfig);
    }

    /**
     * Stream the agent's execution as `[mode, chunk]` tuples.
     *
     * @param array<string, mixed>|Command|null        $state
     * @param RunnableConfig|array<string, mixed>|null $config
     * @return \Generator<int, array{0: string, 1: mixed}>
     */
    public function stream(mixed $state, RunnableConfig|array|null $config = null): \Generator
    {
        $mergedConfig = $this->configForRun($config);
        $initializedState = $this->initializeMiddlewareStates($state, $mergedConfig);

        return $this->compiled->stream($initializedState, $mergedConfig);
    }

    /**
     * Stream events (`v1` / `v2`) from the compiled graph.
     *
     * @param array<string, mixed>|Command|null        $state
     * @param RunnableConfig|array<string, mixed>|null $config
     * @param array<string, mixed>                     $streamOptions
     * @return \Generator<int, mixed>
     */
    public function streamEvents(mixed $state, RunnableConfig|array|null $config = null, string $version = 'v2', array $streamOptions = []): \Generator
    {
        if ($version === 'v3') {
            throw new \InvalidArgumentException('streamEvents version "v3" is not available: there is no stream transformer protocol in this port.');
        }

        $mergedConfig = $this->configForRun($config);
        $initializedState = $this->initializeMiddlewareStates($state, $mergedConfig);

        return $this->compiled->streamEvents($initializedState, $mergedConfig, $version, $streamOptions);
    }

    /**
     * @param RunnableConfig|array<string, mixed> $config
     * @param array<string, mixed>                $options
     */
    public function getState(RunnableConfig|array $config, array $options = []): mixed
    {
        return $this->compiled->getState($config, $options);
    }

    /**
     * @param RunnableConfig|array<string, mixed> $config
     */
    public function getStateHistory(RunnableConfig|array $config, mixed $options = null): array
    {
        return $this->compiled->getStateHistory($config, $options);
    }

    public function getSubgraphs(?string $namespace = null, bool $recurse = false): \Generator
    {
        return $this->compiled->getSubgraphs($namespace, $recurse);
    }

    /**
     * @param RunnableConfig|array<string, mixed> $config
     */
    public function updateState(RunnableConfig|array $config, mixed $values, ?string $asNode = null): RunnableConfig
    {
        return $this->compiled->updateState($config, $values, $asNode);
    }

    /**
     * A context schema in a form the state graph accepts (an AnnotationRoot, or a JSON Schema with `type: object`).
     *
     * @return AnnotationRoot|array<string, mixed>|null
     */
    private static function normalizeContextSchema(mixed $schema): AnnotationRoot|array|null
    {
        if (AnnotationRoot::isInstance($schema)) {
            return $schema;
        }
        if (\is_array($schema) && \is_array($schema['properties'] ?? null) && $schema['properties'] !== []) {
            return ['type' => 'object', ...$schema];
        }

        return null;
    }

    private static function isAi(mixed $message): bool
    {
        return $message instanceof AIMessage || $message instanceof AIMessageChunk;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function toolCallsOf(mixed $message): array
    {
        if ($message instanceof AIMessageChunk) {
            return $message->toMessage()->toolCalls;
        }

        return $message instanceof AIMessage ? $message->toolCalls : [];
    }

    /** @param list<array<string, mixed>> $toolCalls */
    private static function allExtractCalls(array $toolCalls): bool
    {
        foreach ($toolCalls as $call) {
            if (!str_starts_with((string) ($call['name'] ?? ''), 'extract-')) {
                return false;
            }
        }

        return true;
    }

    /** @param list<array<string, mixed>> $toolCalls */
    private static function anyExtractCall(array $toolCalls): bool
    {
        foreach ($toolCalls as $call) {
            if (str_starts_with((string) ($call['name'] ?? ''), 'extract-')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<array<string, mixed>> $toolCalls
     * @return list<array<string, mixed>>
     */
    private static function regularCalls(array $toolCalls): array
    {
        return array_values(array_filter(
            $toolCalls,
            static fn (array $call): bool => !str_starts_with((string) ($call['name'] ?? ''), 'extract-'),
        ));
    }
}
