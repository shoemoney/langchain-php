<?php

declare(strict_types=1);

namespace LangGraph\Agents;

/**
 * A chat model whose real implementation is chosen lazily (`initChatModel`'s `ConfigurableModel`).
 *
 * Port of `ConfigurableModelInterface` from `langchain/src/agents/model.ts`, which names two members:
 * `_queuedMethodOperations` and `_getModelInstance()`. Upstream detects them structurally; PHP states
 * them as an interface so {@see Model::isConfigurableModel()} is a type check.
 *
 * The members are declared once, in the LangChain layer, so `initChatModel`'s own class can implement them.
 * The layering guard forbids a `use` of `LangChain\LanguageModels` here, so the parent is named by its FQCN.
 */
interface ConfigurableModelInterface extends \LangChain\LanguageModels\Chat\Universal\ConfigurableModelInterface
{
}
