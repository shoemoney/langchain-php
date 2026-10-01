<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Tools;

use LangChain\Tools\Schema;
use PHPUnit\Framework\TestCase;

/**
 * Upstream `libs/langchain-core/src/utils/json_schema.ts` deliberately uses DIFFERENT quantifiers:
 *
 *   allOf         -> `.some()`  "if any subschema validates only strings, so does the whole schema"
 *   anyOf / oneOf -> `.every()` "all subschemas must validate only strings"
 *
 * The port had the two branches' quantifiers exchanged. That matters beyond naming:
 * `createTool.php:77` branches on `validatesOnlyStrings()` to pick the tool's input path, so a tool
 * declaring `anyOf: [string, number]` was routed down the string path even though it accepts numbers.
 *
 * `testPlainStringAndNonStringSchemasStillRouteCorrectly` is the positive control: without it, an
 * implementation that returned a constant would satisfy the quantifier tests.
 */
final class SchemaStringOnlyQuantifiersTest extends TestCase
{
    public function testAnyOfRequiresEverySubschemaToBeStringOnly(): void
    {
        // upstream: subschemas.every(...) -> false, because {type: number} does not validate only strings
        self::assertFalse(
            Schema::from(['anyOf' => [['type' => 'string'], ['type' => 'number']]])->validatesOnlyStrings()
        );
    }

    public function testAnyOfOfOnlyStringBranchesIsStringOnly(): void
    {
        self::assertTrue(
            Schema::from(['anyOf' => [['type' => 'string'], ['type' => 'string']]])->validatesOnlyStrings()
        );
    }

    public function testAllOfNeedsOnlyOneStringOnlySubschema(): void
    {
        // upstream: subschemas.some(...) -> true, because {type: string} validates only strings
        self::assertTrue(
            Schema::from(['allOf' => [['type' => 'string'], ['type' => 'number']]])->validatesOnlyStrings()
        );
    }

    public function testAllOfWithNoStringOnlySubschemaIsNotStringOnly(): void
    {
        self::assertFalse(
            Schema::from(['allOf' => [['type' => 'number'], ['type' => 'boolean']]])->validatesOnlyStrings()
        );
    }

    /** Positive control: the unremarkable cases must be unaffected by the quantifier swap. */
    public function testPlainStringAndNonStringSchemasStillRouteCorrectly(): void
    {
        self::assertTrue(Schema::from(['type' => 'string'])->validatesOnlyStrings());
        self::assertFalse(Schema::from(['type' => 'number'])->validatesOnlyStrings());
        self::assertFalse(Schema::from([])->validatesOnlyStrings());
        self::assertTrue(Schema::from(['enum' => ['a', 'b']])->validatesOnlyStrings());
        self::assertFalse(Schema::from(['enum' => ['a', 1]])->validatesOnlyStrings());
        self::assertTrue(Schema::from(['const' => 'x'])->validatesOnlyStrings());
        self::assertFalse(Schema::from(['const' => 1])->validatesOnlyStrings());
    }
}
