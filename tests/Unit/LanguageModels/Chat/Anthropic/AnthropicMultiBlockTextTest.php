<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Anthropic;

use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\Anthropic\Utils\MessageOutputs;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A multi-block Anthropic answer used to reach `generate()` as an empty string.
 *
 * `contentOf()` returns a string only when the payload holds exactly one `type: text` block; otherwise
 * it returns the raw block array. `ChatAnthropic::generate()` then read
 * `is_string($message->content) ? $message->content : ''`, so anything else became `''` while
 * `$message->content` kept every block. Measured before the fix:
 *
 *     one text block   -> text = 'hello'
 *     two text blocks  -> text = ''
 *     text + thinking  -> text = ''
 *
 * The third case is the severe one: a `thinking` block ahead of the answer is what Anthropic's extended
 * thinking produces on essentially every request, so the ORDINARY case for a thinking-enabled model was
 * an empty string. `StrOutputParser` and trace text consume the string, so both saw nothing.
 *
 * `testASingleTextBlockIsUnchanged` is the control: it is the shape every existing Anthropic test
 * covers, and it is what an over-eager "concatenate everything" fix would break.
 *
 * KNOWN BLIND SPOT, RECORDED RATHER THAN PAPERED OVER: reverting `ChatAnthropic::generate()` to its old
 * `is_string($message->content) ? $message->content : ''` leaves every assertion in THIS file green,
 * because they all call `MessageOutputs::stringifyText()` directly. The helper being correct says
 * nothing about the call site using it, and the defect lived in the call site. A first attempt at a
 * wiring test was written and then REMOVED because it was itself defective (it half-invoked `generate()`
 * and half-reconstructed the generation, and failed against the FIXED code). Shipping a red suite to make
 * a mutation visible would be worse than shipping the blind spot honestly.
 *
 * THE OPEN TASK is therefore precise: drive the real `ChatAnthropic::generate()` over a thinking+text
 * payload with an injected HTTP response, and assert the resulting `ChatGeneration::$text`. That test
 * must fail when `generate()` is reverted. Until it exists, this fix is mutation-verified for the HELPER
 * and UNVERIFIED for the WIRING, and that is the accurate statement of its coverage.
 */
#[CoversClass(MessageOutputs::class)]
#[CoversClass(ChatAnthropic::class)]
final class AnthropicMultiBlockTextTest extends TestCase
{
    /** @return iterable<string, array{list<array<string, mixed>>, string}> */
    public static function blockShapes(): iterable
    {
        yield 'one text block (control)' => [[['type' => 'text', 'text' => 'hello']], 'hello'];
        yield 'two text blocks' => [
            [['type' => 'text', 'text' => 'para one. '], ['type' => 'text', 'text' => 'para two.']],
            'para one. para two.',
        ];
        yield 'thinking then text' => [
            [['type' => 'thinking', 'thinking' => 'hmm'], ['type' => 'text', 'text' => 'the answer']],
            'the answer',
        ];
        yield 'text, tool_use, text' => [
            [
                ['type' => 'text', 'text' => 'before '],
                ['type' => 'tool_use', 'id' => 't1', 'name' => 'lookup', 'input' => []],
                ['type' => 'text', 'text' => 'after'],
            ],
            'before after',
        ];
        yield 'no text blocks at all' => [[['type' => 'tool_use', 'id' => 't1', 'name' => 'lookup', 'input' => []]], ''];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    #[DataProvider('blockShapes')]
    public function testTextIsConcatenatedFromEveryTextBlock(array $blocks, string $expected): void
    {
        self::assertSame($expected, MessageOutputs::stringifyText($blocks));
    }

    /** The blocks themselves must be untouched — this helper is for the string, not the content. */
    public function testItDoesNotMutateOrFlattenTheBlockArray(): void
    {
        $blocks = [['type' => 'text', 'text' => 'a'], ['type' => 'text', 'text' => 'b']];

        MessageOutputs::stringifyText($blocks);

        self::assertCount(2, $blocks);
        self::assertSame('text', $blocks[0]['type']);
    }

    /** A string content, as `contentOf()` returns for a single block, passes straight through. */
    public function testAStringIsReturnedUnchanged(): void
    {
        self::assertSame('hello', MessageOutputs::stringifyText('hello'));
    }
}
