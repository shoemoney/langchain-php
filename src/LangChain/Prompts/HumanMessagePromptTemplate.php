<?php

declare(strict_types=1);

namespace LangChain\Prompts;

use LangChain\Messages\HumanMessage;

/**
 * A human message step.
 *
 * Port of `HumanMessagePromptTemplate` from `@langchain_core/prompts/chat`.
 *
 * ```php
 * $message = HumanMessagePromptTemplate::fromTemplate('{text}');
 * $chat = ChatPromptTemplate::fromMessages([$message]);
 * $chat->invoke(['text' => 'Hello world!']);
 * ```
 */
class HumanMessagePromptTemplate extends StringImageMessagePromptTemplate
{
    public const MESSAGE_CLASS = HumanMessage::class;
}
