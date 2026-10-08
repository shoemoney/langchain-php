<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Utils;

use LangChain\LanguageModels\Chat\OpenAI\Utils\ResponsesStreamEvents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `utils/tests/responses_stream_events.test.ts`, plus the block-key
 * bookkeeping that the silent-merge risk of this converter calls for.
 */
#[CoversClass(ResponsesStreamEvents::class)]
final class ResponsesStreamEventsTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $events
     * @param array<string, mixed>       $options
     *
     * @return list<array<string, mixed>>
     */
    private static function collect(array $events, array $options = []): array
    {
        return iterator_to_array(ResponsesStreamEvents::convertOpenAIResponsesStream($events, $options), false);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function completed(array $overrides = []): array
    {
        return [
            'type' => 'response.completed',
            'response' => $overrides + [
                'id' => 'resp_done', 'object' => 'response', 'created_at' => 0, 'status' => 'completed', 'model' => 'gpt-4o-mini',
                'output' => [], 'parallel_tool_calls' => true, 'tool_choice' => 'auto', 'tools' => [],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function created(string $id, string $model = 'gpt-4o-mini'): array
    {
        return ['type' => 'response.created', 'response' => ['id' => $id, 'model' => $model]];
    }

    /** @return array<string, mixed> */
    private static function textDelta(string $delta, int $outputIndex = 0, int $contentIndex = 0): array
    {
        return ['type' => 'response.output_text.delta', 'delta' => $delta, 'content_index' => $contentIndex, 'output_index' => $outputIndex];
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return list<array<string, mixed>>
     */
    private static function ofKind(array $events, string $kind): array
    {
        return array_values(array_filter($events, static fn (array $e): bool => $e['event'] === $kind));
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return list<array<string, mixed>>
     */
    private static function finishesOfType(array $events, string $type): array
    {
        return array_values(array_filter(
            $events,
            static fn (array $e): bool => $e['event'] === 'content-block-finish' && $e['content']['type'] === $type,
        ));
    }

    public function testTextOnlyLifecycle(): void
    {
        $events = self::collect([
            self::created('resp_abc'),
            self::textDelta('Hello'),
            self::textDelta(' world'),
            self::completed(['id' => 'resp_abc']),
        ]);

        $kinds = array_column($events, 'event');
        self::assertContains('message-start', $kinds);
        self::assertContains('message-finish', $kinds);

        $deltas = array_values(array_filter(
            $events,
            static fn (array $e): bool => $e['event'] === 'content-block-delta' && $e['delta']['type'] === 'text-delta',
        ));
        self::assertCount(2, $deltas);
        self::assertSame('Hello', $deltas[0]['delta']['text']);
        self::assertSame(' world', $deltas[1]['delta']['text']);

        $finish = self::ofKind($events, 'content-block-finish')[0];
        self::assertSame('Hello world', $finish['content']['text']);
    }

    public function testMessageStartCarriesTheResponseId(): void
    {
        $events = self::collect([self::created('resp_test'), self::completed(['id' => 'resp_test'])]);

        self::assertSame('resp_test', self::ofKind($events, 'message-start')[0]['id']);
        self::assertSame('response.created', self::ofKind($events, 'provider')[0]['name']);
    }

    public function testKeepsTextBlocksSeparateAcrossOutputItems(): void
    {
        $events = self::collect([
            self::created('resp_multi_text'),
            self::textDelta('First', 0, 0),
            self::textDelta('Second', 1, 0),
            self::completed(['id' => 'resp_multi_text']),
        ]);

        $finishes = self::finishesOfType($events, 'text');
        self::assertCount(2, $finishes);
        self::assertSame([0, 'First'], [$finishes[0]['index'], $finishes[0]['content']['text']]);
        self::assertSame([1, 'Second'], [$finishes[1]['index'], $finishes[1]['content']['text']]);
    }

    public function testKeepsTextBlocksSeparateAcrossContentIndexes(): void
    {
        $events = self::collect([
            self::created('r'),
            self::textDelta('a', 0, 0),
            self::textDelta('b', 0, 1),
            self::textDelta('c', 0, 0),
            self::completed(),
        ]);

        $finishes = self::finishesOfType($events, 'text');
        self::assertSame(['ac', 'b'], array_map(static fn (array $f): string => $f['content']['text'], $finishes));
        self::assertCount(2, self::ofKind($events, 'content-block-start'));
    }

    public function testReasoningDeltas(): void
    {
        $events = self::collect([
            self::created('resp_r', 'o3'),
            ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'Let me', 'summary_index' => 0, 'output_index' => 0],
            ['type' => 'response.reasoning_summary_text.delta', 'delta' => ' think', 'summary_index' => 0, 'output_index' => 0],
            self::completed(['id' => 'resp_r']),
        ]);

        $deltas = array_values(array_filter(
            $events,
            static fn (array $e): bool => $e['event'] === 'content-block-delta' && $e['delta']['type'] === 'reasoning-delta',
        ));
        self::assertCount(2, $deltas);
        self::assertSame('Let me think', self::finishesOfType($events, 'reasoning')[0]['content']['reasoning']);
    }

    public function testKeepsReasoningBlocksSeparateAcrossOutputItems(): void
    {
        $events = self::collect([
            self::created('resp_multi_reasoning', 'o3'),
            ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'First thought', 'summary_index' => 0, 'output_index' => 0],
            ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'Second thought', 'summary_index' => 0, 'output_index' => 1],
            self::completed(),
        ]);

        $finishes = self::finishesOfType($events, 'reasoning');
        self::assertCount(2, $finishes);
        self::assertSame([0, 'First thought'], [$finishes[0]['index'], $finishes[0]['content']['reasoning']]);
        self::assertSame([1, 'Second thought'], [$finishes[1]['index'], $finishes[1]['content']['reasoning']]);
    }

    /** @return list<array<string, mixed>> */
    private static function toolEvents(): array
    {
        return [
            self::created('resp_tools'),
            ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_abc', 'name' => 'web_search', 'arguments' => '']],
            ['type' => 'response.function_call_arguments.delta', 'output_index' => 0, 'delta' => '{"query"'],
            ['type' => 'response.function_call_arguments.delta', 'output_index' => 0, 'delta' => ':"weather"}'],
            ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_abc', 'name' => 'web_search', 'arguments' => '{"query":"weather"}']],
            self::completed(['id' => 'resp_tools']),
        ];
    }

    public function testToolCallStreamingAndFinalization(): void
    {
        $events = self::collect(self::toolEvents());

        $finish = self::finishesOfType($events, 'tool_call')[0];
        self::assertSame('web_search', $finish['content']['name']);
        self::assertSame(['query' => 'weather'], $finish['content']['args']);
        self::assertSame('call_abc', $finish['content']['id']);
        self::assertCount(1, self::ofKind($events, 'content-block-start'));
    }

    public function testToolArgumentDeltasCarryTheAccumulatedArguments(): void
    {
        $events = self::collect(self::toolEvents());
        $deltas = array_values(array_filter($events, static fn (array $e): bool => $e['event'] === 'content-block-delta'));

        self::assertSame('{"query"', $deltas[0]['delta']['fields']['args']);
        self::assertSame('{"query":"weather"}', $deltas[1]['delta']['fields']['args']);
        self::assertSame('call_abc', $deltas[1]['delta']['fields']['id']);
        self::assertSame('web_search', $deltas[1]['delta']['fields']['name']);
    }

    public function testParallelToolCallsGetOneBlockEach(): void
    {
        $events = self::collect([
            self::created('r'),
            ['type' => 'response.output_item.added', 'output_index' => 1, 'item' => ['type' => 'function_call', 'id' => 'a', 'call_id' => 'ca', 'name' => 'one', 'arguments' => '']],
            ['type' => 'response.output_item.added', 'output_index' => 2, 'item' => ['type' => 'function_call', 'id' => 'b', 'call_id' => 'cb', 'name' => 'two', 'arguments' => '']],
            ['type' => 'response.function_call_arguments.delta', 'output_index' => 1, 'delta' => '{"x":1}'],
            ['type' => 'response.function_call_arguments.delta', 'output_index' => 2, 'delta' => '{"y":2}'],
            self::completed(),
        ]);

        $finishes = self::finishesOfType($events, 'tool_call');
        self::assertSame([['one', ['x' => 1]], ['two', ['y' => 2]]], array_map(static fn (array $f): array => [$f['content']['name'], $f['content']['args']], $finishes));
    }

    public function testMalformedToolArgumentsFinishAsAnInvalidToolCall(): void
    {
        $events = self::collect([
            self::created('r'),
            ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'function_call', 'id' => 'a', 'call_id' => 'ca', 'name' => 'one', 'arguments' => '{"x":']],
            self::completed(),
        ]);

        $finish = self::finishesOfType($events, 'invalid_tool_call')[0];
        self::assertSame('{"x":', $finish['content']['args']);
        self::assertSame('Failed to parse tool call arguments as JSON', $finish['content']['error']);
    }

    public function testACustomToolCallStreamsItsInputAsArguments(): void
    {
        $events = self::collect([
            self::created('r'),
            ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'custom_tool_call', 'id' => 'c', 'call_id' => 'cc', 'name' => 'exec', 'input' => '']],
            ['type' => 'response.custom_tool_call_input.delta', 'output_index' => 0, 'delta' => '"print(1)"'],
            self::completed(),
        ]);

        $finish = self::finishesOfType($events, 'tool_call')[0];
        self::assertSame('exec', $finish['content']['name']);
        self::assertSame('print(1)', $finish['content']['args']);
    }

    public function testUsageSnapshotOnCompleted(): void
    {
        $events = self::collect([
            self::created('resp_u'),
            self::completed([
                'id' => 'resp_u',
                'usage' => [
                    'input_tokens' => 100, 'output_tokens' => 20, 'total_tokens' => 120,
                    'input_tokens_details' => ['cached_tokens' => 40], 'output_tokens_details' => ['reasoning_tokens' => 5],
                ],
            ]),
        ]);

        $usage = self::ofKind($events, 'usage')[0]['usage'];
        self::assertSame(100, $usage['input_tokens']);
        self::assertSame(20, $usage['output_tokens']);
        self::assertSame(['cache_read' => 40], $usage['input_token_details']);

        $finish = self::ofKind($events, 'message-finish')[0];
        self::assertSame($usage, $finish['usage']);
        self::assertSame('stop', $finish['reason']);
        self::assertSame('openai', $finish['responseMetadata']['model_provider']);
    }

    public function testStreamUsageFalseSuppressesUsage(): void
    {
        $events = self::collect([self::created('resp_x'), self::completed(['id' => 'resp_x'])], ['streamUsage' => false]);

        self::assertSame([], self::ofKind($events, 'usage'));
        self::assertArrayNotHasKey('usage', self::ofKind($events, 'message-finish')[0]);
    }

    public function testAnIncompleteResponseFinishesWithLength(): void
    {
        $events = self::collect([
            self::created('r'),
            ['type' => 'response.incomplete', 'response' => ['id' => 'r', 'status' => 'incomplete', 'model' => 'm']],
        ]);

        self::assertSame('length', self::ofKind($events, 'message-finish')[0]['reason']);
    }

    public function testUnknownEventsPassThroughAsProviderEvents(): void
    {
        $event = ['type' => 'response.in_progress', 'response' => ['id' => 'r']];
        $events = self::collect([$event]);

        $provider = self::ofKind($events, 'provider')[0];
        self::assertSame('response.in_progress', $provider['name']);
        self::assertSame($event, $provider['payload']);
    }

    public function testPartialImagesAreSkippedEntirely(): void
    {
        $events = self::collect([['type' => 'response.image_generation_call.partial_image', 'partial_image_b64' => 'x']]);

        self::assertSame(['message-start', 'message-finish'], array_column($events, 'event'));
    }

    public function testAnEmptySourceStillBracketsTheMessage(): void
    {
        self::assertSame(['message-start', 'message-finish'], array_column(self::collect([]), 'event'));
    }

    public function testProviderNameIsConfigurable(): void
    {
        $events = self::collect([self::created('r'), self::completed()], ['provider' => 'azure']);

        self::assertSame('azure', self::ofKind($events, 'provider')[0]['provider']);
        self::assertSame('azure', self::ofKind($events, 'message-finish')[0]['responseMetadata']['model_provider']);
    }

    public function testUnfinishedBlocksAreFinalizedBeforeMessageFinish(): void
    {
        $events = self::collect([self::created('r'), self::textDelta('cut off')]);

        $kinds = array_column($events, 'event');
        self::assertSame('content-block-finish', $kinds[count($kinds) - 2]);
        self::assertSame('message-finish', $kinds[count($kinds) - 1]);
    }
}
