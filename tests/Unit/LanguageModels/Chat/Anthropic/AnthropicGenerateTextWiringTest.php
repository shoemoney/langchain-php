<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Anthropic;

use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * THE WIRING TEST for 345's fix, and it exists because the first attempt left a SURVIVING MUTATION.
 *
 * `AnthropicMultiBlockTextTest` asserts `MessageOutputs::stringifyText()` directly, so reverting
 * `ChatAnthropic::generate()` to `is_string($message->content) ? $message->content : ''` left it
 * entirely green — the helper being correct says nothing about the call site using it, and the defect
 * lived in the call site. That blind spot was documented rather than papered over; this file closes it.
 *
 * Every case here goes through the real HTTP client, the real response conversion and the real
 * `generate()`, so it fails when the wiring is reverted.
 */
#[CoversClass(ChatAnthropic::class)]
final class AnthropicGenerateTextWiringTest extends TestCase
{
    /** @return iterable<string, array{list<array<string, mixed>>, string}> */
    public static function payloads(): iterable
    {
        yield 'single text block (control — every existing Anthropic test covers this)' => [
            [['type' => 'text', 'text' => 'hello']],
            'hello',
        ];
        yield 'two text blocks' => [
            [['type' => 'text', 'text' => 'para one. '], ['type' => 'text', 'text' => 'para two.']],
            'para one. para two.',
        ];
        yield 'thinking then text (the ordinary extended-thinking shape)' => [
            [['type' => 'thinking', 'thinking' => 'reasoning about it'], ['type' => 'text', 'text' => 'the final answer']],
            'the final answer',
        ];
        yield 'text, tool_use, text' => [
            [
                ['type' => 'text', 'text' => 'before '],
                ['type' => 'tool_use', 'id' => 't1', 'name' => 'lookup', 'input' => []],
                ['type' => 'text', 'text' => 'after'],
            ],
            'before after',
        ];
    }

    /**
     * @param list<array<string, mixed>> $blocks
     */
    #[DataProvider('payloads')]
    public function testGenerateReturnsTheAnswerTextForTheseBlocks(array $blocks, string $expected): void
    {
        $model = new ChatAnthropic([
            'model' => 'claude-x',
            'apiKey' => 'k',
            'maxRetries' => 0,
            'httpClient' => new FakeHttpClient([
                FakeHttpClient::json(200, [
                    'id' => 'msg_1',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-x',
                    'content' => $blocks,
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 3, 'output_tokens' => 5],
                ]),
            ]),
        ]);

        // `generate()` is `abstract protected`, so the public surface that string consumers actually
        // receive is `generatePrompt()` -> `LLMResult`. Driving that keeps the test on real API.
        $result = $model->generatePrompt([['human' => 'hi']]);

        self::assertSame(
            $expected,
            $result->generations[0][0]->text,
            'generate() must flatten every text block, not type-check its way to an empty string'
        );
    }

    /** The blocks must survive intact alongside the flattened text — flattening is for the string only. */
    public function testTheMessageContentStillCarriesEveryBlock(): void
    {
        $blocks = [
            ['type' => 'thinking', 'thinking' => 'reasoning'],
            ['type' => 'text', 'text' => 'the answer'],
        ];

        $model = new ChatAnthropic([
            'model' => 'claude-x',
            'apiKey' => 'k',
            'maxRetries' => 0,
            'httpClient' => new FakeHttpClient([
                FakeHttpClient::json(200, [
                    'id' => 'msg_1',
                    'type' => 'message',
                    'role' => 'assistant',
                    'model' => 'claude-x',
                    'content' => $blocks,
                    'stop_reason' => 'end_turn',
                    'usage' => ['input_tokens' => 3, 'output_tokens' => 5],
                ]),
            ]),
        ]);

        $result = $model->generatePrompt([['human' => 'hi']]);

        self::assertCount(2, $result->generations[0][0]->message->content);
        self::assertSame('the answer', $result->generations[0][0]->text);
    }
}
