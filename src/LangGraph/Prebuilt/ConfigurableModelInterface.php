<?php

declare(strict_types=1);

namespace LangGraph\Prebuilt;

use LangChain\Runnables\RunnableInterface;

/**
 * A model that resolves to the real chat model lazily.
 *
 * Port of `ConfigurableModelInterface` from `react_agent_executor.ts`, which upstream detects by duck-typing
 * `_queuedMethodOperations` and `_model` (what `initChatModel` returns). `ReactAgent` looks through such a
 * model to the one it wraps before deciding whether tools still need binding.
 *
 * It does not extend `RunnableInterface`: an implementing class is a runnable already (a chat model), and
 * re-inheriting the interface alongside `Runnable` makes its shared constants ambiguous.
 */
interface ConfigurableModelInterface
{
    /**
     * The method calls (`bindTools`, ...) queued on the wrapper, keyed by method name.
     *
     * @return array<string, mixed>
     */
    public function queuedMethodOperations(): array;

    /** The underlying model, with any queued operations applied. */
    public function model(): RunnableInterface;
}
