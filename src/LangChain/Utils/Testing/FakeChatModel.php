<?php

declare(strict_types=1);

namespace LangChain\Utils\Testing;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Tracers\CallbackManagerForLLMRun;

/**
 * A chat model that echoes back what it was given.
 *
 * Port of `FakeChatModel` from `@langchain/core/utils/testing`.
 *
 * Joining the input messages with newlines and returning that as the reply makes
 * the output depend on the whole conversation, which is what a test needs: it
 * means a mis-wired prompt template or a dropped message shows up as a wrong
 * answer rather than as no visible difference at all.
 *
 * String contents pass through; block lists are JSON-encoded. That asymmetry
 * mirrors the original and is worth knowing when writing an assertion: a
 * message with image or file blocks comes back as a JSON blob, not as text.
 */
final class FakeChatModel extends BaseChatModel
{
    /** @param array<string, mixed> $fields */
    public function __construct(array $fields = [])
    {
        parent::__construct($fields);
    }

    public function llmType(): string
    {
        return 'fake';
    }

    /**
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     */
    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        // A stop token short-circuits the echo. That mirrors how a real provider
        // honours `stop`, and it is the only way to test that this model
        // respects the option at all.
        $stop = $options['stop'] ?? [];
        if (is_array($stop) && $stop !== [] && is_string($stop[0])) {
            return new ChatResult([new ChatGeneration(new AIMessage($stop[0]), $stop[0])]);
        }

        $parts = [];
        foreach ($messages as $message) {
            $parts[] = is_string($message->content)
                ? $message->content
                : (string) json_encode($message->content, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        }
        $text = implode("\n", $parts);

        $runManager?->handleLLMNewToken($text);

        return new ChatResult([new ChatGeneration(new AIMessage($text), $text)], []);
    }
}
