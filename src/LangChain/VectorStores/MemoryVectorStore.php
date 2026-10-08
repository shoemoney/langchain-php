<?php

declare(strict_types=1);

namespace LangChain\VectorStores;

use LangChain\Embeddings\EmbeddingsInterface;
use LangChain\Schema\Document;
use LangChain\Utils\MathUtils;

/**
 * An in-memory vector store ranking by cosine similarity (or a supplied callable).
 *
 * Port of `MemoryVectorStore` from `langchain/vectorstores/memory`.
 *
 * Known non-exact behaviours, all pinned by tests:
 *  - the default similarity is {@see MathUtils::cosineSimilarity()}, which folds
 *    a zero-magnitude NaN to 0.0 (upstream's `cosine` returns NaN);
 *  - results are sorted by a real stable descending sort. Upstream's comparator
 *    `(a > b ? -1 : 0)` is not a valid ordering and its own test pins the
 *    correct order, so that is what is ported;
 *  - `Document` has no `id` in this port, so a row's id is read from an `id`
 *    property when the document has one and is never written back onto the
 *    documents search returns.
 *
 * The filter is a `callable(Document): bool`.
 */
class MemoryVectorStore extends VectorStore
{
    /** @var list<MemoryVector> */
    public array $memoryVectors = [];

    /** @var callable(list<float|int>, list<float|int>): float */
    public $similarity;

    /**
     * @param array{similarity?: callable} $args
     */
    public function __construct(EmbeddingsInterface $embeddings, array $args = [])
    {
        $similarity = $args['similarity'] ?? null;
        unset($args['similarity']);
        parent::__construct($embeddings, $args);

        $this->similarity = $similarity ?? self::cosine(...);
    }

    public function vectorstoreType(): string
    {
        return 'memory';
    }

    public function addDocuments(array $documents, array $options = []): ?array
    {
        $texts = array_map(static fn (Document $d): string => $d->pageContent, $documents);

        return $this->addVectors($this->embeddings->embedDocuments($texts), $documents, $options);
    }

    public function addVectors(array $vectors, array $documents, array $options = []): ?array
    {
        foreach ($vectors as $idx => $embedding) {
            $document = $documents[$idx];
            $id = $document->id ?? null;
            $this->memoryVectors[] = new MemoryVector(
                $document->pageContent,
                $embedding,
                $document->metadata,
                is_string($id) ? $id : null,
            );
        }

        return null;
    }

    /**
     * @param callable(Document): bool|null $filter
     *
     * @return list<array{similarity: float, index: int, metadata: array<string, mixed>, content: string, embedding: list<float|int>, id: ?string}>
     */
    protected function queryVectors(array $query, int $k, mixed $filter = null): array
    {
        $rows = [];
        $index = 0;
        foreach ($this->memoryVectors as $vector) {
            if ($filter !== null && !$filter(new Document($vector->content, $vector->metadata))) {
                continue;
            }
            $rows[] = [
                'similarity' => ($this->similarity)($query, $vector->embedding),
                'index' => $index++,
                'metadata' => $vector->metadata,
                'content' => $vector->content,
                'embedding' => $vector->embedding,
                'id' => $vector->id,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['similarity'] <=> $a['similarity']);

        return array_slice($rows, 0, max($k, 0));
    }

    public function similaritySearchVectorWithScore(array $query, int $k, mixed $filter = null): array
    {
        return array_map(
            static fn (array $search): array => [new Document($search['content'], $search['metadata']), $search['similarity']],
            $this->queryVectors($query, $k, $filter),
        );
    }

    /**
     * Max marginal relevance search: relevant to the query, diverse among themselves.
     *
     * @param array{k: int, fetchK?: int, lambda?: float, filter?: mixed} $options
     * @param mixed                                                       $callbacks accepted for parity, unused
     *
     * @return list<Document>
     */
    public function maxMarginalRelevanceSearch(string $query, array $options, mixed $callbacks = null): array
    {
        $queryEmbedding = $this->embeddings->embedQuery($query);

        $searches = $this->queryVectors($queryEmbedding, $options['fetchK'] ?? 20, $options['filter'] ?? null);

        $embeddingList = array_map(static fn (array $search): array => $search['embedding'], $searches);

        $mmrIndexes = MathUtils::maximalMarginalRelevance(
            $queryEmbedding,
            $embeddingList,
            (float) ($options['lambda'] ?? 0.5),
            $options['k'],
        );

        return array_map(
            static fn (int $idx): Document => new Document($searches[$idx]['content'], $searches[$idx]['metadata']),
            $mmrIndexes,
        );
    }

    public static function fromTexts(array $texts, array $metadatas, EmbeddingsInterface $embeddings, array $dbConfig = []): static
    {
        $perText = array_is_list($metadatas) && $metadatas !== [];
        $docs = [];
        foreach ($texts as $i => $text) {
            $metadata = $perText ? ($metadatas[$i] ?? []) : $metadatas;
            $docs[] = new Document($text, $metadata);
        }

        return static::fromDocuments($docs, $embeddings, $dbConfig);
    }

    public static function fromDocuments(array $docs, EmbeddingsInterface $embeddings, array $dbConfig = []): static
    {
        $instance = new static($embeddings, $dbConfig);
        $instance->addDocuments($docs);

        return $instance;
    }

    /**
     * @param array{similarity?: callable} $dbConfig
     */
    public static function fromExistingIndex(EmbeddingsInterface $embeddings, array $dbConfig = []): static
    {
        return new static($embeddings, $dbConfig);
    }

    /**
     * Cosine similarity of two vectors, through the shared matrix helper.
     *
     * @param list<float|int> $a
     * @param list<float|int> $b
     */
    private static function cosine(array $a, array $b): float
    {
        return (float) (MathUtils::cosineSimilarity([$a], [$b])[0][0] ?? 0.0);
    }
}
