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
final class PartialJsonLiteralPrefixTest extends TestCase
{
    /** @return iterable<string, array{string, mixed}> */
    public static function literalPrefixes(): iterable
    {
        foreach (['null' => null, 'true' => true, 'false' => false] as $literal => $value) {
            for ($i = 1; $i <= strlen($literal); $i++) {
                $prefix = substr($literal, 0, $i);
                yield 'root ' . $prefix => [$prefix, $value];
                yield 'array ' . $prefix => ['[1,' . $prefix, [1, $value]];
                yield 'object ' . $prefix => ['{"a":' . $prefix, ['a' => $value]];
            }
        }
    }

    #[DataProvider('literalPrefixes')]
    public function testLiteralPrefixesRecoverInEveryContext(string $json, mixed $expected): void
    {
        self::assertSame($expected, (new PartialJsonParser($json))->parse());
        self::assertSame($expected, JsonUtils::strictParsePartialJson($json));
    }

    /** @return iterable<string, array{string}> */
    public static function malformedLiteralPrefixes(): iterable
    {
        foreach (['n', 'nu', 'nul', 't', 'tr', 'tru', 'f', 'fa', 'fal', 'fals'] as $prefix) {
            foreach (['x', '1', ',', ']'] as $suffix) {
                yield $prefix . $suffix => [$prefix . $suffix];
            }
            yield 'closed array ' . $prefix => ['[' . $prefix . ']'];
            yield 'closed object ' . $prefix => ['{"a":' . $prefix . '}'];
        }
    }

    #[DataProvider('malformedLiteralPrefixes')]
    public function testMalformedLiteralPrefixesStillThrow(string $json): void
    {
        $this->expectException(\RuntimeException::class);
        (new PartialJsonParser($json))->parse();
    }

    public function testLoneMinusRecoversSignedNegativeZero(): void
    {
        foreach (['-', '[-', '{"a":-'] as $json) {
            $result = JsonUtils::strictParsePartialJson($json);
            $number = is_array($result) ? array_values($result)[0] : $result;
            self::assertSame(-0.0, $number);
            self::assertSame(-INF, fdiv(1.0, $number));
        }
    }
}
