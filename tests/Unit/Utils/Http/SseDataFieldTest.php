<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Utils\Http;

use LangChain\Utils\Http\SseParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The SSE `data:` field keeps all but one leading space.
 *
 * The spec removes AT MOST one space after the field name. `ltrim(..., ' ')`
 * removed all of them, so a payload with meaningful leading whitespace was
 * silently rewritten. Measured before the fix: `data:  text` yielded `text`
 * instead of ` text`.
 *
 * The damage was invisible for JSON payloads — `json_decode` tolerates
 * surrounding whitespace — and total for anything else: a plain-text event, a
 * code block, an indented template. A parser bug that only shows on non-JSON
 * payloads is exactly the kind nothing in a JSON-shaped test suite catches.
 */
#[CoversClass(SseParser::class)]
final class SseDataFieldTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function framings(): array
    {
        return [
            'no space' => ['data:text', 'text'],
            'one space' => ['data: text', 'text'],
            // The spec takes ONE. These are the cases that were corrupted.
            'two spaces' => ['data:  text', ' text'],
            'three spaces' => ['data:   x', '  x'],
            'a tab is not stripped' => ["data:\tx", "\tx"],
        ];
    }

    #[DataProvider('framings')]
    public function testOnlyOneLeadingSpaceIsRemoved(string $wire, string $expected): void
    {
        $parser = new SseParser();
        $events = [];
        foreach (str_split($wire . "\n\n", 3) as $piece) {
            foreach ($parser->feed($piece) as $event) {
                $events[] = $event;
            }
        }

        self::assertSame([$expected], $events, sprintf('framing %s', json_encode($wire)));
    }

    /** A JSON payload still decodes: the fix must not break the common case. */
    public function testJsonPayloadsStillDecode(): void
    {
        $parser = new SseParser();
        $events = [];
        foreach (str_split("data: {\"a\": 1}\n\n", 4) as $piece) {
            foreach ($parser->feed($piece) as $event) {
                $events[] = $event;
            }
        }

        self::assertSame([['a' => 1]], array_map(
            static fn (string $e): mixed => json_decode($e, true),
            $events,
        ));
    }
}
