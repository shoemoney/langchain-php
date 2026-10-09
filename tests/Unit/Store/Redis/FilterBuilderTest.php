<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\Redis;

use LangGraph\Store\Redis\FilterBuilder;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of the `FilterBuilder` block of `tests/store.int.test.ts`, plus the JavaScript equality and
 * coercion rules the filters inherit.
 */
#[CoversClass(FilterBuilder::class)]
final class FilterBuilderTest extends TestCase
{
    public function testShouldMatchDocumentsWithSimpleEquality(): void
    {
        $doc = ['name' => 'Test', 'value' => 100];

        self::assertTrue(FilterBuilder::matchesFilter($doc, ['name' => 'Test']));
        self::assertFalse(FilterBuilder::matchesFilter($doc, ['name' => 'Other']));
    }

    public function testShouldMatchDocumentsWithOperators(): void
    {
        $doc = ['age' => 25, 'name' => 'John'];

        self::assertTrue(FilterBuilder::matchesFilter($doc, ['age' => ['$gt' => 20]]));
        self::assertFalse(FilterBuilder::matchesFilter($doc, ['age' => ['$gt' => 30]]));
        self::assertTrue(FilterBuilder::matchesFilter($doc, ['name' => ['$in' => ['John', 'Jane']]]));
        self::assertFalse(FilterBuilder::matchesFilter($doc, ['name' => ['$in' => ['Jane', 'Bob']]]));
    }

    public function testShouldHandleNestedObjectPaths(): void
    {
        $doc = ['user' => ['profile' => ['name' => 'Alice', 'age' => 30]]];

        self::assertTrue(FilterBuilder::matchesFilter($doc, ['user.profile.name' => 'Alice']));
        self::assertTrue(FilterBuilder::matchesFilter($doc, ['user.profile.age' => ['$gte' => 30]]));
        self::assertFalse(FilterBuilder::matchesFilter($doc, ['user.profile.age' => ['$lt' => 30]]));
    }

    public function testShouldHandleArraysInDocuments(): void
    {
        $doc = ['tags' => ['javascript', 'typescript', 'node']];

        self::assertTrue(FilterBuilder::matchesFilter($doc, ['tags' => 'javascript']));
        self::assertFalse(FilterBuilder::matchesFilter($doc, ['tags' => 'python']));
    }

    public function testShouldHandleExistsOperatorCorrectly(): void
    {
        // Upstream's `optional: undefined` is a key that is simply absent here.
        $doc = ['name' => 'Test'];

        self::assertTrue(FilterBuilder::matchesFilter($doc, ['name' => ['$exists' => true]]));
        self::assertFalse(FilterBuilder::matchesFilter($doc, ['optional' => ['$exists' => true]]));
        self::assertTrue(FilterBuilder::matchesFilter($doc, ['missing' => ['$exists' => false]]));
    }

    public function testShouldBuildRedisSearchQueries(): void
    {
        $built = FilterBuilder::buildRedisSearchQuery(['name' => 'Test'], 'prefix');

        self::assertStringContainsString('@prefix:(prefix)', $built['query']);
        self::assertFalse($built['useClientFilter']);

        self::assertTrue(FilterBuilder::buildRedisSearchQuery(['age' => ['$gt' => 25]], 'prefix')['useClientFilter']);
    }

    public function testQueriesWithoutAPrefixSearchEverything(): void
    {
        self::assertSame(['query' => '*', 'useClientFilter' => false], FilterBuilder::buildRedisSearchQuery([]));
        self::assertSame('*', FilterBuilder::buildRedisSearchQuery([], '')['query']);
        self::assertSame('*', FilterBuilder::buildRedisSearchQuery([], '.-')['query'], 'a prefix of only separators has no tokens');
        self::assertSame('@prefix:(users profiles alice)', FilterBuilder::buildRedisSearchQuery([], 'users.profiles-alice')['query']);
        self::assertFalse(FilterBuilder::buildRedisSearchQuery(['tags' => ['$x' => 1, 'plain']], null)['useClientFilter'] === false, 'a $-keyed map is an operator object');
        self::assertFalse(FilterBuilder::buildRedisSearchQuery(['tags' => ['a', 'b']])['useClientFilter'], 'a list is a literal');
    }

    public function testPrefixTokensCannotCarryQuerySyntax(): void
    {
        $tokens = FilterBuilder::prefixTokens('a) | (@prefix:* {x} [y] "z" ~w -v');

        self::assertSame(['a', 'prefix', 'x', 'y', 'z', 'w', 'v'], $tokens);
        self::assertSame(['back\\\\slash', 'q\\?', 'a\\/b'], FilterBuilder::prefixTokens('back\\slash q? a/b'), 'what the tokenizer keeps is escaped');
    }

    /** @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: bool}> */
    public static function equalityCases(): array
    {
        return [
            'int against float' => [['n' => 1], ['n' => 1.0], true],
            'number against numeric string' => [['n' => 5], ['n' => '5'], true],
            'string against different string' => [['s' => 'a'], ['s' => 'b'], false],
            'true against 1' => [['b' => true], ['b' => 1], true],
            'true against the string true' => [['b' => true], ['b' => 'true'], false],
            'false against 0' => [['b' => false], ['b' => 0], true],
            'empty string against 0' => [['s' => ''], ['s' => 0], true],
            'null against null' => [['x' => null], ['x' => null], true],
            'missing against null' => [[], ['x' => null], false],
            'null against a value' => [['x' => null], ['x' => 0], false],
            'list against equal list' => [['l' => [1, 2]], ['l' => [1, 2]], true],
            'list against shorter list' => [['l' => [1, 2]], ['l' => [1]], false],
            'list against reordered list' => [['l' => [1, 2]], ['l' => [2, 1]], false],
            'nested objects equal' => [['o' => ['a' => 1, 'b' => 2]], ['o' => ['b' => 2, 'a' => 1]], true],
            'nested objects differ' => [['o' => ['a' => 1]], ['o' => ['a' => 2]], false],
            'nested objects with different keys' => [['o' => ['a' => 1]], ['o' => ['b' => 1]], false],
            'scalar inside a list' => [['l' => ['x', 'y']], ['l' => 'y'], true],
            'scalar outside a list' => [['l' => ['x', 'y']], ['l' => 'z'], false],
            'a number is not a string in a list' => [['l' => ['5']], ['l' => 5], false],
            'object against a list does not match' => [['l' => [['a' => 1]]], ['l' => ['a' => 1]], false],
        ];
    }

    /**
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $filter
     */
    #[DataProvider('equalityCases')]
    public function testEqualityFollowsJavaScript(array $doc, array $filter, bool $expected): void
    {
        self::assertSame($expected, FilterBuilder::matchesFilter($doc, $filter));
    }

    /** @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>, 2: bool}> */
    public static function operatorCases(): array
    {
        return [
            '$gt coerces a numeric string' => [['n' => '10'], ['n' => ['$gt' => 9]], true],
            '$gt on a missing field' => [[], ['n' => ['$gt' => 1]], false],
            '$gt on null' => [['n' => null], ['n' => ['$lt' => 1]], false],
            '$gt on a non-numeric string' => [['n' => 'abc'], ['n' => ['$gt' => 1]], false],
            '$lte on equal' => [['n' => 3], ['n' => ['$lte' => 3]], true],
            '$eq' => [['n' => 3], ['n' => ['$eq' => 3]], true],
            '$ne on a missing field' => [[], ['n' => ['$ne' => 3]], true],
            '$in with no member' => [['n' => 3], ['n' => ['$in' => []]], false],
            '$in that is not a list' => [['n' => 3], ['n' => ['$in' => 3]], false],
            '$nin that is not a list' => [['n' => 3], ['n' => ['$nin' => 3]], false],
            '$nin with no member' => [['n' => 3], ['n' => ['$nin' => []]], true],
            '$exists on an explicit null' => [['n' => null], ['n' => ['$exists' => true]], true],
            '$exists with a truthy string' => [['n' => 1], ['n' => ['$exists' => '0']], true],
            '$exists with a falsy value' => [['n' => 1], ['n' => ['$exists' => 0]], false],
            'an unknown operator matches nothing' => [['n' => 1], ['n' => ['$regex' => '1']], false],
            'every operator in the object must hold' => [['n' => 5], ['n' => ['$gt' => 1, '$lt' => 3]], false],
            'a path through a scalar is missing' => [['a' => 1], ['a.b' => ['$exists' => true]], false],
            'a path through null is missing' => [['a' => null], ['a.b' => ['$exists' => false]], true],
            'a path into a list index' => [['l' => ['x', 'y']], ['l.1' => 'y'], true],
            'an empty filter matches everything' => [['n' => 1], [], true],
        ];
    }

    /**
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $filter
     */
    #[DataProvider('operatorCases')]
    public function testOperatorEdgeCases(array $doc, array $filter, bool $expected): void
    {
        self::assertSame($expected, FilterBuilder::matchesFilter($doc, $filter));
    }
}
