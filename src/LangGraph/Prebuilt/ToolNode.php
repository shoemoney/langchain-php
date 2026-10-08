<?php

declare(strict_types=1);

namespace LangGraph\Prebuilt;

use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableInterface;
use LangChain\Tools\BaseToolkit;
use LangChain\Tools\StructuredTool;
use LangGraph\Errors\Guard;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Send;
use LangGraph\Utils\RunnableCallable;

/**
 * A node that runs the tools requested by the last AI message.
 *
 * Port of `ToolNode` from `langgraph-core/src/prebuilt/tool_node.ts`.
 *
 * Input is a `list<BaseMessage>` or a `['messages' => list<BaseMessage>]` state (any other keys are kept as
 * the state the tools see through {@see \LangChain\Tools\ToolRuntime::$state}), or the `['lg_tool_call' => …]`
 * packet a `Send` fans out. Output mirrors the input shape: a list of `ToolMessage`s for list input,
 * `['messages' => […]]` for state input. When any tool returned a `Command`, the output is instead a list
 * with one entry per result, exactly as upstream builds it, and parent-addressed `Command`s that only carry
 * `Send`s are merged into one.
 *
 * Errors are the second job. With `handleToolErrors` true (the default) a throwing tool becomes an error
 * `ToolMessage` the model can read and recover from; false rethrows; a callable
 * `fn(\Throwable $e, array $toolCall): string|ToolMessage|Command` decides per error. A graph interrupt is
 * never an error: it is how a human gets into the loop, so it is rethrown whatever `handleToolErrors` says.
 *
 * Tools run one after another (upstream uses `Promise.all`); output order is the tool-call order either way.
 */
class ToolNode extends RunnableCallable
{
    /** @var list<StructuredTool|RunnableInterface> */
    public array $tools;

    /** @var bool|callable(\Throwable, array<string, mixed>): mixed */
    public $handleToolErrors = true;

    /**
     * @param iterable<StructuredTool|RunnableInterface|BaseToolkit> $tools
     * @param array{name?: string, tags?: list<string>, handleToolErrors?: bool|callable} $options
     */
    public function __construct(iterable $tools, array $options = [])
    {
        parent::__construct(
            func: fn (mixed $input, RunnableConfig $config): mixed => $this->run($input, $config),
            name: $options['name'] ?? 'tools',
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
        $this->handleToolErrors = $options['handleToolErrors'] ?? $this->handleToolErrors;
    }

    /**
     * Run one tool call.
     *
     * @param array<string, mixed> $call
     */
    protected function runTool(array $call, RunnableConfig $config, mixed $state): ToolMessage|Command
    {
        try {
            $tool = $this->findTool((string) ($call['name'] ?? ''));
            if ($tool === null) {
                throw new \Exception(sprintf('Tool "%s" not found.', (string) ($call['name'] ?? '')));
            }

            $toolCall = array_merge($call, ['type' => 'tool_call']);
            // The graph store reaches the tool the way it reaches every task: through
            // `configurable[CONFIG_KEY_STORE]`, which `ToolRuntime::fromConfig()` reads.
            $toolConfig = $config->with(['configurable' => $config->configurable + ['__state' => $state]]);
            $toolName = $tool instanceof StructuredTool ? $tool->name : $tool->getName();

            if ($tool instanceof StructuredTool) {
                $capture = new CommandPassthroughTool($tool);
                $output = $capture->invoke($toolCall, $toolConfig);
                if ($capture->didCapture) {
                    $raw = $capture->captured;
                    if ($raw instanceof Send) {
                        return new Command(goto: [$raw]);
                    }
                    if (Command::isCommand($raw)) {
                        return Command::fromMixed($raw);
                    }
                    if ($raw instanceof ToolMessage) {
                        return $raw;
                    }
                }
            } else {
                $output = $tool->invoke($toolCall, $toolConfig);
                if ($output instanceof Send) {
                    return new Command(goto: [$output]);
                }
                if (Command::isCommand($output)) {
                    return Command::fromMixed($output);
                }
            }

            if ($output instanceof ToolMessage) {
                return self::describe($output, $toolName, 'success', $call);
            }

            return new ToolMessage([
                'content' => is_string($output) ? $output : (string) json_encode($output, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR),
                'name' => $toolName,
                'tool_call_id' => (string) ($call['id'] ?? ''),
                'additional_kwargs' => ['status' => 'success'],
            ]);
        } catch (\Throwable $e) {
            if ($this->handleToolErrors === false) {
                throw $e;
            }

            if (Guard::isGraphBubbleUp($e)) {
                // An interrupt is a breakpoint that brings a human into the loop; it is not recoverable
                // by the agent and must not be fed back to the model as an error message.
                throw $e;
            }

            $content = sprintf("Error: %s\n Please fix your mistakes.", $e->getMessage());
            if ($this->handleToolErrors !== true && is_callable($this->handleToolErrors)) {
                $handled = ($this->handleToolErrors)($e, $call);
                if ($handled instanceof ToolMessage || $handled instanceof Command) {
                    return $handled;
                }
                $content = (string) $handled;
            }

            return new ToolMessage([
                'content' => $content,
                'name' => (string) ($call['name'] ?? ''),
                'tool_call_id' => (string) ($call['id'] ?? ''),
                'additional_kwargs' => ['status' => 'error'],
            ]);
        }
    }

    protected function run(mixed $input, RunnableConfig $config): mixed
    {
        if (self::isSendInput($input)) {
            /** @var array<string, mixed> $state */
            $state = $input;
            $toolCall = $state['lg_tool_call'];
            // Drop the internal routing key so tools only see state.
            unset($state['lg_tool_call']);
            $outputs = [$this->runTool((array) $toolCall, $config, $state)];
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
            for ($i = count($messages) - 1; $i >= 0; $i--) {
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

        $inputIsList = is_array($input) && array_is_list($input);

        // Preserve the plain shape when no tool returned a Command.
        $hasCommand = false;
        foreach ($outputs as $output) {
            $hasCommand = $hasCommand || $output instanceof Command;
        }
        if (!$hasCommand) {
            return $inputIsList ? $outputs : ['messages' => $outputs];
        }

        $combined = [];
        $parentSends = null;
        foreach ($outputs as $output) {
            if (!$output instanceof Command) {
                $combined[] = $inputIsList ? [$output] : ['messages' => [$output]];
                continue;
            }

            if ($output->graph === Command::PARENT && is_array($output->goto) && self::allSends($output->goto)) {
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

    /** @param array<string, mixed> $call */
    private static function describe(ToolMessage $message, string $toolName, string $status, array $call): ToolMessage
    {
        return new ToolMessage([
            'content' => $message->content,
            'artifact' => $message->artifact,
            'tool_call_id' => $message->toolCallId !== '' ? $message->toolCallId : (string) ($call['id'] ?? ''),
            'tool_name' => $message->toolName,
            'name' => $message->name ?? $toolName,
            'id' => $message->id,
            'additional_kwargs' => $message->additional_kwargs + ['status' => $status],
            'response_metadata' => $message->response_metadata,
        ]);
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
        if (!is_array($input) || !array_is_list($input)) {
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
        return is_array($input) && isset($input['messages']) && self::isBaseMessageArray($input['messages']);
    }

    private static function isSendInput(mixed $input): bool
    {
        return is_array($input) && !array_is_list($input) && array_key_exists('lg_tool_call', $input);
    }
}
