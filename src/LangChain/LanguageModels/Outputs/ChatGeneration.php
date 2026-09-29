<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Outputs;

use LangChain\Messages\BaseMessage;

/**
 * One chat completion: the message plus its text rendering.
 *
 * Port of the `ChatGeneration` interface from `@langchain/core/outputs`.
 *
 * The `text` field is redundant with `message->text` in principle, but it is
 * not in practice: providers populate the two at different points, and code
 * that only wants the string should not have to know that.
 */
class ChatGeneration extends Generation
{
    /**
     * @param array<string, mixed> $generationInfo
     */
    public function __construct(
        public BaseMessage $message,
        string $text = '',
        array $generationInfo = [],
    ) {
        parent::__construct($text, $generationInfo);
    }
}
