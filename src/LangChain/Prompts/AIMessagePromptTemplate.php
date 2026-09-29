<?php

declare(strict_types=1);

namespace LangChain\Prompts;

use LangChain\Messages\AIMessage;

/**
 * An AI message step.
 *
 * Port of `AIMessagePromptTemplate` from `@langchain_core/prompts/chat`.
 *
 * Mostly used for a few-shot transcript — putting an assistant turn in front of
 * the model's turn is one of the cheapest ways to steer its style — or to
 * pre-fill a response prefix.
 */
class AIMessagePromptTemplate extends StringImageMessagePromptTemplate
{
    public const MESSAGE_CLASS = AIMessage::class;
}
