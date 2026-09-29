<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\TextSplitters;

use LangChain\TextSplitters\CharacterTextSplitter;
use LangChain\TextSplitters\RecursiveCharacterTextSplitter;
use LangChain\TextSplitters\TextLength;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(TextLength::class)]
final class TextLengthTest extends TestCase
{
    #[DataProvider('samples')]
    public function testCountsUtf16CodeUnits(string $text, int $expected): void
    {
        $this->assertSame($expected, TextLength::utf16CodeUnits($text));
    }

    /**
     * @return array<string, array{0: string, 1: int}>
     */
    public static function samples(): array
    {
        return [
            'empty' => ['', 0],
            'ascii' => ['abc', 3],
            'latin accents are one unit each' => ['éüà', 3],
            'bmp punctuation' => ["a\u{2026}b", 3],
            'astral is two units' => ['a🦜b', 4],
            'two astral' => ['a🦜🔗', 5],
            'astral alone' => ['🦜', 2],
            'variation selector is its own unit' => ["\u{1F9CC}\u{FE0F}", 3],
            'newlines count' => ["a\nb\nc", 5],
        ];
    }

    public function testTheDefaultLengthFunctionMatchesUtf16CodeUnits(): void
    {
        $splitter = new CharacterTextSplitter(separator: ' ', chunkSize: 100, chunkOverlap: 0);

        $lengthFunction = $splitter->lengthFunction;
        $this->assertIsCallable($lengthFunction);
        $this->assertSame(
            TextLength::utf16CodeUnits('a🦜 b'),
            $lengthFunction('a🦜 b'),
        );
    }

    public function testAnAstralCharacterCountsAsTwoTowardsChunkSize(): void
    {
        // "a b 🦜" is 5 code points but 6 UTF-16 code units, so against a
        // budget of 5 it must break. This is the case that pins the measure: a
        // code-point length function would call the whole string "small enough"
        // and hand back a single chunk that is over budget by one unit.
        $this->assertSame(5, mb_strlen('a b 🦜', 'UTF-8'));
        $this->assertSame(6, TextLength::utf16CodeUnits('a b 🦜'));

        $utf16 = new RecursiveCharacterTextSplitter(chunkSize: 5, chunkOverlap: 0);
        $this->assertSame(['a b', '🦜'], $utf16->splitText('a b 🦜'));

        $codePoints = new RecursiveCharacterTextSplitter(
            chunkSize: 5,
            chunkOverlap: 0,
            lengthFunction: static fn (string $text): int => mb_strlen($text, 'UTF-8'),
        );
        $this->assertSame(['a b 🦜'], $codePoints->splitText('a b 🦜'));
    }

    public function testTheCodeUnitMeasurePutsAnAstralCharacterOverBudget(): void
    {
        // "🦜 a" is 3 code units, but as two words separated by a space the join
        // costs 3 as well, so the trailing word does not fit and must start a new
        // chunk. Counting code points would have let it through.
        $splitter = new RecursiveCharacterTextSplitter(chunkSize: 3, chunkOverlap: 0);

        $this->assertSame(['🦜', 'a'], $splitter->splitText('🦜 a'));
    }

    public function testAnAstralCharacterIsNeverSplitAcrossChunks(): void
    {
        // A character that occupies two units can land on a chunk boundary; the
        // boundary has to fall between code points, so the text still reassembles.
        $splitter = new RecursiveCharacterTextSplitter(chunkSize: 3, chunkOverlap: 0);

        $chunks = $splitter->splitText('🦜🦜🦜');

        foreach ($chunks as $chunk) {
            $this->assertSame($chunk, mb_convert_encoding($chunk, 'UTF-8', 'UTF-8'));
        }
        $this->assertSame('🦜🦜🦜', implode('', $chunks));
    }
}
