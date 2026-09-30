<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Messages;

use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A field map with an identity but no content is refused, not emptied.
 *
 * `looksLikeFieldMap()` is satisfied by the key `type` alone, so
 * `new HumanMessage(['type' => 'text', 'text' => 'hi'])` — a single content
 * block — was read as a field map with no `content`, fell through to the
 * default, and produced an EMPTY message. Measured before the fix: content
 * became `[]`, the text silently gone, no error anywhere.
 *
 * That is the worst shape of failure: a wrong value written and never flagged,
 * and a message that looks constructed successfully.
 *
 * Upstream refuses the shape. `messages/utils.ts` destructures a two-element
 * TUPLE for an array, requires `role` for a field map, and otherwise reaches
 * `_constructMessageFromParams`, which throws for a type it does not know.
 * Producing an empty message is strictly worse than refusing the input.
 */
#[CoversClass(BaseMessage::class)]
final class FieldMapWithoutContentTest extends TestCase
{
    public function testASingleContentBlockIsRefusedRatherThanEmptied(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new HumanMessage(['type' => 'text', 'text' => 'hi']);
    }

    public function testTheMessageNamesWhatItReceived(): void
    {
        try {
            new HumanMessage(['type' => 'text', 'text' => 'hi']);
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('content', $e->getMessage());
            self::assertStringContainsString('type', $e->getMessage());
        }
    }

    /** @return array<string, array{0: mixed, 1: mixed}> */
    public static function acceptedShapes(): array
    {
        return [
            'a field map with role' => [['role' => 'user', 'content' => 'hi'], 'hi'],
            'a field map with type' => [['type' => 'human', 'content' => 'hi'], 'hi'],
            'a bare string' => ['hi', 'hi'],
            'a list of one block' => [[['type' => 'text', 'text' => 'hi']], [['type' => 'text', 'text' => 'hi']]],
            'a list of two blocks' => [
                [['type' => 'text', 'text' => 'a'], ['type' => 'text', 'text' => 'b']],
                [['type' => 'text', 'text' => 'a'], ['type' => 'text', 'text' => 'b']],
            ],
        ];
    }

    /**
     * Every shape that legitimately carries content must still work — the fix
     * must not harden into refusing valid input.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('acceptedShapes')]
    public function testValidShapesAreUnaffected(mixed $fields, mixed $expected): void
    {
        self::assertSame($expected, (new HumanMessage($fields))->content);
    }

    /** The v0 shape, contentBlocks plus a marker, must keep working. */
    public function testContentBlocksAreStillAccepted(): void
    {
        $message = new HumanMessage(['contentBlocks' => [['type' => 'text', 'text' => 'hi']]]);

        self::assertSame([['type' => 'text', 'text' => 'hi']], $message->content);
        self::assertSame('v1', $message->response_metadata['output_version'] ?? null);
    }
}
