<?php

declare(strict_types=1);

namespace LangChain\VectorStores;

use LangChain\Retrievers\BaseRetriever;
use LangChain\Schema\Document;
use LangChain\Tracers\CallbackManagerForRetrieverRun;

/**
 * A retriever that queries a {@see VectorStoreInterface}.
 *
 * Port of `VectorStoreRetriever` from `@langchain/core/vectorstores`.
 *
 * Fields (the constructor's array): `vectorStore` (required), `k` (default 4),
 * `filter`, `searchType` (`similarity` or `mmr`), `searchKwargs` (MMR only:
 * `fetchK`, `lambda`), plus the `callbacks` / `tags` / `metadata` / `verbose`
 * of {@see BaseRetriever}.
 */
class VectorStoreRetriever extends BaseRetriever implements VectorStoreRetrieverInterface
{
    public VectorStoreInterface $vectorStore;

    public int $k = 4;

    public string $searchType = 'similarity';

    /** @var array{fetchK?: int, lambda?: float}|null */
    public ?array $searchKwargs = null;

    public mixed $filter = null;

    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(array $fields)
    {
        parent::__construct($fields);

        $vectorStore = $fields['vectorStore'] ?? null;
        if (!$vectorStore instanceof VectorStoreInterface) {
            throw new \InvalidArgumentException('VectorStoreRetriever requires a "vectorStore" implementing VectorStoreInterface.');
        }

        $this->vectorStore = $vectorStore;
        $this->k = (int) ($fields['k'] ?? $this->k);
        $this->searchType = (string) ($fields['searchType'] ?? $this->searchType);
        $this->filter = $fields['filter'] ?? null;
        if ($this->searchType === 'mmr') {
            $this->searchKwargs = $fields['searchKwargs'] ?? null;
        }
    }

    /** @return list<string> */
    public static function lcNamespace(): array
    {
        return ['langchain_core', 'vectorstores'];
    }

    public function vectorstoreType(): string
    {
        return $this->vectorStore->vectorstoreType();
    }

    /**
     * @return list<Document>
     */
    protected function getRelevantDocuments(string $query, ?CallbackManagerForRetrieverRun $runManager = null): array
    {
        if ($this->searchType === 'mmr') {
            if (!method_exists($this->vectorStore, 'maxMarginalRelevanceSearch')) {
                throw new \RuntimeException(sprintf(
                    'The vector store backing this retriever, %s does not support max marginal relevance search.',
                    $this->vectorstoreType(),
                ));
            }

            return $this->vectorStore->maxMarginalRelevanceSearch(
                $query,
                ['k' => $this->k, 'filter' => $this->filter, ...($this->searchKwargs ?? [])],
                $runManager?->getChild('vectorstore'),
            );
        }

        return $this->vectorStore->similaritySearch(
            $query,
            $this->k,
            $this->filter,
            $runManager?->getChild('vectorstore'),
        );
    }

    public function addDocuments(array $documents, array $options = []): ?array
    {
        return $this->vectorStore->addDocuments($documents, $options);
    }
}
