<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Responses\Support;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Messages\BaseMessage;
use LangChain\Tracers\CallbackManagerForLLMRun;

/**
 * The chat model the structured-response tests script: replays AI messages in order (cycling), reports a
 * `structuredOutput` capability profile, and records everything handed to `bindTools()`.
 *
 * Upstream's `FakeToolCallingChatModel` (`agents/tests/utils.ts`) is the same idea; this one differs in that
 * `bindTools()` keeps the raw tool definitions (the structured output tools are plain function-definition arrays)
 * and the options, so a test can read back what the agent bound.
 */
final class ScriptedChatModel extends BaseChatModel
{
    /** @var list<BaseMessage> */
    public array $responses;

    /** @var \stdClass{idx: int} shared by every copy `bindTools()` makes */
    public \stdClass $cursor;

    /** @var \ArrayObject<int, array{tools: list<mixed>, options: array<string, mixed>}> */
    public \ArrayObject $bindCalls;

    public bool $structuredOutput;

    /** @var list<mixed> */
    public array $tools = [];

    /**
     * @param array{responses?: list<BaseMessage>, structuredOutput?: bool} $fields
     */
    public function __construct(array $fields = [])
    {
        parent::__construct([]);
        $this->responses = array_values($fields['responses'] ?? []);
        $this->structuredOutput = $fields['structuredOutput'] ?? true;
        $this->cursor = (object) ['idx' => 0];
        $this->bindCalls = new \ArrayObject();
    }

    public function llmType(): string
    {
        return 'scripted';
    }

    /** @return array<string, bool> */
    public function profile(): array
    {
        return ['structuredOutput' => $this->structuredOutput];
    }

    public function bindTools(array $tools, array $kwargs = []): static
    {
        $this->bindCalls->append(['tools' => $tools, 'options' => $kwargs]);
        $next = clone $this;
        $next->tools = $tools;

        return $next;
    }

    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        $pool = $this->responses !== [] ? $this->responses : $messages;
        $message = $pool[$this->cursor->idx % \count($pool)];
        ++$this->cursor->idx;

        return new ChatResult([new ChatGeneration($message, '')]);
    }
}
