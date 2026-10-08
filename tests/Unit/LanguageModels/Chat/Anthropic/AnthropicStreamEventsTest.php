<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Anthropic;

use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\Anthropic\Utils\StreamEvents;
use LangChain\Messages\HumanMessage;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of `utils/tests/stream_events.test.ts` and the parts of
 * `tests/chat_models_stream_events.test.ts` that do not need
 * `ChatModelStream` / `streamEvents()` (not ported). The mocked
 * `createStreamWithRetry` becomes a scripted SSE body on `FakeHttpClient`.
 */
#[CoversClass(StreamEvents::class)]
#[CoversClass(ChatAnthropic::class)]
final class AnthropicStreamEventsTest extends TestCase
{
    // ---- fixtures ---------------------------------------------------------

    private static function start(string $id, array $usage): array
    {
        return ['type' => 'message_start', 'message' => [
            'id' => $id, 'type' => 'message', 'role' => 'assistant', 'content' => [],
            'model' => 'claude-sonnet-4-20250514', 'stop_reason' => null, 'stop_sequence' => null,
            'usage' => $usage,
        ]];
    }

    private static function blockStart(int $index, array $block): array
    {
        return ['type' => 'content_block_start', 'index' => $index, 'content_block' => $block];
    }

    private static function blockDelta(int $index, array $delta): array
    {
        return ['type' => 'content_block_delta', 'index' => $index, 'delta' => $delta];
    }

    private static function stop(int $index): array
    {
        return ['type' => 'content_block_stop', 'index' => $index];
    }

    private static function messageDelta(string $reason, array $usage): array
    {
        return ['type' => 'message_delta', 'delta' => ['stop_reason' => $reason, 'stop_sequence' => null], 'usage' => $usage];
    }

    /** @return list<array<string, mixed>> */
    private static function textOnly(): array
    {
        return [
            self::start('msg_01ABC', ['input_tokens' => 25, 'output_tokens' => 0]),
            self::blockStart(0, ['type' => 'text', 'text' => '']),
            self::blockDelta(0, ['type' => 'text_delta', 'text' => 'Hello']),
            self::blockDelta(0, ['type' => 'text_delta', 'text' => ' world']),
            self::stop(0),
            self::messageDelta('end_turn', ['output_tokens' => 2]),
            ['type' => 'message_stop'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function thinkingPlusText(): array
    {
        return [
            self::start('msg_02DEF', ['input_tokens' => 50, 'output_tokens' => 0]),
            self::blockStart(0, ['type' => 'thinking', 'thinking' => '']),
            self::blockDelta(0, ['type' => 'thinking_delta', 'thinking' => 'Let me']),
            self::blockDelta(0, ['type' => 'thinking_delta', 'thinking' => ' reason...']),
            self::blockDelta(0, ['type' => 'signature_delta', 'signature' => 'sig_abc']),
            self::stop(0),
            self::blockStart(1, ['type' => 'text', 'text' => '']),
            self::blockDelta(1, ['type' => 'text_delta', 'text' => 'The answer is 42.']),
            self::stop(1),
            self::messageDelta('end_turn', ['output_tokens' => 20]),
            ['type' => 'message_stop'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function toolCall(): array
    {
        return [
            self::start('msg_03GHI', ['input_tokens' => 100, 'output_tokens' => 0]),
            self::blockStart(0, ['type' => 'text', 'text' => '']),
            self::blockDelta(0, ['type' => 'text_delta', 'text' => 'Let me search.']),
            self::stop(0),
            self::blockStart(1, ['type' => 'tool_use', 'id' => 'toolu_01ABC', 'name' => 'web_search']),
            self::blockDelta(1, ['type' => 'input_json_delta', 'partial_json' => '{"query"']),
            self::blockDelta(1, ['type' => 'input_json_delta', 'partial_json' => ':"weather"}']),
            self::stop(1),
            self::messageDelta('tool_use', ['output_tokens' => 15]),
            ['type' => 'message_stop'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function cacheUsage(): array
    {
        return [
            self::start('msg_04JKL', [
                'input_tokens' => 100, 'output_tokens' => 0,
                'cache_creation_input_tokens' => 500, 'cache_read_input_tokens' => 200,
            ]),
            self::blockStart(0, ['type' => 'text', 'text' => '']),
            self::blockDelta(0, ['type' => 'text_delta', 'text' => 'Cached response']),
            self::stop(0),
            self::messageDelta('end_turn', ['output_tokens' => 3]),
            ['type' => 'message_stop'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return list<array<string, mixed>>
     */
    private static function convert(array $events, array $options = []): array
    {
        return iterator_to_array(StreamEvents::convertAnthropicStream($events, $options), false);
    }

    /**
     * Drive the real ChatAnthropic over a scripted SSE body.
     *
     * @param list<array<string, mixed>> $events
     *
     * @return array{0: list<array<string, mixed>>, 1: FakeHttpClient}
     */
    private static function viaModel(array $events, array $fields = [], array $options = []): array
    {
        $chunks = array_map(
            static fn (array $e): string => 'event: ' . $e['type'] . "\ndata: " . json_encode($e) . "\n\n",
            $events,
        );
        $http = new FakeHttpClient([], $chunks);
        $model = new ChatAnthropic($fields + ['apiKey' => 'fake-key', 'model' => 'claude-sonnet-4-20250514', 'httpClient' => $http]);

        return [iterator_to_array($model->streamChatModelEvents([new HumanMessage('hello')], $options), false), $http];
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return array<string, mixed>
     */
    private static function find(array $events, callable $predicate): array
    {
        foreach ($events as $event) {
            if ($predicate($event)) {
                return $event;
            }
        }
        self::fail('no matching event');
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return array<string, mixed>
     */
    private static function messageFinish(array $events): array
    {
        return self::find($events, static fn (array $e): bool => $e['event'] === 'message-finish');
    }

    // ---- ported: utils/tests/stream_events.test.ts ------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private static function costEvents(mixed $cost = null, bool $withCost = false): array
    {
        $events = self::start('msg_01', ['input_tokens' => 100, 'output_tokens' => 1]);

        return [
            $events,
            self::blockStart(0, ['type' => 'text', 'text' => '']),
            self::blockDelta(0, ['type' => 'text_delta', 'text' => 'hello']),
            self::stop(0),
            self::messageDelta('end_turn', ['output_tokens' => 42] + ($withCost ? ['cost' => $cost] : [])),
            ['type' => 'message_stop'],
        ];
    }

    public function testSurfacesAGatewayProvidedCostOnTheMessageFinishResponseMetadata(): void
    {
        $events = self::convert(self::costEvents(0.0123, true));

        self::assertSame(['usage' => ['cost' => 0.0123]], self::messageFinish($events)['responseMetadata']);
    }

    public function testAddsNoResponseMetadataWhenNoCostIsPresent(): void
    {
        self::assertSame([], self::messageFinish(self::convert(self::costEvents()))['responseMetadata']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function ignoredCosts(): array
    {
        return ['a nonnumeric cost' => ['0.0123'], 'a null cost' => [null]];
    }

    #[DataProvider('ignoredCosts')]
    public function testIgnoresNonNumericCosts(mixed $cost): void
    {
        $events = self::convert(self::costEvents($cost, true));

        self::assertSame([], self::messageFinish($events)['responseMetadata']);
    }

    public function testLeavesTheRestOfTheMessageFinishEventUntouched(): void
    {
        $finish = self::messageFinish(self::convert(self::costEvents(0.0123, true)));

        self::assertSame('stop', $finish['reason']);
        self::assertSame(['model_provider' => 'anthropic'], $finish['metadata']);
    }

    public function testPreservesTheCostWhenStreamUsageIsFalse(): void
    {
        $finish = self::messageFinish(self::convert(self::costEvents(0.0123, true), ['streamUsage' => false]));

        self::assertSame(['usage' => ['cost' => 0.0123]], $finish['responseMetadata']);
        self::assertArrayNotHasKey('usage', $finish);
    }

    public function testLeavesTokenAccountingUntouched(): void
    {
        $events = self::convert(self::costEvents(0.0123, true));

        self::assertSame([
            'input_tokens' => 100,
            'output_tokens' => 43,
            'total_tokens' => 143,
            'input_token_details' => ['cache_creation' => 0, 'cache_read' => 0],
        ], self::messageFinish($events)['usage']);

        foreach ($events as $event) {
            if (isset($event['usage'])) {
                self::assertArrayNotHasKey('cost', $event['usage']);
            }
        }
    }

    // ---- ported: chat_models_stream_events.test.ts (native) ---------------

    public function testPassesInvocationCacheControlAsATopLevelRequestField(): void
    {
        $cacheControl = ['type' => 'ephemeral', 'ttl' => '1h'];

        [, $http] = self::viaModel(self::textOnly(), options: ['cache_control' => $cacheControl]);

        $body = $http->lastRequestBody();
        self::assertSame($cacheControl, $body['cache_control']);
        self::assertTrue($body['stream']);
        self::assertStringNotContainsString('"cache_control"', json_encode($body['messages']));
    }

    public function testEmitsCorrectLifecycleEvents(): void
    {
        $events = self::convert(self::textOnly());

        self::assertSame([
            'message-start', 'provider', 'content-block-start',
            'content-block-delta', 'content-block-delta', 'content-block-finish',
            'usage', 'message-finish',
        ], array_column($events, 'event'));
    }

    public function testMessageStartCarriesIdAndUsage(): void
    {
        $start = self::find(self::convert(self::textOnly()), static fn (array $e): bool => $e['event'] === 'message-start');

        self::assertSame('msg_01ABC', $start['id']);
        self::assertSame(25, $start['usage']['input_tokens']);
    }

    public function testTextDeltasAccumulateCorrectly(): void
    {
        $deltas = array_values(array_filter(
            self::convert(self::textOnly()),
            static fn (array $e): bool => $e['event'] === 'content-block-delta',
        ));

        self::assertSame(['type' => 'text-delta', 'text' => 'Hello'], $deltas[0]['delta']);
        self::assertSame(['type' => 'text-delta', 'text' => ' world'], $deltas[1]['delta']);
    }

    public function testContentBlockFinishCarriesFinalizedText(): void
    {
        $finish = self::find(
            self::convert(self::textOnly()),
            static fn (array $e): bool => $e['event'] === 'content-block-finish' && $e['index'] === 0,
        );

        self::assertSame(['type' => 'text', 'text' => 'Hello world'], $finish['content']);
    }

    public function testMessageFinishCarriesStopReason(): void
    {
        self::assertSame('stop', self::messageFinish(self::convert(self::textOnly()))['reason']);
    }

    public function testReasoningBlockAccumulatesCorrectly(): void
    {
        $events = self::convert(self::thinkingPlusText());

        $deltas = array_values(array_filter(
            $events,
            static fn (array $e): bool => $e['event'] === 'content-block-delta' && $e['index'] === 0 && $e['delta']['type'] === 'reasoning-delta',
        ));
        self::assertCount(2, $deltas);
        self::assertSame('Let me', $deltas[0]['delta']['reasoning']);
        self::assertSame(' reason...', $deltas[1]['delta']['reasoning']);

        $finish = self::find($events, static fn (array $e): bool => $e['event'] === 'content-block-finish' && $e['index'] === 0);
        self::assertSame('reasoning', $finish['content']['type']);
        self::assertSame('Let me reason...', $finish['content']['reasoning']);
        self::assertSame('sig_abc', $finish['content']['signature']);
    }

    public function testTextBlockFollowsReasoningWithCorrectIndex(): void
    {
        $finish = self::find(
            self::convert(self::thinkingPlusText()),
            static fn (array $e): bool => $e['event'] === 'content-block-finish' && $e['index'] === 1,
        );

        self::assertSame(['type' => 'text', 'text' => 'The answer is 42.'], $finish['content']);
    }

    public function testSignatureDeltaIsEmittedAsABlockDelta(): void
    {
        $sig = self::find(
            self::convert(self::thinkingPlusText()),
            static fn (array $e): bool => $e['event'] === 'content-block-delta' && ($e['delta']['fields']['signature'] ?? null) === 'sig_abc',
        );

        self::assertSame('block-delta', $sig['delta']['type']);
        self::assertSame('reasoning', $sig['delta']['fields']['type']);
    }

    public function testToolCallArgsAccumulateCorrectly(): void
    {
        $events = self::convert(self::toolCall());

        $start = self::find($events, static fn (array $e): bool => $e['event'] === 'content-block-start' && $e['index'] === 1);
        self::assertSame('tool_call_chunk', $start['content']['type']);
        self::assertSame('web_search', $start['content']['name']);
        self::assertSame('toolu_01ABC', $start['content']['id']);

        $deltas = array_values(array_filter(
            $events,
            static fn (array $e): bool => $e['event'] === 'content-block-delta' && $e['index'] === 1,
        ));
        self::assertCount(2, $deltas);
        self::assertSame('block-delta', $deltas[0]['delta']['type']);
        self::assertSame('tool_call_chunk', $deltas[0]['delta']['fields']['type']);
        self::assertSame('{"query"', $deltas[0]['delta']['fields']['args']);
        self::assertSame('{"query":"weather"}', $deltas[1]['delta']['fields']['args']);
    }

    public function testToolCallFinishHasParsedArgs(): void
    {
        $finish = self::find(
            self::convert(self::toolCall()),
            static fn (array $e): bool => $e['event'] === 'content-block-finish' && $e['index'] === 1,
        );

        self::assertSame([
            'type' => 'tool_call', 'id' => 'toolu_01ABC', 'name' => 'web_search', 'args' => ['query' => 'weather'],
        ], $finish['content']);
    }

    public function testMessageFinishHasToolUseReason(): void
    {
        self::assertSame('tool_use', self::messageFinish(self::convert(self::toolCall()))['reason']);
    }

    public function testUnparseableToolArgsFinishAsAnInvalidToolCall(): void
    {
        $events = self::convert([
            self::blockStart(0, ['type' => 'tool_use', 'id' => 't', 'name' => 'n']),
            self::blockDelta(0, ['type' => 'input_json_delta', 'partial_json' => '{"a":']),
            self::stop(0),
        ]);

        $finish = $events[2];
        self::assertSame('invalid_tool_call', $finish['content']['type']);
        self::assertSame('{"a":', $finish['content']['args']);
        self::assertSame('Failed to parse tool call arguments as JSON', $finish['content']['error']);
    }

    public function testUsageSnapshotWithCacheDetails(): void
    {
        $start = self::find(self::convert(self::cacheUsage(), ['streamUsage' => true]), static fn (array $e): bool => $e['event'] === 'message-start');

        // 100 + 500 + 200 = 800
        self::assertSame(800, $start['usage']['input_tokens']);
        self::assertSame(500, $start['usage']['input_token_details']['cache_creation']);
        self::assertSame(200, $start['usage']['input_token_details']['cache_read']);
    }

    public function testUsageEventEmittedOnMessageDelta(): void
    {
        $usage = array_values(array_filter(
            self::convert(self::cacheUsage(), ['streamUsage' => true]),
            static fn (array $e): bool => $e['event'] === 'usage',
        ));

        self::assertNotEmpty($usage);
        self::assertSame(3, $usage[count($usage) - 1]['usage']['output_tokens']);
    }

    public function testMessageFinishCarriesFinalUsage(): void
    {
        $finish = self::messageFinish(self::convert(self::cacheUsage(), ['streamUsage' => true]));

        self::assertSame(800, $finish['usage']['input_tokens']);
        self::assertSame(3, $finish['usage']['output_tokens']);
    }

    public function testNoUsageEventsWhenStreamUsageIsFalse(): void
    {
        $events = self::convert(self::textOnly(), ['streamUsage' => false]);

        self::assertSame([], array_filter($events, static fn (array $e): bool => $e['event'] === 'usage'));
        $start = self::find($events, static fn (array $e): bool => $e['event'] === 'message-start');
        self::assertArrayNotHasKey('usage', $start);
    }

    public function testMessageStartMetadataIsForwardedAsAProviderEvent(): void
    {
        $meta = self::find(
            self::convert(self::textOnly()),
            static fn (array $e): bool => $e['event'] === 'provider' && $e['name'] === 'message_start',
        );

        self::assertSame('anthropic', $meta['provider']);
        self::assertSame('claude-sonnet-4-20250514', $meta['payload']['model']);
        self::assertSame('msg_01ABC', $meta['payload']['id']);
    }

    public function testUnknownEventsAreForwardedAsProviderEvents(): void
    {
        $source = self::textOnly();
        $stop = array_pop($source);
        $events = self::convert([...$source, ['type' => 'ping'], $stop]);

        $ping = self::find($events, static fn (array $e): bool => $e['event'] === 'provider' && $e['name'] === 'ping');
        self::assertSame(['type' => 'ping'], $ping['payload']);
    }

    public function testDeltaForAnUnknownBlockIndexIsIgnored(): void
    {
        self::assertSame([], self::convert([self::blockDelta(7, ['type' => 'text_delta', 'text' => 'x']), self::stop(7)]));
    }

    public function testCitationsCompactionAndUnknownDeltasAccumulate(): void
    {
        $events = self::convert([
            self::blockStart(0, ['type' => 'text', 'text' => '']),
            self::blockDelta(0, ['type' => 'citations_delta', 'citation' => ['type' => 'char_location']]),
            self::blockDelta(0, ['type' => 'mystery_delta', 'x' => 1]),
            self::stop(0),
            self::blockStart(1, ['type' => 'compaction']),
            self::blockDelta(1, ['type' => 'compaction_delta', 'content' => 'summary']),
            self::stop(1),
        ]);

        self::assertSame([['type' => 'char_location']], $events[1]['delta']['fields']['annotations']);
        self::assertSame(['type' => 'mystery_delta', 'x' => 1], $events[2]['delta']['fields']);
        self::assertSame([['type' => 'char_location']], $events[3]['content']['annotations']);
        self::assertSame('non_standard', $events[5]['delta']['fields']['type']);
        self::assertSame('summary', $events[5]['delta']['fields']['value']['compaction']['content']);
    }

    public function testStopReasonsMapToFinishReasons(): void
    {
        foreach (['end_turn' => 'stop', 'stop_sequence' => 'stop', 'max_tokens' => 'length', 'tool_use' => 'tool_use'] as $raw => $mapped) {
            $events = self::convert([self::messageDelta($raw, ['output_tokens' => 1]), ['type' => 'message_stop']]);
            self::assertSame($mapped, self::messageFinish($events)['reason'], $raw);
        }
    }

    public function testContextManagementIsForwardedAsAProviderEvent(): void
    {
        $events = self::convert([[
            'type' => 'message_delta',
            'delta' => ['stop_reason' => 'end_turn', 'context_management' => ['edits' => []]],
            'usage' => ['output_tokens' => 1],
        ]]);

        $ctx = self::find($events, static fn (array $e): bool => $e['event'] === 'provider');
        self::assertSame('context_management', $ctx['name']);
    }

    // ---- through ChatAnthropic over FakeHttpClient -------------------------

    public function testModelStreamsTypedEventsEndToEndOverTheTransport(): void
    {
        [$events, $http] = self::viaModel(self::toolCall());

        self::assertSame(self::convert(self::toolCall()), $events);
        self::assertTrue($http->lastRequestBody()['stream']);
        self::assertSame('hello', $http->lastRequestBody()['messages'][0]['content']);
    }

    public function testModelStreamUsageFlagGatesUsageEvents(): void
    {
        [$events] = self::viaModel(self::textOnly(), ['streamUsage' => false]);

        self::assertSame([], array_filter($events, static fn (array $e): bool => $e['event'] === 'usage'));
        self::assertArrayNotHasKey('usage', self::messageFinish($events));
    }

    public function testModelStreamMidStreamErrorEventSurfaces(): void
    {
        $this->expectException(\LangChain\LanguageModels\Chat\Anthropic\AnthropicException::class);

        self::viaModel([self::start('m', ['input_tokens' => 1, 'output_tokens' => 0]), ['type' => 'error', 'error' => ['type' => 'overloaded_error', 'message' => 'busy']]]);
    }
}
