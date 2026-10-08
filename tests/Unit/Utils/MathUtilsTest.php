<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Utils;

use LangChain\Utils\MathUtils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `utils/tests/math_utils.test.ts`.
 *
 * `Matrix.rand` / `Matrix.zeros` (ml-matrix) become a seeded-free random matrix
 * helper and `array_fill`.
 */
#[CoversClass(MathUtils::class)]
final class MathUtilsTest extends TestCase
{
    /**
     * @return list<list<float>>
     */
    private static function rand(int $rows, int $cols): array
    {
        $m = [];
        for ($i = 0; $i < $rows; $i++) {
            $row = [];
            for ($j = 0; $j < $cols; $j++) {
                $row[] = mt_rand() / mt_getrandmax();
            }
            $m[] = $row;
        }

        return $m;
    }

    /**
     * @return list<list<float>>
     */
    private static function zeros(int $rows, int $cols): array
    {
        return array_fill(0, $rows, array_fill(0, $cols, 0.0));
    }

    public function testCosineSimilarityZero(): void
    {
        self::assertEquals(self::zeros(3, 3), MathUtils::cosineSimilarity(self::rand(3, 3), self::zeros(3, 3)));
    }

    public function testCosineSimilarityIdentity(): void
    {
        $x = self::rand(4, 4);
        $actual = MathUtils::cosineSimilarity($x, $x);

        for ($i = 0; $i < 4; $i++) {
            self::assertEqualsWithDelta(1.0, $actual[$i][$i], 1e-9);
        }
    }

    public function testCosineSimilarity(): void
    {
        $x = [[1.0, 2.0, 3.0], [0.0, 1.0, 0.0], [1.0, 2.0, 0.0]];
        $y = [[0.5, 1.0, 1.5], [1.0, 0.0, 0.0], [2.0, 5.0, 2.0], [0.0, 0.0, 0.0]];
        $expected = [
            [1, 0.2672612419124244, 0.8374357893586237, 0],
            [0.5345224838248488, 0, 0.8703882797784892, 0],
            [0.5976143046671968, 0.4472135954999579, 0.9341987329938275, 0],
        ];

        $actual = MathUtils::cosineSimilarity($x, $y);

        foreach ($expected as $i => $row) {
            foreach ($row as $j => $value) {
                self::assertEqualsWithDelta($value, $actual[$i][$j], 1e-12);
            }
        }
    }

    public function testCosineSimilarityEmpty(): void
    {
        $x = [[]];

        self::assertSame([[]], MathUtils::cosineSimilarity($x, $x));
        self::assertSame([[]], MathUtils::cosineSimilarity($x, self::rand(3, 3)));
    }

    public function testCosineSimilarityWrongShape(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Number of columns in X and Y must be the same');

        MathUtils::cosineSimilarity(self::rand(2, 2), self::rand(2, 4));
    }

    public function testCosineSimilarityDifferentRowCountsIsFine(): void
    {
        $result = MathUtils::cosineSimilarity(self::rand(2, 2), self::rand(4, 2));

        self::assertCount(2, $result);
        self::assertCount(4, $result[0]);
    }

    public function testMaximalMarginalRelevanceLambdaZero(): void
    {
        $query = self::rand(5, 1);
        $query = array_map(static fn (array $r): float => $r[0], $query);
        $zeros = array_fill(0, 5, 0.0);

        self::assertSame([0, 2], MathUtils::maximalMarginalRelevance($query, [$query, $query, $zeros], 0, 2));
    }

    public function testMaximalMarginalRelevanceLambdaOne(): void
    {
        $query = array_map(static fn (array $r): float => $r[0], self::rand(5, 1));
        $zeros = array_fill(0, 5, 0.0);

        self::assertSame([0, 1], MathUtils::maximalMarginalRelevance($query, [$query, $query, $zeros], 1, 2));
    }

    public function testMaximalMarginalRelevance(): void
    {
        // Vectors 30, 45 and 75 degrees from the query (cosine 0.87, 0.71, 0.26),
        // the latter two 15 and 60 degrees from the first (0.97, 0.71). The third
        // is chosen only while lambda <~ 0.26 / 0.71.
        $query = [1, 0];
        $embeddings = [[3 ** 0.5, 1], [1, 1], [1, 2 + 3 ** 0.5]];

        self::assertSame([0, 2], MathUtils::maximalMarginalRelevance($query, $embeddings, 25 / 71, 2));
        self::assertSame([0, 1], MathUtils::maximalMarginalRelevance($query, $embeddings, 27 / 71, 2));
    }

    public function testMaximalMarginalRelevanceQueryDim(): void
    {
        $vector = array_map(static fn (array $r): float => $r[0], self::rand(5, 1));
        $embeddings = self::rand(4, 5);

        self::assertSame(
            MathUtils::maximalMarginalRelevance($vector, $embeddings, 1, 2),
            MathUtils::maximalMarginalRelevance([$vector], $embeddings, 1, 2),
        );
    }

    public function testMaximalMarginalRelevanceHasNoDuplicates(): void
    {
        $query = array_map(static fn (array $r): float => $r[0], self::rand(1536, 1));

        $actual = MathUtils::maximalMarginalRelevance($query, self::rand(60, 1536), 0.5, 60);

        self::assertCount(60, array_unique($actual));
    }

    public function testMaximalMarginalRelevanceOfNothingIsEmpty(): void
    {
        self::assertSame([], MathUtils::maximalMarginalRelevance([1, 0], [], 0.5, 4));
        self::assertSame([], MathUtils::maximalMarginalRelevance([1, 0], [[1, 0]], 0.5, 0));
    }

    public function testNormalize(): void
    {
        self::assertEquals([[0.25, 0.5], [0.75, 1]], MathUtils::normalize([[1, 2], [3, 4]]));
    }

    public function testNormalizeAsSimilarity(): void
    {
        self::assertEquals([[0.75, 0.5], [0.25, 0]], MathUtils::normalize([[1, 2], [3, 4]], true));
    }

    public function testInnerProduct(): void
    {
        self::assertEquals([[11, 23], [39, 83]], MathUtils::innerProduct([[1, 2], [5, 6]], [[3, 4], [7, 8]]));
    }

    public function testDistance(): void
    {
        $actual = MathUtils::euclideanDistance([[1, 2]], [[2, 4]]);

        self::assertEqualsWithDelta(2.23606797749979, $actual[0][0], 1e-9);
    }
}
