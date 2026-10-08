<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store;

use LangGraph\Store\StoreUtils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of `checkpoint/src/tests/utils.test.ts` plus the helpers it leaves
 * implicit (`tokenizePath`, `compareValues`, `cosineSimilarity`).
 */
#[CoversClass(StoreUtils::class)]
final class StoreUtilsTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function nestedData(): array
    {
        return [
            'name' => 'test',
            'info' => [
                'age' => 25,
                'tags' => ['a', 'b', 'c'],
                'metadata' => ['created' => '2024-01-01', 'updated' => '2024-01-02'],
            ],
            'items' => [
                ['id' => 1, 'value' => 'first', 'tags' => ['x', 'y']],
                ['id' => 2, 'value' => 'second', 'tags' => ['y', 'z']],
                ['id' => 3, 'value' => 'third', 'tags' => ['z', 'w']],
            ],
            'empty' => null,
            'numbers' => [0, 0.1, '0'],
            'emptyList' => [],
            'emptyDict' => new \stdClass(),
        ];
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private static function set(array $values): array
    {
        $values = array_values(array_unique($values));
        sort($values);

        return $values;
    }

    public function testExtractsTextFromNestedData(): void
    {
        $data = self::nestedData();
        $whole = StoreUtils::stringify($data);

        self::assertSame([$whole], StoreUtils::getTextAtPath($data, '$'));
        self::assertSame([$whole], StoreUtils::getTextAtPath($data, ''));

        self::assertSame(['test'], StoreUtils::getTextAtPath($data, 'name'));
        self::assertSame(['25'], StoreUtils::getTextAtPath($data, 'info.age'));
        self::assertSame(['2024-01-01'], StoreUtils::getTextAtPath($data, 'info.metadata.created'));

        self::assertSame(['first'], StoreUtils::getTextAtPath($data, 'items[0].value'));
        self::assertSame(['third'], StoreUtils::getTextAtPath($data, 'items[-1].value'));
        self::assertSame(['y'], StoreUtils::getTextAtPath($data, 'items[1].tags[0]'));

        self::assertSame(self::set(['first', 'second', 'third']), self::set(StoreUtils::getTextAtPath($data, 'items[*].value')));
        self::assertSame(self::set(['2024-01-01', '2024-01-02']), self::set(StoreUtils::getTextAtPath($data, 'info.metadata.*')));
        self::assertSame(self::set(['test', '25']), self::set(StoreUtils::getTextAtPath($data, '{name,info.age}')));
        self::assertSame(
            self::set(['1', '2', '3', 'first', 'second', 'third']),
            self::set(StoreUtils::getTextAtPath($data, 'items[*].{id,value}')),
        );
        self::assertSame(self::set(['x', 'y', 'z', 'w']), self::set(StoreUtils::getTextAtPath($data, 'items[*].tags[*]')));

        self::assertSame([], StoreUtils::getTextAtPath(null, 'any.path'));
        self::assertSame([], StoreUtils::getTextAtPath([], 'any.path'));
        self::assertSame([], StoreUtils::getTextAtPath($data, 'nonexistent'));
        self::assertSame([], StoreUtils::getTextAtPath($data, 'items[99].value'));
        self::assertSame([], StoreUtils::getTextAtPath($data, 'items[*].nonexistent'));

        self::assertSame([], StoreUtils::getTextAtPath($data, 'empty'));
        self::assertSame(['[]'], StoreUtils::getTextAtPath($data, 'emptyList'));
        self::assertSame(['{}'], StoreUtils::getTextAtPath($data, 'emptyDict'));

        self::assertSame(['0', '0.1'], self::set(StoreUtils::getTextAtPath($data, 'numbers[*]')));

        self::assertSame([], StoreUtils::getTextAtPath($data, 'items[].value'));
        self::assertSame([], StoreUtils::getTextAtPath($data, 'items[abc].value'));
        self::assertSame([], StoreUtils::getTextAtPath($data, '{unclosed'));
        self::assertSame([], StoreUtils::getTextAtPath($data, 'nested[{invalid}]'));
    }

    public function testWholeDocumentTextIsTwoSpaceIndentedJson(): void
    {
        self::assertSame(
            "{\n  \"a\": [\n    1,\n    {\n      \"b\": \"x/y é\"\n    }\n  ]\n}",
            StoreUtils::stringify(['a' => [1, ['b' => 'x/y é']]]),
        );
        self::assertSame('[]', StoreUtils::stringify([]));
        self::assertSame('{}', StoreUtils::stringify(new \stdClass()));
    }

    public function testScalarLeavesAreStringifiedLikeJavaScript(): void
    {
        self::assertSame(['true'], StoreUtils::getTextAtPath(['a' => true], 'a'));
        self::assertSame(['false'], StoreUtils::getTextAtPath(['a' => false], 'a'));
        self::assertSame(['1'], StoreUtils::getTextAtPath(['a' => 1.0], 'a'));
        self::assertSame(['0.1'], StoreUtils::getTextAtPath(['a' => 0.1], 'a'));
    }

    public function testAPathMayBeSuppliedAlreadyTokenized(): void
    {
        self::assertSame(['25'], StoreUtils::getTextAtPath(self::nestedData(), ['info', 'age']));
    }

    /**
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function tokenizeCases(): iterable
    {
        yield 'empty' => ['', []];
        yield 'plain' => ['metadata.title', ['metadata', 'title']];
        yield 'array wildcard' => ['chapters[*].content', ['chapters', '[*]', 'content']];
        yield 'negative index' => ['array[-1]', ['array', '[-1]']];
        yield 'multi field' => ['{a,b.c}', ['{a,b.c}']];
        yield 'after multi field' => ['items[*].{id,value}', ['items', '[*]', '{id,value}']];
        yield 'nested brackets' => ['a[b[0]]', ['a', '[b[0]]']];
        yield 'unclosed' => ['{unclosed', ['{unclosed']];
        yield 'dots collapse' => ['a..b', ['a', 'b']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('tokenizeCases')]
    public function testTokenizePath(string $path, array $expected): void
    {
        self::assertSame($expected, StoreUtils::tokenizePath($path));
    }

    /**
     * @return iterable<string, array{0: mixed, 1: mixed, 2: bool}>
     */
    public static function compareCases(): iterable
    {
        yield 'equal string' => ['a', 'a', true];
        yield 'unequal string' => ['a', 'b', false];
        yield 'int is float' => [1, 1.0, true];
        yield 'strict type' => ['1', 1, false];
        yield 'eq op' => [5, ['$eq' => 5], true];
        yield 'ne op' => [5, ['$ne' => 5], false];
        yield 'gt op' => [5, ['$gt' => 4.99], true];
        yield 'gt op boundary' => [5, ['$gt' => 5], false];
        yield 'gte op' => [5, ['$gte' => 5], true];
        yield 'lt op' => [3, ['$lt' => 5], true];
        yield 'lte op' => [6, ['$lte' => 5], false];
        yield 'numeric string coerces' => ['7', ['$gt' => 5], true];
        yield 'non numeric never compares' => ['abc', ['$lt' => 5], false];
        yield 'missing value never compares' => [null, ['$gt' => 5], false];
        yield 'in op' => ['a', ['$in' => ['a', 'b']], true];
        yield 'in op miss' => ['c', ['$in' => ['a', 'b']], false];
        yield 'in op with a non-list' => ['a', ['$in' => 'a'], false];
        yield 'nin op' => ['c', ['$nin' => ['a', 'b']], true];
        yield 'nin op with a non-list' => ['a', ['$nin' => 'a'], true];
        yield 'all operators must hold' => [5, ['$gte' => 3, '$lt' => 5], false];
        yield 'unknown operator is a literal' => [5, ['$foo' => 5], false];
        yield 'equal lists compare by value, where JS compares by reference' => [[1], [1], true];
    }

    #[DataProvider('compareCases')]
    public function testCompareValues(mixed $item, mixed $filter, bool $expected): void
    {
        self::assertSame($expected, StoreUtils::compareValues($item, $filter));
    }

    public function testCosineSimilarity(): void
    {
        self::assertEqualsWithDelta(1.0, StoreUtils::cosineSimilarity([1, 2, 3], [1, 2, 3]), 1e-12);
        self::assertEqualsWithDelta(-1.0, StoreUtils::cosineSimilarity([1, 0], [-1, 0]), 1e-12);
        self::assertEqualsWithDelta(0.0, StoreUtils::cosineSimilarity([1, 0], [0, 1]), 1e-12);
        self::assertSame(0.0, StoreUtils::cosineSimilarity([0, 0], [1, 1]));
    }

    public function testCosineSimilarityRejectsMismatchedLengths(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Vectors must have the same length');

        StoreUtils::cosineSimilarity([1], [1, 2]);
    }
}
