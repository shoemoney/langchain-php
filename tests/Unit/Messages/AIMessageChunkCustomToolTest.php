<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Messages;

use LangChain\Messages\AIMessageChunk;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Converted custom-input cases from core/messages/tests/base_message.test.ts.
 * Decode at toMessage(), preserving this port's undecoded chunk contract.
 */
#[CoversClass(AIMessageChunk::class)]
final class AIMessageChunkCustomToolTest extends TestCase
{
    private static function chunk(?string $args): AIMessageChunk
    {
        return new AIMessageChunk(['content' => '', 'tool_call_chunks' => [[
            'name' => 'execute_code', 'args' => $args, 'id' => 'call_custom', 'index' => 0,
            'type' => 'tool_call_chunk', 'isCustomTool' => true,
        ]]]);
    }

    public function testPreservesRawTextInputForCustomToolCallChunks(): void
    {
        $start = self::chunk('');
        $delta = new AIMessageChunk(['content' => '', 'tool_call_chunks' => [[
            'type' => 'tool_call_chunk', 'args' => "console.log('custom tool streaming repro')", 'index' => 0,
        ]]]);
        $folded = (new AIMessageChunk(''))->concat($start)->concat($delta);

        self::assertSame("console.log('custom tool streaming repro')", $folded->toolCallChunks[0]['args']);
        self::assertTrue($folded->toolCallChunks[0]['isCustomTool']);
        self::assertFalse(property_exists($folded, 'toolCalls'));
        $message = $folded->toMessage();
        self::assertSame([], $message->invalidToolCalls);
        self::assertSame([[
            'name' => 'execute_code', 'args' => ['input' => "console.log('custom tool streaming repro')"],
            'id' => 'call_custom', 'type' => 'tool_call', 'index' => 0,
        ]], $message->toolCalls);
    }

    public function testPreservesLeadingAndTrailingWhitespaceInCustomToolCallInput(): void
    {
        $message = self::chunk("  console.log('x')  ")->toMessage();

        self::assertSame([], $message->invalidToolCalls);
        self::assertSame(['input' => "  console.log('x')  "], $message->toolCalls[0]['args']);
    }

    public static function rawInputs(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'absent' => [null, ''];
        yield 'whitespace only' => [" \n\t ", " \n\t "];
        yield 'JSON object remains raw' => ['{"x":1}', '{"x":1}'];
        yield 'JSON scalar remains raw' => ['42', '42'];
        yield 'malformed JSON remains raw' => ['{"x":', '{"x":'];
        yield 'unicode multiline' => ["雪\nconsole.log('x')\n", "雪\nconsole.log('x')\n"];
    }

    #[DataProvider('rawInputs')]
    public function testCustomInputIsNeverTrimmedOrJsonDecoded(?string $input, string $expected): void
    {
        $message = self::chunk($input)->toMessage();

        self::assertSame([], $message->invalidToolCalls);
        self::assertSame(['input' => $expected], $message->toolCalls[0]['args']);
    }

    public function testMixedFunctionAndCustomCallsKeepTheirDistinctArgumentSemantics(): void
    {
        $chunk = new AIMessageChunk(['content' => '', 'tool_call_chunks' => [
            ['name' => 'execute_code', 'args' => '{"x":1}', 'id' => 'custom', 'index' => 0, 'isCustomTool' => true],
            ['name' => 'lookup', 'args' => '{"x":1}', 'id' => 'function', 'index' => 1],
            ['name' => 'broken', 'args' => 'plain text', 'id' => 'invalid', 'index' => 2, 'isCustomTool' => false],
        ]]);

        $message = $chunk->toMessage();

        self::assertSame(['custom', 'function'], array_column($message->toolCalls, 'id'));
        self::assertSame([['input' => '{"x":1}'], ['x' => 1]], array_column($message->toolCalls, 'args'));
        self::assertSame(['invalid'], array_column($message->invalidToolCalls, 'id'));
    }

    public function testCustomCallsStillRejectDecodedArgsAndMissingIds(): void
    {
        foreach ([['args' => ['input' => 'code'], 'id' => 'custom'], ['args' => '{"x":1}', 'id' => null]] as $fields) {
            $chunk = new AIMessageChunk(['content' => '', 'tool_call_chunks' => [[
                'name' => 'execute_code', 'index' => 0, 'isCustomTool' => true, ...$fields,
            ]]]);

            $message = $chunk->toMessage();

            self::assertSame([], $message->toolCalls);
            self::assertCount(1, $message->invalidToolCalls);
        }
    }
}
