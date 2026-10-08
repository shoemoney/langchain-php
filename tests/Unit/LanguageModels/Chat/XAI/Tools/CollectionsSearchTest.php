<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\XAI\Tools;

use LangChain\LanguageModels\Chat\XAI\Tools\CollectionsSearch;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Ports `tools/tests/collections_search.test.ts` (6 tests).
 */
#[CoversClass(CollectionsSearch::class)]
final class CollectionsSearchTest extends TestCase
{
    public function testCreatesAToolWithCorrectType(): void
    {
        self::assertSame(CollectionsSearch::TOOL_TYPE, CollectionsSearch::create()['type']);
    }

    public function testCreatesAToolWithDefaultOptionsEmpty(): void
    {
        $tool = CollectionsSearch::create();

        self::assertSame('file_search', $tool['type']);
        self::assertArrayNotHasKey('vector_store_ids', $tool);
    }

    public function testCreatesAToolWithVectorStoreIdsOption(): void
    {
        $tool = CollectionsSearch::create(['vectorStoreIds' => ['collection_abc123', 'collection_def456']]);

        self::assertSame(['type' => 'file_search', 'vector_store_ids' => ['collection_abc123', 'collection_def456']], $tool);
    }

    public function testCreatesAToolWithSingleVectorStoreId(): void
    {
        $tool = CollectionsSearch::create(['vectorStoreIds' => ['collection_single']]);

        self::assertSame(['type' => 'file_search', 'vector_store_ids' => ['collection_single']], $tool);
    }

    public function testConvertsCamelCaseOptionsToSnakeCase(): void
    {
        $tool = CollectionsSearch::create(['vectorStoreIds' => ['test_collection']]);

        self::assertArrayHasKey('vector_store_ids', $tool);
        self::assertArrayNotHasKey('vectorStoreIds', $tool);
    }

    public function testEmptyVectorStoreIdsArrayIsPreserved(): void
    {
        self::assertSame([], CollectionsSearch::create(['vectorStoreIds' => []])['vector_store_ids']);
    }
}
