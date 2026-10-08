<?php

declare(strict_types=1);

namespace LangGraph\Pregel\Messages;

use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableInterface;
use LangChain\Tracers\CallbackManager;
use LangChain\Tracers\Serialized;

/**
 * Reports a graph node's run to the callback manager.
 *
 * In LangGraph JS a node is a `RunnableCallable`, and invoking any runnable
 * through `callWithConfig` fires `handleChainStart` / `handleChainEnd` /
 * `handleChainError`. That is the signal {@see StreamMessagesHandler} reads to
 * learn which messages a node returned. This port's runnables do not fire
 * chain events themselves, so without this wrapper a node's output messages
 * would never reach a `messages` stream.
 *
 * It is applied by the loop to a task's runnable ONLY when a handler that needs
 * those events (`messages`, `tools`) is attached, so a graph that did not ask
 * pays nothing for it.
 *
 * Non-dict inputs and outputs are wrapped as `{input: ...}` / `{output: ...}`,
 * as upstream's `_coerceToDict` does, so every handler sees a map.
 */
final class TracedNode extends Runnable
{
    public function __construct(public readonly RunnableInterface $inner)
    {
    }

    public function getName(): string
    {
        return $this->inner->getName();
    }

    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        $config ??= new RunnableConfig();

        $manager = CallbackManager::configure($config->callbacks, null, $config->tags, null, $config->metadata);
        if ($manager === null) {
            return $this->inner->invoke($input, $config);
        }

        $run = $manager->handleChainStart(
            new Serialized(['langgraph', 'pregel', 'PregelNode'], []),
            self::coerceToDict($input, 'input'),
            null,
            null,
            [],
            [],
            $config->runName ?? $this->getName(),
        );

        try {
            $output = $this->inner->invoke($input, $config);
        } catch (\Throwable $e) {
            $run->handleChainError($e);

            throw $e;
        }

        $run->handleChainEnd(self::coerceToDict($output, 'output'));

        return $output;
    }

    /**
     * @return array<string, mixed>
     */
    private static function coerceToDict(mixed $value, string $key): array
    {
        return is_array($value) && !array_is_list($value) ? $value : [$key => $value];
    }
}
