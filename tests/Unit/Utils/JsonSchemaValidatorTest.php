<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Utils;

use LangChain\Utils\JsonSchemaValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/** Public core JSON Schema validation and its preserved decoded-JSON/error contracts. */
#[CoversClass(JsonSchemaValidator::class)]
final class JsonSchemaValidatorTest extends TestCase
{
    public function testStructuredIssuesPreserveOrderPathsAndAjvPointerEscaping(): void
    {
        $schema = [
            'type' => 'object',
            'required' => ['missing'],
            'properties' => ['a/b~c' => ['type' => 'array', 'items' => ['type' => 'boolean']]],
            'additionalProperties' => false,
        ];

        self::assertSame([
            ['path' => [], 'message' => "data must have required property 'missing'"],
            ['path' => ['a/b~c', 1], 'message' => 'data/a~1b~0c/1 must be boolean'],
            ['path' => [], 'message' => 'data must NOT have additional properties'],
        ], JsonSchemaValidator::validate(['a/b~c' => [true, 'yes'], 'extra' => 1], $schema));
    }

    public function testBooleanAndEmptySchemasKeepTheEstablishedApi(): void
    {
        self::assertSame([], JsonSchemaValidator::validate(['a' => 1], true));
        self::assertSame([], JsonSchemaValidator::validate(['a' => 1], []));
        self::assertSame([], JsonSchemaValidator::validate(['a' => 1], null));
        self::assertSame([['path' => [], 'message' => 'data boolean schema is false']], JsonSchemaValidator::validate(['a' => 1], false));
    }

    public function testLocalReferencesDecodePointerSegmentsAndKeepSiblingConstraints(): void
    {
        $schema = [
            '$defs' => ['a/b~c' => ['type' => 'integer']],
            'properties' => ['value' => ['$ref' => '#/$defs/a~1b~0c', 'minimum' => 2]],
        ];

        self::assertSame([], JsonSchemaValidator::validate(['value' => 3], $schema));
        self::assertSame([['path' => ['value'], 'message' => 'data/value must be >= 2']], JsonSchemaValidator::validate(['value' => 1], $schema));
        self::assertSame([['path' => ['value'], 'message' => 'data/value must be integer']], JsonSchemaValidator::validate(['value' => 'three'], $schema));
    }

    public function testValidationDoesNotTransformTheInstanceOrRewriteTheSchema(): void
    {
        $value = ['count' => '3'];
        $schema = ['type' => 'object', 'properties' => ['count' => ['type' => 'integer', 'default' => 0]]];
        $beforeValue = $value;
        $beforeSchema = $schema;

        self::assertSame([['path' => ['count'], 'message' => 'data/count must be integer']], JsonSchemaValidator::validate($value, $schema));
        self::assertSame($beforeValue, $value);
        self::assertSame($beforeSchema, $schema);
    }

    public function testCompositionTracksEvaluatedPropertiesForTheSharedCoreApi(): void
    {
        $schema = [
            'allOf' => [['properties' => ['kind' => ['enum' => ['a', 'b']]]]],
            'if' => ['properties' => ['kind' => ['const' => 'a']], 'required' => ['kind']],
            'then' => ['properties' => ['value' => ['type' => 'number']], 'required' => ['value']],
            'else' => ['properties' => ['label' => ['type' => 'string']], 'required' => ['label']],
            'unevaluatedProperties' => false,
        ];

        self::assertSame([], JsonSchemaValidator::validate(['kind' => 'a', 'value' => 3], $schema));
        self::assertSame([], JsonSchemaValidator::validate(['kind' => 'b', 'label' => 'three'], $schema));
        self::assertSame([['path' => [], 'message' => 'data must NOT have unevaluated properties']], JsonSchemaValidator::validate(['kind' => 'a', 'value' => 3, 'extra' => true], $schema));
    }

    public function testLegacyTupleItemsAndAdditionalItemsRetainTheirBehavior(): void
    {
        $schema = ['type' => 'array', 'items' => [['type' => 'integer'], ['type' => 'string']], 'additionalItems' => false];

        self::assertSame([], JsonSchemaValidator::validate([1, 'a'], $schema));
        self::assertSame([['path' => [2], 'message' => 'data/2 boolean schema is false']], JsonSchemaValidator::validate([1, 'a', true], $schema));
    }

    public function testCallsHaveIndependentRootsAndProduceOnlyTheOriginalIssueFields(): void
    {
        $ref = ['$ref' => '#/$defs/N', '$defs' => ['N' => ['type' => 'integer']]];
        self::assertSame([], JsonSchemaValidator::validate(3, $ref));
        $issues = JsonSchemaValidator::validate(3, ['$ref' => '#/$defs/N', '$defs' => ['N' => ['type' => 'string']]]);

        self::assertSame([['path' => [], 'message' => 'data must be string']], $issues);
        self::assertSame(['path', 'message'], array_keys($issues[0]), 'the compatibility API never exposed a keyword field');
        self::assertSame([], JsonSchemaValidator::validate(3, $ref));
    }

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testCoreValidationWorksWithoutLoadingAnyGraphClass(): void
    {
        self::assertSame([], array_values(array_filter(get_declared_classes(), static fn (string $class): bool => str_starts_with($class, 'LangGraph\\'))));

        self::assertSame([], JsonSchemaValidator::validate(['ok' => true], ['properties' => ['ok' => ['type' => 'boolean']]]));

        self::assertSame([], array_values(array_filter(get_declared_classes(), static fn (string $class): bool => str_starts_with($class, 'LangGraph\\'))));
    }
}
