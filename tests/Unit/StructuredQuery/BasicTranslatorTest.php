<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\StructuredQuery;

use LangChain\StructuredQuery\BasicTranslator;
use LangChain\StructuredQuery\Comparators;
use LangChain\StructuredQuery\Comparison;
use LangChain\StructuredQuery\Operation;
use LangChain\StructuredQuery\Operators;
use LangChain\StructuredQuery\StructuredQuery;
use LangChain\StructuredQuery\TranslatorOpts;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `BasicTranslator` / IR have no upstream tests; these pin the behaviour read
 * off `base.ts` and `ir.ts`.
 */
#[CoversClass(BasicTranslator::class)]
#[CoversClass(Comparison::class)]
#[CoversClass(Operation::class)]
#[CoversClass(StructuredQuery::class)]
#[CoversClass(Comparators::class)]
#[CoversClass(Operators::class)]
final class BasicTranslatorTest extends TestCase
{
    public function testTranslatesNestedStructuredQuery(): void
    {
        $query = new StructuredQuery('dinosaurs', new Operation(Operators::AND, [
            new Comparison(Comparators::GTE, 'year', '1990'),
            new Operation(Operators::OR, [
                new Comparison(Comparators::EQ, 'genre', 'scifi'),
                new Comparison(Comparators::LT, 'rating', 8.5),
            ]),
        ]));

        self::assertSame([
            'filter' => [
                '$and' => [
                    ['year' => ['$gte' => 1990]],
                    ['$or' => [
                        ['genre' => ['$eq' => 'scifi']],
                        ['rating' => ['$lt' => 8.5]],
                    ]],
                ],
            ],
        ], $query->accept(new BasicTranslator()));
    }

    public function testQueryWithoutFilterYieldsNoFilterKey(): void
    {
        self::assertSame([], (new StructuredQuery('hello'))->accept(new BasicTranslator()));
    }

    public function testOperationWithoutArgsMapsToNull(): void
    {
        self::assertSame(['$and' => null], (new Operation(Operators::AND))->accept(new BasicTranslator()));
    }

    public function testDisallowedFunctionsThrowWithAllowedList(): void
    {
        $t = new BasicTranslator();
        try {
            $t->formatFunction(Operators::NOT);
            self::fail('expected not allowed');
        } catch (\Error $e) {
            self::assertSame('Operator not not allowed. Allowed operators: and, or', $e->getMessage());
        }
        $custom = new BasicTranslator(new TranslatorOpts([Operators::AND], [Comparators::EQ]));
        try {
            $custom->formatFunction(Comparators::GT);
            self::fail('expected not allowed');
        } catch (\Error $e) {
            self::assertSame('Comparator gt not allowed. Allowed comparators: eq', $e->getMessage());
        }
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Unknown comparator or operator');
        $t->formatFunction('in');
    }

    public function testEmptyAllowListMeansAnythingKnownIsAllowed(): void
    {
        $t = new BasicTranslator(new TranslatorOpts([], []));
        self::assertSame('$not', $t->formatFunction(Operators::NOT));
        self::assertSame('$lte', $t->formatFunction(Comparators::LTE));
    }

    public function testUnsupportedValueTypeSurfaces(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Unsupported value type');
        (new Comparison(Comparators::EQ, 'a', ['in', 'array']))->accept(new BasicTranslator());
    }

    public function testMergeFilters(): void
    {
        $t = new BasicTranslator();
        $d = ['a' => ['$eq' => 1]];
        $g = ['b' => ['$eq' => 2]];

        self::assertNull($t->mergeFilters([], []));
        self::assertNull($t->mergeFilters(null, []));
        self::assertSame($g, $t->mergeFilters([], $g));
        self::assertSame($g, $t->mergeFilters($d, $g, 'replace'));
        self::assertNull($t->mergeFilters($d, [], 'replace'));
        self::assertNull($t->mergeFilters($d, [], 'and'));
        self::assertSame($d, $t->mergeFilters($d, [], 'and', true));
        self::assertSame($d, $t->mergeFilters($d, [], 'or'));
        self::assertSame(['$and' => [$d, $g]], $t->mergeFilters($d, $g));
        self::assertSame(['$or' => [$d, $g]], $t->mergeFilters($d, $g, 'or'));
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Unknown merge type');
        $t->mergeFilters($d, $g, 'nand');
    }

    public function testComparatorAndOperatorMapsMatchUpstream(): void
    {
        self::assertSame(['and' => 'and', 'or' => 'or', 'not' => 'not'], Operators::all());
        self::assertSame(['eq', 'ne', 'lt', 'gt', 'lte', 'gte'], array_keys(Comparators::all()));
        self::assertFalse(Comparators::has('in'));
        self::assertFalse(Comparators::has('nin'));
    }

    public function testUnknownExpressionTypeThrows(): void
    {
        $bad = new class () extends \LangChain\StructuredQuery\Expression {
            public string $exprName = 'Other';
        };
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Unknown Expression type');
        $bad->accept(new BasicTranslator());
    }
}
