<?php

declare(strict_types=1);

namespace LangGraph\Agents;

/**
 * Model type guards for the agent.
 *
 * Port of `langchain/src/agents/model.ts`. Its `AgentLanguageModelLike` type alias
 * (`RunnableInterface<BaseLanguageModelInput, LanguageModelOutput>`) has no runtime form; any
 * `LangChain\Runnables\RunnableInterface` stands in for it.
 *
 * The layering guard forbids importing `LangChain\LanguageModels` into this package, so the chat model
 * type is named by its FQCN inline.
 */
final class Model
{
    private function __construct()
    {
    }

    /**
     * Upstream tests for `invoke` plus `_streamResponseChunks`; a chat model is exactly that here.
     */
    public static function isBaseChatModel(mixed $model): bool
    {
        return $model instanceof \LangChain\LanguageModels\BaseChatModel;
    }

    public static function isConfigurableModel(mixed $model): bool
    {
        // The LangGraph interface extends the LangChain one, so the base check covers both.
        return $model instanceof \LangChain\LanguageModels\Chat\Universal\ConfigurableModelInterface;
    }
}
