<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Tools;

use LangChain\Tools\Schema;
use LangChain\Tools\ToolException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A property declared as a `Schema` INSTANCE must behave exactly like the same
 * property declared as a plain array.
 *
 * `Schema::string()` returns a Schema, so `Schema::object(['a' =>
 * Schema::string()])` is the natural spelling — and it silently did nothing.
 * Neither consumer unwrapped it:
 *
 *   * `toJsonSchema()` emitted `{"a":{"schema":{"type":"string"}}}`. A model
 *     handed that reads no argument type, so the tool described an argument it
 *     could not constrain.
 *   * `errors()` looked for `$prop['type']`, found none, and returned no
 *     errors. That fails OPEN: the schema looked declared, and the tool
 *     accepted `1` and `null` for a field declared as a string.
 *
 * The existing tests missed it because every one of them built properties as
 * plain arrays, so the passing suite described a shape the library's own
 * factories do not produce.
 */
#[CoversClass(Schema::class)]
final class NestedSchemaInstanceTest extends TestCase
{
    public function testTheWireFormatDeclaresTheArgumentType(): void
    {
        $schema = Schema::object(['a' => Schema::string()], ['a']);

        self::assertSame(
            ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']],
            $schema->toJsonSchema(),
            'A Schema instance as a property must be emitted as its JSON Schema, '
            . 'not as {"schema":{...}} — the latter describes no type to the model.',
        );
    }

    public function testANestedInstanceConstrainsTheValueInsteadOfFailingOpen(): void
    {
        $schema = Schema::object(['a' => Schema::string()], ['a']);

        $schema->validate(['a' => 'ok']);

        // Each of these is the same rejection the plain-array form already
        // performed. Failing open on the instance form was the whole defect.
        foreach ([['a' => 1], ['a' => null], ['a' => []], ['a' => new \stdClass()]] as $bad) {
            try {
                $schema->validate($bad);
                self::fail('must reject ' . json_encode($bad) . ' for a string field');
            } catch (ToolException) {
                self::assertTrue(true);
            }
        }
    }

    public function testAnInstanceAndAPlainArrayAreIndistinguishable(): void
    {
        $viaInstance = Schema::object(['a' => Schema::string()], ['a']);
        $viaArray = Schema::object(['a' => ['type' => 'string']], ['a']);

        self::assertSame(
            $viaArray->toJsonSchema(),
            $viaInstance->toJsonSchema(),
            'The two spellings must produce one wire format, not two.',
        );
        self::assertSame($viaArray->errors(['a' => 1]), $viaInstance->errors(['a' => 1]));
        self::assertSame($viaArray->errors(['a' => 'ok']), $viaInstance->errors(['a' => 'ok']));
    }

    public function testNestingIsUnwrappedAtEveryDepth(): void
    {
        $schema = Schema::object([
            'a' => Schema::string(),
            'n' => Schema::object(['deep' => Schema::string()], ['deep']),
        ], ['a']);

        self::assertSame(
            [
                'type' => 'object',
                'properties' => [
                    'a' => ['type' => 'string'],
                    'n' => ['type' => 'object', 'properties' => ['deep' => ['type' => 'string']], 'required' => ['deep']],
                ],
                'required' => ['a'],
            ],
            $schema->toJsonSchema(),
        );

        $schema->validate(['a' => 'ok', 'n' => ['deep' => 'x']]);

        foreach ([['a' => 'ok', 'n' => ['deep' => 9]], ['a' => 'ok', 'n' => []]] as $bad) {
            try {
                $schema->validate($bad);
                self::fail('must reject ' . json_encode($bad));
            } catch (ToolException) {
                self::assertTrue(true);
            }
        }
    }

    public function testATypeUnionSurvivesTheUnwrap(): void
    {
        // A union is an ordinary nested array, not a Schema. Normalising must
        // not "helpfully" collapse or discard it.
        $schema = Schema::object(['u' => ['type' => ['string', 'null']]]);

        self::assertSame(
            ['type' => 'object', 'properties' => ['u' => ['type' => ['string', 'null']]], 'required' => []],
            $schema->toJsonSchema(),
        );
        $schema->validate(['u' => null]);
        self::assertTrue(true, 'a null is valid for a string|null union');
    }

    public function testAnEmptySchemaIsUnaffected(): void
    {
        self::assertSame(['type' => 'object', 'properties' => []], Schema::any()->toJsonSchema());
        self::assertSame([], Schema::any()->errors(['anything' => 'goes']));
    }
}
