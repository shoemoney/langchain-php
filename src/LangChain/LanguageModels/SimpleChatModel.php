<?php

declare(strict_types=1);

namespace LangChain\LanguageModels;

use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Tracers\CallbackManagerForLLMRun;

/**
 * A chat model with the `ChatResult` wrapping already done.
 *
 * Port of `SimpleChatModel` from `@langchain/core/language_models/chat_models`.
 *
 * Subclasses implement `_call()` and get a single `AIMessage` for free. The
 * trade is that the output must be a string: a subclass that needs to attach
 * tool calls or structured content must extend {@see BaseChatModel} and build
 * its own `ChatGeneration`.
 */
abstract class SimpleChatModel extends BaseChatModel
{
    /**
     * Answer one message list with plain text.
     *
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     */
    abstract protected function call(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): string;

    /**
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     */
    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        $text = $this->call($messages, $options, $runManager);

        return new ChatResult([new ChatGeneration(new AIMessage($text), $text)]);
    }
}
