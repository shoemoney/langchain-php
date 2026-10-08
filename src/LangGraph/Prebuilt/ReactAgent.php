<?php

declare(strict_types=1);

namespace LangGraph\Prebuilt;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Messages\ToolMessageChunk;
use LangChain\Runnables\RunnableBinding;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableInterface;
use LangChain\Runnables\RunnableLambda;
use LangChain\Runnables\RunnableSequence;
use LangChain\Tools\StructuredTool;
use LangGraph\Pregel\CompiledStateGraph;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Send;
use LangGraph\State\AnnotationRoot;
use LangGraph\State\StateGraph;

/**
 * Build a tool-calling agent graph.
 *
 * Port of `createReactAgent` and its helpers (`_shouldBindTools`, `_bindTools`, `_getModel`) from
 * `langgraph-core/src/prebuilt/react_agent_executor.ts`.
 *
 * ```
 * $agent = ReactAgent::create(['llm' => $model, 'tools' => [$search]]);
 * $result = $agent->invoke(['messages' => [new HumanMessage('hi')]]);
 * ```
 *
 * The graph is `START -> agent -> (tools -> agent)* -> END`, with three optional nodes:
 * `pre_model_hook` before `agent`, `post_model_hook` after it, and `generate_structured_response` once the
 * loop ends. `$params` takes the keys of upstream's `CreateReactAgentParams`:
 *
 *  - `llm` (required): a chat model, a model bound with tools, a sequence containing one, or a callable
 *    `fn(array $state, RunnableConfig $config): RunnableInterface` that picks a model per call. The config
 *    stands in for upstream's `Runtime`: its `context` and `configurable` are what a dynamic model reads.
 *  - `tools` (required): a list of tools (a non-runnable entry is a provider-side "server" tool, offered to
 *    the model but never executed) or a ready {@see ToolNode}.
 *  - `prompt`: a string (becomes a system message), a `SystemMessage`, `fn(array $state, RunnableConfig $config)`
 *    returning messages, or a runnable. `stateModifier` and `messageModifier` are the deprecated spellings and
 *    may not be combined with it.
 *  - `stateSchema` / `contextSchema`: extra state channels (must still contain `messages`) and the context shape.
 *  - `checkpointer` (alias `checkpointSaver`), `interruptBefore`, `interruptAfter`, `store`, `name`, `description`.
 *  - `responseFormat`: a JSON Schema, or `['schema' => ..., 'prompt' => ..., ...options]`. After the loop one more
 *    model call produces `structuredResponse` through `withStructuredOutput()`.
 *  - `includeAgentName`: `"inline"` to fold the agent name into message text for the model.
 *  - `preModelHook` / `postModelHook`: nodes to run before / after the model call.
 *  - `version`: `"v1"` (default) runs all of a message's tool calls in one `tools` task; `"v2"` fans each call
 *    out as its own `Send`.
 *
 * The tool calls of one message run one after another in the `tools` node (upstream uses `Promise.all`).
 */
final class ReactAgent
{
    public const NODE_AGENT = 'agent';
    public const NODE_TOOLS = 'tools';
    public const NODE_PRE_MODEL_HOOK = 'pre_model_hook';
    public const NODE_POST_MODEL_HOOK = 'post_model_hook';
    public const NODE_GENERATE_STRUCTURED_RESPONSE = 'generate_structured_response';

    private const PROMPT_RUNNABLE_NAME = 'prompt';

    private function __construct()
    {
    }

    /**
     * Port of `createReactAgent`.
     *
     * @param array<string, mixed> $params
     */
    public static function create(array $params): CompiledStateGraph
    {
        if (!array_key_exists('llm', $params) || $params['llm'] === null) {
            throw new \InvalidArgumentException('ReactAgent::create() requires "llm".');
        }
        if (!array_key_exists('tools', $params)) {
            throw new \InvalidArgumentException('ReactAgent::create() requires "tools".');
        }

        $llm = $params['llm'];
        $tools = $params['tools'];
        $messageModifier = $params['messageModifier'] ?? null;
        $stateModifier = $params['stateModifier'] ?? null;
        $prompt = $params['prompt'] ?? null;
        $stateSchema = $params['stateSchema'] ?? null;
        $contextSchema = $params['contextSchema'] ?? null;
        $responseFormat = $params['responseFormat'] ?? null;
        $preModelHook = $params['preModelHook'] ?? null;
        $postModelHook = $params['postModelHook'] ?? null;
        $name = $params['name'] ?? null;
        $version = $params['version'] ?? 'v1';
        $includeAgentName = $params['includeAgentName'] ?? null;

        if ($version !== 'v1' && $version !== 'v2') {
            throw new \InvalidArgumentException(sprintf('Unknown version "%s": expected "v1" or "v2".', is_scalar($version) ? (string) $version : get_debug_type($version)));
        }

        if ($tools instanceof ToolNode) {
            $toolClasses = $tools->tools;
            $toolNode = $tools;
        } else {
            $toolClasses = array_values((array) $tools);
            $toolNode = new ToolNode(array_values(array_filter($toolClasses, self::isClientTool(...))));
        }

        /** @var RunnableInterface|null $cachedStaticModel */
        $cachedStaticModel = null;

        $getStaticModel = static function (RunnableInterface $llm) use (&$cachedStaticModel, $toolClasses, $prompt, $stateModifier, $messageModifier, $includeAgentName): RunnableInterface {
            if ($cachedStaticModel !== null) {
                return $cachedStaticModel;
            }

            $modelWithTools = self::shouldBindTools($llm, $toolClasses) ? self::bindTools($llm, $toolClasses) : $llm;

            $promptRunnable = self::getPrompt($prompt, $stateModifier, $messageModifier);
            $modelRunnable = $includeAgentName === AgentName::MODE_INLINE
                ? AgentName::withAgentName($modelWithTools, $includeAgentName)
                : $modelWithTools;

            return $cachedStaticModel = $promptRunnable->pipe($modelRunnable);
        };

        $getDynamicModel = static function (callable $llm, array $state, RunnableConfig $config) use ($prompt, $stateModifier, $messageModifier, $includeAgentName): RunnableInterface {
            $model = $llm($state, $config);
            if (!$model instanceof RunnableInterface) {
                throw new \InvalidArgumentException(sprintf('The "llm" callable must return a model, got %s.', get_debug_type($model)));
            }

            return self::getPrompt($prompt, $stateModifier, $messageModifier)->pipe(
                $includeAgentName === AgentName::MODE_INLINE ? AgentName::withAgentName($model, $includeAgentName) : $model
            );
        };

        // If any of the tools are configured to return directly after running, the graph must check for them.
        $shouldReturnDirect = [];
        foreach ($toolClasses as $tool) {
            if ($tool instanceof StructuredTool && $tool->returnDirect) {
                $shouldReturnDirect[$tool->name] = true;
            }
        }

        $isDynamicLlm = self::isDynamicModel($llm);

        $generateStructuredResponse = static function (array $state, RunnableConfig $config) use ($llm, $isDynamicLlm, $responseFormat): array {
            if ($responseFormat === null) {
                throw new \Exception('Attempted to generate structured output with no passed response schema. Please contact us for help.');
            }
            $messages = array_values((array) ($state['messages'] ?? []));

            $model = $isDynamicLlm ? $llm($state, $config) : self::getModel($llm);

            if (!$model instanceof BaseChatModel) {
                throw new \Exception(sprintf('Expected `llm` to be a ChatModel with .withStructuredOutput() method, got %s', get_debug_type($model)));
            }

            if (is_array($responseFormat) && array_key_exists('schema', $responseFormat)) {
                $options = $responseFormat;
                $schema = $options['schema'];
                $formatPrompt = $options['prompt'] ?? null;
                unset($options['schema'], $options['prompt']);

                $modelWithStructuredOutput = $model->withStructuredOutput($schema, $options);
                if ($formatPrompt !== null) {
                    array_unshift($messages, new SystemMessage(['content' => $formatPrompt]));
                }
            } else {
                $modelWithStructuredOutput = $model->withStructuredOutput($responseFormat);
            }

            $response = $modelWithStructuredOutput->invoke($messages, $config);

            // Some `withStructuredOutput` parsers return null instead of throwing when the model output does not
            // satisfy the schema. Surface that as an explicit error so it does not propagate as a missing
            // `structuredResponse`.
            if ($response === null) {
                throw new \Exception(
                    'Failed to parse structured response against the provided `responseFormat` schema: '
                    . 'the structured-output parser returned null/undefined, which usually means the model output did not satisfy the schema.'
                );
            }

            return ['structuredResponse' => $response];
        };

        $callModel = static function (array $state, RunnableConfig $config) use ($llm, $isDynamicLlm, $getStaticModel, $getDynamicModel, $name): array {
            // The model runnable is built here, per call, so a configurable model is validated against live state.
            $modelRunnable = $isDynamicLlm
                ? $getDynamicModel($llm, $state, $config)
                : $getStaticModel($llm);

            $response = $modelRunnable->invoke(self::getModelInputState($state), $config);
            if ($response instanceof BaseMessage) {
                self::stampName($response, $name);
            }

            return ['messages' => [$response]];
        };

        $schema = $stateSchema ?? AgentState::annotation();

        $workflow = new StateGraph(
            $schema,
            $contextSchema instanceof AnnotationRoot || $contextSchema === null ? $contextSchema : ['context' => $contextSchema],
        );
        $workflow->addNode(self::NODE_TOOLS, $toolNode);

        if (!array_key_exists('messages', $workflow->schemaDefinition)) {
            throw new \InvalidArgumentException('Missing required `messages` key in state schema.');
        }

        $conditionalMap = static fn (array $map): array => array_filter($map, static fn (mixed $v): bool => $v !== null);

        // `StateGraph::addEdge()` refuses an unknown node (upstream defers that to compile), so the nodes an edge
        // names are added before the edge.
        $entrypoint = self::NODE_AGENT;
        $inputSchema = null;
        if ($preModelHook !== null) {
            $workflow->addNode(self::NODE_PRE_MODEL_HOOK, $preModelHook);
            $entrypoint = self::NODE_PRE_MODEL_HOOK;

            $inputSchema = new AnnotationRoot($workflow->schemaDefinition + AgentState::preHookAnnotation()->spec);
        }

        $workflow->addNode(self::NODE_AGENT, $callModel, $inputSchema !== null ? ['input' => $inputSchema] : []);
        if ($preModelHook !== null) {
            $workflow->addEdge(self::NODE_PRE_MODEL_HOOK, self::NODE_AGENT);
        }
        $workflow->addEdge(Constants::START, $entrypoint);

        if ($postModelHook !== null) {
            $workflow
                ->addNode(self::NODE_POST_MODEL_HOOK, $postModelHook)
                ->addEdge(self::NODE_AGENT, self::NODE_POST_MODEL_HOOK)
                ->addConditionalEdges(
                    self::NODE_POST_MODEL_HOOK,
                    static function (array $state) use ($version, $entrypoint, $responseFormat): string|array {
                        $messages = array_values((array) ($state['messages'] ?? []));

                        $toolMessageIds = [];
                        foreach ($messages as $message) {
                            if ($message instanceof ToolMessage || $message instanceof ToolMessageChunk) {
                                $toolMessageIds[$message->toolCallId] = true;
                            }
                        }

                        $lastAiMessage = null;
                        for ($i = count($messages) - 1; $i >= 0; $i--) {
                            if ($messages[$i] instanceof AIMessage || $messages[$i] instanceof AIMessageChunk) {
                                $lastAiMessage = $messages[$i];
                                break;
                            }
                        }

                        $pendingToolCalls = [];
                        foreach (self::toolCallsOf($lastAiMessage) as $call) {
                            if (!isset($call['id']) || !isset($toolMessageIds[$call['id']])) {
                                $pendingToolCalls[] = $call;
                            }
                        }

                        $lastMessage = $messages === [] ? null : $messages[count($messages) - 1];
                        if ($pendingToolCalls !== []) {
                            if ($version === 'v2') {
                                return array_map(
                                    static fn (array $toolCall): Send => new Send(self::NODE_TOOLS, [...$state, 'lg_tool_call' => $toolCall]),
                                    $pendingToolCalls,
                                );
                            }

                            return self::NODE_TOOLS;
                        }

                        if ($lastMessage instanceof ToolMessage || $lastMessage instanceof ToolMessageChunk) {
                            return $entrypoint;
                        }
                        if ($responseFormat !== null) {
                            return self::NODE_GENERATE_STRUCTURED_RESPONSE;
                        }

                        return Constants::END;
                    },
                    $conditionalMap([
                        self::NODE_TOOLS => self::NODE_TOOLS,
                        $entrypoint => $entrypoint,
                        self::NODE_GENERATE_STRUCTURED_RESPONSE => $responseFormat !== null ? self::NODE_GENERATE_STRUCTURED_RESPONSE : null,
                        Constants::END => $responseFormat !== null ? null : Constants::END,
                    ]),
                );
        }

        if ($responseFormat !== null) {
            $workflow
                ->addNode(self::NODE_GENERATE_STRUCTURED_RESPONSE, $generateStructuredResponse)
                ->addEdge(self::NODE_GENERATE_STRUCTURED_RESPONSE, Constants::END);
        }

        if ($postModelHook === null) {
            $workflow->addConditionalEdges(
                self::NODE_AGENT,
                static function (array $state) use ($version, $responseFormat): string|array {
                    $messages = array_values((array) ($state['messages'] ?? []));
                    $lastMessage = $messages === [] ? null : $messages[count($messages) - 1];

                    // If there is no tool call, the loop is finished.
                    $toolCalls = self::toolCallsOf($lastMessage);
                    if ($toolCalls === []) {
                        return $responseFormat !== null ? self::NODE_GENERATE_STRUCTURED_RESPONSE : Constants::END;
                    }

                    if ($version === 'v2') {
                        return array_map(
                            static fn (array $toolCall): Send => new Send(self::NODE_TOOLS, [...$state, 'lg_tool_call' => $toolCall]),
                            $toolCalls,
                        );
                    }

                    return self::NODE_TOOLS;
                },
                $conditionalMap([
                    self::NODE_TOOLS => self::NODE_TOOLS,
                    self::NODE_GENERATE_STRUCTURED_RESPONSE => $responseFormat !== null ? self::NODE_GENERATE_STRUCTURED_RESPONSE : null,
                    Constants::END => $responseFormat !== null ? null : Constants::END,
                ]),
            );
        }

        if ($shouldReturnDirect !== []) {
            $workflow->addConditionalEdges(
                self::NODE_TOOLS,
                static function (array $state) use ($shouldReturnDirect, $entrypoint): string {
                    $messages = array_values((array) ($state['messages'] ?? []));
                    // Check the last consecutive tool messages.
                    for ($i = count($messages) - 1; $i >= 0; $i--) {
                        $message = $messages[$i];
                        if (!$message instanceof ToolMessage && !$message instanceof ToolMessageChunk) {
                            break;
                        }
                        if ($message->name !== null && isset($shouldReturnDirect[$message->name])) {
                            return Constants::END;
                        }
                    }

                    return $entrypoint;
                },
                [$entrypoint => $entrypoint, Constants::END => Constants::END],
            );
        } else {
            $workflow->addEdge(self::NODE_TOOLS, $entrypoint);
        }

        $compileOptions = [
            'checkpointer' => $params['checkpointer'] ?? $params['checkpointSaver'] ?? null,
            'interruptBefore' => $params['interruptBefore'] ?? null,
            'interruptAfter' => $params['interruptAfter'] ?? null,
            'store' => $params['store'] ?? null,
            'name' => $name,
            'description' => $params['description'] ?? null,
        ];

        return $workflow->compile(array_filter($compileOptions, static fn (mixed $v): bool => $v !== null));
    }

    /**
     * Whether `$llm` still needs the agent's tools bound to it.
     *
     * Port of `_shouldBindTools`. A model that already carries tools is checked against the agent's: the counts must
     * match and every client tool must appear by name, else this throws. Provider tool shapes are all understood:
     * OpenAI (`type: function`), Anthropic and Google (`name`, Google wrapped in `functionDeclarations`) and Bedrock
     * (`toolSpec.name`). A tool that names nothing (a server tool) counts toward the total and is otherwise ignored.
     *
     * "Already carries tools" is a `RunnableBinding` with tools in its kwargs or config, or (the PHP spelling, because
     * `BaseChatModel::bindTools()` returns the model itself) a chat model whose `kwargs()['tools']` is set.
     *
     * @param list<mixed> $tools
     */
    public static function shouldBindTools(RunnableInterface $llm, array $tools): bool
    {
        $model = self::resolveModelStep($llm);

        $boundTools = self::boundToolsOf($model);
        if ($boundTools === null) {
            return true;
        }

        // Google style.
        if (count($boundTools) === 1 && is_array($boundTools[0]) && isset($boundTools[0]['functionDeclarations'])) {
            $boundTools = array_values((array) $boundTools[0]['functionDeclarations']);
        }

        if (count($tools) !== count($boundTools)) {
            throw new \Exception('Number of tools in the model.bindTools() and tools passed to createReactAgent must match');
        }

        $toolNames = [];
        foreach ($tools as $tool) {
            if (self::isClientTool($tool)) {
                $toolNames[self::toolName($tool)] = true;
            }
        }

        $boundToolNames = [];
        foreach ($boundTools as $boundTool) {
            $boundToolName = null;
            if (is_array($boundTool)) {
                if (($boundTool['type'] ?? null) === 'function') {
                    // OpenAI style.
                    $boundToolName = $boundTool['function']['name'] ?? null;
                } elseif (array_key_exists('name', $boundTool)) {
                    // Anthropic or Google style.
                    $boundToolName = $boundTool['name'];
                } elseif (isset($boundTool['toolSpec']) && is_array($boundTool['toolSpec']) && array_key_exists('name', $boundTool['toolSpec'])) {
                    // Bedrock style.
                    $boundToolName = $boundTool['toolSpec']['name'];
                } else {
                    // Unknown tool type: ignored.
                    continue;
                }
            } elseif (self::isClientTool($boundTool)) {
                $boundToolName = self::toolName($boundTool);
            }

            if (is_string($boundToolName) && $boundToolName !== '') {
                $boundToolNames[$boundToolName] = true;
            }
        }

        $missingTools = array_keys(array_diff_key($toolNames, $boundToolNames));
        if ($missingTools !== []) {
            throw new \Exception(
                sprintf("Missing tools '%s' in the model.bindTools().", implode(',', array_map(strval(...), $missingTools)))
                . 'Tools in the model.bindTools() must match the tools passed to createReactAgent.'
            );
        }

        return false;
    }

    /**
     * Bind tools to whatever model-like `$llm` is.
     *
     * Port of `_bindTools`: a chat model is bound directly; a binding keeps its kwargs and config around the newly
     * bound model; a configurable model is bound through the model it wraps; a sequence has its model step replaced.
     *
     * @param list<mixed> $toolClasses
     */
    public static function bindTools(RunnableInterface $llm, array $toolClasses): RunnableInterface
    {
        $model = self::simpleBindTools($llm, $toolClasses);
        if ($model !== null) {
            return $model;
        }

        if ($llm instanceof ConfigurableModelInterface) {
            $model = self::simpleBindTools($llm->model(), $toolClasses);
            if ($model !== null) {
                return $model;
            }
        }

        if ($llm instanceof RunnableSequence) {
            $modelStep = self::modelStepIndex($llm);
            if ($modelStep !== null) {
                $model = self::simpleBindTools($llm->steps[$modelStep], $toolClasses);
                if ($model !== null) {
                    $nextSteps = $llm->steps;
                    $nextSteps[$modelStep] = $model;

                    return RunnableSequence::from($nextSteps);
                }
            }
        }

        throw new \Exception(sprintf('llm %s must define bindTools method.', get_debug_type($llm)));
    }

    /**
     * Dig the chat model out of a sequence, a configurable model or a binding.
     *
     * Port of `_getModel`.
     */
    public static function getModel(RunnableInterface $llm): RunnableInterface
    {
        $model = self::resolveModelStep($llm);

        // Get the underlying model from a binding (bindings may nest).
        while ($model instanceof RunnableBinding) {
            $model = $model->bound;
        }

        if (!$model instanceof BaseChatModel) {
            throw new \Exception(sprintf(
                'Expected `llm` to be a ChatModel or RunnableBinding (e.g. llm.bind_tools(...)) with invoke() and generate() methods, got %s',
                get_debug_type($model),
            ));
        }

        return $model;
    }

    /** Whether `llm` is a `fn(state, config)` that picks the model per call rather than a model. */
    private static function isDynamicModel(mixed $llm): bool
    {
        return !$llm instanceof RunnableInterface && is_callable($llm);
    }

    private static function isClientTool(mixed $tool): bool
    {
        return $tool instanceof RunnableInterface;
    }

    private static function toolName(mixed $tool): string
    {
        return $tool instanceof StructuredTool ? $tool->name : $tool->getName();
    }

    /**
     * The tool calls of an AI message, or an empty list for anything else.
     *
     * @return list<array<string, mixed>>
     */
    private static function toolCallsOf(mixed $message): array
    {
        if ($message instanceof AIMessageChunk) {
            $message = $message->toMessage();
        }
        if (!$message instanceof AIMessage) {
            return [];
        }

        return array_values($message->toolCalls);
    }

    /** Whether `$step` is something a model can hide behind. */
    private static function isModelStep(mixed $step): bool
    {
        return $step instanceof RunnableBinding || $step instanceof BaseChatModel || $step instanceof ConfigurableModelInterface;
    }

    private static function modelStepIndex(RunnableSequence $sequence): ?int
    {
        foreach ($sequence->steps as $i => $step) {
            if (self::isModelStep($step)) {
                return $i;
            }
        }

        return null;
    }

    /** Look through a sequence and a configurable model to the model-like thing inside. */
    private static function resolveModelStep(RunnableInterface $llm): RunnableInterface
    {
        $model = $llm;
        if ($model instanceof RunnableSequence) {
            $index = self::modelStepIndex($model);
            $model = $index === null ? $model : $model->steps[$index];
        }

        if ($model instanceof ConfigurableModelInterface) {
            $model = $model->model();
        }

        return $model;
    }

    /**
     * The tools already bound to a model, or null when none are.
     *
     * @return list<mixed>|null
     */
    private static function boundToolsOf(RunnableInterface $model): ?array
    {
        if ($model instanceof RunnableBinding) {
            foreach ([$model->kwargs, $model->config ?? []] as $bag) {
                if (isset($bag['tools']) && is_array($bag['tools'])) {
                    return array_values($bag['tools']);
                }
            }

            return self::boundToolsOf($model->bound);
        }

        if ($model instanceof BaseChatModel) {
            $tools = $model->kwargs()['tools'] ?? null;

            return is_array($tools) ? array_values($tools) : null;
        }

        return null;
    }

    /** @param list<mixed> $toolClasses */
    private static function simpleBindTools(RunnableInterface $llm, array $toolClasses): ?RunnableInterface
    {
        if ($llm instanceof BaseChatModel && $llm->supportsToolBinding()) {
            return $llm->bindTools($toolClasses);
        }

        if ($llm instanceof RunnableBinding) {
            // Bindings are not flattened by `bind()` / `withConfig()` here, so look through any depth of them.
            $newBound = self::simpleBindTools($llm->bound, $toolClasses);
            if ($newBound !== null) {
                return new RunnableBinding($newBound, $llm->kwargs, $llm->config);
            }
        }

        return null;
    }

    /**
     * Port of `_getPrompt`: at most one of the three spellings, folded to one prompt runnable.
     */
    private static function getPrompt(mixed $prompt, mixed $stateModifier, mixed $messageModifier): RunnableInterface
    {
        $definedCount = count(array_filter([$prompt, $stateModifier, $messageModifier], static fn (mixed $x): bool => $x !== null));
        if ($definedCount > 1) {
            throw new \InvalidArgumentException('Expected only one of prompt, stateModifier, or messageModifier, got multiple values');
        }

        $finalPrompt = $prompt;
        if ($stateModifier !== null) {
            $finalPrompt = $stateModifier;
        } elseif ($messageModifier !== null) {
            $finalPrompt = self::convertMessageModifierToPrompt($messageModifier);
        }

        return self::getPromptRunnable($finalPrompt);
    }

    /** Port of `_convertMessageModifierToPrompt`: a messages-only modifier becomes a state prompt. */
    private static function convertMessageModifierToPrompt(mixed $messageModifier): mixed
    {
        if (is_string($messageModifier) || $messageModifier instanceof SystemMessage) {
            return $messageModifier;
        }

        if ($messageModifier instanceof RunnableInterface) {
            return RunnableLambda::from(static fn (array $state): mixed => $state['messages'] ?? [])->pipe($messageModifier);
        }

        if (is_callable($messageModifier)) {
            return static fn (array $state): mixed => $messageModifier($state['messages'] ?? []);
        }

        throw new \InvalidArgumentException(sprintf('Unexpected type for messageModifier: %s', get_debug_type($messageModifier)));
    }

    /** Port of `_getPromptRunnable`. */
    private static function getPromptRunnable(mixed $prompt): RunnableInterface
    {
        if ($prompt === null) {
            return RunnableLambda::from(static fn (array $state): mixed => $state['messages'] ?? [])
                ->withConfig(['runName' => self::PROMPT_RUNNABLE_NAME]);
        }

        if (is_string($prompt) || $prompt instanceof SystemMessage) {
            $systemMessage = is_string($prompt) ? new SystemMessage($prompt) : $prompt;

            return RunnableLambda::from(static fn (array $state): array => [$systemMessage, ...array_values((array) ($state['messages'] ?? []))])
                ->withConfig(['runName' => self::PROMPT_RUNNABLE_NAME]);
        }

        if ($prompt instanceof RunnableInterface) {
            return $prompt;
        }

        if (is_callable($prompt)) {
            return RunnableLambda::from($prompt)->withConfig(['runName' => self::PROMPT_RUNNABLE_NAME]);
        }

        throw new \InvalidArgumentException(sprintf("Got unexpected type for 'prompt': %s", get_debug_type($prompt)));
    }

    /**
     * What the model is shown: the state, with `llmInputMessages` (when a pre-model hook set any) standing in for `messages`.
     *
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    private static function getModelInputState(array $state): array
    {
        $messages = $state['messages'] ?? null;
        $llmInputMessages = $state[AgentState::LLM_INPUT_MESSAGES] ?? null;
        unset($state['messages'], $state[AgentState::LLM_INPUT_MESSAGES]);

        if (is_array($llmInputMessages) && $llmInputMessages !== []) {
            return ['messages' => $llmInputMessages] + $state;
        }

        return ['messages' => $messages] + $state;
    }

    /**
     * Put the agent's name on a response, both on the message and in the kwargs a checkpoint serialises
     * (upstream sets `response.name` and `response.lc_kwargs.name`).
     */
    private static function stampName(BaseMessage $message, ?string $name): void
    {
        $message->name = $name;
        \Closure::bind(static function (BaseMessage $m) use ($name): void {
            if ($name === null) {
                unset($m->kwargs['name']);
            } else {
                $m->kwargs['name'] = $name;
            }
        }, null, BaseMessage::class)($message);
    }
}
