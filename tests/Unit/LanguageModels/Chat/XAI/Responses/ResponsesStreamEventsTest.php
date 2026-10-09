<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\XAI\Responses;

use LangChain\LanguageModels\Chat\XAI\Utils\ResponsesStreamEvents;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Ports `utils/tests/responses_stream_events.test.ts` (2 tests), plus the options and the OpenAI-delegation
 * contract the adapter exists for.
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
        return iterator_to_array(ResponsesStreamEvents::convertXAIResponsesStream($events, $options), false);
    }

    /** @return list<array<string, mixed>> */
    private static function textStream(): array
    {
        return [
            ['type' => 'response.created', 'response' => ['id' => 'resp_xai', 'model' => 'grok-3']],
            ['type' => 'response.output_text.delta', 'delta' => 'Hello', 'content_index' => 0, 'output_index' => 0],
            [
                'type' => 'response.completed',
                'response' => [
                    'id' => 'resp_xai', 'object' => 'response', 'created_at' => 0, 'status' => 'completed', 'model' => 'grok-3', 'output' => [],
                    'usage' => ['input_tokens' => 5, 'output_tokens' => 2, 'total_tokens' => 7],
                ],
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return list<string>
     */
    private static function kinds(array $events): array
    {
        return array_column($events, 'event');
    }

    public function testTextOnlyLifecycle(): void
    {
        $events = self::collect(self::textStream());

        self::assertContains('message-finish', self::kinds($events));
        $providerMeta = null;
        foreach ($events as $event) {
            if ($event['event'] === 'provider' && $event['name'] === 'response.created') {
                $providerMeta = $event;
            }
        }
        self::assertNotNull($providerMeta);
        self::assertSame('xai', $providerMeta['provider']);
    }

    public function testReasoningSummaryDeltas(): void
    {
        $events = self::collect([
            ['type' => 'response.created', 'response' => ['id' => 'resp_r', 'model' => 'grok-3']],
            ['type' => 'response.reasoning_summary_text.delta', 'delta' => 'thinking', 'summary_index' => 0, 'output_index' => 0],
            ['type' => 'response.completed', 'response' => ['id' => 'resp_r', 'status' => 'completed', 'model' => 'grok-3', 'output' => []]],
        ]);

        $reasoning = array_filter(
            $events,
            static fn (array $e): bool => $e['event'] === 'content-block-delta' && $e['delta']['type'] === 'reasoning-delta',
        );
        self::assertGreaterThanOrEqual(1, count($reasoning));
    }

    public function testTheTextAndTheUsageComeThrough(): void
    {
        $events = self::collect(self::textStream());

        $text = implode('', array_map(
            static fn (array $e): string => $e['event'] === 'content-block-delta' && $e['delta']['type'] === 'text-delta' ? $e['delta']['text'] : '',
            $events,
        ));
        self::assertSame('Hello', $text);

        $finish = end($events);
        self::assertSame('message-finish', $finish['event']);
        self::assertSame(7, $finish['usage']['total_tokens']);
    }

    public function testStreamUsageFalseSuppressesTheUsageEvents(): void
    {
        $events = self::collect(self::textStream(), ['streamUsage' => false]);

        self::assertNotContains('usage', self::kinds($events));
        self::assertArrayNotHasKey('usage', end($events));
    }

    public function testTheProviderIsXaiEvenIfTheCallerPassesAnother(): void
    {
        $events = self::collect(self::textStream(), ['provider' => 'openai']);

        $providers = array_unique(array_column(array_filter($events, static fn (array $e): bool => $e['event'] === 'provider'), 'provider'));
        self::assertSame(['xai'], array_values($providers));
    }

    public function testAnEmptyStreamYieldsNothingThatThrows(): void
    {
        self::assertIsArray(self::collect([]));
    }
}
