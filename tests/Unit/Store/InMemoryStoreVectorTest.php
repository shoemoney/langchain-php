<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store;

use LangChain\Embeddings\Embeddings;
use LangGraph\Store\IndexConfig;
use LangGraph\Store\InMemoryStore;
use LangGraph\Store\PutOperation;
use LangGraph\Store\SearchItem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `checkpoint/src/tests/vector.test.ts` (`InMemoryStore Vector Search`).
 */
#[CoversClass(InMemoryStore::class)]
#[CoversClass(IndexConfig::class)]
#[CoversClass(Embeddings::class)]
final class InMemoryStoreVectorTest extends TestCase
{
    private CharacterEmbeddings $embeddings;

    private InMemoryStore $store;

    protected function setUp(): void
    {
        $this->embeddings = new CharacterEmbeddings(500);
        $this->store = new InMemoryStore(new IndexConfig(500, $this->embeddings));
    }

    /**
     * @param list<SearchItem> $items
     * @return list<string>
     */
    private static function keys(array $items): array
    {
        return array_map(static fn (SearchItem $i): string => $i->key, $items);
    }

    public function testInitializesWithAnEmbeddingsConfig(): void
    {
        $config = $this->store->indexConfig;

        self::assertNotNull($config);
        self::assertSame(500, $config->dims);
        self::assertSame($this->embeddings, $config->embeddings);
        self::assertNull((new InMemoryStore())->indexConfig);
    }

    public function testAutoEmbedsAndSearchesDocuments(): void
    {
        foreach ([
            ['doc1', ['text' => 'short text']],
            ['doc2', ['text' => 'longer text document']],
            ['doc3', ['text' => 'longest text document here']],
            ['doc4', ['description' => 'text in description field']],
            ['doc5', ['content' => 'text in content field']],
            ['doc6', ['body' => 'text in body field']],
        ] as [$key, $value]) {
            $this->store->put(['test'], $key, $value);
        }

        $order = self::keys($this->store->search(['test'], ['query' => 'long text']));

        self::assertNotEmpty($order);
        self::assertContains('doc2', $order);
        self::assertContains('doc3', $order);
    }

    public function testUpdatingADocumentUpdatesItsEmbedding(): void
    {
        $this->store->put(['test'], 'doc1', ['text' => 'zany zebra Xerxes']);
        $this->store->put(['test'], 'doc2', ['text' => 'something about dogs']);
        $this->store->put(['test'], 'doc3', ['text' => 'text about birds']);

        $initial = $this->store->search(['test'], ['query' => 'Zany Xerxes']);
        self::assertNotEmpty($initial);
        $initialScore = $initial[0]->score;
        self::assertNotNull($initialScore);
        self::assertSame('doc1', $initial[0]->key);

        $this->store->put(['test'], 'doc1', ['text' => 'new text about dogs']);

        $afterScore = 0.0;
        foreach ($this->store->search(['test'], ['query' => 'Zany Xerxes']) as $hit) {
            if ($hit->key === 'doc1') {
                $afterScore = $hit->score ?? 0.0;
            }
        }
        self::assertLessThan($initialScore, $afterScore);

        $newScore = null;
        foreach ($this->store->search(['test'], ['query' => 'new text about dogs']) as $hit) {
            if ($hit->key === 'doc1') {
                $newScore = $hit->score;
            }
        }
        self::assertGreaterThan($afterScore, $newScore);
    }

    public function testNonIndexedDocumentsStillAppearInResults(): void
    {
        $this->store->put(['test'], 'doc1', ['text' => 'new text about dogs']);
        $this->store->put(['test'], 'doc2', ['text' => 'new text about dogs'], false);

        $results = $this->store->search(['test'], ['query' => 'new text about dogs', 'limit' => 3]);

        self::assertContains('doc1', self::keys($results));
        self::assertContains('doc2', self::keys($results));
    }

    public function testCombinesVectorSearchWithFilters(): void
    {
        foreach ([
            ['doc1', ['text' => 'red apple', 'color' => 'red', 'score' => 4.5]],
            ['doc2', ['text' => 'red car', 'color' => 'red', 'score' => 3.0]],
            ['doc3', ['text' => 'green apple', 'color' => 'green', 'score' => 4.0]],
            ['doc4', ['text' => 'blue car', 'color' => 'blue', 'score' => 3.5]],
        ] as [$key, $value]) {
            $this->store->put(['test'], $key, $value);
        }

        $results = $this->store->search(['test'], ['query' => 'apple', 'filter' => ['color' => 'red']]);
        self::assertCount(2, $results);
        self::assertSame('doc1', $results[0]->key);

        $results = $this->store->search(['test'], ['query' => 'car', 'filter' => ['color' => 'red']]);
        self::assertCount(2, $results);
        self::assertSame('doc2', $results[0]->key);

        $results = $this->store->search(['test'], ['query' => 'bbbbluuu', 'filter' => ['score' => ['$gt' => 3.2]]]);
        self::assertCount(3, $results);
        self::assertSame('doc4', $results[0]->key);

        $results = $this->store->search(['test'], ['query' => 'apple', 'filter' => ['score' => ['$gte' => 4.0], 'color' => 'green']]);
        self::assertCount(1, $results);
        self::assertSame('doc3', $results[0]->key);
    }

    public function testFieldSpecificIndexingAtStoreLevel(): void
    {
        $store = new InMemoryStore(new IndexConfig(500, $this->embeddings, ['key0', 'key1', 'key3']));

        // Two vectors (key1, key3); key2 is not indexed.
        $store->put(['test'], 'doc1', ['key1' => 'xxx', 'key2' => 'yyy', 'key3' => 'zzz']);
        // Three vectors (key0, key1, key3).
        $store->put(['test'], 'doc2', ['key0' => 'uuu', 'key1' => 'vvv', 'key2' => 'www', 'key3' => 'xxx']);

        // doc2.key3 and doc1.key1 both hold "xxx".
        $results1 = $store->search(['test'], ['query' => 'xxx']);
        self::assertCount(2, $results1);
        self::assertNotSame($results1[0]->key, $results1[1]->key);
        self::assertSame($results1[0]->score, $results1[1]->score);
        $baseScore = $results1[0]->score;
        self::assertNotNull($baseScore);

        $results2 = $store->search(['test'], ['query' => 'uuu']);
        self::assertCount(2, $results2);
        self::assertSame('doc2', $results2[0]->key);
        self::assertGreaterThan($results2[1]->score, $results2[0]->score);
        self::assertEqualsWithDelta($baseScore, $results2[0]->score, 1e-5);

        // "www" lives in the unindexed key2.
        $results3 = $store->search(['test'], ['query' => 'www']);
        self::assertCount(2, $results3);
        self::assertLessThan($baseScore, $results3[0]->score);
        self::assertLessThan($baseScore, $results3[1]->score);
    }

    public function testFieldSpecificIndexingAtOperationLevel(): void
    {
        $store = new InMemoryStore(new IndexConfig(500, $this->embeddings, ['key17']));

        $store->put(['test'], 'doc3', ['key0' => 'aaa', 'key1' => 'bbb', 'key2' => 'ccc', 'key3' => 'ddd'], ['key0', 'key1']);
        $store->put(['test'], 'doc4', ['key0' => 'eee', 'key1' => 'bbb', 'key2' => 'fff', 'key3' => 'ggg'], ['key1', 'key3']);

        $results1 = $store->search(['test'], ['query' => 'aaa']);
        self::assertCount(2, $results1);
        self::assertSame('doc3', $results1[0]->key);
        self::assertGreaterThan($results1[1]->score, $results1[0]->score);

        $results2 = $store->search(['test'], ['query' => 'ggg']);
        self::assertCount(2, $results2);
        self::assertSame('doc4', $results2[0]->key);
        self::assertGreaterThan($results2[1]->score, $results2[0]->score);

        $results3 = $store->search(['test'], ['query' => 'bbb']);
        self::assertCount(2, $results3);
        self::assertNotSame($results3[0]->key, $results3[1]->key);
        self::assertSame($results3[0]->score, $results3[1]->score);

        $results4 = $store->search(['test'], ['query' => 'ccc']);
        self::assertCount(2, $results4);
        foreach ($results4 as $hit) {
            self::assertLessThan($results1[0]->score, $hit->score);
        }

        $store->put(['test'], 'doc5', ['key0' => 'hhh', 'key1' => 'iii'], false);
        $results5 = $store->search(['test'], ['query' => 'hhh']);
        self::assertCount(3, $results5);
        $doc5 = array_values(array_filter($results5, static fn (SearchItem $r): bool => $r->key === 'doc5'));
        self::assertCount(1, $doc5);
        self::assertNull($doc5[0]->score);
    }

    public function testWholeDocumentIsEmbeddedByDefault(): void
    {
        $recording = new RecordingEmbeddings(500);
        $store = new InMemoryStore(new IndexConfig(500, $recording));

        $store->put(['test'], 'doc1', ['a' => 'b']);

        self::assertSame(["{\n  \"a\": \"b\"\n}"], $recording->embedded);
    }

    public function testIdenticalTextsInOneBatchAreEmbeddedOnce(): void
    {
        $recording = new RecordingEmbeddings(500);
        $store = new InMemoryStore(new IndexConfig(500, $recording, ['t']));

        $store->put(['test'], 'doc1', ['t' => 'same']);
        $store->put(['test'], 'doc2', ['t' => 'same']);
        self::assertSame(['same', 'same'], $recording->embedded);

        $recording->embedded = [];
        $store->batch([
            new PutOperation(['test'], 'doc3', ['t' => 'dup']),
            new PutOperation(['test'], 'doc4', ['t' => 'dup']),
        ]);
        self::assertSame(['dup'], $recording->embedded);
    }

    public function testNumericTextsAreEmbeddedAsStrings(): void
    {
        $store = new InMemoryStore(new IndexConfig(500, $this->embeddings, ['text']));
        $store->put(['test'], 'doc1', ['text' => '123']);

        $results = $store->search(['test'], ['query' => '123']);

        self::assertSame(['doc1'], self::keys($results));
        self::assertEqualsWithDelta(1.0, $results[0]->score, 1e-9);
    }
}

/**
 * Embeds text as a normalized histogram of its character codes.
 *
 * @internal
 */
class CharacterEmbeddings extends Embeddings
{
    public function __construct(public readonly int $dims = 500)
    {
    }

    public function embedQuery(string $document): array
    {
        return $this->generateEmbedding($document);
    }

    public function embedDocuments(array $documents): array
    {
        return array_map($this->generateEmbedding(...), $documents);
    }

    /**
     * @return list<float>
     */
    private function generateEmbedding(string $text): array
    {
        $embedding = array_fill(0, $this->dims, 0.0);
        $length = min(strlen($text), $this->dims);
        for ($i = 0; $i < $length; $i++) {
            $embedding[ord($text[$i]) % $this->dims] += 1.0;
        }
        $magnitude = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $embedding)));

        return array_map(static fn (float $v): float => $v / $magnitude, $embedding);
    }
}

/**
 * Remembers every text it was asked to embed.
 *
 * @internal
 */
final class RecordingEmbeddings extends CharacterEmbeddings
{
    /** @var list<string> */
    public array $embedded = [];

    public function embedDocuments(array $documents): array
    {
        $this->embedded = array_merge($this->embedded, $documents);

        return parent::embedDocuments($documents);
    }
}
