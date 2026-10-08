<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\StructuredQuery;

use LangChain\StructuredQuery\Utils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `structured_query/tests/utils.test.ts` ("Casting values correctly"),
 * plus the JS number-model edges it relies on.
 */
#[CoversClass(Utils::class)]
final class UtilsTest extends TestCase
{
    public function testCastingValuesCorrectly(): void
    {
        $stringString = ['string', 'test', 'this is a string', '        ', "\n\n\n\n\n\n", "asdf\n    zxcv"];
        $intString = ['1a', '2b', '3c', 'a4', "123\n    asdf"];
        $floatString = ['1.1a', '2.2b', '3.3c', 'c4.4'];
        $intInt = ['1', 2, 3];
        $floatFloat = ['1.1', 2.2, 3.3];
        $booleanBoolean = [true, false];

        foreach ($stringString as $input) {
            $value = Utils::castValue($input);
            self::assertIsString($value);
            self::assertTrue(Utils::isString($value));
        }
        foreach ($intString as $input) {
            $value = Utils::castValue($input);
            self::assertIsString($value);
            self::assertTrue(Utils::isString($value));
            self::assertFalse(Utils::isInt($value));
        }
        foreach ($floatString as $input) {
            $value = Utils::castValue($input);
            self::assertIsString($value);
            self::assertTrue(Utils::isString($value));
            self::assertFalse(Utils::isFloat($value));
        }
        foreach ($intInt as $input) {
            $value = Utils::castValue($input);
            self::assertIsInt($value);
            self::assertTrue(Utils::isInt($value));
        }
        foreach ($floatFloat as $input) {
            $value = Utils::castValue($input);
            self::assertIsFloat($value);
            self::assertTrue(Utils::isFloat($value));
        }
        foreach ($booleanBoolean as $input) {
            $value = Utils::castValue($input);
            self::assertIsBool($value);
            self::assertTrue(Utils::isBoolean($value));
        }
    }

    public function testNumericStringsThatDoNotRoundTripStayStrings(): void
    {
        foreach (['007', '1.0', '1e3', ' 5', '+5', '5 ', '', '0x10', '-0'] as $s) {
            self::assertSame($s, Utils::castValue($s), "'{$s}' must stay a string");
        }
        self::assertSame(-5, Utils::castValue('-5'));
        self::assertSame(0.5, Utils::castValue('0.5'));
        self::assertSame(1.0E-7, Utils::castValue('1e-7'));
    }

    public function testAWholeFloatIsAnInteger(): void
    {
        self::assertSame(3, Utils::castValue(3.0));
        self::assertTrue(Utils::isInt(3.0));
        self::assertFalse(Utils::isFloat(3.0));
    }

    public function testUnsupportedValuesThrow(): void
    {
        foreach ([null, [], [1], new \stdClass()] as $bad) {
            try {
                Utils::castValue($bad);
                self::fail('expected Unsupported value type');
            } catch (\Error $e) {
                self::assertSame('Unsupported value type', $e->getMessage());
            }
        }
    }

    public function testIsFilterEmpty(): void
    {
        self::assertTrue(Utils::isFilterEmpty(null));
        self::assertTrue(Utils::isFilterEmpty(''));
        self::assertTrue(Utils::isFilterEmpty([]));
        self::assertTrue(Utils::isFilterEmpty(new \stdClass()));
        self::assertFalse(Utils::isFilterEmpty('x'));
        self::assertFalse(Utils::isFilterEmpty('0'));
        self::assertFalse(Utils::isFilterEmpty(static fn () => true));
        self::assertFalse(Utils::isFilterEmpty(['a' => 1]));
        self::assertFalse(Utils::isFilterEmpty([1]));
    }

    public function testIsObject(): void
    {
        self::assertTrue(Utils::isObject(['a' => 1]));
        self::assertTrue(Utils::isObject(new \stdClass()));
        self::assertFalse(Utils::isObject([1, 2]));
        self::assertFalse(Utils::isObject(static fn () => 1));
        self::assertFalse(Utils::isObject('x'));
        self::assertFalse(Utils::isObject(null));
    }
}
