<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Universal;

use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableInterface;

/**
 * A chat model whose real implementation is chosen lazily (`initChatModel`'s `ConfigurableModel`).
 *
 * Upstream names these two members in `agents/model.ts` (`_queuedMethodOperations` and
 * `_getModelInstance()`) and detects them structurally; PHP states them as an interface so an agent can
 * recognise a configurable model with a type check. The LangGraph agent packages extend this interface by
 * its fully qualified name, which is how they recognise the class without importing from
 * `LangChain\LanguageModels` (the layering guard forbids that import).
 */
interface ConfigurableModelInterface
{
    /**
     * The method calls (`bindTools`, `withStructuredOutput`) queued on the wrapper, keyed by method name,
     * each mapped to the argument list it will be replayed with.
     *
     * @return array<string, mixed>
     */
    public function getQueuedMethodOperations(): array;

    /**
     * Resolve the underlying model, with every queued operation applied.
     *
     * Usually a chat model; it is a plain runnable once `withStructuredOutput()` has been queued, because
     * that returns a parsing pipeline rather than a model.
     */
    public function getModelInstance(?RunnableConfig $config = null): RunnableInterface;
}
