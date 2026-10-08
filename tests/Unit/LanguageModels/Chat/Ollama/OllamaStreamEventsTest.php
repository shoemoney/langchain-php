<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Ollama;

use LangChain\LanguageModels\Chat\Ollama\ChatOllama;
use LangChain\LanguageModels\Chat\Ollama\OllamaStreamEvents;
use LangChain\Messages\HumanMessage;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Ports `utils/tests/stream_events.test.ts` and `chat_models_stream_events.test.ts`.
 *
 * The second upstream file asserts through vitest matchers (`toHaveStreamText`,
 * `toHaveStreamReasoning`, ...) over `streamEvents()`, a core API this port does
 * not have. The same four scenarios are asserted here directly on the events
 * {@see ChatOllama::streamChatModelEvents()} yields, over a real NDJSON body.
 */
#[CoversClass(OllamaStreamEvents::class)]
#[CoversClass(ChatOllama::class)]
final class OllamaStreamEventsTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $chunks
     * @param array{streamUsage?: bool, think?: bool} $options
     *
     * @return list<array<string, mixed>>
     */
    private static function collect(array $chunks, array $options = []): array
    {
        return iterator_to_array(OllamaStreamEvents::convert($chunks, $options), false);
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return list<string>
     */
    private static function names(array $events): array
    {
        return array_column($events, 'event');
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return array<string, mixed>
     */
    private static function last(array $events): array
    {
        return $events[count($events) - 1];
    }

    // ---- utils/tests/stream_events.test.ts ---------------------------------

    public function testTextOnlyStreaming(): void
    {
        $events = self::collect([
            ['message' => ['content' => 'Hello']],
            ['message' => ['content' => ' world']],
            ['message' => [], 'done_reason' => 'stop'],
        ]);

        self::assertContains('message-start', self::names($events));
        self::assertContains('message-finish', self::names($events));

        $finish = array_values(array_filter($events, static fn (array $e): bool => $e['event'] === 'content-block-finish'));
        self::assertSame('Hello world', $finish[0]['content']['text']);
    }

    public function testThinkingWhenThinkOptionEnabled(): void
    {
        $events = self::collect(
            [['message' => ['thinking' => 'hmm']], ['message' => [], 'done_reason' => 'stop']],
            ['think' => true],
        );

        $finish = array_values(array_filter(
            $events,
            static fn (array $e): bool => $e['event'] === 'content-block-finish' && $e['content']['type'] === 'reasoning',
        ));

        self::assertCount(1, $finish);
        self::assertSame('hmm', $finish[0]['content']['reasoning']);
    }

    public function testUsageFromTokenCounts(): void
    {
        $events = self::collect([
            ['message' => ['content' => 'Hi'], 'prompt_eval_count' => 10, 'eval_count' => 3],
            ['message' => [], 'done_reason' => 'stop'],
        ]);

        $usage = array_values(array_filter($events, static fn (array $e): bool => $e['event'] === 'usage'));
        self::assertGreaterThan(0, count($usage));
        self::assertSame(['input_tokens' => 10, 'output_tokens' => 3, 'total_tokens' => 13], $usage[0]['usage']);
    }

    // ---- behaviour upstream leaves untested --------------------------------

    public function testThinkingIsIgnoredWhenThinkIsOff(): void
    {
        $events = self::collect([['message' => ['thinking' => 'hmm', 'content' => 'hi']]]);

        self::assertSame(['message-start', 'content-block-start', 'content-block-delta', 'content-block-finish', 'message-finish'], self::names($events));
    }

    public function testStreamUsageFalseSuppressesUsageEvents(): void
    {
        $events = self::collect(
            [['message' => ['content' => 'Hi'], 'prompt_eval_count' => 10, 'eval_count' => 3]],
            ['streamUsage' => false],
        );

        self::assertNotContains('usage', self::names($events));
        self::assertArrayNotHasKey('usage', self::last($events));
    }

    public function testToolCallsFinishAsDecodedToolCalls(): void
    {
        $events = self::collect([
            ['message' => ['tool_calls' => [['function' => ['name' => 'web_search', 'arguments' => ['query' => 'weather']]]]]],
            ['message' => [], 'done_reason' => 'stop'],
        ]);

        $finish = array_values(array_filter($events, static fn (array $e): bool => $e['event'] === 'content-block-finish'));

        self::assertSame('tool_call', $finish[0]['content']['type']);
        self::assertSame('web_search', $finish[0]['content']['name']);
        self::assertSame(['query' => 'weather'], $finish[0]['content']['args']);
    }

    public function testMalformedStringArgumentsFinishAsAnInvalidToolCall(): void
    {
        $events = self::collect([
            ['message' => ['tool_calls' => [['function' => ['name' => 'f', 'arguments' => '{oops']]]]],
        ]);

        $finish = array_values(array_filter($events, static fn (array $e): bool => $e['event'] === 'content-block-finish'));

        self::assertSame('invalid_tool_call', $finish[0]['content']['type']);
        self::assertSame('{oops', $finish[0]['content']['args']);
    }

    public function testDoneReasonLengthIsKeptAndAnythingElseIsStop(): void
    {
        self::assertSame('length', self::last(self::collect([['message' => [], 'done_reason' => 'length']]))['reason']);
        self::assertSame('stop', self::last(self::collect([['message' => [], 'done_reason' => 'load']]))['reason']);
        self::assertNull(self::last(self::collect([['message' => []]]))['reason']);
    }

    public function testTheMessageFinishNamesTheProvider(): void
    {
        $finish = self::last(self::collect([['message' => ['content' => 'x']]]));

        self::assertSame(['model_provider' => 'ollama'], $finish['responseMetadata']);
    }

    // ---- chat_models_stream_events.test.ts, over the wire -------------------

    /**
     * @param list<array<string, mixed>> $chunks
     * @return list<array<string, mixed>>
     */
    private static function eventsFromWire(array $chunks, ?FakeHttpClient &$http = null): array
    {
        $body = implode('', array_map(static fn (array $c): string => json_encode($c) . "\n", $chunks));
        $http = new FakeHttpClient([], [$body]);
        $model = new ChatOllama(['model' => 'llama3', 'think' => true, 'checkOrPullModel' => false, 'httpClient' => $http]);

        return iterator_to_array($model->streamChatModelEvents([new HumanMessage('Hello')]), false);
    }

    public function testStreamsTextOverTheWire(): void
    {
        $events = self::eventsFromWire([
            ['message' => ['content' => 'Hello']],
            ['message' => ['content' => ' world']],
            ['message' => [], 'done_reason' => 'stop'],
        ], $http);

        $deltas = array_column(
            array_filter($events, static fn (array $e): bool => $e['event'] === 'content-block-delta'),
            'delta',
        );
        self::assertSame('Hello world', implode('', array_column($deltas, 'text')));
        self::assertTrue($http->lastRequestBody()['stream']);
    }

    public function testStreamsReasoningOverTheWire(): void
    {
        $events = self::eventsFromWire([
            ['message' => ['thinking' => 'Let me reason...']],
            ['message' => [], 'done_reason' => 'stop'],
        ]);

        $finish = array_values(array_filter($events, static fn (array $e): bool => $e['event'] === 'content-block-finish'));
        self::assertSame('Let me reason...', $finish[0]['content']['reasoning']);
    }

    public function testStreamsToolCallsOverTheWire(): void
    {
        $events = self::eventsFromWire([
            ['message' => ['content' => 'Let me search.']],
            ['message' => ['tool_calls' => [['function' => ['name' => 'web_search', 'arguments' => ['query' => 'weather']]]]]],
            ['message' => [], 'done_reason' => 'stop'],
        ]);

        $calls = array_values(array_filter(
            $events,
            static fn (array $e): bool => $e['event'] === 'content-block-finish' && $e['content']['type'] === 'tool_call',
        ));

        self::assertCount(1, $calls);
        self::assertSame('web_search', $calls[0]['content']['name']);
        self::assertSame(['query' => 'weather'], $calls[0]['content']['args']);
    }

    public function testStreamsUsageOverTheWire(): void
    {
        $events = self::eventsFromWire([
            ['message' => ['content' => 'Hi'], 'prompt_eval_count' => 10, 'eval_count' => 3],
            ['message' => [], 'done_reason' => 'stop'],
        ]);

        $finish = self::last($events);
        self::assertSame(['input_tokens' => 10, 'output_tokens' => 3, 'total_tokens' => 13], $finish['usage']);
    }
}
