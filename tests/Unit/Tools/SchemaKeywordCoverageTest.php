<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Tools;

use LangChain\Tools\Schema;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `allOf` is a conjunction, and the object keywords do not need a `type`.
 *
 * Two silent validation gaps: `allOf` was not handled in `check()` at all, so a
 * schema declared through it constrained nothing; and a `$type === null` schema
 * returned before `properties`/`required` were ever examined, so
 * `{"properties": {"a": {"type": "string"}}}` — which constrains `a` with no
 * top-level `type`, exactly as JSON Schema specifies — enforced nothing.
 *
 * In both cases arguments that violated the declaration reached the tool body.
 */
#[CoversClass(Schema::class)]
final class SchemaKeywordCoverageTest extends TestCase
{
    public function testAllOfEnforcesEverySubschema(): void
    {
        $schema = new Schema([
            'allOf' => [
                ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']],
            ],
        ]);

        $this->assertNotSame([], $schema->errors([]), 'a missing required key must be caught');
        $this->assertNotSame([], $schema->errors(['a' => 1]), 'a wrong type must be caught');
        $this->assertSame([], $schema->errors(['a' => 'x']), 'a conforming value must pass');
    }

    public function testAllOfRequiresEveryBranchToHold(): void
    {
        $schema = new Schema([
            'allOf' => [
                ['type' => 'object', 'required' => ['a']],
                ['type' => 'object', 'required' => ['b']],
            ],
        ]);

        $this->assertNotSame([], $schema->errors([]), 'both branches are required');
        $this->assertNotSame([], $schema->errors(['a' => 1]), 'the second branch still applies');
        $this->assertSame([], $schema->errors(['a' => 1, 'b' => 2]));
    }

    public function testPropertiesAreEnforcedWithoutATopLevelType(): void
    {
        $schema = new Schema(['properties' => ['a' => ['type' => 'string']]]);

        $this->assertNotSame([], $schema->errors(['a' => 1]), 'the property type must apply');
        $this->assertSame([], $schema->errors(['a' => 'x']));
    }

    public function testRequiredIsEnforcedWithoutATopLevelType(): void
    {
        $schema = new Schema(['properties' => ['a' => ['type' => 'string']], 'required' => ['a']]);

        $this->assertNotSame([], $schema->errors([]), 'required applies with no type');
        $this->assertSame([], $schema->errors(['a' => 'x']));
    }

    public function testAdditionalPropertiesIsEnforcedWithoutATopLevelType(): void
    {
        $schema = new Schema([
            'properties' => ['a' => ['type' => 'string']],
            'additionalProperties' => false,
        ]);

        $this->assertNotSame([], $schema->errors(['a' => 'x', 'b' => 1]));
        $this->assertSame([], $schema->errors(['a' => 'x']));
    }

    /**
     * The `type`-bearing shape must be unchanged — these fixes must not move
     * anything that already worked.
     */
    public function testTypedSchemasStillBehaveTheSame(): void
    {
        $schema = new Schema(['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']]);

        $this->assertSame([], $schema->errors(['a' => 'x']));
        $this->assertNotSame([], $schema->errors(['a' => 1]));
        $this->assertNotSame([], $schema->errors([]));
    }
}
