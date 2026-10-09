<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\XAI\Responses;

use LangChain\LanguageModels\Chat\XAI\ChatXAIResponses;
use LangChain\Messages\HumanMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Ports `chat_models/tests/chat_models_responses_stream_events.test.ts` (5 tests).
 *
 * The mock overrides the transport seam (`postStream()`) exactly as upstream's `MockStreamChatXAIResponses`
 * overrides `_makeRequest()`. Upstream asserts through vitest matchers over `streamEvents()`
 * (`toHaveStreamText`, `toHaveStreamUsage`, `toHaveStreamReasoning`); the port has no such matchers, so the same
 * facts are read off the protocol events `streamChatModelEvents()` yields.
 */
#[CoversClass(ChatXAIResponses::class)]
final class ChatXAIResponsesStreamEventsTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $events
     */
    private static function mock(array $events): ChatXAIResponses
    {
        return new class ($events) extends ChatXAIResponses {
            /** @var list<array<string, mixed>> */
            public array $requests = [];

            /** @param list<array<string, mixed>> $events */
            public function __construct(private readonly array $events)
            {
                parent::__construct(['apiKey' => 'fake-key', 'model' => 'grok-3', 'streaming' => true]);
            }

            protected function postStream(array $params): \Generator
            {
                $this->requests[] = $params;
                yield from $this->events;
            }

            protected function post(array $params): array
            {
                throw new \LogicException('non-streaming not mocked');
            }
        };
    }

    /** @return list<array<string, mixed>> */
    private static function textEvents(): array
    {
        return [
            ['type' => 'response.created', 'response' => ['id' => 'resp_xai', 'model' => 'grok-3']],
            ['type' => 'response.output_text.delta', 'delta' => 'Hello', 'content_index' => 0, 'output_index' => 0],
            [
                'type' => 'response.completed',
                'response' => [
                    'id' => 'resp_xai', 'object' => 'response', 'created_at' => 0, 'status' => 'completed', 'model' => 'grok-3', 'output' => [],
                    'usage' => ['input_tokens' => 3, 'output_tokens' => 2, 'total_tokens' => 5],
                ],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function reasoningEvents(): array
    {
        return [
            ['type' => 'response.created', 'response' => ['id' => 'resp_reasoning', 'model' => 'grok-3']],
            ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'thinking', 'summary_index' => 0, 'output_index' => 0],
            ['type' => 'response.completed', 'response' => ['id' => 'resp_reasoning', 'status' => 'completed', 'model' => 'grok-3', 'output' => []]],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function protocolEvents(ChatXAIResponses $model): array
    {
        return iterator_to_array($model->streamChatModelEvents([new HumanMessage('Hello')]), false);
    }

    /**
     * @param list<array<string, mixed>> $events
     */
    private static function streamText(array $events): string
    {
        return implode('', array_map(
            static fn (array $e): string => $e['event'] === 'content-block-delta' && $e['delta']['type'] === 'text-delta' ? $e['delta']['text'] : '',
            $events,
        ));
    }

    public function testChatModelStreamTextEndToEnd(): void
    {
        self::assertSame('Hello', self::streamText(self::protocolEvents(self::mock(self::textEvents()))));
    }

    public function testEmitsLifecycleEvents(): void
    {
        $kinds = array_column(self::protocolEvents(self::mock(self::textEvents())), 'event');

        self::assertContains('message-start', $kinds);
        self::assertContains('message-finish', $kinds);
    }

    public function testStreamsText(): void
    {
        $chunks = [];
        foreach (self::mock(self::textEvents())->stream('Hello') as [, $chunk]) {
            $chunks[] = $chunk;
        }

        self::assertSame('Hello', implode('', array_map(static fn ($c): string => (string) $c->content, $chunks)));
    }

    public function testStreamsUsage(): void
    {
        $events = self::protocolEvents(self::mock(self::textEvents()));

        $usage = array_values(array_filter($events, static fn (array $e): bool => $e['event'] === 'usage'));
        self::assertNotEmpty($usage);
        self::assertSame(3, $usage[0]['usage']['input_tokens']);
        self::assertSame(2, $usage[0]['usage']['output_tokens']);
        self::assertSame(5, $usage[0]['usage']['total_tokens']);
    }

    public function testStreamsReasoning(): void
    {
        $events = self::protocolEvents(self::mock(self::reasoningEvents()));

        $reasoning = implode('', array_map(
            static fn (array $e): string => $e['event'] === 'content-block-delta' && $e['delta']['type'] === 'reasoning-delta' ? $e['delta']['reasoning'] : '',
            $events,
        ));
        self::assertSame('thinking', $reasoning);
    }

    public function testTheStreamedRequestIsMarkedStreamingAndCarriesTheConvertedInput(): void
    {
        $model = self::mock(self::textEvents());

        self::protocolEvents($model);

        self::assertTrue($model->requests[0]['stream']);
        self::assertSame([['role' => 'user', 'content' => 'Hello']], $model->requests[0]['input']);
    }
}
