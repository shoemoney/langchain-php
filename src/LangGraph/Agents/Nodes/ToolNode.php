<?php

declare(strict_types=1);

namespace LangGraph\Agents\Nodes;

use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableInterface;
use LangChain\Tools\BaseToolkit;
use LangChain\Tools\StructuredTool;
use LangChain\Tools\ToolException;
use LangGraph\Agents\Errors\MiddlewareError;
use LangGraph\Agents\Errors\ToolInvocationError;
use LangGraph\Agents\RunnableCallable;
use LangGraph\Agents\Runtime;
use LangGraph\Errors\Guard;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Send;
use LangGraph\Prebuilt\CommandPassthroughTool;

/**
 * The agent's tool-executing node: runs the tool calls of the last AI message.
 *
 * Port of `ToolNode` from `langchain/src/agents/nodes/ToolNode.ts`. This is the `createAgent` variant,
 * distinct from `LangGraph\Prebuilt\ToolNode` (the `langgraph-core` one): it adds the `wrapToolCall`
 * middleware seam, `ToolInvocationError`/`MiddlewareError` aware error handling, graceful "not a valid
 * tool" messages, and a signal.
 *
 * Options (upstream `ToolNodeOptions`), all optional:
 *
 *  - `name`, `tags`: the node's name and tags;
 *  - `signal`: an abort signal (callable returning `true` once aborted, or an object with an `aborted`
 *    property) that cancels the tool call and stops errors from being recovered;
 *  - `handleToolErrors`: `true` catches every error into an error ToolMessage, `false` rethrows all, and a
 *    callable `fn(\Throwable $error, array $toolCall): ?ToolMessage` decides per error (returning null
 *    rethrows). The DEFAULT only catches tool errors, never errors from `wrapToolCall` middleware;
 *  - `wrapToolCall`: `fn(array $request, callable $handler): ToolMessage|Command`, where `$request` is
 *    `['toolCall' => …, 'tool' => …, 'state' => …, 'runtime' => Runtime]`. It may pass a modified request
 *    to `$handler`, which supplies tools that were registered dynamically.
 *
 * Input is a `list<BaseMessage>`, a `['messages' => list<BaseMessage>]` state, or the `['lg_tool_call' => …]`
 * packet a `Send` fans out. Output mirrors the input shape; when any tool returned a `Command`, the output
 * is a list with one entry per result and parent-addressed `Command`s that only carry `Send`s are merged.
 *
 * Tools run one after another (upstream uses `Promise.all`); output order is the tool-call order either way.
 * A tool the model names that is not registered yields an error ToolMessage instead of throwing, whatever
 * `handleToolErrors` says.
 */
class ToolNode extends RunnableCallable
{
    /** The name of the tool node in the state graph. */
    public const TOOLS_NODE_NAME = 'tools';

    /** @var list<StructuredTool|RunnableInterface> */
    public array $tools;

    /** @var mixed Abort signal, see the class docblock. */
    public mixed $signal;

    /** @var bool|callable(\Throwable, array<string, mixed>): ?ToolMessage */
    public $handleToolErrors;

    /** @var (callable(array<string, mixed>, callable): (ToolMessage|Command))|null */
    public $wrapToolCall;

    /**
     * @param iterable<StructuredTool|RunnableInterface|BaseToolkit> $tools
     * @param array{name?: string, tags?: list<string>, signal?: mixed, handleToolErrors?: bool|callable, wrapToolCall?: callable} $options
     */
    public function __construct(iterable $tools, public array $options = [])
    {
        parent::__construct(
            func: fn (mixed $input, RunnableConfig $config): mixed => $this->run($input, $config),
            name: $options['name'] ?? self::TOOLS_NODE_NAME,
            tags: $options['tags'] ?? null,
            trace: false,
        );

        $flat = [];
        foreach ($tools as $tool) {
            if ($tool instanceof BaseToolkit) {
                array_push($flat, ...$tool->getTools());
            } else {
                $flat[] = $tool;
            }
        }
        $this->tools = $flat;
        $this->handleToolErrors = $options['handleToolErrors'] ?? self::defaultHandleToolErrors(...);
        $this->signal = $options['signal'] ?? null;
        $this->wrapToolCall = $options['wrapToolCall'] ?? null;
    }

    /**
     * Default error handler for tool errors.
     *
     * Applied to errors from the base handler (tool execution); errors from `wrapToolCall` middleware
     * bubble up by default. Converts the error to a ToolMessage so the LLM can see it and retry.
     *
     * @param array<string, mixed> $toolCall
     */
    public static function defaultHandleToolErrors(\Throwable $error, array $toolCall): ToolMessage
    {
        if (ToolInvocationError::isInstance($error)) {
            return self::errorMessage($error->getMessage(), $toolCall);
        }

        // Catch all other tool errors and convert them to a ToolMessage.
        return self::errorMessage(self::describeError($error) . "\n Please fix your mistakes.", $toolCall);
    }

    /**
     * Handle errors from tool execution or middleware.
     *
     * @param array<string, mixed> $call
     * @param bool $isMiddlewareError Whether the error came from `wrapToolCall` middleware
     * @throws \Throwable when the error is not handled
     */
    private function handleError(\Throwable $error, array $call, bool $isMiddlewareError): ToolMessage
    {
        // An interrupt brings a human into the loop: it is not recoverable by the agent and must not be
        // fed back, even when `handleToolErrors` is true.
        if (Guard::isGraphInterrupt($error)) {
            throw $error;
        }

        // If the signal is aborted, bubble the error up to the invoke caller.
        if (Utils::isAborted($this->signal)) {
            throw $error;
        }

        // A recoverable tool error (e.g. input validation) can be rewrapped as a MiddlewareError with the
        // original on `getPrevious()`, once per `wrapToolCall` middleware. Walk the cause chain to the root;
        // if it is a ToolInvocationError, unwrap it so the self-correction path still applies.
        $effectiveError = $error;
        $errorFromMiddleware = $isMiddlewareError;
        if ($isMiddlewareError) {
            $unwrapped = $error;
            while (MiddlewareError::isInstance($unwrapped)) {
                $unwrapped = $unwrapped->getPrevious();
            }
            if (ToolInvocationError::isInstance($unwrapped)) {
                /** @var \Throwable $unwrapped */
                $effectiveError = $unwrapped;
                $errorFromMiddleware = false;
            }
        }

        // From middleware and not `true`: the default handler and `false` both re-raise.
        if ($errorFromMiddleware && $this->handleToolErrors !== true) {
            throw $effectiveError;
        }

        if ($this->handleToolErrors === false || $this->handleToolErrors === null) {
            throw $effectiveError;
        }

        if ($this->handleToolErrors !== true && \is_callable($this->handleToolErrors)) {
            $result = ($this->handleToolErrors)($effectiveError, $call);
            if ($result instanceof ToolMessage) {
                $result->additional_kwargs['status'] ??= 'error';

                return $result;
            }

            // The handler declined: re-raise.
            throw $effectiveError;
        }

        return new ToolMessage([
            'name' => (string) ($call['name'] ?? ''),
            'content' => self::describeError($effectiveError) . "\n Please fix your mistakes.",
            'tool_call_id' => (string) ($call['id'] ?? ''),
            'additional_kwargs' => ['status' => 'error'],
        ]);
    }

    /**
     * Run one tool call.
     *
     * @param array<string, mixed> $call
     */
    protected function runTool(array $call, RunnableConfig $config, mixed $state): ToolMessage|Command
    {
        // Find the tool to include in the request; it may be absent for dynamically registered tools.
        $registeredTool = $this->findTool((string) ($call['name'] ?? ''));

        // The base handler executes the tool. It does not catch errors, so `wrapToolCall` middleware can;
        // without middleware the caller handles them below.
        $baseHandler = function (array $request) use ($config, $state): ToolMessage|Command {
            /** @var array<string, mixed> $toolCall */
            $toolCall = $request['toolCall'];
            // The tool from the request (possibly overridden by middleware) wins over the registered one.
            $tool = $request['tool'] ?? $this->findTool((string) ($toolCall['name'] ?? ''));

            if ($tool === null) {
                // Not found: a graceful message rather than an exception, so the LLM can retry.
                return $this->invalidToolMessage($toolCall);
            }

            $toolName = $tool instanceof StructuredTool ? $tool->name : $tool->getName();
            $toolConfig = $config->with([
                'configurable' => $config->configurable + ['__state' => $state],
                'signal' => Utils::mergeAbortSignals($this->signal, $config->signal),
            ]);
            $invocation = [...$toolCall, 'type' => 'tool_call'];

            try {
                if ($tool instanceof StructuredTool) {
                    // StructuredTool::invoke wraps every result in a ToolMessage, which would JSON-encode a
                    // Command; the passthrough records what the body actually returned.
                    $capture = new CommandPassthroughTool($tool);
                    $output = $capture->invoke($invocation, $toolConfig);
                    if ($capture->didCapture && (Command::isCommand($capture->captured) || $capture->captured instanceof ToolMessage)) {
                        $output = $capture->captured;
                    }
                } else {
                    $output = $tool->invoke($invocation, $toolConfig);
                }
            } catch (ToolException $e) {
                // The model sent arguments that do not match the tool's schema.
                throw new ToolInvocationError($e, $toolCall);
            }

            if (Command::isCommand($output)) {
                return Command::fromMixed($output);
            }
            if ($output instanceof ToolMessage) {
                $output->name ??= $toolName;

                return $output;
            }

            return new ToolMessage([
                'name' => $toolName,
                'content' => \is_string($output)
                    ? $output
                    : (string) json_encode($output, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR),
                'tool_call_id' => (string) ($toolCall['id'] ?? ''),
            ]);
        };

        $request = [
            'toolCall' => $call,
            'tool' => $registeredTool,
            'state' => $state,
            'runtime' => Runtime::fromConfig($config)->with(['toolCallId' => $call['id'] ?? null]),
        ];

        if ($this->wrapToolCall !== null) {
            try {
                return ($this->wrapToolCall)($request, $baseHandler);
            } catch (\Throwable $e) {
                return $this->handleError($e, $call, true);
            }
        }

        // No middleware: a missing tool is a graceful error, other failures are handled here.
        if ($registeredTool === null) {
            return $this->invalidToolMessage($call);
        }

        try {
            return $baseHandler($request);
        } catch (\Throwable $e) {
            return $this->handleError($e, $call, false);
        }
    }

    protected function run(mixed $input, RunnableConfig $config): mixed
    {
        if (self::isSendInput($input)) {
            /** @var array<string, mixed> $newState */
            $newState = $input;
            $toolCall = (array) $newState['lg_tool_call'];
            // Drop the internal routing keys so tools only see state.
            unset($newState['lg_tool_call'], $newState['jumpTo']);
            $outputs = [$this->runTool($toolCall, $config, $newState)];
        } else {
            if (self::isBaseMessageArray($input)) {
                $messages = $input;
            } elseif (self::isMessagesState($input)) {
                $messages = $input['messages'];
            } else {
                throw new \Exception('ToolNode only accepts BaseMessage[] or { messages: BaseMessage[] } as input.');
            }

            $answered = [];
            foreach ($messages as $message) {
                if ($message instanceof ToolMessage) {
                    $answered[$message->toolCallId] = true;
                }
            }

            $aiMessage = null;
            for ($i = \count($messages) - 1; $i >= 0; $i--) {
                if ($messages[$i] instanceof AIMessage) {
                    $aiMessage = $messages[$i];
                    break;
                }
                if ($messages[$i] instanceof AIMessageChunk) {
                    $aiMessage = $messages[$i]->toMessage();
                    break;
                }
            }

            if ($aiMessage === null) {
                throw new \Exception('ToolNode only accepts AIMessages as input.');
            }

            $outputs = [];
            foreach ($aiMessage->toolCalls as $call) {
                if (isset($call['id']) && isset($answered[$call['id']])) {
                    continue;
                }
                $outputs[] = $this->runTool($call, $config, $input);
            }
        }

        $inputIsList = \is_array($input) && array_is_list($input);

        // Preserve the plain shape when no tool returned a Command.
        $hasCommand = false;
        foreach ($outputs as $output) {
            $hasCommand = $hasCommand || $output instanceof Command;
        }
        if (!$hasCommand) {
            return $inputIsList ? $outputs : ['messages' => $outputs];
        }

        // Mixed Command and non-Command outputs.
        $combined = [];
        $parentSends = null;
        foreach ($outputs as $output) {
            if (!$output instanceof Command) {
                $combined[] = $inputIsList ? [$output] : ['messages' => [$output]];
                continue;
            }

            if ($output->graph === Command::PARENT && \is_array($output->goto) && self::allSends($output->goto)) {
                $parentSends = [...($parentSends ?? []), ...array_values($output->goto)];
            } else {
                $combined[] = $output;
            }
        }

        if ($parentSends !== null) {
            $combined[] = new Command(graph: Command::PARENT, goto: $parentSends);
        }

        return $combined;
    }

    /** Error message for a call to a tool that is not registered, naming the ones that are. */
    public static function invalidToolError(string $toolName, array $availableTools): string
    {
        return sprintf('Error: %s is not a valid tool, try one of [%s].', $toolName, implode(', ', $availableTools));
    }

    /** @param array<string, mixed> $call */
    private function invalidToolMessage(array $call): ToolMessage
    {
        $available = array_map(
            static fn (StructuredTool|RunnableInterface $t): string => $t instanceof StructuredTool ? $t->name : $t->getName(),
            $this->tools,
        );

        return self::errorMessage(self::invalidToolError((string) ($call['name'] ?? ''), $available), $call);
    }

    /** @param array<string, mixed> $call */
    private static function errorMessage(string $content, array $call): ToolMessage
    {
        return new ToolMessage([
            'content' => $content,
            'tool_call_id' => (string) ($call['id'] ?? ''),
            'name' => (string) ($call['name'] ?? ''),
            'additional_kwargs' => ['status' => 'error'],
        ]);
    }

    /** The JS `${error}` form: `Name: message`. */
    private static function describeError(\Throwable $error): string
    {
        $name = $error instanceof MiddlewareError ? $error->errorName : 'Error';

        return $name . ': ' . $error->getMessage();
    }

    private function findTool(string $name): StructuredTool|RunnableInterface|null
    {
        foreach ($this->tools as $tool) {
            $toolName = $tool instanceof StructuredTool ? $tool->name : $tool->getName();
            if ($toolName === $name) {
                return $tool;
            }
        }

        return null;
    }

    /** @param array<mixed> $goto */
    private static function allSends(array $goto): bool
    {
        foreach ($goto as $item) {
            if (!Send::isSend($item)) {
                return false;
            }
        }

        return true;
    }

    private static function isBaseMessageArray(mixed $input): bool
    {
        if (!\is_array($input) || !array_is_list($input)) {
            return false;
        }
        foreach ($input as $message) {
            if (!$message instanceof BaseMessage) {
                return false;
            }
        }

        return true;
    }

    private static function isMessagesState(mixed $input): bool
    {
        return \is_array($input) && isset($input['messages']) && self::isBaseMessageArray($input['messages']);
    }

    private static function isSendInput(mixed $input): bool
    {
        return \is_array($input) && !array_is_list($input) && \array_key_exists('lg_tool_call', $input);
    }
}
