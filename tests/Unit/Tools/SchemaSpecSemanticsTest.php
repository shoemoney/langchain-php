<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Tools;

use LangChain\Tools\{DynamicStructuredTool, Schema, ToolException};
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Three places where this port's validation was stricter — or looser — than
 * JSON Schema itself, each found by probing rather than by reading.
 */
#[CoversClass(Schema::class)]
final class SchemaSpecSemanticsTest extends TestCase
{
    /**
     * `"arguments": "{}"` decodes to `[]` in PHP, and `Js::isList([])` is true,
     * so `matchesType([], 'object')` was false and the type check errored before
     * the `checkSub()` disambiguation that exists precisely to resolve this.
     *
     * A tool declared with an object schema and nothing required could therefore
     * never be called with no arguments — and models really do send `{}`.
     */
    public function testAnEmptyArgumentObjectSatisfiesAnObjectSchema(): void
    {
        $schema = Schema::object(['q' => Schema::string()], []);

        $schema->validate([]);   // must not throw

        $tool = new DynamicStructuredTool(
            ['name' => 'noop', 'description' => 'takes nothing', 'schema' => $schema],
            static fn (array $a): string => 'ran',
        );
        self::assertSame('ran', $tool->invoke([]));
    }

    /**
     * …but only where the schema genuinely declares an object. Widening must not
     * turn `required` off or break a schema that really means a list.
     */
    public function testWideningTheEmptyCaseDoesNotWeakenRequiredOrListTypes(): void
    {
        $required = Schema::object(['q' => Schema::string()], ['q']);
        try {
            $required->validate([]);
            self::fail('a missing required key must still be rejected');
        } catch (ToolException) {
            self::assertTrue(true);
        }

        $listSchema = Schema::from(['type' => 'array']);
        $listSchema->validate([]);   // a bare array type still means a list

        // And a value that is neither empty nor an object is still refused.
        $typed = Schema::from(['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]);
        try {
            $typed->validate('not an object');
            self::fail('a string must not satisfy an object schema');
        } catch (ToolException) {
            self::assertTrue(true);
        }
    }

    /**
     * `allOf` is a conjunction WITH its siblings, not a replacement for them.
     *
     * The branch ended in `return`, so once the clauses held the sibling
     * keywords never ran. Measured: this schema accepted `{"a": 1, "b": "y"}`
     * with `a` declared a string and holding an integer — a fail-open, where the
     * schema looks declared and the constraint is skipped.
     */
    public function testAllOfDoesNotSkipItsSiblingConstraints(): void
    {
        $schema = Schema::from([
            'type' => 'object',
            'properties' => ['a' => ['type' => 'string']],
            'allOf' => [['required' => ['b']]],
        ]);

        // The allOf clause is satisfied, so the SIBLING property check must run.
        $schema->validate(['a' => 'ok', 'b' => 'y']);

        $this->expectException(ToolException::class);
        $schema->validate(['a' => 1, 'b' => 'y']);
    }

    /** The allOf clause itself is still enforced, which is why the fix was a
     *  fall-through and not a removal. */
    public function testAllOfStillEnforcesItsOwnClauses(): void
    {
        $schema = Schema::from([
            'type' => 'object',
            'properties' => ['a' => ['type' => 'string']],
            'allOf' => [['required' => ['b']]],
        ]);

        $this->expectException(ToolException::class);
        $schema->validate(['a' => 'ok']);   // `b` missing, from the allOf clause
    }

    /**
     * JSON Schema 2019-09+ defines `integer` as a number with a zero fractional
     * part, and `json_decode('3.0')` yields a float. Measured: an integer field
     * holding `3.0` was refused with "expected integer, got number", so a model
     * writing `3.0` had its whole tool call rejected.
     */
    public function testIntegerAcceptsAZeroFractionFloat(): void
    {
        $schema = Schema::from(['type' => 'integer']);

        $schema->validate(3);
        $schema->validate(3.0);
        $schema->validate(0.0);
        $schema->validate(-2.0);

        $this->expectException(ToolException::class);
        $schema->validate(3.5);
    }

    /** Infinity is not an integer, and must not be laundered into one by the
     *  new float branch. */
    public function testInfinityIsNotAnInteger(): void
    {
        $schema = Schema::from(['type' => 'integer']);

        $this->expectException(ToolException::class);
        $schema->validate(INF);
    }
}
