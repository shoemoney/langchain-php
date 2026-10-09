<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp;

use LangGraph\Mcp\JsonSchemaValidator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The validator stands in for the MCP SDK's `fromJsonSchema`; these pin the keyword semantics the converted
 * tools and elicitation answers depend on.
 */
#[CoversClass(JsonSchemaValidator::class)]
final class JsonSchemaValidatorTest extends TestCase
{
    /** @return array<string, array{0: mixed, 1: mixed, 2: bool}> */
    public static function cases(): array
    {
        $ref = ['type' => 'object', 'properties' => ['a' => ['$ref' => '#/$defs/N']], '$defs' => ['N' => ['type' => 'integer', 'minimum' => 1]]];
        $conditional = [
            'type' => 'object',
            'if' => ['properties' => ['kind' => ['const' => 'a']], 'required' => ['kind']],
            'then' => ['required' => ['x']],
            'else' => ['required' => ['y']],
        ];

        return [
            'boolean true schema' => [1, true, true],
            'boolean false schema' => [1, false, false],
            'empty object is an object' => [[], ['type' => 'object'], true],
            'empty array is an array' => [[], ['type' => 'array'], true],
            'list is not an object' => [[1], ['type' => 'object'], false],
            'integral float is an integer' => [3.0, ['type' => 'integer'], true],
            'fractional float is not' => [3.5, ['type' => 'integer'], false],
            'bool is not a number' => [true, ['type' => 'number'], false],
            'type union' => [null, ['type' => ['string', 'null']], true],
            'enum hit' => ['b', ['enum' => ['a', 'b']], true],
            'enum compares numbers numerically' => [3.0, ['enum' => [3]], true],
            'enum miss' => ['c', ['enum' => ['a', 'b']], false],
            'const object order is irrelevant' => [['b' => 1, 'a' => 2], ['const' => ['a' => 2, 'b' => 1]], true],
            'required present' => [['a' => 1], ['required' => ['a']], true],
            'required missing' => [['b' => 1], ['required' => ['a']], false],
            'required on an empty object' => [[], ['required' => ['a']], false],
            'additionalProperties false' => [['a' => 1, 'z' => 1], ['properties' => ['a' => []], 'additionalProperties' => false], false],
            'additionalProperties schema' => [['a' => 1, 'z' => 'x'], ['properties' => ['a' => []], 'additionalProperties' => ['type' => 'integer']], false],
            'patternProperties' => [['x1' => 1], ['patternProperties' => ['^x' => ['type' => 'string']]], false],
            'ref resolved' => [['a' => 0], $ref, false],
            'ref satisfied' => [['a' => 2], $ref, true],
            'unresolvable ref' => [1, ['$ref' => '#/$defs/Missing'], false],
            'allOf conjunction' => [['a' => 1], ['allOf' => [['required' => ['a']], ['required' => ['b']]]], false],
            'anyOf disjunction' => [1, ['anyOf' => [['type' => 'string'], ['type' => 'integer']]], true],
            'oneOf exactly one' => [1, ['oneOf' => [['type' => 'string'], ['type' => 'integer']]], true],
            'oneOf both match' => [1, ['oneOf' => [['type' => 'number'], ['type' => 'integer']]], false],
            'oneOf none match' => [true, ['oneOf' => [['type' => 'number'], ['type' => 'string']]], false],
            'not' => ['x', ['not' => ['type' => 'string']], false],
            'if/then satisfied' => [['kind' => 'a', 'x' => 1], $conditional, true],
            'if/then violated' => [['kind' => 'a'], $conditional, false],
            'if/else satisfied' => [['kind' => 'b', 'y' => 1], $conditional, true],
            'if/else violated' => [['kind' => 'b'], $conditional, false],
            'numeric bounds' => [5, ['minimum' => 1, 'maximum' => 4], false],
            'exclusive bound' => [4, ['exclusiveMaximum' => 4], false],
            'multipleOf' => [9, ['multipleOf' => 3], true],
            'string length' => ['ab', ['minLength' => 3], false],
            'pattern' => ['abc', ['pattern' => '^b'], false],
            'minItems' => [[1], ['type' => 'array', 'minItems' => 2], false],
            'uniqueItems' => [[1, 1], ['uniqueItems' => true], false],
            'items' => [[1, 'x'], ['items' => ['type' => 'integer']], false],
            'tuple prefixItems' => [[1, 'x'], ['prefixItems' => [['type' => 'integer'], ['type' => 'string']]], true],
            'contains' => [[1, 2], ['contains' => ['type' => 'string']], false],
            'unevaluatedProperties sees through allOf' => [['a' => 1], ['allOf' => [['properties' => ['a' => []]]], 'unevaluatedProperties' => false], true],
            'unevaluatedProperties rejects the rest' => [['a' => 1, 'b' => 2], ['allOf' => [['properties' => ['a' => []]]], 'unevaluatedProperties' => false], false],
            'unknown keywords are ignored' => [1, ['x-provider' => ['choices' => [1]], 'format' => 'email'], true],
        ];
    }

    #[DataProvider('cases')]
    public function testValidates(mixed $value, mixed $schema, bool $valid): void
    {
        self::assertSame($valid, JsonSchemaValidator::validate($value, $schema) === []);
    }

    public function testMessagesAreAjvStyleWithAnInstancePointer(): void
    {
        $schema = ['type' => 'object', 'properties' => ['a' => ['type' => 'object', 'properties' => ['b' => ['type' => 'boolean']]]], 'required' => ['c']];

        $messages = array_column(JsonSchemaValidator::validate(['a' => ['b' => 'yes']], $schema), 'message');

        self::assertContains('data/a/b must be boolean', $messages);
        self::assertContains("data must have required property 'c'", $messages);
        self::assertSame(['data must be object'], array_column(JsonSchemaValidator::validate('x', $schema), 'message'));
    }

    public function testIssuePathsAreStructured(): void
    {
        $issues = JsonSchemaValidator::validate(['items' => [1, 'x']], ['properties' => ['items' => ['items' => ['type' => 'integer']]]]);

        self::assertSame(['items', 1], $issues[0]['path']);
        self::assertSame('data/items/1 must be integer', $issues[0]['message']);
    }
}
