<?php

declare(strict_types=1);

namespace LangChain\Utils;

/**
 * Row-wise matrix similarity and distance helpers, plus maximal marginal relevance.
 *
 * Port of `@langchain/core/utils/math`, with the three `ml-distance` kernels it
 * depends on (cosine, inner product, euclidean) inlined as private methods.
 *
 * Note for the integrator: `LangGraph\Store\StoreUtils::cosineSimilarity()` is a
 * second, vector-level cosine that returns 0.0 for a zero magnitude. This class
 * reaches the same answer through {@see self::cosineSimilarity()} (NaN folded to
 * 0 in {@see self::matrixFunc()}), so the store could delegate to
 * `MathUtils::cosineSimilarity([$a], [$b])[0][0]` and drop its copy. It is left
 * alone here because that file is not owned by this work package.
 */
final class MathUtils
{
    private function __construct()
    {
    }

    /**
     * Apply a vector function between every row of X and every row of Y.
     *
     * @param list<list<int|float>> $x
     * @param list<list<int|float>> $y
     * @param callable(list<int|float>, list<int|float>): float $func
     *
     * @return list<list<int|float>> `[[]]` when either matrix is empty
     *
     * @throws \InvalidArgumentException when the column counts differ
     */
    public static function matrixFunc(array $x, array $y, callable $func): array
    {
        if ($x === [] || $x[0] === [] || $y === [] || $y[0] === []) {
            return [[]];
        }

        if (count($x[0]) !== count($y[0])) {
            throw new \InvalidArgumentException(sprintf(
                'Number of columns in X and Y must be the same. X has shape %d,%d and Y has shape %d,%d.',
                count($x),
                count($x[0]),
                count($y),
                count($y[0]),
            ));
        }

        $out = [];
        foreach ($x as $xVector) {
            $row = [];
            foreach ($y as $yVector) {
                $similarity = $func($xVector, $yVector);
                $row[] = is_nan($similarity) ? 0.0 : $similarity;
            }
            $out[] = $row;
        }

        return $out;
    }

    /**
     * Divide every cell by the largest cell (or `1 - cell/max` for similarities).
     *
     * @param list<list<int|float>> $m
     *
     * @return list<list<float>>
     */
    public static function normalize(array $m, bool $similarity = false): array
    {
        $max = self::matrixMaxVal($m);

        return array_map(
            static fn (array $row): array => array_map(
                static fn (int|float $val): float => $similarity ? 1 - self::divide($val, $max) : self::divide($val, $max),
                $row,
            ),
            $m,
        );
    }

    /**
     * Row-wise cosine similarity.
     *
     * @param list<list<int|float>> $x
     * @param list<list<int|float>> $y
     *
     * @return list<list<int|float>>
     */
    public static function cosineSimilarity(array $x, array $y): array
    {
        return self::matrixFunc($x, $y, self::cosine(...));
    }

    /**
     * @param list<list<int|float>> $x
     * @param list<list<int|float>> $y
     *
     * @return list<list<int|float>>
     */
    public static function innerProduct(array $x, array $y): array
    {
        return self::matrixFunc($x, $y, self::innerProductDistance(...));
    }

    /**
     * @param list<list<int|float>> $x
     * @param list<list<int|float>> $y
     *
     * @return list<list<int|float>>
     */
    public static function euclideanDistance(array $x, array $y): array
    {
        return self::matrixFunc($x, $y, self::euclidean(...));
    }

    /**
     * Select embeddings that are relevant to the query yet diverse from each other.
     *
     * @param list<int|float>|list<list<int|float>> $queryEmbedding
     * @param list<list<int|float>>                 $embeddingList
     *
     * @return list<int> indexes into `$embeddingList`, most relevant first
     */
    public static function maximalMarginalRelevance(
        array $queryEmbedding,
        array $embeddingList,
        float $lambda = 0.5,
        int $k = 4,
    ): array {
        if (min($k, count($embeddingList)) <= 0) {
            return [];
        }

        $queryExpanded = isset($queryEmbedding[0]) && is_array($queryEmbedding[0])
            ? $queryEmbedding
            : [$queryEmbedding];

        $similarityToQuery = self::cosineSimilarity($queryExpanded, $embeddingList)[0];
        $mostSimilar = self::argMax($similarityToQuery)['maxIndex'];

        $selectedEmbeddings = [$embeddingList[$mostSimilar]];
        $selectedIndexes = [$mostSimilar];

        while (count($selectedIndexes) < min($k, count($embeddingList))) {
            $bestScore = -INF;
            $bestIndex = -1;

            $similarityToSelected = self::cosineSimilarity($embeddingList, $selectedEmbeddings);

            foreach ($similarityToQuery as $queryScoreIndex => $queryScore) {
                if (in_array($queryScoreIndex, $selectedIndexes, true)) {
                    continue;
                }
                $maxSimilarityToSelected = max($similarityToSelected[$queryScoreIndex]);
                $score = $lambda * $queryScore - (1 - $lambda) * $maxSimilarityToSelected;

                if ($score > $bestScore) {
                    $bestScore = $score;
                    $bestIndex = $queryScoreIndex;
                }
            }
            $selectedEmbeddings[] = $embeddingList[$bestIndex];
            $selectedIndexes[] = $bestIndex;
        }

        return $selectedIndexes;
    }

    /**
     * @param list<int|float> $array
     *
     * @return array{maxIndex: int, maxValue: int|float}
     */
    private static function argMax(array $array): array
    {
        if ($array === []) {
            return ['maxIndex' => -1, 'maxValue' => NAN];
        }

        $maxValue = $array[0];
        $maxIndex = 0;
        for ($i = 1, $n = count($array); $i < $n; $i++) {
            if ($array[$i] > $maxValue) {
                $maxIndex = $i;
                $maxValue = $array[$i];
            }
        }

        return ['maxIndex' => $maxIndex, 'maxValue' => $maxValue];
    }

    /**
     * @param list<list<int|float>> $arrays
     */
    private static function matrixMaxVal(array $arrays): int|float
    {
        $acc = 0;
        foreach ($arrays as $array) {
            $acc = max($acc, self::argMax($array)['maxValue']);
        }

        return $acc;
    }

    /** IEEE division, so a zero maximum gives NaN/INF as it does upstream instead of throwing. */
    private static function divide(int|float $a, int|float $b): float
    {
        if ($b == 0) {
            return $a == 0 ? NAN : ($a > 0 ? INF : -INF);
        }

        return $a / $b;
    }

    /**
     * @param list<int|float> $a
     * @param list<int|float> $b
     */
    private static function cosine(array $a, array $b): float
    {
        $p = 0.0;
        $p2 = 0.0;
        $q2 = 0.0;
        foreach ($a as $i => $value) {
            $p += $value * $b[$i];
            $p2 += $value * $value;
            $q2 += $b[$i] * $b[$i];
        }

        return self::divide($p, sqrt($p2) * sqrt($q2));
    }

    /**
     * @param list<int|float> $a
     * @param list<int|float> $b
     */
    private static function innerProductDistance(array $a, array $b): float
    {
        $ans = 0.0;
        foreach ($a as $i => $value) {
            $ans += $value * $b[$i];
        }

        return $ans;
    }

    /**
     * @param list<int|float> $p
     * @param list<int|float> $q
     */
    private static function euclidean(array $p, array $q): float
    {
        $d = 0.0;
        foreach ($p as $i => $value) {
            $d += ($value - $q[$i]) * ($value - $q[$i]);
        }

        return sqrt($d);
    }
}
