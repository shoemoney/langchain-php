<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Messages;

use LangChain\Messages\AIMessageChunk;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A chunk's tool-call `args` is undecoded JSON by construction.
 *
 * That is what lets deltas accumulate as strings. An already-decoded array is
 * not a shape `parseToolCalls()` can re-parse — and calling `trim()` on one
 * raised a TypeError that aborted stream reconstruction outright. A crash is the
 * wrong outcome for a tool call whose arguments are unusable: the existing
 * invalid-tool-call list exists for exactly that.
 */
#[CoversClass(AIMessageChunk::class)]
final class ToolCallArgsShapeTest extends TestCase
{
    public function testAStringArgumentIsDecodedAsUsual(): void
    {
        $chunk = new AIMessageChunk([
            'content' => '',
            'tool_call_chunks' => [['index' => 0, 'id' => 't', 'name' => 'f', 'args' => '{"a":1}']],
        ]);

        [$calls, $invalid] = $chunk->parseToolCalls();

        self::assertSame([['name' => 'f', 'args' => ['a' => 1], 'id' => 't', 'type' => 'tool_call', 'index' => 0]], $calls);
        self::assertSame([], $invalid);
    }

    public function testAnAlreadyDecodedArgumentBecomesAnInvalidCallRatherThanACrash(): void
    {
        $chunk = new AIMessageChunk([
            'content' => '',
            'tool_call_chunks' => [['index' => 0, 'id' => 't', 'name' => 'f', 'args' => ['a' => 1]]],
        ]);

        [$calls, $invalid] = $chunk->parseToolCalls();

        self::assertSame([], $calls);
        self::assertCount(1, $invalid);
        self::assertStringContainsString('undecoded JSON string', $invalid[0]['error']);
    }

    public function testANonStringNonArrayArgumentIsAlsoHandled(): void
    {
        $chunk = new AIMessageChunk([
            'content' => '',
            'tool_call_chunks' => [['index' => 0, 'id' => 't', 'name' => 'f', 'args' => 42]],
        ]);

        [$calls, $invalid] = $chunk->parseToolCalls();

        self::assertSame([], $calls);
        self::assertCount(1, $invalid);
    }

    public function testEmptyAndAbsentArgumentsStillProduceACallWithNoArguments(): void
    {
        foreach ([null, '', '   '] as $args) {
            $chunk = new AIMessageChunk([
                'content' => '',
                'tool_call_chunks' => [['index' => 0, 'id' => 't', 'name' => 'f', 'args' => $args]],
            ]);

            [$calls, $invalid] = $chunk->parseToolCalls();

            self::assertCount(1, $calls, 'args=' . var_export($args, true));
            self::assertSame([], $calls[0]['args']);
            self::assertSame([], $invalid);
        }
    }
}
