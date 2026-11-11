<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\OutputParsers;

use LangChain\OutputParsers\PartialJsonParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Short unicode escapes follow upstream `strictParsePartialJson`: `\u1`
 * recovers as `u1`. A lone backslash still recovers as a backslash.
 * Byte assertions distinguish the two cases precisely.
 */
#[CoversClass(PartialJsonParser::class)]
final class TruncatedEscapeRecoveryTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> fragment => expected bytes */
    public static function truncatedEscapes(): array
    {
        return [
            'lone backslash' => ['"a\\', '61 5c'],
            'no hex digits' => ['"a\\u', '61 75'],
            'one hex digit' => ['"a\\u1', '61 75 31'],
            'two hex digits' => ['"a\\u12', '61 75 31 32'],
            'three hex digits' => ['"a\\u123', '61 75 31 32 33'],
        ];
    }

    #[DataProvider('truncatedEscapes')]
    public function testACutEscapeMatchesUpstreamBytes(string $fragment, string $expectedHex): void
    {
        $recovered = (new PartialJsonParser($fragment))->parse();

        self::assertSame(
            str_replace(' ', '', $expectedHex),
            bin2hex((string) $recovered),
            'a truncated escape must match upstream recovery',
        );
    }

    /** Text without an escape remains unchanged. */
    public function testTextWithNoEscapeIsUnaffected(): void
    {
        $recovered = (new PartialJsonParser('"au12'))->parse();

        self::assertSame('au12', $recovered);
        self::assertSame('61753132', bin2hex($recovered), 'no 5c here, and none should be added');
    }

    /** A complete escape still resolves to the character, unchanged. */
    public function testACompleteEscapeIsUnaffected(): void
    {
        self::assertSame(
            'aሴ',
            (new PartialJsonParser('"a\u1234"'))->parse(),
            'the four-digit path is untouched',
        );
    }
}
