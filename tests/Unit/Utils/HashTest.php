<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Utils;

use LangGraph\Utils\Hash;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of `langgraph-core/src/tests/hash.test.ts` (`XXH3_128`).
 *
 * Every vector below is upstream's. On top of them, `xxh3-oracle.json` holds the
 * digests a Node run of upstream's own `hash.ts` produced for every input length
 * from 0 to 600 bytes, which covers all six code paths (0, 1-3, 4-8, 9-16, 17-128,
 * 129-240, and the long-input loop) rather than the handful of lengths upstream
 * happened to write down.
 */
#[CoversClass(Hash::class)]
final class HashTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function upstreamVectors(): array
    {
        $a = 'abcdefghijklmnopqrstuvwxyz1234567890';

        return [
            'empty string' => ['', '99aa06d3014798d86001c324468d497f'],
            'a' => ['a', 'a96faf705af16834e6c632b61e964e1f'],
            'ab' => ['ab', '89c65ebc828eebaca873719c24d5735c'],
            'abc' => ['abc', '06b05ab6733a618578af5f94892f3950'],
            'abcd' => ['abcd', '8d6b60383dfa90c21be79eecd1b1353d'],
            'abcde' => ['abcde', '3043c78169f25c3f97d5a48ef320eec2'],
            'abcdef' => ['abcdef', '389197a55db2b2e4da35a6714d34f8a2'],
            'abcdefg' => ['abcdefg', '2aafd83869a59c313fe798c0edaa6dc6'],
            'abcdefgh' => ['abcdefgh', 'dac23237af37353342b702b313880f12'],
            'abcdefghi' => ['abcdefghi', 'b43ff5bc5ff2e0adc0646b2d7986db98'],
            'abcdefghij' => ['abcdefghij', '9e814df2752571c7b0a8c058e69ff5a7'],
            'abcdefghijk' => ['abcdefghijk', 'f63802ddeb8a84810c30617e220bd2c5'],
            'abcdefghijkl' => ['abcdefghijkl', 'd5c1c71e1ef3a2b6ca41a0e8a26ef9e2'],
            'abcdefghijklm' => ['abcdefghijklm', 'b3f3c61b89a9d1224c633bfeef25de5b'],
            'abcdefghijklmn' => ['abcdefghijklmn', '4d15f6daa22c156bcb0743e0c58a8d23'],
            'abcdefghijklmno' => ['abcdefghijklmno', '5e190a0fa5ad0836d35dc9eaab32b9a0'],
            'abcdefghijklmnop' => ['abcdefghijklmnop', '1f58fc809b1b8c4b3e8e153ff12f6330'],
            '17 bytes' => ['abcdefghijklmnopq', '11078c38a5ca3a8dc3acc9940596efab'],
            '32 bytes' => ['abcdefghijklmnopqrstuvwxyz123456', '668b14f3933edd9f52625e96b6d3b0f3'],
            '68 bytes' => [$a . 'abcdefghijklmnopqrstuvwxyz123456', '2d794cf93e1a067211e9b7a76062d8d6'],
            '140 bytes' => [str_repeat($a, 3) . 'abcdefghijklmnopqrstuvwxyz123456', '8d2ec8e569ae8fa6d7cc4c23a95f14d9'],
            '141 bytes' => [str_repeat($a, 3) . 'abcdefghijklmnopqrstuvwxyz123456a', 'b2ea1620c3bb852c2012ecacf727c481'],
            '180 bytes' => [str_repeat($a, 5), 'c800bd4157366fc23720f1739930fb8a'],
            '288 bytes' => [str_repeat($a, 8), 'bc971ffa336b4d9c484aaf4bea72ea4c'],
            '289 bytes' => [str_repeat($a, 8) . 'a', '6ac1192bda13b7b0ccc2d9ba4de6130f'],
            '512 a' => [str_repeat('a', 512), '9718dbab650037cd4659a548a9cc8db1'],
            '1024 b' => [str_repeat('b', 1024), 'fd5ea522e67427228507db63d38fc496'],
            '4096 c' => [str_repeat('c', 4096), 'b1fc3898cb4ccfb9bc50ac89ff26de23'],
            'hello' => ['hello', 'b5e9c1ad071b3e7fc779cfaa5e523818'],
            'multi-byte' => ['hello世界', '136ef66cd12de20cef7671666c482f52'],
            'punctuation' => ['!@#$%^&*()', 'ecce31aa3ba802e484741d278c4654b0'],
            'newline' => ["hello\nworld", '9b32ed3fe4b0707222962e00f9cd6b5a'],
            'tab' => ["hello\tworld", '234bacfb02656dc40604b57b6fc10016'],
            '16 bytes' => ['1234567890123456', 'f57143299804fb6a2e61ad3f8cc8fed5'],
            '128 bytes' => [str_repeat('a', 128), '134e2a91815f3105ef354c1b9e35d99d'],
            '240 bytes' => [str_repeat('b', 240), '5bb7a7da5e4ff82c807fb4b4352efc95'],
            '241 bytes' => [str_repeat('c', 241), 'b90b3e70eb1fd4a05d911035549cfeaf'],
        ];
    }

    #[DataProvider('upstreamVectors')]
    public function testMatchesUpstreamVectors(string $input, string $expected): void
    {
        self::assertSame($expected, Hash::xxh3($input));
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function oracleDigests(): array
    {
        $fixture = json_decode(
            (string) file_get_contents(__DIR__ . '/../../Fixtures/langgraph/xxh3-oracle.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $cases = [];
        foreach ($fixture['digests'] as $length => $digest) {
            $cases["{$length} bytes"] = [(int) $length, $digest];
        }

        return $cases;
    }

    #[DataProvider('oracleDigests')]
    public function testMatchesTheNodeOracleAtEveryLengthUpToSixHundred(int $length, string $expected): void
    {
        $input = substr(str_repeat('abcdefghijklmnopqrstuvwxyz1234567890', 20), 0, $length);

        self::assertSame($expected, Hash::xxh3($input));
    }

    public function testAnExplicitZeroSeedIsTheDefault(): void
    {
        self::assertSame('b5e9c1ad071b3e7fc779cfaa5e523818', Hash::xxh3('hello', 0));
        self::assertSame(Hash::xxh3(''), Hash::xxh3('', 0));
    }

    /**
     * Upstream's seeded digests (`hello`/1 is `4a93b99b...`) are not the reference
     * algorithm's, and PHP only has the reference one, so a seed is refused instead
     * of returning a digest LangGraph JS would not.
     */
    public function testANonZeroSeedIsRefusedRatherThanSilentlyDiffering(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Hash::xxh3('hello', 1);
    }

    public function testIsXxh3RecognisesA128BitHexDigest(): void
    {
        foreach ([
            '32492f4bab4024f66da6c4ff3e821c65',
            'f2d76ea0c369b4533fa9aa9a35a8977a',
            'f9ba21d816b67aa8ec87181f23014542',
            '4a48370063a9d5590bb01dba7d4aadaa',
            '04a913522053b6d1de18c51b98821e54',
            Hash::xxh3('anything'),
        ] as $hash) {
            self::assertTrue(Hash::isXXH3($hash), $hash);
        }
    }

    public function testIsXxh3RejectsAnythingElse(): void
    {
        foreach (['', 'abc', str_repeat('a', 31), str_repeat('a', 33), str_repeat('A', 32), str_repeat('g', 32), "{$this->hex32()}\n"] as $value) {
            self::assertFalse(Hash::isXXH3($value), var_export($value, true));
        }
    }

    private function hex32(): string
    {
        return str_repeat('a', 32);
    }
}
