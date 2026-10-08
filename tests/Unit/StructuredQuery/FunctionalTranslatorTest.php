<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\StructuredQuery;

use Closure;
use LangChain\Schema\Document;
use LangChain\StructuredQuery\Comparators;
use LangChain\StructuredQuery\Comparison;
use LangChain\StructuredQuery\FunctionalTranslator;
use LangChain\StructuredQuery\Operation;
use LangChain\StructuredQuery\Operators;
use LangChain\StructuredQuery\StructuredQuery;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of `structured_query/tests/functional.test.ts`.
 *
 * Upstream builds the provider matrix inside `describe` with a loop; here the
 * same matrix is a data provider. Names keep upstream's
 * `value -> comparator -> documentValue` shape.
 */
#[CoversClass(FunctionalTranslator::class)]
final class FunctionalTranslatorTest extends TestCase
{
    private const ATTRIBUTES = ['string' => 'stringValue', 'number' => 'numberValue', 'boolean' => 'booleanValue'];
    private const INPUTS = ['stringValue' => 'value', 'numberValue' => 1, 'booleanValue' => true];

    public function testGetAllowedComparatorsForType(): void
    {
        $t = new FunctionalTranslator();
        $all = [Comparators::EQ, Comparators::NE, Comparators::GT, Comparators::GTE, Comparators::LT, Comparators::LTE];
        self::assertSame($all, $t->getAllowedComparatorsForType('string'));
        self::assertSame($all, $t->getAllowedComparatorsForType('number'));
        self::assertSame([Comparators::EQ, Comparators::NE], $t->getAllowedComparatorsForType('boolean'));
    }

    public function testUnsupportedType(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Unsupported data type: unsupported');
        (new FunctionalTranslator())->getAllowedComparatorsForType('unsupported');
    }

    /** @return array<string, array{string, string, bool, Document}> */
    public static function comparisonMatrix(): array
    {
        $doc = static fn (string $s, int $n, bool $b): Document => new Document('', [
            'stringValue' => $s,
            'numberValue' => $n,
            'booleanValue' => $b,
        ]);
        $valid = [
            Comparators::EQ => [$doc('value', 1, true)],
            Comparators::NE => [$doc('not-value', 0, false)],
            Comparators::GT => [$doc('valueee', 2, true)],
            Comparators::GTE => [$doc('valueee', 2, true), $doc('value', 1, true)],
            Comparators::LT => [$doc('val', 0, true)],
            Comparators::LTE => [$doc('val', 0, true), $doc('value', 1, true)],
        ];
        $invalid = [
            Comparators::EQ => [$doc('not-value', 0, false)],
            Comparators::NE => [$doc('value', 1, true)],
            Comparators::GT => [$doc('value', 1, true)],
            Comparators::GTE => [$doc('val', 0, true)],
            Comparators::LT => [$doc('valueee', 2, true)],
            Comparators::LTE => [$doc('valueee', 2, true)],
        ];

        $cases = [];
        $translator = new FunctionalTranslator();
        foreach (['string', 'number', 'boolean'] as $type) {
            $attribute = self::ATTRIBUTES[$type];
            $value = self::INPUTS[$attribute];
            foreach ($translator->getAllowedComparatorsForType($type) as $comparator) {
                foreach ($valid[$comparator] as $d) {
                    $label = var_export($value, true) . " -> {$comparator} -> " . var_export($d->metadata[$attribute], true);
                    $cases["valid {$label}"] = [$attribute, $comparator, true, $d];
                }
                foreach ($invalid[$comparator] as $d) {
                    $label = var_export($value, true) . " -> {$comparator} -> " . var_export($d->metadata[$attribute], true);
                    $cases["invalid {$label}"] = [$attribute, $comparator, false, $d];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('comparisonMatrix')]
    public function testVisitComparisonReturnsTrueOrFalse(string $attribute, string $comparator, bool $expected, Document $document): void
    {
        $filter = (new FunctionalTranslator())->visitComparison(
            new Comparison($comparator, $attribute, self::INPUTS[$attribute])
        );

        self::assertSame($expected, $filter($document));
    }

    public function testBooleansRejectOrderingComparators(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage("'gt' comparator not allowed to be used with boolean");
        (new FunctionalTranslator())->visitComparison(new Comparison(Comparators::GT, 'flag', true));
    }

    public function testMissingAttributeOnlyMatchesNe(): void
    {
        $t = new FunctionalTranslator();
        $doc = new Document('', []);
        self::assertTrue($t->visitComparison(new Comparison(Comparators::NE, 'x', 1))($doc));
        self::assertFalse($t->visitComparison(new Comparison(Comparators::EQ, 'x', 1))($doc));
        self::assertFalse($t->visitComparison(new Comparison(Comparators::LT, 'x', 1))($doc));
    }

    public function testStringsCompareLexicallyNotNumerically(): void
    {
        $t = new FunctionalTranslator();
        // PHP's own `"10" > "9"` is true; JS is false.
        $gt = $t->visitComparison(new Comparison(Comparators::GT, 'v', 'abc'));
        self::assertFalse($gt(new Document('', ['v' => 'abb'])));
        $cmp = $t->getComparatorFunction(Comparators::GT);
        self::assertFalse($cmp('10', '9'));
        self::assertTrue($cmp('9', '10'));
    }

    public function testEqualityIsStrictAcrossTypesButNotIntVsFloat(): void
    {
        $eq = (new FunctionalTranslator())->getComparatorFunction(Comparators::EQ);
        self::assertFalse($eq('1', 1));
        self::assertTrue($eq(1.0, 1));
        self::assertFalse($eq(true, 1));
    }

    public function testMixedTypeRelationalCoercesThroughNumbers(): void
    {
        $t = new FunctionalTranslator();
        self::assertTrue($t->getComparatorFunction(Comparators::GT)('5', 3));
        self::assertFalse($t->getComparatorFunction(Comparators::GT)('abc', 3));
        self::assertFalse($t->getComparatorFunction(Comparators::LTE)('abc', 3));
        self::assertFalse($t->getComparatorFunction(Comparators::GTE)('abc', 3));
        self::assertFalse($t->getComparatorFunction(Comparators::LT)('abc', 3));
    }

    public function testNumericStringValueIsCastBeforeComparing(): void
    {
        $t = new FunctionalTranslator();
        // An LLM said "5"; typeof is string, so gt is allowed, and the value casts to 5.
        $f = $t->visitComparison(new Comparison(Comparators::GT, 'n', '5'));
        self::assertTrue($f(new Document('', ['n' => 6])));
        self::assertFalse($f(new Document('', ['n' => 5])));
    }

    public function testOperationsAndOrAndEmptyArgs(): void
    {
        $t = new FunctionalTranslator();
        $a = new Comparison(Comparators::GT, 'n', 1);
        $b = new Comparison(Comparators::LT, 'n', 10);
        $and = $t->visitOperation(new Operation(Operators::AND, [$a, $b]));
        $or = $t->visitOperation(new Operation(Operators::OR, [$a, $b]));

        self::assertTrue($and(new Document('', ['n' => 5])));
        self::assertFalse($and(new Document('', ['n' => 50])));
        self::assertTrue($or(new Document('', ['n' => 50])));
        self::assertTrue($t->visitOperation(new Operation(Operators::AND))(new Document()));
        self::assertTrue($t->visitOperation(new Operation(Operators::AND, []))(new Document()));
        // OR folds from `true`, so an or of nothing-true is still true: upstream's reduce seed.
        self::assertTrue($t->visitOperation(new Operation(Operators::OR, [new Comparison(Comparators::EQ, 'n', 99)]))(new Document('', ['n' => 1])));
    }

    public function testDisallowedOperatorAndComparator(): void
    {
        $t = new FunctionalTranslator();
        try {
            $t->visitOperation(new Operation(Operators::NOT, []));
            self::fail('expected Operator not allowed');
        } catch (\Error $e) {
            self::assertSame('Operator not allowed', $e->getMessage());
        }
        $t->allowedComparators = [Comparators::EQ];
        try {
            $t->visitComparison(new Comparison(Comparators::GT, 'n', 1));
            self::fail('expected Comparator not allowed');
        } catch (\Error $e) {
            self::assertSame('Comparator not allowed', $e->getMessage());
        }
    }

    public function testFormatFunctionIsNotImplemented(): void
    {
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Not implemented');
        (new FunctionalTranslator())->formatFunction();
    }

    public function testVisitStructuredQuery(): void
    {
        $t = new FunctionalTranslator();
        self::assertSame([], $t->visitStructuredQuery(new StructuredQuery('q')));
        $out = $t->visitStructuredQuery(new StructuredQuery('q', new Comparison(Comparators::EQ, 'a', 1)));
        self::assertInstanceOf(Closure::class, $out['filter']);
        self::assertTrue($out['filter'](new Document('', ['a' => 1])));
    }

    public function testMergeFilters(): void
    {
        $t = new FunctionalTranslator();
        $yes = static fn (Document $d): bool => true;
        $no = static fn (Document $d): bool => false;
        $doc = new Document();

        self::assertNull($t->mergeFilters(null, null));
        self::assertSame($no, $t->mergeFilters(null, $no));
        self::assertSame($no, $t->mergeFilters($yes, $no, 'replace'));
        self::assertNull($t->mergeFilters($yes, null, 'replace'));
        // upstream quirk: and + empty generated => undefined; or => the default
        self::assertNull($t->mergeFilters($yes, null, 'and'));
        self::assertSame($yes, $t->mergeFilters($yes, null, 'or'));
        self::assertFalse($t->mergeFilters($yes, $no, 'and')($doc));
        self::assertTrue($t->mergeFilters($yes, $no, 'or')($doc));
        $this->expectException(\Error::class);
        $this->expectExceptionMessage('Unknown merge type');
        $t->mergeFilters($yes, $no, 'xor');
    }
}
