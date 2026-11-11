<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\OutputParsers;

use LangChain\OutputParsers\JsonUtils;
use LangChain\OutputParsers\PartialJsonParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(PartialJsonParser::class)]
#[CoversClass(JsonUtils::class)]
final class PartialJsonNumberPrefixTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function numbersWithoutSignificandDigits(): iterable
    {
        // Upstream json.ts parseNumber() throws when Number.parseFloat is NaN.
        foreach (['-.', '-e', '-E', '-e1', '-e+', '-e-', '-E+2', '-E-2', '-.e', '-.E', '-.e1', '-.e+', '-.e-', '-.E+2', '-.E-2'] as $number) {
            yield 'root ' . $number => [$number];
            yield 'array ' . $number => ['[' . $number];
            yield 'closed array ' . $number => ['[' . $number . ']'];
            yield 'object ' . $number => ['{"a":' . $number];
            yield 'closed object ' . $number => ['{"a":' . $number . '}'];
        }
    }

    #[DataProvider('numbersWithoutSignificandDigits')]
    public function testNumberWithoutSignificandDigitsStillThrows(string $json): void
    {
        self::assertNull(JsonUtils::parsePartialJson($json));
        $this->expectException(\RuntimeException::class);
        JsonUtils::strictParsePartialJson($json);
    }

    /** @return iterable<string, array{string, int|float}> */
    public static function recoverableNumbers(): iterable
    {
        foreach (['0.' => 0.0, '0e' => 0.0, '0e+' => 0.0, '-.5' => -0.5, '-.5e' => -0.5, '-.5e+' => -0.5, '-.5e-' => -0.5, '-1e' => -1.0, '-1e+' => -1.0, '-1e-' => -1.0, '-.5e2' => -50.0] as $number => $value) {
            yield $number => [(string) $number, $value];
        }
    }

    #[DataProvider('recoverableNumbers')]
    public function testValidSignificandStillRecoversWithAPartialExponent(string $json, int|float $expected): void
    {
        self::assertEquals($expected, (new PartialJsonParser($json))->parse());
        self::assertEquals($expected, JsonUtils::strictParsePartialJson($json));
    }

    /** @return iterable<string, array{string}> */
    public static function negativeZeroNumbers(): iterable
    {
        foreach (['-', '-0.', '-0e', '-0e+', '-0e-', '-.0', '-.0e'] as $number) {
            yield 'root ' . $number => [$number];
            yield 'array ' . $number => ['[' . $number];
        }
        yield 'closed lone minus array' => ['[-]'];
        yield 'closed lone minus object' => ['{"a":-}'];
    }

    #[DataProvider('negativeZeroNumbers')]
    public function testRecoverableNegativeZeroPreservesItsSign(string $json): void
    {
        $result = JsonUtils::strictParsePartialJson($json);
        $number = is_array($result) ? array_values($result)[0] : $result;
        self::assertSame(-0.0, $number);
        self::assertSame(-INF, fdiv(1.0, $number));
    }
}
