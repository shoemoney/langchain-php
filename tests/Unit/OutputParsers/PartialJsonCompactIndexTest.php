<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\OutputParsers;

use LangChain\OutputParsers\PartialJsonParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Capture approved N02c malformed-subpart behavior across compact-index boundaries. */
#[CoversClass(PartialJsonParser::class)]
final class PartialJsonCompactIndexTest extends TestCase
{
    /** @return iterable<string, array{int|string, string, string}> */
    public static function substitutionBoundaries(): iterable
    {
        yield '63-literal' => [63, '4oB0', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAnPycgYXQgcG9zaXRpb24gMA=='];
        yield '63-literal-slot' => [63, 'dP9ydQ==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAndCcgYXQgcG9zaXRpb24gMA=='];
        yield '63-unicode-prefix' => [63, 'Ilx1MeKAMg==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpJbnZhbGlkIHVuaWNvZGUgZXNjYXBlIHNlcXVlbmNlICdcdTE/MicgYXQgcG9zaXRpb24gMg=='];
        yield '63-unicode-tail' => [63, 'Ilx1MTL/NEE=', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpJbnZhbGlkIHVuaWNvZGUgZXNjYXBlIHNlcXVlbmNlICdcdTEyPzQnIGF0IHBvc2l0aW9uIDI='];
        yield '63-escape' => [63, 'Ilz/bg==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpJbnZhbGlkIGVzY2FwZSBzZXF1ZW5jZSAnXD8nIGF0IHBvc2l0aW9uIDI='];
        yield '63-close' => [63, 'ImHigCJ4', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAneCcgYXQgcG9zaXRpb24gNA=='];
        yield '63-eof' => [63, 'IsOp8J+YgOKA', 'dmFsdWU6czo3OiLDqfCfmIA/Ijs='];
        yield '63-diagnostic' => [63, 'WyLDqfCfmIDigCIseA==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAneCcgYXQgcG9zaXRpb24gNw=='];
        yield '65533-literal' => [65533, '4oB0', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAn77+9JyBhdCBwb3NpdGlvbiAw'];
        yield '65533-literal-slot' => [65533, 'dP9ydQ==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAndCcgYXQgcG9zaXRpb24gMA=='];
        yield '65533-unicode-prefix' => [65533, 'Ilx1MeKAMg==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpJbnZhbGlkIHVuaWNvZGUgZXNjYXBlIHNlcXVlbmNlICdcdTHvv70yJyBhdCBwb3NpdGlvbiAy'];
        yield '65533-unicode-tail' => [65533, 'Ilx1MTL/NEE=', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpJbnZhbGlkIHVuaWNvZGUgZXNjYXBlIHNlcXVlbmNlICdcdTEy77+9NCcgYXQgcG9zaXRpb24gMg=='];
        yield '65533-escape' => [65533, 'Ilz/bg==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpJbnZhbGlkIGVzY2FwZSBzZXF1ZW5jZSAnXO+/vScgYXQgcG9zaXRpb24gMg=='];
        yield '65533-close' => [65533, 'ImHigCJ4', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAneCcgYXQgcG9zaXRpb24gNA=='];
        yield '65533-eof' => [65533, 'IsOp8J+YgOKA', 'dmFsdWU6czo5OiLDqfCfmIDvv70iOw=='];
        yield '65533-diagnostic' => [65533, 'WyLDqfCfmIDigCIseA==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAneCcgYXQgcG9zaXRpb24gNw=='];
        yield 'none-literal' => ['none', '4oB0', 'dmFsdWU6YjoxOw=='];
        yield 'none-literal-slot' => ['none', 'dP9ydQ==', 'dmFsdWU6YjoxOw=='];
        yield 'none-unicode-prefix' => ['none', 'Ilx1MeKAMg==', 'dmFsdWU6czo0OiJ1MTIyIjs='];
        yield 'none-unicode-tail' => ['none', 'Ilx1MTL/NEE=', 'dmFsdWU6czo2OiJ1MTI0NEEiOw=='];
        yield 'none-escape' => ['none', 'Ilz/bg==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpJbnZhbGlkIGVzY2FwZSBzZXF1ZW5jZSAnXCcgYXQgcG9zaXRpb24gMg=='];
        yield 'none-close' => ['none', 'ImHigCJ4', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAneCcgYXQgcG9zaXRpb24gNA=='];
        yield 'none-eof' => ['none', 'IsOp8J+YgOKA', 'dmFsdWU6czo2OiLDqfCfmIAiOw=='];
        yield 'none-diagnostic' => ['none', 'WyLDqfCfmIDigCIseA==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAneCcgYXQgcG9zaXRpb24gNw=='];
        yield 'long-literal' => ['long', '4oB0', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAnPycgYXQgcG9zaXRpb24gMA=='];
        yield 'long-literal-slot' => ['long', 'dP9ydQ==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAndCcgYXQgcG9zaXRpb24gMA=='];
        yield 'long-unicode-prefix' => ['long', 'Ilx1MeKAMg==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpJbnZhbGlkIHVuaWNvZGUgZXNjYXBlIHNlcXVlbmNlICdcdTE/MicgYXQgcG9zaXRpb24gMg=='];
        yield 'long-unicode-tail' => ['long', 'Ilx1MTL/NEE=', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpJbnZhbGlkIHVuaWNvZGUgZXNjYXBlIHNlcXVlbmNlICdcdTEyPzQnIGF0IHBvc2l0aW9uIDI='];
        yield 'long-escape' => ['long', 'Ilz/bg==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpJbnZhbGlkIGVzY2FwZSBzZXF1ZW5jZSAnXD8nIGF0IHBvc2l0aW9uIDI='];
        yield 'long-close' => ['long', 'ImHigCJ4', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAneCcgYXQgcG9zaXRpb24gNA=='];
        yield 'long-eof' => ['long', 'IsOp8J+YgOKA', 'dmFsdWU6czo3OiLDqfCfmIA/Ijs='];
        yield 'long-diagnostic' => ['long', 'WyLDqfCfmIDigCIseA==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAneCcgYXQgcG9zaXRpb24gNw=='];
        yield '48-literal' => [48, '4oB0', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAndCcgYXQgcG9zaXRpb24gMQ=='];
        yield '48-literal-slot' => [48, 'dP9ydQ==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAndCcgYXQgcG9zaXRpb24gMA=='];
        yield '48-unicode-prefix' => [48, 'Ilx1MeKAMg==', 'dmFsdWU6czo0OiJ1MTAyIjs='];
        yield '48-unicode-tail' => [48, 'Ilx1MTL/NEE=', 'dmFsdWU6czo0OiLhiIRBIjs='];
        yield '48-escape' => [48, 'Ilz/bg==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpJbnZhbGlkIGVzY2FwZSBzZXF1ZW5jZSAnXDAnIGF0IHBvc2l0aW9uIDI='];
        yield '48-close' => [48, 'ImHigCJ4', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAneCcgYXQgcG9zaXRpb24gNA=='];
        yield '48-eof' => [48, 'IsOp8J+YgOKA', 'dmFsdWU6czo3OiLDqfCfmIAwIjs='];
        yield '48-diagnostic' => [48, 'WyLDqfCfmIDigCIseA==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAneCcgYXQgcG9zaXRpb24gNw=='];
        yield '49-literal' => [49, '4oB0', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAndCcgYXQgcG9zaXRpb24gMQ=='];
        yield '49-literal-slot' => [49, 'dP9ydQ==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAndCcgYXQgcG9zaXRpb24gMA=='];
        yield '49-unicode-prefix' => [49, 'Ilx1MeKAMg==', 'dmFsdWU6czo0OiJ1MTEyIjs='];
        yield '49-unicode-tail' => [49, 'Ilx1MTL/NEE=', 'dmFsdWU6czo0OiLhiJRBIjs='];
        yield '49-escape' => [49, 'Ilz/bg==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpJbnZhbGlkIGVzY2FwZSBzZXF1ZW5jZSAnXDEnIGF0IHBvc2l0aW9uIDI='];
        yield '49-close' => [49, 'ImHigCJ4', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAneCcgYXQgcG9zaXRpb24gNA=='];
        yield '49-eof' => [49, 'IsOp8J+YgOKA', 'dmFsdWU6czo3OiLDqfCfmIAxIjs='];
        yield '49-diagnostic' => [49, 'WyLDqfCfmIDigCIseA==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAneCcgYXQgcG9zaXRpb24gNw=='];
        yield '34-literal' => [34, '4oB0', 'dmFsdWU6czoxOiJ0Ijs='];
        yield '34-literal-slot' => [34, 'dP9ydQ==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAndCcgYXQgcG9zaXRpb24gMA=='];
        yield '34-unicode-prefix' => [34, 'Ilx1MeKAMg==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpJbnZhbGlkIHVuaWNvZGUgZXNjYXBlIHNlcXVlbmNlICdcdTEiMicgYXQgcG9zaXRpb24gMg=='];
        yield '34-unicode-tail' => [34, 'Ilx1MTL/NEE=', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpJbnZhbGlkIHVuaWNvZGUgZXNjYXBlIHNlcXVlbmNlICdcdTEyIjQnIGF0IHBvc2l0aW9uIDI='];
        yield '34-escape' => [34, 'Ilz/bg==', 'dmFsdWU6czoyOiIibiI7'];
        yield '34-close' => [34, 'ImHigCJ4', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAnIicgYXQgcG9zaXRpb24gMw=='];
        yield '34-eof' => [34, 'IsOp8J+YgOKA', 'dmFsdWU6czo2OiLDqfCfmIAiOw=='];
        yield '34-diagnostic' => [34, 'WyLDqfCfmIDigCIseA==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpFeHBlY3RlZCAnLCcgb3IgJ10nIGF0IHBvc2l0aW9uIDUsIGdvdCAnIic='];
        yield '92-literal' => [92, '4oB0', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAnXCcgYXQgcG9zaXRpb24gMA=='];
        yield '92-literal-slot' => [92, 'dP9ydQ==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAndCcgYXQgcG9zaXRpb24gMA=='];
        yield '92-unicode-prefix' => [92, 'Ilx1MeKAMg==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpJbnZhbGlkIHVuaWNvZGUgZXNjYXBlIHNlcXVlbmNlICdcdTFcMicgYXQgcG9zaXRpb24gMg=='];
        yield '92-unicode-tail' => [92, 'Ilx1MTL/NEE=', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpJbnZhbGlkIHVuaWNvZGUgZXNjYXBlIHNlcXVlbmNlICdcdTEyXDQnIGF0IHBvc2l0aW9uIDI='];
        yield '92-escape' => [92, 'Ilz/bg==', 'dmFsdWU6czoyOiJcbiI7'];
        yield '92-close' => [92, 'ImHigCJ4', 'dmFsdWU6czozOiJhIngiOw=='];
        yield '92-eof' => [92, 'IsOp8J+YgOKA', 'dmFsdWU6czo3OiLDqfCfmIBcIjs='];
        yield '92-diagnostic' => [92, 'WyLDqfCfmIDigCIseA==', 'dmFsdWU6YToxOntpOjA7czo5OiLDqfCfmIAiLHgiO30='];
        yield '12288-literal' => [12288, '4oB0', 'dmFsdWU6YjoxOw=='];
        yield '12288-literal-slot' => [12288, 'dP9ydQ==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAndCcgYXQgcG9zaXRpb24gMA=='];
        yield '12288-unicode-prefix' => [12288, 'Ilx1MeKAMg==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpJbnZhbGlkIHVuaWNvZGUgZXNjYXBlIHNlcXVlbmNlICdcdTHjgIAyJyBhdCBwb3NpdGlvbiAy'];
        yield '12288-unicode-tail' => [12288, 'Ilx1MTL/NEE=', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpJbnZhbGlkIHVuaWNvZGUgZXNjYXBlIHNlcXVlbmNlICdcdTEy44CANCcgYXQgcG9zaXRpb24gMg=='];
        yield '12288-escape' => [12288, 'Ilz/bg==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpJbnZhbGlkIGVzY2FwZSBzZXF1ZW5jZSAnXOOAgCcgYXQgcG9zaXRpb24gMg=='];
        yield '12288-close' => [12288, 'ImHigCJ4', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAneCcgYXQgcG9zaXRpb24gNA=='];
        yield '12288-eof' => [12288, 'IsOp8J+YgOKA', 'dmFsdWU6czo5OiLDqfCfmIDjgIAiOw=='];
        yield '12288-diagnostic' => [12288, 'WyLDqfCfmIDigCIseA==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAneCcgYXQgcG9zaXRpb24gNw=='];
        yield '32-literal' => [32, '4oB0', 'dmFsdWU6YjoxOw=='];
        yield '32-literal-slot' => [32, 'dP9ydQ==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAndCcgYXQgcG9zaXRpb24gMA=='];
        yield '32-unicode-prefix' => [32, 'Ilx1MeKAMg==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpJbnZhbGlkIHVuaWNvZGUgZXNjYXBlIHNlcXVlbmNlICdcdTEgMicgYXQgcG9zaXRpb24gMg=='];
        yield '32-unicode-tail' => [32, 'Ilx1MTL/NEE=', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpJbnZhbGlkIHVuaWNvZGUgZXNjYXBlIHNlcXVlbmNlICdcdTEyIDQnIGF0IHBvc2l0aW9uIDI='];
        yield '32-escape' => [32, 'Ilz/bg==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpJbnZhbGlkIGVzY2FwZSBzZXF1ZW5jZSAnXCAnIGF0IHBvc2l0aW9uIDI='];
        yield '32-close' => [32, 'ImHigCJ4', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAneCcgYXQgcG9zaXRpb24gNA=='];
        yield '32-eof' => [32, 'IsOp8J+YgOKA', 'dmFsdWU6czo3OiLDqfCfmIAgIjs='];
        yield '32-diagnostic' => [32, 'WyLDqfCfmIDigCIseA==', 'ZXJyb3I6UnVudGltZUV4Y2VwdGlvbjpVbmV4cGVjdGVkIGNoYXJhY3RlciAneCcgYXQgcG9zaXRpb24gNw=='];
    }

    #[DataProvider('substitutionBoundaries')]
    public function testLogicalSlotsAndBoundedLookaheadKeepApprovedSubstitutionBehavior(int|string $policy, string $encoded, string $expected): void
    {
        $previous = mb_substitute_character();
        try {
            // 'long' retains the last numeric replacement; pin that hidden state.
            mb_substitute_character(63);
            mb_substitute_character($policy);
            try {
                $actual = 'value:' . serialize((new PartialJsonParser(base64_decode($encoded, true)))->parse());
            } catch (\RuntimeException $error) {
                $actual = 'error:' . $error::class . ':' . $error->getMessage();
            }
            self::assertSame(base64_decode($expected, true), $actual);
        } finally {
            mb_substitute_character($previous);
        }
    }

    public function testSubstitutionPolicyIsCapturedAtConstructionRatherThanAtCharacterAccess(): void
    {
        $previous = mb_substitute_character();
        try {
            mb_substitute_character(63);
            $question = new PartialJsonParser("\"a\xE2\x80b");
            mb_substitute_character('none');
            $none = new PartialJsonParser("\"a\xE2\x80b");
            mb_substitute_character(34);
            self::assertSame('a?b', $question->parse());
            self::assertSame('ab', $none->parse());
        } finally {
            mb_substitute_character($previous);
        }
    }

    public function testLongPolicyRetainsItsNumericFallbackAtConstruction(): void
    {
        $previous = mb_substitute_character();
        try {
            mb_substitute_character(0xFFFD);
            mb_substitute_character('long');
            $parser = new PartialJsonParser("\"a\xE2\x80b");
            mb_substitute_character(63);
            self::assertSame("a\u{FFFD}b", $parser->parse());
        } finally {
            mb_substitute_character($previous);
        }
    }

    public function testLargeUnicodeAndMalformedBuffersRetainTheirWholeRecovery(): void
    {
        $previous = mb_substitute_character();
        try {
            $input = '"' . str_repeat('é😀\uD83D\uDE00', 120000) . '\u12';
            self::assertSame(str_repeat('é😀😀', 120000) . 'u12', (new PartialJsonParser($input))->parse());
            mb_substitute_character(63);
            self::assertSame(str_repeat('é?b😀', 120000), (new PartialJsonParser('"' . str_repeat("é\xE2\x80b😀", 120000)))->parse());
            mb_substitute_character('none');
            self::assertSame(str_repeat('éb😀', 120000), (new PartialJsonParser('"' . str_repeat("é\xE2\x80b😀", 120000)))->parse());
        } finally {
            mb_substitute_character($previous);
        }
    }
}
