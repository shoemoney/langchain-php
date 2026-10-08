<?php

declare(strict_types=1);

namespace LangGraph\Agents;

/**
 * A chat model whose real implementation is chosen lazily (`initChatModel`'s `ConfigurableModel`).
 *
 * Port of `ConfigurableModelInterface` from `langchain/src/agents/model.ts`, which names two members:
 * `_queuedMethodOperations` and `_getModelInstance()`. Upstream detects them structurally; PHP states
 * them as an interface so {@see Model::isConfigurableModel()} is a type check.
 */
interface ConfigurableModelInterface
{
    /** @return array<string, mixed> */
    public function getQueuedMethodOperations(): array;

    /** Resolve the underlying chat model. */
    public function getModelInstance(): \LangChain\LanguageModels\BaseChatModel;
}
