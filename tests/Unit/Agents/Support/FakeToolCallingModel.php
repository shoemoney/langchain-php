<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Support;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableLambda;
use LangChain\Tracers\CallbackManagerForLLMRun;

/**
 * Port of `FakeToolCallingModel` from `langchain/src/agents/tests/utils.ts`: a chat model that echoes the
 * conversation back as the AI message content and replays scripted tool calls, one set per model call.
 *
 * The content is the non-empty message contents joined with `-` (a system prompt `Foo` and a user message `hi?`
 * give `Foo-hi?`). The scripted index is shared by every copy `bindTools()` makes and resets at the start of
 * a conversation, so one model can serve several `invoke()` calls. It does not extend `FakeToolCallingChatModel`
 * because upstream's two fakes behave differently: this one's `bindTools()` is a plain copy, and it never sleeps.
 */
class FakeToolCallingModel extends BaseChatModel
{
    /** @var list<list<array<string, mixed>>> */
    public array $toolCalls;

    /** @var \stdClass{current: int} the index, shared across the copies bindTools() makes */
    public \stdClass $indexRef;

    public mixed $structuredResponse;

    /** @var list<mixed> */
    public array $tools = [];

    /**
     * @param array{toolCalls?: list<list<array<string, mixed>>>, structuredResponse?: mixed, index?: int, indexRef?: \stdClass} $fields
     */
    public function __construct(array $fields = [])
    {
        parent::__construct([]);
        $this->toolCalls = $fields['toolCalls'] ?? [];
        $this->structuredResponse = $fields['structuredResponse'] ?? null;
        $this->indexRef = $fields['indexRef'] ?? (object) ['current' => $fields['index'] ?? 0];
    }

    public function llmType(): string
    {
        return 'fake-tool-calling';
    }

    public function index(): int
    {
        return $this->indexRef->current;
    }

    /**
     * @param list<mixed>          $tools
     * @param array<string, mixed> $kwargs
     */
    public function bindTools(array $tools, array $kwargs = []): static
    {
        $next = clone $this;
        $next->tools = [...$this->tools, ...$tools];

        return $next;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, mixed> $config
     */
    public function withStructuredOutput(array $schema, array $config = []): Runnable
    {
        return RunnableLambda::from(fn (): mixed => $this->structuredResponse);
    }

    /**
     * @param list<\LangChain\Messages\BaseMessage> $messages
     * @param array<string, mixed>                  $options
     */
    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        $last = $messages[\count($messages) - 1];
        $content = \is_string($last->content) ? $last->content : '';

        // Prompt concatenation.
        if (\count($messages) > 1) {
            $parts = [];
            foreach ($messages as $message) {
                $part = $message->content;
                if ($part === '' || $part === []) {
                    continue;
                }
                $parts[] = \is_string($part)
                    ? $part
                    : implode('-', array_map(
                        static fn (mixed $p): string => \is_string($p) ? $p : (\is_array($p) && isset($p['text']) ? (string) $p['text'] : ''),
                        $part,
                    ));
            }
            $content = implode('-', $parts);
        }

        // Reset the index at the start of a new conversation (only human messages), so the model can be reused.
        $allHuman = true;
        foreach ($messages as $message) {
            $allHuman = $allHuman && $message instanceof HumanMessage;
        }
        $isStartOfConversation = \count($messages) === 1 || (\count($messages) === 2 && $allHuman);
        if ($isStartOfConversation && $this->indexRef->current !== 0) {
            $this->indexRef->current = 0;
        }

        $currentToolCalls = $this->toolCalls[$this->indexRef->current] ?? [];
        $messageId = (string) $this->indexRef->current;

        // Move to the next set of tool calls for subsequent invocations.
        $this->indexRef->current = ($this->indexRef->current + 1) % max(1, \count($this->toolCalls));

        $message = new AIMessage([
            'content' => $content,
            'id' => $messageId,
            'tool_calls' => array_map(static fn (array $call): array => [...$call, 'type' => 'tool_call'], $currentToolCalls),
        ]);

        return new ChatResult([new ChatGeneration($message, $content)]);
    }
}
