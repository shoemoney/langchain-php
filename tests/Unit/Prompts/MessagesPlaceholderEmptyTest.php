<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prompts;

use LangChain\Messages\HumanMessage;
use LangChain\Prompts\InputFormatError;
use LangChain\Prompts\MessagesPlaceholder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * An empty message list is a valid input, not a missing one.
 *
 * Upstream guards with `!input` (chat.ts:144), and in JavaScript an EMPTY ARRAY
 * IS TRUTHY — so `[]` passes and coerces to an empty result. PHP's `[]` is
 * falsy, so the same expression threw here, and a MessagesPlaceholder refused
 * the first turn of every conversation — which is precisely the situation an
 * empty history describes.
 */
#[CoversClass(MessagesPlaceholder::class)]
final class MessagesPlaceholderEmptyTest extends TestCase
{
    public function testAnEmptyListYieldsNoMessagesRatherThanThrowing(): void
    {
        $placeholder = new MessagesPlaceholder('history');

        self::assertSame([], $placeholder->formatMessages(['history' => []]));
    }

    public function testAnAbsentValueIsStillAnError(): void
    {
        $this->expectException(InputFormatError::class);

        (new MessagesPlaceholder('history'))->formatMessages([]);
    }

    public function testANullValueIsStillAnError(): void
    {
        $this->expectException(InputFormatError::class);

        (new MessagesPlaceholder('history'))->formatMessages(['history' => null]);
    }

    public function testAPopulatedListStillCoerces(): void
    {
        $placeholder = new MessagesPlaceholder('history');

        $out = $placeholder->formatMessages(['history' => [new HumanMessage('hi')]]);

        self::assertCount(1, $out);
        self::assertInstanceOf(HumanMessage::class, $out[0]);
    }

    public function testASingleMessageStillCoerces(): void
    {
        $placeholder = new MessagesPlaceholder('history');

        self::assertCount(1, $placeholder->formatMessages(['history' => new HumanMessage('hi')]));
    }

    public function testAnEmptyListOnAnOptionalPlaceholderIsStillFine(): void
    {
        self::assertSame([], (new MessagesPlaceholder('history', true))->formatMessages(['history' => []]));
    }

    /**
     * The first turn of a conversation, end to end.
     *
     * This is the shape the bug actually broke, and it is what a caller writes.
     */
    public function testAFirstTurnWithNoHistoryFormats(): void
    {
        $template = \LangChain\Prompts\ChatPromptTemplate::fromMessages([
            new \LangChain\Prompts\MessagesPlaceholder('history', true),
            new HumanMessage('what is the weather?'),
        ]);

        $messages = $template->formatMessages(['history' => []]);

        self::assertCount(1, $messages);
        self::assertSame('what is the weather?', $messages[0]->content);
    }
}
