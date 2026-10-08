<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\DeepSeek;

use LangChain\LanguageModels\Chat\DeepSeek\ChatDeepSeek;
use LangChain\Messages\AIMessageChunk;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `chat_models_reasoning.test.ts` and `chat_models_stream_events.test.ts`
 * from `@langchain/deepseek`.
 */
#[CoversClass(ChatDeepSeek::class)]
final class ChatDeepSeekReasoningTest extends TestCase
{
    /**
     * A model that streams `$contents` as one delta each, then a stop chunk.
     *
     * @param list<string> $contents
     */
    private static function streaming(array $contents, bool $withEnvelope = false): ChatDeepSeek
    {
        $events = [];
        foreach ($contents as $content) {
            $events[] = self::sse(['choices' => [$withEnvelope
                ? ['index' => 0, 'delta' => ['content' => $content], 'finish_reason' => null]
                : ['delta' => ['content' => $content]]]]);
        }
        $events[] = self::sse(['choices' => [['finish_reason' => 'stop']]]);
        $events[] = "data: [DONE]\n\n";

        return new ChatDeepSeek(['apiKey' => 'test', 'httpClient' => new FakeHttpClient([], $events)]);
    }

    /**
     * @return list<AIMessageChunk>
     */
    private static function collect(ChatDeepSeek $model): array
    {
        $chunks = [];
        foreach ($model->stream('hi') as [, $chunk]) {
            $chunks[] = $chunk;
        }

        return $chunks;
    }

    /**
     * @param list<AIMessageChunk> $chunks
     */
    private static function content(array $chunks): string
    {
        return implode('', array_map(static fn (AIMessageChunk $c): string => (string) $c->content, $chunks));
    }

    /**
     * @param list<AIMessageChunk> $chunks
     */
    private static function reasoning(array $chunks): string
    {
        return implode('', array_map(static fn (AIMessageChunk $c): string => (string) ($c->additional_kwargs['reasoning_content'] ?? ''), $chunks));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function sse(array $payload): string
    {
        return 'data: ' . json_encode($payload + ['id' => 'chatcmpl-123', 'model' => 'deepseek-chat']) . "\n\n";
    }

    public function testConstructorAcceptsModelShorthand(): void
    {
        $model = new ChatDeepSeek('deepseek-chat', ['apiKey' => 'test']);

        self::assertSame('deepseek-chat', $model->model);
    }

    public function testSeparatesThinkTagsIntoReasoningContent(): void
    {
        $chunks = self::collect(self::streaming(['<think>', 'thinking process...', '</think>', 'Hello world'], withEnvelope: true));

        self::assertSame('Hello world', self::content($chunks));
        self::assertSame('thinking process...', self::reasoning($chunks));
    }

    public function testHandlesMultipleThinkBlocksAndContentBeforeAndAfter(): void
    {
        $chunks = self::collect(self::streaming([
            'Start ', '<think>', 'Reason 1', '</think>', ' Middle ', '<think>', 'Reason 2', '</think>', ' End',
        ]));

        self::assertSame('Start  Middle  End', self::content($chunks));
        self::assertSame('Reason 1Reason 2', self::reasoning($chunks));
    }

    public function testFlushesAnUnclosedThinkTagAtTheEndOfTheStream(): void
    {
        $chunks = self::collect(self::streaming(['Start ', '<think>', 'Unclosed thought']));

        self::assertSame('Unclosed thought', self::reasoning($chunks));
        self::assertSame('Start ', self::content($chunks));
    }

    public function testHandlesTagsSplitAcrossChunks(): void
    {
        $chunks = self::collect(self::streaming(['<th', 'ink>Thought', '</th', 'ink>']));

        self::assertSame('Thought', self::reasoning($chunks));
        self::assertSame('', self::content($chunks));
    }

    public function testHandlesEmptyThinkBlocks(): void
    {
        $chunks = self::collect(self::streaming(['Before ', '<think>', '</think>', ' After']));

        self::assertSame('Before  After', self::content($chunks));
        self::assertSame('', self::reasoning($chunks));
    }

    public function testTreatsANestedOpeningTagAsReasoningText(): void
    {
        $chunks = self::collect(self::streaming(['<think>', 'Outer ', '<think>', 'Inner', '</think>', ' Content']));

        // The first closing tag ends the outer block; what follows is content.
        self::assertSame(' Content', self::content($chunks));
        self::assertSame('Outer <think>Inner', self::reasoning($chunks));
    }

    public function testHandlesMalformedTagsGracefully(): void
    {
        $chunks = self::collect(self::streaming(['</think>', 'Text ', '<think', ' more']));

        // An orphan closing tag and an unfinished opening tag are plain content.
        self::assertSame('</think>Text <think more', self::content($chunks));
        self::assertSame('', self::reasoning($chunks));
    }

    public function testASplitTagThatTurnsOutNotToBeOneIsReleasedAsContent(): void
    {
        $chunks = self::collect(self::streaming(['a <th', 'at is all']));

        self::assertSame('a <that is all', self::content($chunks));
    }

    // ---- chat_models_stream_events.test.ts ------------------------------------------------------------

    public function testStreamsText(): void
    {
        $model = new ChatDeepSeek(['apiKey' => 'fake-key', 'model' => 'deepseek-chat', 'httpClient' => new FakeHttpClient([], [
            self::sse(['choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hello']]]]),
            self::sse(['choices' => [['index' => 0, 'delta' => ['content' => ' world']]]]),
            self::sse(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]]),
            "data: [DONE]\n\n",
        ])]);

        self::assertSame('Hello world', self::content(self::collect($model)));
    }

    public function testStreamsNativeReasoningContent(): void
    {
        $model = new ChatDeepSeek(['apiKey' => 'fake-key', 'model' => 'deepseek-reasoner', 'httpClient' => new FakeHttpClient([], [
            self::sse(['choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'reasoning_content' => 'Let me reason...']]]]),
            self::sse(['choices' => [['index' => 0, 'delta' => ['content' => 'Answer.']]]]),
            self::sse(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]]),
            "data: [DONE]\n\n",
        ])]);

        $chunks = self::collect($model);

        self::assertSame('Let me reason...', self::reasoning($chunks));
        self::assertSame('Answer.', self::content($chunks));

        $folded = array_shift($chunks);
        foreach ($chunks as $chunk) {
            $folded = $folded->concat($chunk);
        }
        self::assertSame('Let me reason...', $folded->additional_kwargs['reasoning_content']);
        self::assertSame('deepseek', $folded->response_metadata['model_provider']);
        self::assertSame(
            [['type' => 'reasoning', 'reasoning' => 'Let me reason...'], ['type' => 'text', 'text' => 'Answer.']],
            ChatDeepSeek::contentBlocks($folded),
        );
    }

    public function testStreamsToolCalls(): void
    {
        $model = new ChatDeepSeek(['apiKey' => 'fake-key', 'httpClient' => new FakeHttpClient([], [
            self::sse(['choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'id' => 'call_1', 'function' => ['name' => 'web_search', 'arguments' => '']]]]]]]),
            self::sse(['choices' => [['index' => 0, 'delta' => ['tool_calls' => [['index' => 0, 'function' => ['arguments' => '{"query":"weather"}']]]]]]]),
            self::sse(['choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]]),
            "data: [DONE]\n\n",
        ])]);

        $folded = null;
        foreach (self::collect($model) as $chunk) {
            $folded = $folded === null ? $chunk : $folded->concat($chunk);
        }

        $calls = $folded->toMessage()->toolCalls;
        self::assertSame('web_search', $calls[0]['name']);
        self::assertSame(['query' => 'weather'], $calls[0]['args']);
    }
}
