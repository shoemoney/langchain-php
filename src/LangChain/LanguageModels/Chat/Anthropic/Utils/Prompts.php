<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Anthropic\Utils;

use LangChain\Schema\PromptValue;

/** Pure prompt conversion from `@langchain/anthropic/utils/prompts.ts`. */
final class Prompts
{
    private function __construct()
    {
    }

    /**
     * Convert a formatted LangChain prompt into the Anthropic Messages request fields.
     *
     * @return array{system?: string|list<array<string, mixed>>, messages: list<array<string, mixed>>}
     */
    public static function convertPromptToAnthropic(PromptValue $formattedPrompt): array
    {
        $payload = MessageInputs::convert($formattedPrompt->toChatMessages());
        $payload['messages'] ??= [];

        return $payload;
    }
}
