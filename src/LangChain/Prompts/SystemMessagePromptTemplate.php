<?php

declare(strict_types=1);

namespace LangChain\Prompts;

use LangChain\Messages\SystemMessage;

/**
 * A system message step.
 *
 * Port of `SystemMessagePromptTemplate` from `@langchain_core/prompts/chat`.
 *
 * ```php
 * $message = SystemMessagePromptTemplate::fromTemplate('{text}');
 * $chat = ChatPromptTemplate::fromMessages([$message]);
 * $chat->invoke(['text' => 'Hello world!']);
 * ```
 */
class SystemMessagePromptTemplate extends StringImageMessagePromptTemplate
{
    public const MESSAGE_CLASS = SystemMessage::class;
}
