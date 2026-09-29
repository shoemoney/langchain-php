<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\OutputParsers;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\OutputParsers\StringOutputParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `output_parsers/tests/string.test.ts`.
 */
#[CoversClass(StringOutputParser::class)]
final class StringOutputParserTest extends TestCase
{
    public function testStringInput(): void
    {
        $parser = new StringOutputParser();

        self::assertSame('hello', $parser->invoke('hello'));
    }

    public function testBaseMessageStringContent(): void
    {
        $parser = new StringOutputParser();
        $message = new AIMessage(['content' => 'hello']);

        self::assertSame('hello', $parser->invoke($message));
    }

    public function testBaseMessageComplexTextType(): void
    {
        $parser = new StringOutputParser();
        $message = new AIMessage([
            'content' => [
                ['type' => 'text', 'text' => 'hello'],
            ],
        ]);

        self::assertSame('hello', $parser->invoke($message));
    }

    public function testBaseMessageMultipleComplexTextType(): void
    {
        $parser = new StringOutputParser();
        $message = new AIMessage([
            'content' => [
                ['type' => 'text', 'text' => 'hello'],
                ['type' => 'text', 'text' => 'there'],
            ],
        ]);

        self::assertSame('hellothere', $parser->invoke($message));
    }

    public function testBaseMessageComplexTextAndImageTypeFails(): void
    {
        $parser = new StringOutputParser();
        $message = new AIMessage([
            'content' => [
                ['type' => 'text', 'text' => 'hello'],
                ['type' => 'image_url', 'image_url' => 'https://example.com/example.png'],
            ],
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot coerce a multimodal "image_url" message part into a string.');

        $parser->invoke($message);
    }

    public function testIgnoresReasoningBlocksAndReturnsOnlyText(): void
    {
        $parser = new StringOutputParser();
        $message = new AIMessage([
            'content' => [
                ['type' => 'reasoning', 'reasoning' => 'internal reasoning'],
                ['type' => 'text', 'text' => 'final answer'],
            ],
        ]);

        self::assertSame('final answer', $parser->invoke($message));
    }

    public function testIgnoresThinkingBlocks(): void
    {
        $parser = new StringOutputParser();
        $message = new AIMessage([
            'content' => [
                ['type' => 'thinking', 'thinking' => 'hidden thoughts'],
                ['type' => 'text', 'text' => 'visible output'],
            ],
        ]);

        self::assertSame('visible output', $parser->invoke($message));
    }

    public function testIgnoresRedactedThinkingBlocks(): void
    {
        $parser = new StringOutputParser();
        $message = new AIMessage([
            'content' => [
                ['type' => 'redacted_thinking', 'redacted_thinking' => 'redacted'],
                ['type' => 'text', 'text' => 'answer'],
            ],
        ]);

        self::assertSame('answer', $parser->invoke($message));
    }

    public function testGetFormatInstructionsIsEmpty(): void
    {
        self::assertSame('', (new StringOutputParser())->getFormatInstructions());
    }

    public function testInvokeRejectsAnUnsupportedInputType(): void
    {
        $parser = new StringOutputParser();

        $this->expectException(\InvalidArgumentException::class);

        /** @phpstan-ignore-next-line intentional wrong type */
        $parser->invoke(42);
    }

    public function testParseResultUsesTheFirstGeneration(): void
    {
        $parser = new StringOutputParser();

        self::assertSame('first', $parser->parseResult([
            ['text' => 'first'],
            ['text' => 'second'],
        ]));
    }

    public function testInvokeAcceptsAnyBaseMessageSubclass(): void
    {
        $parser = new StringOutputParser();

        $message = new class(['content' => 'hello']) extends BaseMessage {
            public string $type = 'human';
        };

        self::assertSame('hello', $parser->invoke($message));
    }
}
