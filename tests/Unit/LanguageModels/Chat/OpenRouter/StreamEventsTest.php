<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenRouter;

use LangChain\LanguageModels\Chat\OpenRouter\Utils\Stream;
use LangChain\LanguageModels\Chat\OpenRouter\Utils\StreamEvents;
use LangChain\Utils\Http\SseParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `utils/tests/stream_events.test.ts` (1 case), plus the SSE-to-chunk
 * stage (`utils/stream.ts`) that has no upstream test of its own.
 */
#[CoversClass(StreamEvents::class)]
#[CoversClass(Stream::class)]
final class StreamEventsTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $chunks
     *
     * @return list<array<string, mixed>>
     */
    private static function collectEvents(array $chunks): array
    {
        return iterator_to_array(StreamEvents::convertOpenRouterStream($chunks), false);
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return array<string, mixed>|null
     */
    private static function finishOf(array $events, string $type): ?array
    {
        foreach ($events as $event) {
            if ($event['event'] === 'content-block-finish' && $event['content']['type'] === $type) {
                return $event;
            }
        }

        return null;
    }

    public function testMapsReasoningFieldToReasoningContent(): void
    {
        $events = self::collectEvents([
            ['id' => 'gen-1', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'reasoning' => 'thinking...'], 'finish_reason' => null]]],
            ['id' => 'gen-1', 'choices' => [['index' => 0, 'delta' => ['content' => 'Answer'], 'finish_reason' => null]]],
            ['id' => 'gen-1', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]],
        ]);

        self::assertSame('thinking...', self::finishOf($events, 'reasoning')['content']['reasoning']);
        self::assertSame('Answer', self::finishOf($events, 'text')['content']['text']);
    }

    public function testEventsAreBracketedByMessageStartAndMessageFinish(): void
    {
        $events = self::collectEvents(OpenAiStreamFixtures::textOnlyChunks());

        self::assertSame('message-start', $events[0]['event']);
        self::assertSame('chatcmpl-text', $events[0]['id']);
        $last = $events[count($events) - 1];
        self::assertSame('message-finish', $last['event']);
        self::assertSame('stop', $last['reason']);
    }

    public function testParsesSseDataIntoChunksAndForwardsMalformedEventsAsNull(): void
    {
        $parser = new SseParser();
        $bytes = "data: {\"a\":1}\n\ndata: not json\n\n: OPENROUTER PROCESSING\n\ndata: [DONE]\n\n";
        $payloads = (static function () use ($parser, $bytes): \Generator {
            yield from $parser->feed($bytes);
            yield from $parser->flush();
        })();

        self::assertSame([['a' => 1], null], iterator_to_array(Stream::jsonParse($payloads), false));
    }

    public function testAnEmptyDataPayloadIsForwardedAsNull(): void
    {
        self::assertSame([null], iterator_to_array(Stream::jsonParse(['']), false));
    }
}
