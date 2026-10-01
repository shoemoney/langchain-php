<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI;

use LangChain\LanguageModels\Chat\OpenAI\Utils\Completions;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `Completions::convertMessage()` assigned `$message->content` verbatim, so an Anthropic-shaped reply
 * carried its blocks straight onto an OpenAI-compatible wire:
 *
 *     {"role":"assistant","content":[{"type":"thinking",…},{"type":"text",…}]}
 *
 * OpenAI has no `thinking` block type and a strict endpoint answers 400. Upstream drops
 * `thinking`/`reasoning`/`tool_use` on the way out and keeps the text.
 *
 * WHY THIS PATH WAS ONLY NOW REACHABLE: `MessageOutputs::contentOf()` used to collapse a multi-block
 * Anthropic reply to a plain string, so this consumer never saw blocks. Correcting that (iteration 345)
 * made the array reachable for the first time. The fix upstream of this one was right; the consumer on
 * the other side of it had simply never been exercised.
 *
 * Asserted on the ENCODED WIRE SHAPE, which is the only place the defect exists — the message object
 * legitimately holds every block, and what must not happen is one of them reaching the provider.
 */
#[CoversClass(Completions::class)]
final class NonOpenAIBlocksDroppedTest extends TestCase
{
    /** RED before the fix: a `thinking` block reaches the OpenAI payload. */
    public function testThinkingBlocksAreNotForwardedToOpenAI(): void
    {
        $out = Completions::convertMessage(new AIMessage([
            'content' => [
                ['type' => 'thinking', 'thinking' => 'reasoning about it'],
                ['type' => 'text', 'text' => 'the answer'],
            ],
        ]));

        $json = json_encode($out);
        self::assertStringNotContainsString('thinking', $json, $json);
        self::assertStringContainsString('the answer', $json, $json);
    }

    public function testRedactedThinkingAndReasoningBlocksAreAlsoDropped(): void
    {
        $out = Completions::convertMessage(new AIMessage([
            'content' => [
                ['type' => 'redacted_thinking', 'data' => 'xx'],
                ['type' => 'reasoning', 'reasoning' => 'because'],
                ['type' => 'text', 'text' => 'kept'],
            ],
        ]));

        $json = json_encode($out);
        self::assertStringNotContainsString('redacted_thinking', $json, $json);
        self::assertStringNotContainsString('reasoning', $json, $json);
        self::assertStringContainsString('kept', $json, $json);
    }

    /** CONTROL: plain string content is untouched — the overwhelmingly common case. */
    public function testStringContentIsUnchanged(): void
    {
        $out = Completions::convertMessage(new AIMessage(['content' => 'just text']));

        self::assertSame('just text', $out['content']);
    }

    /** CONTROL: a text-only block array still reaches the provider as blocks. */
    public function testTextOnlyBlockArrayIsStillForwarded(): void
    {
        $out = Completions::convertMessage(new AIMessage([
            'content' => [['type' => 'text', 'text' => 'a'], ['type' => 'text', 'text' => 'b']],
        ]));

        self::assertCount(2, $out['content']);
    }

    /** CONTROL: a HumanMessage is unaffected by any of this. */
    public function testHumanMessagesAreUnaffected(): void
    {
        $out = Completions::convertMessage(new HumanMessage('hi'));

        self::assertSame('user', $out['role']);
        self::assertSame('hi', $out['content']);
    }
}
