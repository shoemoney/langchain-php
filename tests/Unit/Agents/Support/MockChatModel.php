<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Support;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Messages\AIMessage;
use LangChain\Tracers\CallbackManagerForLLMRun;

/**
 * The `MockChatModel` of `agents/tests/withAgentName.test.ts`: echoes the last message back as an AI
 * message named `test-agent`.
 */
final class MockChatModel extends BaseChatModel
{
    public function llmType(): string
    {
        return 'mock';
    }

    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        $last = $messages[\count($messages) - 1];
        $text = 'Echo: ' . (\is_string($last->content) ? $last->content : (string) json_encode($last->content));

        return new ChatResult([new ChatGeneration(new AIMessage(['content' => $text, 'name' => 'test-agent']), $text)]);
    }
}
