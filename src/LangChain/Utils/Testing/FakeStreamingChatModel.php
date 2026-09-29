<?php

declare(strict_types=1);

namespace LangChain\Utils\Testing;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\BaseMessage;
use LangChain\Tracers\CallbackManagerForLLMRun;

/**
 * A streaming chat model that replays a fixed list of messages.
 *
 * Port of `FakeStreamingChatModel` from `@langchain/core/utils/testing`.
 *
 * This is the double for testing *tool calling* against a model. `chunks` lets a
 * test script an exact delta sequence — including partial tool-call arguments,
 * which is the case worth testing because it is where chunk folding breaks.
 * With no `chunks`, it falls back to streaming a `responses` entry character by
 * character, which is enough for a token-streaming test that does not care
 * about tool calls.
 */
final class FakeStreamingChatModel extends BaseChatModel
{
    /** Milliseconds to pause between chunks. */
    public int $sleep = 0;

    /** @var list<BaseMessage> Full messages to fall back to when no chunks are given. */
    public array $responses = [];

    /** @var list<AIMessageChunk> Exact chunks to emit, including tool-call deltas. */
    public array $chunks = [];

    public ?string $thrownErrorString = null;

    /**
     * How tool specs are rendered in `bindTools()`.
     *
     * Provider tool formats are genuinely different — OpenAI nests under
     * `function`, Anthropic uses `input_schema`, Bedrock wraps in `toolSpec` —
     * and a test that only ever sees one format will not notice a binding bug
     * in the other three. This switch makes the difference testable.
     */
    public string $toolStyle = 'openai';

    /** @var list<StructuredToolSpec> */
    private array $boundTools = [];

    /** @param array<string, mixed> $fields */
    public function __construct(array $fields = [])
    {
        parent::__construct($fields);

        $this->sleep = isset($fields['sleep']) ? (int) $fields['sleep'] : 0;
        $this->responses = array_values((array) ($fields['responses'] ?? []));
        $this->chunks = array_values((array) ($fields['chunks'] ?? []));
        $this->thrownErrorString = isset($fields['thrownErrorString']) ? (string) $fields['thrownErrorString'] : null;
        $this->toolStyle = (string) ($fields['toolStyle'] ?? 'openai');
    }

    public function llmType(): string
    {
        return 'fake';
    }

    /**
     * Render bound tools in this provider's format.
     *
     * Returns a *new* instance rather than mutating `$this`, matching the
     * original's comment that this "mirrors LangChain .bind semantics". A bind
     * that mutated its receiver would make one call's tools leak into the next
     * call's, which is the bug `RunnableBinding` exists to avoid.
     *
     * @param list<StructuredToolSpec> $tools
     */
    public function bindTools(array $tools): self
    {
        $merged = array_merge($this->boundTools, $tools);
        $dicts = array_map($this->renderTool(...), $merged);

        $next = new self([
            'sleep' => $this->sleep,
            'responses' => $this->responses,
            'chunks' => $this->chunks,
            'toolStyle' => $this->toolStyle,
            'thrownErrorString' => $this->thrownErrorString,
        ]);
        $next->boundTools = $merged;
        $next->kwargs['tools'] = $this->toolStyle === 'google' ? [['functionDeclarations' => $dicts]] : $dicts;

        return $next;
    }

    /**
     * @return array<string, mixed>
     */
    private function renderTool(StructuredToolSpec $tool): array
    {
        return match ($this->toolStyle) {
            'openai' => [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'parameters' => $tool->schema->toJsonSchema(),
                ],
            ],
            'anthropic' => [
                'name' => $tool->name,
                'description' => $tool->description,
                'input_schema' => $tool->schema->toJsonSchema(),
            ],
            'bedrock' => [
                'toolSpec' => [
                    'name' => $tool->name,
                    'description' => $tool->description,
                    'inputSchema' => $tool->schema->toJsonSchema(),
                ],
            ],
            'google' => [
                'name' => $tool->name,
                'description' => $tool->description,
                'parameters' => $tool->schema->toJsonSchema(),
            ],
            default => throw new \InvalidArgumentException("Unsupported tool style: {$this->toolStyle}"),
        };
    }

    /**
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     */
    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        if ($this->thrownErrorString !== null) {
            throw new \RuntimeException($this->thrownErrorString);
        }

        $content = $this->responses[0]?->content ?? $messages[0]->content ?? '';
        $toolCalls = $this->chunks[0]?->toolCalls ?? [];

        return new ChatResult([
            new ChatGeneration(new AIMessage([
                'content' => $content,
                'tool_calls' => $toolCalls,
            ]), ''),
        ]);
    }

    /**
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     * @return \Generator<int, ChatGenerationChunk>
     */
    protected function streamResponseChunks(
        array $messages,
        array $options = [],
        ?CallbackManagerForLLMRun $runManager = null,
    ): \Generator {
        if ($this->thrownErrorString !== null) {
            throw new \RuntimeException($this->thrownErrorString);
        }

        if ($this->chunks !== []) {
            foreach ($this->chunks as $msgChunk) {
                $cg = new ChatGenerationChunk(
                    new AIMessageChunk([
                        'content' => $msgChunk->content,
                        'tool_calls' => $msgChunk->toolCalls,
                        'additional_kwargs' => $msgChunk->additional_kwargs,
                    ]),
                    is_string($msgChunk->content) ? $msgChunk->content : '',
                );

                yield $cg;
                $runManager?->handleLLMNewToken(
                    is_string($msgChunk->content) ? $msgChunk->content : '',
                    ['chunk' => $cg],
                );
            }

            return;
        }

        $fallback = $this->responses[0] ?? new AIMessage(is_string($messages[0]->content ?? null) ? $messages[0]->content : '');
        $text = is_string($fallback->content) ? $fallback->content : '';

        foreach ($text === '' ? [] : mb_str_split($text) as $char) {
            if ($this->sleep > 0) {
                usleep($this->sleep * 1000);
            }

            $cg = new ChatGenerationChunk(new AIMessageChunk($char), $char);
            yield $cg;
            $runManager?->handleLLMNewToken($char, ['chunk' => $cg]);
        }
    }
}
