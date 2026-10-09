<?php

declare(strict_types=1);

namespace LangGraph\Agents\Nodes;

use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableInterface;
use LangChain\Tools\StructuredTool;
use LangGraph\Agents\Errors\MiddlewareError;
use LangGraph\Agents\Errors\MultipleStructuredOutputsError;
use LangGraph\Agents\Errors\StructuredOutputParsingError;
use LangGraph\Agents\Middleware\Utils as MiddlewareUtils;
use LangGraph\Agents\Model;
use LangGraph\Agents\Responses\ProviderStrategy;
use LangGraph\Agents\Responses\ResponseFormats;
use LangGraph\Agents\Responses\ToolStrategy;
use LangGraph\Agents\RunnableCallable;
use LangGraph\Agents\Runtime;
use LangGraph\Agents\Utils as AgentUtils;
use LangGraph\Agents\WithAgentName;
use LangGraph\Pregel\Command;

/**
 * The model-calling node of an agent.
 *
 * Port of `AgentNode` from `langchain/src/agents/nodes/AgentNode.ts`.
 *
 * It binds the tools to the model, composes every middleware's `wrapModelCall` around the base model call
 * (the first middleware is the outermost layer: `[auth, retry, cache]` means auth wraps retry wraps cache wraps
 * the model), invokes the model and returns the state update as `Command`s.
 *
 * Options (upstream `AgentNodeOptions`):
 *
 *  - `model` (required): a runnable chat model. A model-id string is only resolved when the node runs, through
 *    `initChatModel`, which this port does not have yet (WP-20), so it raises then;
 *  - `systemMessage` (required): the {@see SystemMessage} (empty when there is no prompt);
 *  - `toolClasses`: every tool the agent offers (client tools are runnables, anything else is a provider tool);
 *  - `shouldReturnDirect`: names of the tools whose result ends the run (a list of names, or a name-keyed map);
 *  - `includeAgentName`: `"inline"` to fold the agent name into message text for the model;
 *  - `name`: the agent name, stamped on every AI message (defaults to `model`);
 *  - `responseFormat`: structured output configuration (a schema, a strategy from {@see ResponseFormats}, or a list);
 *  - `middleware`: the agent's middleware (used to know whether a `wrapToolCall` exists);
 *  - `signal`: an abort signal (callable returning true/throwable once aborted, or an object with `aborted`);
 *  - `wrapModelCallHookMiddleware`: the middleware that define `wrapModelCall`, in order. An entry may also be
 *    the legacy `[middleware, callable]` pair, whose second member is ignored.
 *
 * Concurrency differs from upstream in one way: model calls made by concurrent graph tasks are sequential here,
 * so the per-invocation copy of the system message upstream keeps (`currentSystemMessage`) is a local variable
 * of each call, which keeps invocations isolated all the same.
 */
final class AgentNode extends RunnableCallable
{
    /** The name of the agent node in the state graph. */
    public const AGENT_NODE_NAME = 'model_request';

    /** @var array<string, mixed> */
    private array $options;

    private SystemMessage $systemMessage;

    /** @var array<string, true> */
    private array $shouldReturnDirect = [];

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(array $options)
    {
        parent::__construct(
            func: fn (mixed $input, RunnableConfig $config): mixed => $this->run(\is_array($input) ? $input : [], $config),
            name: \is_string($options['name'] ?? null) ? $options['name'] : 'model',
        );

        if (!($options['systemMessage'] ?? null) instanceof SystemMessage) {
            throw new \InvalidArgumentException('AgentNode requires a "systemMessage" (use an empty SystemMessage for none).');
        }

        $this->options = $options;
        $this->systemMessage = $options['systemMessage'];

        $returnDirect = (array) ($options['shouldReturnDirect'] ?? []);
        foreach (array_is_list($returnDirect) ? $returnDirect : array_keys($returnDirect) as $toolName) {
            $this->shouldReturnDirect[(string) $toolName] = true;
        }
    }

    /**
     * @param array<string, mixed> $state
     * @return list<Command>|array{messages: list<BaseMessage>, structuredResponse: mixed}
     */
    private function run(array $state, RunnableConfig $config): array
    {
        $messages = $this->messagesOf($state);

        // Check if we just executed a returnDirect tool successfully. If so, stop.
        $lastMessage = $messages === [] ? null : $messages[array_key_last($messages)];
        if ($lastMessage instanceof ToolMessage
            && $lastMessage->name !== null && $lastMessage->name !== ''
            && ($lastMessage->additional_kwargs['status'] ?? null) !== 'error'
            && isset($this->shouldReturnDirect[$lastMessage->name])
        ) {
            return [new Command(update: ['messages' => []])];
        }

        ['response' => $response, 'lastAiMessage' => $lastAiMessage, 'collectedCommands' => $collectedCommands] = $this->invokeModel($state, $config);

        // structuredResponse is returned as a plain state update (not a Command) because its channel only
        // allows a single write per step.
        if (\is_array($response) && \array_key_exists('structuredResponse', $response) && \array_key_exists('messages', $response)) {
            return [
                'messages' => [...$messages, ...$response['messages']],
                'structuredResponse' => $response['structuredResponse'],
            ];
        }

        $commands = [];
        $aiMessage = self::isAiMessage($response) ? $response : $lastAiMessage;

        if ($aiMessage !== null) {
            $this->stampName($aiMessage);

            if ($this->areMoreStepsNeeded($state, $aiMessage)) {
                $commands[] = new Command(update: [
                    'messages' => [
                        new AIMessage([
                            'content' => 'Sorry, need more steps to process this request.',
                            'name' => $this->getName(),
                            'id' => $aiMessage->id,
                        ]),
                    ],
                ]);
            } else {
                $commands[] = new Command(update: ['messages' => [$aiMessage]]);
            }
        }

        // Commands (from base handler retries or middleware).
        if ($response instanceof Command && !\in_array($response, $collectedCommands, true)) {
            $commands[] = $response;
        }
        array_push($commands, ...$collectedCommands);

        return $commands;
    }

    /**
     * Derive the model from the options.
     */
    private function deriveModel(): RunnableInterface
    {
        $model = $this->options['model'] ?? null;

        if (\is_string($model)) {
            // `initChatModel` turns a "provider:model" string into a chat model. The layering guard forbids
            // importing `LangChain\LanguageModels` here, so the class is named by its FQCN.
            // `openai:` model strings default to the Responses API; pass a model instance to opt out.
            return \LangChain\LanguageModels\Chat\Universal\InitChatModel::init(
                $model,
                str_starts_with($model, 'openai:') ? ['useResponsesApi' => true] : [],
            );
        }

        if ($model instanceof RunnableInterface) {
            return $model;
        }

        throw new \Exception('No model option was provided, either via `model` option.');
    }

    /**
     * @param array<string, mixed> $state
     * @return array{response: mixed, lastAiMessage: AIMessage|AIMessageChunk|null, collectedCommands: list<Command>}
     */
    private function invokeModel(array $state, RunnableConfig $config): array
    {
        $model = $this->deriveModel();

        // A local copy of the current system message: each invocation gets its own.
        $currentSystemMessage = $this->systemMessage;

        // The effective AIMessage through the middleware chain, and the Commands middleware returned.
        $lastAiMessage = null;
        /** @var list<Command> $collectedCommands */
        $collectedCommands = [];

        // The base handler performs the actual model invocation.
        $baseHandler = function (array $request) use (&$lastAiMessage, &$currentSystemMessage, $config): mixed {
            // Check if the LLM already has bound tools and throw if it does.
            AgentUtils::validateLLMHasNoBoundTools($request['model'] ?? null);

            $structuredResponseFormat = $this->getResponseFormat($request['model'], $request['responseFormat'] ?? null);
            $modelWithTools = $this->bindTools($request['model'], $request, $structuredResponseFormat);

            // Prepend the system message to the messages if it is not empty.
            $messages = [
                ...($currentSystemMessage->text() === '' ? [] : [$currentSystemMessage]),
                ...$request['messages'],
            ];

            $signal = Utils::mergeAbortSignals($this->options['signal'] ?? null, $config->signal);
            self::throwIfAborted($signal);

            $response = $modelWithTools->invoke($messages, $config->with(['signal' => $signal]));
            self::throwIfAborted($signal);

            $lastAiMessage = $response;

            // The user asked for a native schema output: try to parse the response and return the structured response if valid.
            if ($structuredResponseFormat !== null && $structuredResponseFormat['type'] === 'native') {
                $strategy = $structuredResponseFormat['strategy'];
                $structuredResponse = $response instanceof AIMessage ? $strategy->parse($response) : null;
                if ($structuredResponse !== null) {
                    return ['structuredResponse' => $structuredResponse, 'messages' => [$response]];
                }

                // A terminal response (no tool calls) that fails the schema is an error, not a silent exit with no
                // structured response. With tool calls the loop continues and a later step gets another chance.
                if (self::toolCallsOfResponse($response) === []) {
                    $schemaTitle = \is_string($strategy->schema['title'] ?? null) ? $strategy->schema['title'] : 'providerStrategy';

                    throw new StructuredOutputParsingError($schemaTitle, ['Model output did not satisfy the provided response schema.']);
                }

                return $response;
            }

            if ($structuredResponseFormat === null || self::toolCallsOfResponse($response) === []) {
                return $response;
            }

            $toolCalls = array_values(array_filter(
                self::toolCallsOfResponse($response),
                static fn (array $call): bool => isset($structuredResponseFormat['tools'][$call['name'] ?? '']),
            ));

            // No structured tool calls: the response is returned as is.
            if ($toolCalls === []) {
                return $response;
            }

            // Several structured tool calls is not defined/supported.
            if (\count($toolCalls) > 1) {
                return $this->handleMultipleStructuredOutputs($response, $toolCalls, $structuredResponseFormat);
            }

            $toolStrategy = $structuredResponseFormat['tools'][$toolCalls[0]['name']];

            return $this->handleSingleStructuredOutput(
                $response,
                $toolCalls[0],
                $structuredResponseFormat,
                $toolStrategy->options['toolMessageContent'] ?? null,
            );
        };

        $wrapperMiddleware = (array) ($this->options['wrapModelCallHookMiddleware'] ?? []);
        $wrappedHandler = $baseHandler;

        // Build the composed handler from last to first so the first middleware becomes the outermost.
        for ($i = \count($wrapperMiddleware) - 1; $i >= 0; $i--) {
            $middleware = self::middlewareOf($wrapperMiddleware[$i]);
            $wrapModelCall = AgentUtils::middlewareValue($middleware, 'wrapModelCall');
            if ($wrapModelCall === null) {
                continue;
            }

            $innerHandler = $wrappedHandler;
            $middlewareName = AgentUtils::middlewareName($middleware);

            $wrappedHandler = function (array $request) use (
                $middleware,
                $middlewareName,
                $wrapModelCall,
                $innerHandler,
                $state,
                $config,
                &$currentSystemMessage,
                &$lastAiMessage,
                &$collectedCommands,
            ): mixed {
                $baselineSystemMessage = $currentSystemMessage;

                // Merge the context with the default context of the middleware.
                $contextSchema = AgentUtils::middlewareValue($middleware, 'contextSchema');
                $context = \is_array($contextSchema)
                    ? MiddlewareUtils::parseContext($contextSchema, $config->context ?? [], $middlewareName)
                    : $config->context;

                $runtime = Runtime::fromConfig($config)->with(['context' => $context]);

                // The request with the state this middleware sees (its own fields plus the messages) and the runtime.
                $stateSchema = AgentUtils::middlewareValue($middleware, 'stateSchema');
                $requestWithStateAndRuntime = [
                    ...$request,
                    'state' => [
                        ...($stateSchema !== null ? AgentUtils::parseMiddlewareState($stateSchema, $state) : []),
                        'messages' => $state['messages'] ?? [],
                    ],
                    'runtime' => $runtime,
                ];

                // The handler validates tools and system prompt changes, then calls the inner handler.
                $handlerWithValidation = function (array $req) use (
                    $middlewareName,
                    $innerHandler,
                    $baselineSystemMessage,
                    &$currentSystemMessage,
                    &$lastAiMessage,
                    &$collectedCommands,
                ): mixed {
                    $currentSystemMessage = $baselineSystemMessage;

                    $this->validateToolModifications($req, $middlewareName);

                    $normalizedReq = $req;
                    $hasSystemPromptChanged = ($req['systemPrompt'] ?? null) !== $currentSystemMessage->text();
                    $hasSystemMessageChanged = ($req['systemMessage'] ?? null) !== $currentSystemMessage;
                    if ($hasSystemPromptChanged && $hasSystemMessageChanged) {
                        throw new \Exception('Cannot change both systemPrompt and systemMessage in the same request.');
                    }

                    // The system prompt string changed: make a new SystemMessage out of it.
                    if ($hasSystemPromptChanged) {
                        $currentSystemMessage = new SystemMessage([
                            'content' => [['type' => 'text', 'text' => (string) ($req['systemPrompt'] ?? '')]],
                        ]);
                        $normalizedReq = [
                            ...$req,
                            'systemPrompt' => $currentSystemMessage->text(),
                            'systemMessage' => $currentSystemMessage,
                        ];
                    }

                    // The system message changed: it becomes the current one.
                    if ($hasSystemMessageChanged) {
                        $replacement = $req['systemMessage'] ?? null;
                        $currentSystemMessage = $replacement instanceof SystemMessage
                            ? new SystemMessage($replacement->kwargs())
                            : new SystemMessage([]);
                        $normalizedReq = [
                            ...$req,
                            'systemPrompt' => $currentSystemMessage->text(),
                            'systemMessage' => $currentSystemMessage,
                        ];
                    }

                    $innerHandlerResult = $innerHandler($normalizedReq);

                    // Normalize Commands so middleware always sees an AIMessage from handler(): when an inner
                    // handler (base handler or nested middleware) returns a Command, substitute the tracked
                    // AI message and collect the raw Command so the framework can still propagate it. Only
                    // collect it if not already present: Commands from inner middleware are tracked below.
                    if ($innerHandlerResult instanceof Command && $lastAiMessage !== null) {
                        if (!\in_array($innerHandlerResult, $collectedCommands, true)) {
                            $collectedCommands[] = $innerHandlerResult;
                        }

                        return $lastAiMessage;
                    }

                    return $innerHandlerResult;
                };

                try {
                    $middlewareResponse = $wrapModelCall($requestWithStateAndRuntime, $handlerWithValidation);

                    // Validate that this specific middleware returned a valid response.
                    if (!self::isInternalModelResponse($middlewareResponse)) {
                        throw new \Exception(sprintf(
                            'Invalid response from "wrapModelCall" in middleware "%s": expected AIMessage or Command, got %s',
                            $middlewareName,
                            MiddlewareUtils::typeOf($middlewareResponse),
                        ));
                    }

                    if (self::isAiMessage($middlewareResponse)) {
                        $lastAiMessage = $middlewareResponse;
                    } elseif ($middlewareResponse instanceof Command) {
                        $collectedCommands[] = $middlewareResponse;
                    }

                    return $middlewareResponse;
                } catch (\Throwable $error) {
                    throw MiddlewareError::wrap($error, $middlewareName);
                }
            };
        }

        // Execute the wrapped handler with the initial request, with the system message reset to its initial state.
        $currentSystemMessage = $this->systemMessage;
        $initialRequest = [
            'model' => $model,
            'responseFormat' => $this->options['responseFormat'] ?? null,
            'systemPrompt' => $currentSystemMessage->text(),
            'systemMessage' => $currentSystemMessage,
            'messages' => $this->messagesOf($state),
            'tools' => $this->options['toolClasses'] ?? [],
            'state' => $state,
            'runtime' => Runtime::fromConfig($config),
        ];

        $response = $wrappedHandler($initialRequest);

        return ['response' => $response, 'lastAiMessage' => $lastAiMessage, 'collectedCommands' => $collectedCommands];
    }

    /**
     * Reject tool modifications a `wrapModelCall` hook is not allowed to make.
     *
     * Each client tool in the request is either "added" (a new name, not among the registered tools) or
     * "replaced" (the name of a registered tool, but a different instance). Added tools are allowed when a
     * `wrapToolCall` middleware exists to execute them; replaced tools are always rejected, to preserve the
     * identity of the tool the ToolNode executes.
     *
     * @param array<string, mixed> $req
     */
    private function validateToolModifications(array $req, string $middlewareName): void
    {
        $registered = [];
        foreach ((array) ($this->options['toolClasses'] ?? []) as $tool) {
            if (AgentUtils::isClientTool($tool)) {
                $registered[self::toolName($tool)] = $tool;
            }
        }

        $added = [];
        $replaced = [];
        foreach ((array) ($req['tools'] ?? []) as $tool) {
            if (!AgentUtils::isClientTool($tool)) {
                continue;
            }
            $name = self::toolName($tool);
            if (!isset($registered[$name])) {
                $added[] = $name;
            } elseif ($registered[$name] !== $tool) {
                $replaced[] = $name;
            }
        }

        if ($added !== []) {
            $hasWrapToolCallHandler = false;
            foreach ((array) ($this->options['middleware'] ?? []) as $m) {
                if (AgentUtils::middlewareValue($m, 'wrapToolCall') !== null) {
                    $hasWrapToolCallHandler = true;
                    break;
                }
            }

            if (!$hasWrapToolCallHandler) {
                throw new \Exception(sprintf(
                    'You have added a new tool in "wrapModelCall" hook of middleware "%s": %s. This is not supported unless a middleware provides a "wrapToolCall" handler to execute it.',
                    $middlewareName,
                    implode(', ', $added),
                ));
            }
        }

        if ($replaced !== []) {
            throw new \Exception(sprintf(
                'You have modified a tool in "wrapModelCall" hook of middleware "%s": %s. This is not supported.',
                $middlewareName,
                implode(', ', $replaced),
            ));
        }
    }

    /**
     * Whether the agent must stop because there are not enough steps left to act on the response.
     *
     * @param array<string, mixed> $state
     */
    private function areMoreStepsNeeded(array $state, BaseMessage $response): bool
    {
        $allToolsReturnDirect = false;
        if ($response instanceof AIMessage || $response instanceof AIMessageChunk) {
            $allToolsReturnDirect = true;
            foreach (self::toolCallsOf($response) as $call) {
                if (!isset($this->shouldReturnDirect[(string) ($call['name'] ?? '')])) {
                    $allToolsReturnDirect = false;
                    break;
                }
            }
        }

        $remainingSteps = $state['remainingSteps'] ?? null;
        $messages = $this->messagesOf($state);
        $last = $messages === [] ? null : $messages[array_key_last($messages)];

        return $remainingSteps !== null && $remainingSteps !== 0 && (
            ($remainingSteps < 1 && $allToolsReturnDirect)
            || ($remainingSteps < 2 && AgentUtils::hasToolCalls($last))
        );
    }

    /**
     * Bind the request's tools (or the agent's) to the model, and wrap it with the agent name when asked.
     *
     * @param array<string, mixed> $preparedOptions the request
     */
    private function bindTools(RunnableInterface $model, array $preparedOptions, ?array $structuredResponseFormat = null): RunnableInterface
    {
        $structuredTools = array_values($structuredResponseFormat['tools'] ?? []);

        // The request's tools if provided, otherwise the agent's, plus the tools that carry the structured output.
        $allTools = [
            ...array_values((array) ($preparedOptions['tools'] ?? $this->options['toolClasses'] ?? [])),
            ...array_map(static fn (ToolStrategy $strategy): array => $strategy->tool, $structuredTools),
        ];

        // With structured tools the tool choice is "any", so the model has to call one of them.
        $toolChoice = $preparedOptions['toolChoice'] ?? null;
        if ($toolChoice === null || $toolChoice === '') {
            $toolChoice = $structuredTools !== [] ? 'any' : null;
        }

        $modelSettings = (array) ($preparedOptions['modelSettings'] ?? []);
        $options = [];

        // The user asked for a native schema output.
        if ($structuredResponseFormat !== null && $structuredResponseFormat['type'] === 'native') {
            $strategy = $structuredResponseFormat['strategy'];
            $resolvedStrict = $modelSettings['strict'] ?? $strategy->strict;

            $options = [
                // OpenAI-style options: ChatOpenAI, ChatXAI and other OpenAI-compatible providers.
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => $strategy->schema['name'] ?? 'extract',
                        ...(\is_string($strategy->schema['description'] ?? null) ? ['description' => $strategy->schema['description']] : []),
                        'schema' => $strategy->schema,
                        'strict' => $resolvedStrict,
                    ],
                ],
                // Anthropic-style options.
                'outputConfig' => ['format' => ['type' => 'json_schema', 'schema' => $strategy->schema]],
                // Google-style options.
                'responseSchema' => $strategy->schema,
                // For LangSmith structured output tracing.
                'ls_structured_output_format' => ['kwargs' => ['method' => 'json_schema'], 'schema' => $strategy->schema],
            ];
            // Don't force strict on tools: it makes Anthropic's combined grammar "too complex for compilation",
            // and only OpenAI Chat Completions needs it (re-applied there). An explicit override is in modelSettings.
        }

        $options = [...$options, ...$modelSettings];
        if ($toolChoice !== null) {
            $options['tool_choice'] = $toolChoice;
        }

        // Bind tools to the model if they are not already bound.
        $modelWithTools = AgentUtils::bindTools($model, $allTools, $options);

        return ($this->options['includeAgentName'] ?? null) === 'inline'
            ? WithAgentName::withAgentName($modelWithTools, 'inline')
            : $modelWithTools;
    }

    /**
     * The response format primitives for the given model and the response format the user provided.
     *
     * A tool selection yields a name-keyed map of tool strategies; a native schema (or a model that supports JSON
     * schema output) yields a single provider strategy.
     *
     * @return array{type: 'tool', tools: array<string, ToolStrategy>}|array{type: 'native', strategy: ProviderStrategy}|null
     */
    private function getResponseFormat(mixed $model, mixed $responseFormat): ?array
    {
        if ($responseFormat === null || $responseFormat === false) {
            return null;
        }

        $resolvedModel = $model;
        if (Model::isConfigurableModel($model)) {
            $resolvedModel = $model->getModelInstance();
        }

        $strategies = ResponseFormats::transformResponseFormat($responseFormat, null, $resolvedModel);

        if ($strategies === []) {
            return null;
        }

        // Either a list of provider strategies or a list of tool strategies.
        $isProviderStrategy = true;
        foreach ($strategies as $strategy) {
            $isProviderStrategy = $isProviderStrategy && $strategy instanceof ProviderStrategy;
        }

        if (!$isProviderStrategy) {
            $tools = [];
            foreach ($strategies as $strategy) {
                if ($strategy instanceof ToolStrategy) {
                    $tools[$strategy->name()] = $strategy;
                }
            }

            return ['type' => 'tool', 'tools' => $tools];
        }

        // There can only be one provider strategy.
        return ['type' => 'native', 'strategy' => $strategies[0]];
    }

    /**
     * The model returned several structured outputs: hand the error to the strategy's `handleError`.
     *
     * @param list<array<string, mixed>> $toolCalls
     * @param array{type: 'tool', tools: array<string, ToolStrategy>} $responseFormat
     */
    private function handleMultipleStructuredOutputs(AIMessage|AIMessageChunk $response, array $toolCalls, array $responseFormat): Command
    {
        $error = new MultipleStructuredOutputsError(array_map(static fn (array $call): string => (string) $call['name'], $toolCalls));

        return $this->handleToolStrategyError($error, $response, $toolCalls[0], $responseFormat);
    }

    /**
     * The model returned a single structured output: parse it into the structured response and a message to the LLM.
     *
     * @param array<string, mixed> $toolCall
     * @param array{type: 'tool', tools: array<string, ToolStrategy>} $responseFormat
     * @return array{structuredResponse: mixed, messages: list<BaseMessage>}|Command
     */
    private function handleSingleStructuredOutput(
        AIMessage|AIMessageChunk $response,
        array $toolCall,
        array $responseFormat,
        ?string $lastMessage = null,
    ): array|Command {
        $tool = $responseFormat['tools'][$toolCall['name']];

        try {
            $structuredResponse = $tool->parse((array) ($toolCall['args'] ?? []));

            return [
                'structuredResponse' => $structuredResponse,
                'messages' => [
                    $response,
                    new ToolMessage([
                        'tool_call_id' => $toolCall['id'] ?? '',
                        'content' => json_encode($structuredResponse, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE),
                        'name' => $toolCall['name'],
                    ]),
                    new AIMessage($lastMessage ?? 'Returning structured response: ' . json_encode($structuredResponse, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)),
                ],
            ];
        } catch (StructuredOutputParsingError $error) {
            return $this->handleToolStrategyError($error, $response, $toolCall, $responseFormat);
        }
    }

    /**
     * Decide, from the first tool strategy's `handleError`, whether a structured output error is retried or raised.
     *
     * `false` raises the error; `true`/unset retries with the error message; a string retries with that string; a
     * callable retries with the string it returns for the error. A retry is a Command back to the model node.
     *
     * @param array<string, mixed> $toolCall
     * @param array{type: 'tool', tools: array<string, ToolStrategy>} $responseFormat
     */
    private function handleToolStrategyError(
        StructuredOutputParsingError|MultipleStructuredOutputsError $error,
        AIMessage|AIMessageChunk $response,
        array $toolCall,
        array $responseFormat,
    ): Command {
        // The `handleError` of the first tool strategy is enough: all entries built from one list share it.
        $first = $responseFormat['tools'] === [] ? null : $responseFormat['tools'][array_key_first($responseFormat['tools'])];
        $errorHandler = $first?->options['handleError'] ?? null;

        $toolCallId = $toolCall['id'] ?? null;
        if ($toolCallId === null || $toolCallId === '') {
            throw new \Exception('Tool call ID is required to handle tool output errors. Please provide a tool call ID.');
        }

        // Default behavior is to retry; only an explicit `false` throws.
        if ($errorHandler === false) {
            throw $error;
        }

        $content = $error->getMessage();
        if (\is_string($errorHandler)) {
            $content = $errorHandler;
        } elseif ($errorHandler instanceof \Closure || (\is_callable($errorHandler) && !\is_array($errorHandler))) {
            $content = $errorHandler($error);
            if (!\is_string($content)) {
                throw new \Exception('Error handler must return a string.');
            }
        }

        return new Command(
            update: ['messages' => [$response, new ToolMessage(['content' => $content, 'tool_call_id' => $toolCallId])]],
            goto: self::AGENT_NODE_NAME,
        );
    }

    /**
     * Returns the node's internal bookkeeping state, not graph output.
     *
     * @return array<string, mixed>
     */
    public function getState(): mixed
    {
        $state = parent::getState();
        $original = \is_array($state) ? $state : [];

        return ['messages' => [], ...$original];
    }

    /**
     * Put the agent's name on a response, both on the message and in the kwargs a checkpoint serializes
     * (upstream sets `response.name` and `response.lc_kwargs.name`).
     */
    private function stampName(BaseMessage $message): void
    {
        $name = $this->getName();
        $message->name = $name;
        \Closure::bind(static function (BaseMessage $m) use ($name): void {
            $m->kwargs['name'] = $name;
        }, null, BaseMessage::class)($message);
    }

    /**
     * @param array<string, mixed> $state
     * @return list<BaseMessage>
     */
    private function messagesOf(array $state): array
    {
        return array_values((array) ($state['messages'] ?? []));
    }

    private static function isAiMessage(mixed $value): bool
    {
        return $value instanceof AIMessage || $value instanceof AIMessageChunk;
    }

    /**
     * A response a model call may produce: an AI message, a Command, or a structured response record.
     */
    private static function isInternalModelResponse(mixed $response): bool
    {
        return self::isAiMessage($response)
            || Command::isCommand($response)
            || (\is_array($response) && \array_key_exists('structuredResponse', $response) && \array_key_exists('messages', $response));
    }

    /**
     * The tool calls of a model response (none when it is not an AI message).
     *
     * @return list<array<string, mixed>>
     */
    private static function toolCallsOfResponse(mixed $response): array
    {
        return self::isAiMessage($response) ? self::toolCallsOf($response) : [];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function toolCallsOf(AIMessage|AIMessageChunk $message): array
    {
        return $message instanceof AIMessageChunk ? $message->toMessage()->toolCalls : $message->toolCalls;
    }

    /**
     * @param array<string, mixed>|object|list<mixed> $entry a middleware, or the legacy `[middleware, fn]` pair
     * @return array<string, mixed>|object
     */
    private static function middlewareOf(array|object $entry): array|object
    {
        if (\is_array($entry) && array_is_list($entry) && \count($entry) === 2 && (\is_array($entry[0]) || \is_object($entry[0])) && \is_callable($entry[1])) {
            return $entry[0];
        }

        return $entry;
    }

    private static function toolName(mixed $tool): string
    {
        return $tool instanceof StructuredTool ? $tool->name : $tool->getName();
    }

    /**
     * Raise the signal's reason once it has aborted (upstream: `raceWithSignal`).
     *
     * @param callable(): mixed $signal
     */
    private static function throwIfAborted(callable $signal): void
    {
        $reason = $signal();
        if ($reason instanceof \Throwable) {
            throw $reason;
        }
        if ($reason === true) {
            throw new \RuntimeException('Aborted');
        }
    }
}
