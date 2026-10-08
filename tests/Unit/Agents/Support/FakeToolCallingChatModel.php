<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Support;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Tracers\CallbackManagerForLLMRun;

/**
 * Port of `FakeToolCallingChatModel` from `langchain/src/agents/tests/utils.ts` (the parts the converted
 * tests use): replays scripted responses in order and records the tools bound to it.
 *
 * `bindTools()` returns a copy (as every provider here does) with a simplified OpenAI-style tool list in
 * `$tools`. With no scripted responses it echoes the last message back.
 */
final class FakeToolCallingChatModel extends BaseChatModel
{
    /** @var list<BaseMessage> */
    public array $responses;

    public int $idx = 0;

    /** @var list<array<string, mixed>> */
    public array $tools = [];

    /** @var \ArrayObject<int, list<BaseMessage>> What each call was given; shared with the copies `bindTools()` makes. */
    public \ArrayObject $seen;

    /** @param array<string, mixed> $fields */
    public function __construct(array $fields = [])
    {
        parent::__construct($fields);
        $this->seen = new \ArrayObject();
        $this->responses = array_values((array) ($fields['responses'] ?? []));
    }

    public function llmType(): string
    {
        return 'fake';
    }

    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        $this->seen->append($messages);
        $pool = $this->responses !== [] ? $this->responses : $messages;
        $message = $pool[$this->idx % \count($pool)];
        $this->idx++;

        return new ChatResult([new ChatGeneration($message, '')]);
    }

    public function bindTools(array $tools, array $kwargs = []): static
    {
        $next = clone $this;
        foreach ($tools as $tool) {
            $next->tools[] = ['type' => 'function', 'function' => ['name' => $tool->name]];
        }

        return $next;
    }
}
