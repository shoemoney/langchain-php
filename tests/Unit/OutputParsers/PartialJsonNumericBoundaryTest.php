<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\OutputParsers;

use LangChain\OutputParsers\JsonUtils;
use LangChain\OutputParsers\PartialJsonParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Boundary cases derived from json.ts's Number.parseFloat, with PHP's complete native decode pinned separately. */
#[CoversClass(PartialJsonParser::class)]
#[CoversClass(JsonUtils::class)]
final class PartialJsonNumericBoundaryTest extends TestCase
{
    /** @return iterable<string, array{string, float}> */
    public static function unsafeIntegerTokens(): iterable
    {
        foreach ([
            '9007199254740992' => 9007199254740992.0,
            '9007199254740993' => 9007199254740992.0,
            '9007199254740995' => 9007199254740996.0,
            '9223372036854775807' => 9223372036854775808.0,
            '9223372036854775808' => 9223372036854775808.0,
            '18446744073709551615' => 18446744073709551616.0,
            '999999999999999999999999999999' => 1.0e30,
        ] as $token => $expected) {
            yield (string) $token => [(string) $token, $expected];
            yield '-' . $token => ['-' . $token, -$expected];
        }
    }

    #[DataProvider('unsafeIntegerTokens')]
    public function testDirectPartialParserUsesTheSourceDoubleInsteadOfSaturating(string $token, float $expected): void
    {
        self::assertSame($expected, (new PartialJsonParser($token))->parse());
    }

    #[DataProvider('unsafeIntegerTokens')]
    public function testUnclosedContainersRecoverTheSameDouble(string $token, float $expected): void
    {
        self::assertSame([$expected], JsonUtils::strictParsePartialJson('[' . $token));
        self::assertSame(['value' => $expected], JsonUtils::strictParsePartialJson('{"value":' . $token));
    }

    public function testSafeIntegerTokensRetainThePortsIntegerRepresentation(): void
    {
        foreach ([0, 1, -1, 9007199254740990, -9007199254740990, 9007199254740991, -9007199254740991] as $number) {
            self::assertSame($number, (new PartialJsonParser((string) $number))->parse());
        }
    }

    public function testOversizedIntegerAndExponentOverflowUseInfinityRatherThanAnIntegerBound(): void
    {
        foreach ([str_repeat('9', 310), '1e309'] as $token) {
            self::assertSame([INF], JsonUtils::strictParsePartialJson('[' . $token));
            self::assertSame([-INF], JsonUtils::strictParsePartialJson('[-' . $token));
        }
    }

    public function testCompleteIntegerNegativeZeroKeepsTheNativeJsonDecodeConvention(): void
    {
        foreach (['-0', '[-0]', '{"value":-0}'] as $json) {
            $native = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
            $result = JsonUtils::strictParsePartialJson($json);
            self::assertSame($native, $result);
            $number = \is_array($result) ? array_values($result)[0] : $result;
            self::assertSame(0, $number);
            self::assertSame(INF, fdiv(1.0, $number));
        }
    }

    public function testPartialIntegerNegativeZeroKeepsTheSourceSign(): void
    {
        self::assertSame(-INF, fdiv(1.0, (new PartialJsonParser('-0'))->parse()));
        foreach (['[-0', '{"value":-0', '[-0,', '{"value":-0,'] as $json) {
            $result = JsonUtils::strictParsePartialJson($json);
            $number = array_values($result)[0];
            self::assertSame(-0.0, $number);
            self::assertSame(-INF, fdiv(1.0, $number));
        }
    }

    public function testCompleteDecimalNegativeZeroRetainsTheNativeNegativeSign(): void
    {
        foreach (['-0.0', '[-0.0]', '{"value":-0.0}'] as $json) {
            $result = JsonUtils::strictParsePartialJson($json);
            self::assertSame(json_decode($json, true, 512, JSON_THROW_ON_ERROR), $result);
            $number = \is_array($result) ? array_values($result)[0] : $result;
            self::assertSame(-INF, fdiv(1.0, $number));
        }
    }

    public function testCompleteIntegersStillUseTheNativeDecoderPrecision(): void
    {
        // Unlike JS JSON.parse, PHP retains this exact integer on its complete path.
        self::assertSame(9007199254740993, JsonUtils::strictParsePartialJson('9007199254740993'));
        self::assertSame([9007199254740993], JsonUtils::strictParsePartialJson('[9007199254740993]'));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedNumbers(): iterable
    {
        foreach (['[9007199254740993x', '[099999999999999999999999', '[-e309', '[1e++309', '{"value":18446744073709551615]'] as $json) {
            yield $json => [$json];
        }
    }

    #[DataProvider('malformedNumbers')]
    public function testNumericRecoveryDoesNotConsumeMalformedTokens(string $json): void
    {
        self::assertNull(JsonUtils::parsePartialJson($json));
        $this->expectException(\RuntimeException::class);
        JsonUtils::strictParsePartialJson($json);
    }
}
