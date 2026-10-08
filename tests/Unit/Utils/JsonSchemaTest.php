<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Utils;

use LangChain\Tools\Schema;
use LangChain\Utils\FunctionCalling;
use LangChain\Utils\JsonSchema;
use LangChain\Utils\Testing\FakeTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of `utils/tests/json_schema.test.ts`.
 *
 * The `with zod v4 schemas` block is skipped: Zod does not exist in PHP and the
 * port is JSON-Schema-native (COMPLETION_PLAN section 1). The `with Standard JSON
 * Schema` and `caching` blocks are converted; "same reference" becomes "the
 * conversion callback ran once", since PHP arrays have no identity.
 */
#[CoversClass(JsonSchema::class)]
final class JsonSchemaTest extends TestCase
{
    private const OBJECT_SCHEMA = [
        'type' => 'object',
        'properties' => ['name' => ['type' => 'string'], 'age' => ['type' => 'number']],
        'required' => ['name', 'age'],
    ];

    /**
     * A Standard JSON Schema object whose `input()` counts its calls.
     */
    private static function standardSchema(array $schema, ?\ArrayObject $log = null): \stdClass
    {
        $log ??= new \ArrayObject();

        return (object) ['~standard' => [
            'version' => 1,
            'vendor' => 'test',
            'jsonSchema' => [
                'input' => static function (array $params) use ($schema, $log): array {
                    $log[] = $params;

                    return $schema;
                },
                'output' => static fn (): array => $schema,
            ],
        ]];
    }

    public function testExtractsJsonSchemaViaStandardInput(): void
    {
        self::assertSame(self::OBJECT_SCHEMA, JsonSchema::toJsonSchema(self::standardSchema(self::OBJECT_SCHEMA)));
    }

    public function testExtractsJsonSchemaFromAnArrayShapedStandardSchema(): void
    {
        $standard = (array) self::standardSchema(self::OBJECT_SCHEMA);

        self::assertSame(self::OBJECT_SCHEMA, JsonSchema::toJsonSchema($standard));
    }

    public function testPassesTargetDraft07ToTheInputFunction(): void
    {
        $log = new \ArrayObject();

        JsonSchema::toJsonSchema(self::standardSchema(['type' => 'object'], $log));

        self::assertSame([['target' => 'draft-07']], $log->getArrayCopy());
    }

    public function testAPlainJsonSchemaPassesThrough(): void
    {
        self::assertSame(self::OBJECT_SCHEMA, JsonSchema::toJsonSchema(self::OBJECT_SCHEMA));
    }

    public function testASchemaObjectIsConvertedToItsJsonSchema(): void
    {
        self::assertSame(self::OBJECT_SCHEMA, JsonSchema::toJsonSchema(new Schema(self::OBJECT_SCHEMA)));
    }

    public function testCachesStandardJsonSchemaResultsPerSchemaObject(): void
    {
        $log = new \ArrayObject();
        $schema = self::standardSchema(['type' => 'object', 'properties' => ['x' => ['type' => 'number']]], $log);

        $first = JsonSchema::toJsonSchema($schema);
        $second = JsonSchema::toJsonSchema($schema);

        self::assertSame($first, $second);
        self::assertCount(1, $log, 'the second conversion must be served from the cache');
    }

    public function testManyRepeatedCallsConvertOnlyOncePerSchema(): void
    {
        $log = new \ArrayObject();
        $schemas = [];
        for ($i = 0; $i < 20; $i++) {
            $schemas[] = self::standardSchema(['type' => 'object', 'properties' => ["field_$i" => ['type' => 'string']]], $log);
        }

        for ($rep = 0; $rep < 50; $rep++) {
            foreach ($schemas as $s) {
                JsonSchema::toJsonSchema($s);
            }
        }

        self::assertCount(20, $log, 'N distinct schemas, N conversions, however many calls');
    }

    public function testDifferentSchemasGiveDifferentResults(): void
    {
        $one = JsonSchema::toJsonSchema(self::standardSchema(['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]));
        $two = JsonSchema::toJsonSchema(self::standardSchema(['type' => 'object', 'properties' => ['age' => ['type' => 'number']]]));

        self::assertNotEquals($one, $two);
    }

    public function testBypassesTheCacheWhenParamsAreProvided(): void
    {
        $log = new \ArrayObject();
        $schema = self::standardSchema(['type' => 'object'], $log);

        JsonSchema::toJsonSchema($schema);
        JsonSchema::toJsonSchema($schema, ['target' => 'draft-2020-12']);
        JsonSchema::toJsonSchema($schema, ['target' => 'draft-2020-12']);

        self::assertCount(3, $log);
    }

    public function testCacheKeepsTheContentCorrect(): void
    {
        $schema = new Schema([
            'type' => 'object',
            'properties' => ['title' => ['type' => 'string'], 'count' => ['type' => 'number'], 'active' => ['type' => 'boolean']],
            'required' => ['title', 'count', 'active'],
        ]);

        $first = JsonSchema::toJsonSchema($schema);
        $second = JsonSchema::toJsonSchema($schema);

        self::assertSame($first, $second);
        self::assertSame('object', $first['type']);
        self::assertSame(['title', 'count', 'active'], $first['required']);
    }

    public function testTheConvertToOpenAIFunctionPathIsServedFromTheCache(): void
    {
        // The real hot path: every model call converts every tool's schema.
        $log = new \ArrayObject();
        $toolLike = [
            'name' => 'get_weather',
            'description' => 'Get weather for a city',
            'schema' => self::standardSchema(['type' => 'object', 'properties' => ['city' => ['type' => 'string']]], $log),
        ];

        $first = FunctionCalling::convertToOpenAIFunction($toolLike);
        $second = FunctionCalling::convertToOpenAIFunction($toolLike);

        self::assertSame($first['parameters'], $second['parameters']);
        self::assertCount(1, $log);
    }

    public function testAFakeToolSchemaConvertsThroughTheSamePath(): void
    {
        $tool = new FakeTool(['name' => 't', 'description' => 'd', 'schema' => new Schema(self::OBJECT_SCHEMA)]);

        self::assertSame(self::OBJECT_SCHEMA, FunctionCalling::convertToOpenAIFunction($tool)['parameters']);
    }

    /**
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function stringOnlySchemas(): array
    {
        return [
            'null' => [null, false],
            'empty' => [[], false],
            'list' => [[['type' => 'string']], false],
            'scalar' => ['string', false],
            'type string' => [['type' => 'string'], true],
            'type number' => [['type' => 'number'], false],
            'type array of strings' => [['type' => ['string', 'string']], true],
            'type array mixed' => [['type' => ['string', 'null']], false],
            'type invalid' => [['type' => 5], false],
            'enum strings' => [['enum' => ['a', 'b']], true],
            'enum mixed' => [['enum' => ['a', 1]], false],
            'enum empty' => [['enum' => []], false],
            'const string' => [['const' => 'x'], true],
            'const number' => [['const' => 1], false],
            'allOf with one string schema' => [['allOf' => [['type' => 'object'], ['type' => 'string']]], true],
            'allOf with none' => [['allOf' => [['type' => 'object']]], false],
            'anyOf all strings' => [['anyOf' => [['type' => 'string'], ['const' => 'a']]], true],
            'anyOf one non-string' => [['anyOf' => [['type' => 'string'], ['type' => 'number']]], false],
            'oneOf all strings' => [['oneOf' => [['type' => 'string'], ['enum' => ['q']]]], true],
            'anyOf empty' => [['anyOf' => []], false],
            'not' => [['not' => ['type' => 'number']], false],
            'resolvable ref to string' => [['$ref' => '#/definitions/s', 'definitions' => ['s' => ['type' => 'string']]], true],
            'resolvable ref to number' => [['$ref' => '#/definitions/s', 'definitions' => ['s' => ['type' => 'number']]], false],
            'unresolvable ref' => [['$ref' => '#/definitions/missing'], false],
            'remote ref' => [['$ref' => 'https://example.com/s.json'], false],
            'no constraint' => [['description' => 'anything'], false],
        ];
    }

    #[DataProvider('stringOnlySchemas')]
    public function testValidatesOnlyStrings(mixed $schema, bool $expected): void
    {
        self::assertSame($expected, JsonSchema::validatesOnlyStrings($schema));
    }
}
