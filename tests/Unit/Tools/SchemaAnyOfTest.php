<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Tools;

use LangChain\Tools\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `anyOf` must decide the string path the same way `allOf` already does.
 *
 * `check()` validated a bare string through an `anyOf` spelling, but
 * `validatesOnlyStrings()` — the method that decides whether a tool takes the
 * bare-string path or the structured one — did not look at `anyOf` at all. A
 * tool declaring `{"anyOf": [{"type": "string"}]}` therefore passed validation
 * for a bare string and was then handed the STRUCTURED path, where the value
 * arrives as `{"input": "..."}`. The tool body sees a different shape than the
 * schema it declared, with no error anywhere.
 *
 * CORRECTION (iteration 314): two expectations below asserted the SWAPPED quantifiers — `anyOf`
 * was pinned to `.some()` and `allOf` to `.every()`, the exact inverse of upstream. A test written
 * against the buggy implementation is how the swap survived review; the authoritative expectations
 * now come from `SchemaStringOnlyQuantifiersTest`, which fails against the old code.
 */
#[CoversClass(Schema::class)]
final class SchemaAnyOfTest extends TestCase
{
    /** @return iterable<string, array{array<string, mixed>, bool}> */
    public static function schemas(): iterable
    {
        yield 'anyOf of one string' => [['anyOf' => [['type' => 'string']]], true];
        // Upstream `json_schema.ts` uses `.every()` for anyOf: "All subschemas must validate only
        // strings." This expectation was `true` — the `.some()` rule — which enshrined the swapped
        // quantifiers this suite now covers in SchemaStringOnlyQuantifiersTest.
        yield 'anyOf including a non-string' => [['anyOf' => [['type' => 'string'], ['type' => 'integer']]], false];
        yield 'anyOf of no strings' => [['anyOf' => [['type' => 'integer'], ['type' => 'boolean']]], false];
        yield 'empty anyOf' => [['anyOf' => []], false];
        yield 'anyOf wrapping allOf' => [['anyOf' => [['allOf' => [['type' => 'string']]]]], true];
        yield 'anyOf of a string enum' => [['anyOf' => [['enum' => ['a', 'b']]]], true];

        // the shapes that already worked, pinned so the fix cannot regress them
        yield 'allOf of strings' => [['allOf' => [['type' => 'string']]], true];
        // Upstream uses `.some()` for allOf: "If any subschema validates only strings, then the
        // overall schema validates only strings." Was `false` — the `.every()` rule.
        yield 'allOf incl. a string' => [['allOf' => [['type' => 'string'], ['type' => 'integer']]], true];
        yield 'plain string type' => [['type' => 'string'], true];
        yield 'type array of strings' => [['type' => ['string']], true];
        yield 'type array with a non-string' => [['type' => ['string', 'null']], false];
        yield 'empty schema' => [[], false];
    }

    #[DataProvider('schemas')]
    public function testStringPathDecision(array $schema, bool $expected): void
    {
        self::assertSame($expected, (new Schema($schema))->validatesOnlyStrings());
    }

    /**
     * The two methods must agree: whatever `check()` accepts as a bare string
     * has to be routed down the bare-string path.
     */
    public function testTheDecisionAgreesWithWhatTheSchemaAccepts(): void
    {
        $schema = new Schema(['anyOf' => [['type' => 'string']]]);

        self::assertSame([], $schema->errors('hello'), 'a bare string is valid');
        self::assertTrue($schema->validatesOnlyStrings(), 'so it must take the string path');
    }
}
