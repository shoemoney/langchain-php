<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenRouter;

use LangChain\LanguageModels\Chat\OpenRouter\ChatOpenRouter;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `chat_models/tests/chat_models_stream_events.test.ts` (5 cases).
 *
 * Upstream's `toHaveStream*` matchers read `ChatModelStream` properties; here
 * {@see OpenAiStreamFixtures::fold()} derives the same values from the events.
 */
#[CoversClass(ChatOpenRouter::class)]
final class ChatOpenRouterStreamEventsTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $chunks
     *
     * @return array{text: string, reasoning: string, toolCalls: list<array{name: string, args: mixed}>, usage: array<string, mixed>|null, events: list<array<string, mixed>>}
     */
    private static function streamed(array $chunks): array
    {
        $model = new ChatOpenRouter([
            'apiKey' => 'fake-key',
            'model' => 'openai/gpt-4o-mini',
            'httpClient' => new FakeHttpClient([], OpenAiStreamFixtures::sseBody($chunks)),
        ]);

        return OpenAiStreamFixtures::fold($model->streamChatModelEvents([new \LangChain\Messages\HumanMessage('Hello')]));
    }

    public function testStreamsText(): void
    {
        self::assertSame('Hello world', self::streamed(OpenAiStreamFixtures::textOnlyChunks())['text']);
    }

    public function testStreamsReasoning(): void
    {
        self::assertSame('Let me reason...', self::streamed(OpenAiStreamFixtures::reasoningTextChunks())['reasoning']);
    }

    public function testStreamsOpenRouterReasoningField(): void
    {
        $chunks = [
            ['id' => 'gen-1', 'model' => 'openai/gpt-4o-mini', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'reasoning' => 'thinking...'], 'finish_reason' => null]]],
            ['id' => 'gen-1', 'model' => 'openai/gpt-4o-mini', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]],
        ];

        self::assertSame('thinking...', self::streamed($chunks)['reasoning']);
    }

    public function testStreamsToolCalls(): void
    {
        self::assertSame(
            [['name' => 'web_search', 'args' => ['query' => 'weather']]],
            self::streamed(OpenAiStreamFixtures::toolCallChunks())['toolCalls'],
        );
    }

    public function testStreamsUsage(): void
    {
        $usage = self::streamed(OpenAiStreamFixtures::textOnlyChunksWithUsage())['usage'];

        self::assertSame(['input_tokens' => 10, 'output_tokens' => 2, 'total_tokens' => 12], $usage);
    }

    public function testStreamUsageFalseSuppressesUsageEvents(): void
    {
        $model = new ChatOpenRouter([
            'apiKey' => 'fake-key',
            'model' => 'openai/gpt-4o-mini',
            'streamUsage' => false,
            'httpClient' => new FakeHttpClient([], OpenAiStreamFixtures::sseBody(OpenAiStreamFixtures::textOnlyChunksWithUsage())),
        ]);

        $folded = OpenAiStreamFixtures::fold($model->streamChatModelEvents([new \LangChain\Messages\HumanMessage('Hello')]));

        self::assertNull($folded['usage']);
    }
}
