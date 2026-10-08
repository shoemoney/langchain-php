<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Tools;

use LangChain\LanguageModels\Chat\OpenAI\Tools\FileSearch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** `tools/tests/fileSearch.test.ts`. */
#[CoversClass(FileSearch::class)]
final class FileSearchTest extends TestCase
{
    public function testBasicToolWithVectorStoreIds(): void
    {
        self::assertSame(
            ['type' => 'file_search', 'vector_store_ids' => ['vs_abc123']],
            FileSearch::tool(['vectorStoreIds' => ['vs_abc123']]),
        );
    }

    public function testMultipleVectorStores(): void
    {
        self::assertSame(
            ['type' => 'file_search', 'vector_store_ids' => ['vs_abc123', 'vs_def456', 'vs_ghi789']],
            FileSearch::tool(['vectorStoreIds' => ['vs_abc123', 'vs_def456', 'vs_ghi789']]),
        );
    }

    public function testMaxResultsLimit(): void
    {
        self::assertSame(
            ['type' => 'file_search', 'vector_store_ids' => ['vs_abc123'], 'max_num_results' => 5],
            FileSearch::tool(['vectorStoreIds' => ['vs_abc123'], 'maxNumResults' => 5]),
        );
    }

    public function testComparisonFilter(): void
    {
        $filter = ['type' => 'eq', 'key' => 'category', 'value' => 'blog'];

        self::assertSame(
            ['type' => 'file_search', 'vector_store_ids' => ['vs_abc123'], 'filters' => $filter],
            FileSearch::tool(['vectorStoreIds' => ['vs_abc123'], 'filters' => $filter]),
        );
    }

    public function testCompoundFilterAnd(): void
    {
        $filter = ['type' => 'and', 'filters' => [
            ['type' => 'eq', 'key' => 'category', 'value' => 'technical'],
            ['type' => 'gte', 'key' => 'year', 'value' => 2024],
        ]];

        self::assertSame($filter, FileSearch::tool(['vectorStoreIds' => ['vs_abc123'], 'filters' => $filter])['filters']);
    }

    public function testCompoundFilterOr(): void
    {
        $filter = ['type' => 'or', 'filters' => [
            ['type' => 'eq', 'key' => 'category', 'value' => 'blog'],
            ['type' => 'eq', 'key' => 'category', 'value' => 'announcement'],
        ]];

        self::assertSame($filter, FileSearch::tool(['vectorStoreIds' => ['vs_abc123'], 'filters' => $filter])['filters']);
    }

    public function testRankingOptions(): void
    {
        self::assertSame(
            ['ranker' => 'auto', 'score_threshold' => 0.8],
            FileSearch::tool(['vectorStoreIds' => ['vs_abc123'], 'rankingOptions' => ['ranker' => 'auto', 'scoreThreshold' => 0.8]])['ranking_options'],
        );
    }

    public function testHybridSearchWeights(): void
    {
        self::assertSame(
            ['hybrid_search' => ['embedding_weight' => 0.7, 'text_weight' => 0.3]],
            FileSearch::tool([
                'vectorStoreIds' => ['vs_abc123'],
                'rankingOptions' => ['hybridSearch' => ['embeddingWeight' => 0.7, 'textWeight' => 0.3]],
            ])['ranking_options'],
        );
    }

    public function testAllOptions(): void
    {
        self::assertSame(
            [
                'type' => 'file_search',
                'vector_store_ids' => ['vs_abc123', 'vs_def456'],
                'max_num_results' => 10,
                'filters' => ['type' => 'eq', 'key' => 'status', 'value' => 'published'],
                'ranking_options' => [
                    'ranker' => 'default-2024-11-15',
                    'score_threshold' => 0.75,
                    'hybrid_search' => ['embedding_weight' => 0.6, 'text_weight' => 0.4],
                ],
            ],
            FileSearch::tool([
                'vectorStoreIds' => ['vs_abc123', 'vs_def456'],
                'maxNumResults' => 10,
                'filters' => ['type' => 'eq', 'key' => 'status', 'value' => 'published'],
                'rankingOptions' => [
                    'ranker' => 'default-2024-11-15',
                    'scoreThreshold' => 0.75,
                    'hybridSearch' => ['embeddingWeight' => 0.6, 'textWeight' => 0.4],
                ],
            ]),
        );
    }

    public function testSupportsAllComparisonOperators(): void
    {
        foreach (['eq', 'ne', 'gt', 'gte', 'lt', 'lte'] as $op) {
            $tool = FileSearch::tool(['vectorStoreIds' => ['vs_abc123'], 'filters' => ['type' => $op, 'key' => 'count', 'value' => 100]]);

            self::assertSame('file_search', $tool['type']);
            self::assertSame(['type' => $op, 'key' => 'count', 'value' => 100], $tool['filters']);
        }
    }
}
