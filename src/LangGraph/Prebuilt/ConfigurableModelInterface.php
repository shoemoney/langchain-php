<?php

declare(strict_types=1);

namespace LangGraph\Prebuilt;

/**
 * A model that resolves to the real chat model lazily.
 *
 * Port of `ConfigurableModelInterface` from `react_agent_executor.ts`, which upstream detects by duck-typing
 * `_queuedMethodOperations` and `_model` (what `initChatModel` returns). `ReactAgent` looks through such a
 * model to the one it wraps before deciding whether tools still need binding.
 *
 * The members (`getQueuedMethodOperations()`, `getModelInstance()`) are declared by the LangChain-layer
 * interface that `initChatModel`'s class implements; the layering guard forbids a `use` of
 * `LangChain\LanguageModels` here, so the parent is named by its FQCN. It does not extend
 * `RunnableInterface`: an implementing class is a runnable already (a chat model), and re-inheriting the
 * interface alongside `Runnable` makes its shared constants ambiguous.
 */
interface ConfigurableModelInterface extends \LangChain\LanguageModels\Chat\Universal\ConfigurableModelInterface
{
}
