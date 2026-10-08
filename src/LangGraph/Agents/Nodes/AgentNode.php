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
use LangGraph\Agents\Middleware\Utils as MiddlewareUtils;
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
 *  - `responseFormat`: structured output configuration. The strategies behind it (`toolStrategy`,
 *    `providerStrategy`) are WP-21c, so setting one raises when the model is called;
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
            // `initChatModel` (WP-20) turns a "provider:model" string into a chat model.
            throw new \RuntimeException(sprintf(
                'Cannot resolve the model "%s": model id strings need initChatModel, which is not ported yet. Pass a chat model instance.',
                $model,
            ));
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

            if (($request['responseFormat'] ?? null) !== null) {
                throw new \LogicException(
                    'Structured responses (responseFormat) are not available yet: toolStrategy/providerStrategy belong to WP-21c.',
                );
            }

            $modelWithTools = $this->bindTools($request['model'], $request);

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

            return $response;
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
    private function bindTools(RunnableInterface $model, array $preparedOptions): RunnableInterface
    {
        $allTools = array_values((array) ($preparedOptions['tools'] ?? $this->options['toolClasses'] ?? []));

        $options = (array) ($preparedOptions['modelSettings'] ?? []);
        $toolChoice = $preparedOptions['toolChoice'] ?? null;
        if ($toolChoice !== null && $toolChoice !== '') {
            $options['tool_choice'] = $toolChoice;
        }

        // Bind tools to the model if they are not already bound.
        $modelWithTools = AgentUtils::bindTools($model, $allTools, $options);

        return ($this->options['includeAgentName'] ?? null) === 'inline'
            ? WithAgentName::withAgentName($modelWithTools, 'inline')
            : $modelWithTools;
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
