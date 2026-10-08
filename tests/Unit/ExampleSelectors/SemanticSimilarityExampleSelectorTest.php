<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\ExampleSelectors;

use LangChain\ExampleSelectors\SemanticSimilarityExampleSelector;
use LangChain\VectorStores\MemoryVectorStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Own tests: upstream has none for `SemanticSimilarityExampleSelector`.
 */
#[CoversClass(SemanticSimilarityExampleSelector::class)]
final class SemanticSimilarityExampleSelectorTest extends TestCase
{
    private const EXAMPLES = [
        ['input' => 'happy', 'output' => 'sad'],
        ['input' => 'tall', 'output' => 'short'],
        ['input' => 'sunny', 'output' => 'gloomy'],
        ['input' => 'windy', 'output' => 'calm'],
    ];

    public function testFromExamplesSelectsTheSemanticallyClosestExample(): void
    {
        $selector = SemanticSimilarityExampleSelector::fromExamples(
            self::EXAMPLES,
            new KeywordEmbeddings(),
            MemoryVectorStore::class,
            ['k' => 1],
        );

        $this->assertSame([['input' => 'tall', 'output' => 'short']], $selector->selectExamples(['adjective' => 'short']));
        $this->assertSame([['input' => 'sunny', 'output' => 'gloomy']], $selector->selectExamples(['adjective' => 'gloomy']));
    }

    public function testKDefaultsToFour(): void
    {
        $selector = SemanticSimilarityExampleSelector::fromExamples(self::EXAMPLES, new KeywordEmbeddings(), MemoryVectorStore::class);

        $this->assertSame($selector->vectorStoreRetriever->k, 4);
        $this->assertCount(4, $selector->selectExamples(['adjective' => 'happy']));
    }

    public function testExamplesAreEmbeddedAsTheirSortedValuesJoinedBySpaces(): void
    {
        $embeddings = new KeywordEmbeddings();
        SemanticSimilarityExampleSelector::fromExamples(
            [['output' => 'sad', 'input' => 'happy']],
            $embeddings,
            MemoryVectorStore::class,
        );

        // keys sort to input, output; so values read "happy sad" whatever order the example was written in
        $this->assertSame(['happy sad'], $embeddings->embedded);
    }

    public function testInputKeysRestrictWhatIsEmbeddedAndWhatIsQueried(): void
    {
        $embeddings = new KeywordEmbeddings();
        $selector = SemanticSimilarityExampleSelector::fromExamples(
            self::EXAMPLES,
            $embeddings,
            MemoryVectorStore::class,
            ['k' => 1, 'inputKeys' => ['input']],
        );
        $embeddings->embedded = [];

        $chosen = $selector->selectExamples(['input' => 'windy', 'ignored' => 'sad']);

        $this->assertSame([['input' => 'windy', 'output' => 'calm']], $chosen);
        $this->assertSame(['windy'], $embeddings->embedded);
    }

    public function testExampleKeysProjectTheReturnedExamples(): void
    {
        $selector = SemanticSimilarityExampleSelector::fromExamples(
            self::EXAMPLES,
            new KeywordEmbeddings(),
            MemoryVectorStore::class,
            ['k' => 1, 'exampleKeys' => ['output']],
        );

        $this->assertSame([['output' => 'sad']], $selector->selectExamples(['q' => 'happy']));
    }

    public function testAddExampleStoresTheExampleAsMetadataOfADocument(): void
    {
        $store = new MemoryVectorStore(new KeywordEmbeddings());
        $selector = new SemanticSimilarityExampleSelector(['vectorStore' => $store, 'k' => 1]);

        $selector->addExample(['input' => 'happy', 'output' => 'sad']);

        $this->assertCount(1, $store->memoryVectors);
        $this->assertSame('happy sad', $store->memoryVectors[0]->content);
        $this->assertSame(['input' => 'happy', 'output' => 'sad'], $store->memoryVectors[0]->metadata);
        $this->assertSame([['input' => 'happy', 'output' => 'sad']], $selector->selectExamples(['q' => 'sad']));
    }

    public function testAnExistingRetrieverCanBeUsedInsteadOfAStore(): void
    {
        $store = new MemoryVectorStore(new KeywordEmbeddings());
        $retriever = $store->asRetriever(2);

        $selector = new SemanticSimilarityExampleSelector(['vectorStoreRetriever' => $retriever]);

        $this->assertSame($retriever, $selector->vectorStoreRetriever);
    }

    public function testConstructionWithNeitherStoreNorRetrieverIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('You must specify one of "vectorStore" and "vectorStoreRetriever".');

        new SemanticSimilarityExampleSelector([]);
    }
}
