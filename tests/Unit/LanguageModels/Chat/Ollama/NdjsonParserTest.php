<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Ollama;

use LangChain\LanguageModels\Chat\Ollama\NdjsonParser;
use LangChain\LanguageModels\Chat\Ollama\OllamaException;
use LangChain\Utils\Http\SseParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(NdjsonParser::class)]
final class NdjsonParserTest extends TestCase
{
    /** @return list<array<string, mixed>> */
    private static function drain(\Generator $records): array
    {
        return iterator_to_array($records, false);
    }

    public function testOneRecordPerLine(): void
    {
        $parser = new NdjsonParser();

        self::assertSame(
            [['a' => 1], ['b' => 2]],
            self::drain($parser->feed("{\"a\":1}\n{\"b\":2}\n")),
        );
    }

    public function testALineSplitAcrossFeedsIsHeldUntilItsNewline(): void
    {
        $parser = new NdjsonParser();

        self::assertSame([], self::drain($parser->feed('{"message":{"con')));
        self::assertSame([], self::drain($parser->feed('tent":"Hi"}')));
        self::assertSame([['message' => ['content' => 'Hi']]], self::drain($parser->feed("}\n")));
    }

    public function testAFinalLineWithoutANewlineIsEmittedByFlush(): void
    {
        $parser = new NdjsonParser();

        self::assertSame([], self::drain($parser->feed('{"done":true}')));
        self::assertSame([['done' => true]], self::drain($parser->flush()));
        self::assertSame([], self::drain($parser->flush()));
    }

    public function testBlankLinesAndCarriageReturnsAreIgnored(): void
    {
        $parser = new NdjsonParser();

        self::assertSame([['a' => 1]], self::drain($parser->feed("\n\r\n{\"a\":1}\r\n\n")));
    }

    public function testAMalformedLineIsFatalNotSkipped(): void
    {
        $this->expectException(OllamaException::class);
        $this->expectExceptionMessage('malformed line');

        self::drain((new NdjsonParser())->feed("{not json}\n"));
    }

    /**
     * The regression this class exists for: Ollama streams NDJSON, and the SSE
     * parser — which needs `data:` fields — yields nothing from it, silently.
     */
    public function testTheSseParserWouldHaveYieldedNothing(): void
    {
        $body = "{\"message\":{\"content\":\"Hi\"}}\n{\"done\":true}\n";

        self::assertSame([], iterator_to_array((new SseParser())->feed($body), false));
        self::assertCount(2, self::drain((new NdjsonParser())->feed($body)));
    }
}
