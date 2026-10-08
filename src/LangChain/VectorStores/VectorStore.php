<?php

declare(strict_types=1);

namespace LangChain\VectorStores;

use LangChain\Embeddings\EmbeddingsInterface;
use LangChain\Load\Serializable;
use LangChain\Schema\Document;
use LangChain\Tracers\CallbackManager;

/**
 * Abstract base for vector stores.
 *
 * Port of `VectorStore` from `@langchain/core/vectorstores`. The three
 * abstract operations are `addVectors`, `addDocuments` and
 * `similaritySearchVectorWithScore`; everything else is derived from them.
 * Promises become plain return values (see HANDOFF, "Promises exist so ported
 * logic maps 1:1").
 */
abstract class VectorStore extends Serializable implements VectorStoreInterface
{
    public EmbeddingsInterface $embeddings;

    /**
     * @param array<string, mixed> $dbConfig recorded as the serialization kwargs
     */
    public function __construct(EmbeddingsInterface $embeddings, array $dbConfig = [])
    {
        $this->embeddings = $embeddings;
        $this->kwargs = $dbConfig;
    }

    /** @return list<string> */
    public static function lcId(): array
    {
        $parts = explode('\\', static::class);

        return ['langchain', 'vectorstores', (string) end($parts)];
    }

    /**
     * Upstream's `lc_namespace` embeds the store type, so the id is per instance.
     *
     * @return array{id: list<string>, kwargs: array, lc: int}
     */
    public function toSerializedConstructor(): array
    {
        $parts = explode('\\', static::class);

        return [
            'lc' => self::LC_SCHEMA_VERSION,
            'type' => 'constructor',
            'id' => ['langchain', 'vectorstores', $this->vectorstoreType(), (string) end($parts)],
            'kwargs' => $this->filterKwargs($this->kwargs()),
        ];
    }

    abstract public function vectorstoreType(): string;

    abstract public function addVectors(array $vectors, array $documents, array $options = []): ?array;

    abstract public function addDocuments(array $documents, array $options = []): ?array;

    /**
     * @param array<string, mixed> $params
     */
    public function delete(array $params = []): void
    {
        throw new \RuntimeException('Not implemented.');
    }

    abstract public function similaritySearchVectorWithScore(array $query, int $k, mixed $filter = null): array;

    /**
     * `$callbacks` is accepted for parity; upstream does not pass it on to
     * `embedQuery` either ("implement passing to embedQuery later").
     */
    public function similaritySearch(string $query, int $k = 4, mixed $filter = null, array|CallbackManager|null $callbacks = null): array
    {
        $results = $this->similaritySearchVectorWithScore(
            $this->embeddings->embedQuery($query),
            $k,
            $filter,
        );

        return array_map(static fn (array $result): Document => $result[0], $results);
    }

    public function similaritySearchWithScore(string $query, int $k = 4, mixed $filter = null, array|CallbackManager|null $callbacks = null): array
    {
        return $this->similaritySearchVectorWithScore(
            $this->embeddings->embedQuery($query),
            $k,
            $filter,
        );
    }

    /**
     * @param list<string>                       $texts
     * @param array<array-key, mixed>            $metadatas one metadata map for every text, or a list with one per text
     * @param array<string, mixed>               $dbConfig
     */
    public static function fromTexts(array $texts, array $metadatas, EmbeddingsInterface $embeddings, array $dbConfig = []): static
    {
        throw new \RuntimeException(
            'the Langchain vectorstore implementation you are using forgot to override this, please report a bug',
        );
    }

    /**
     * @param list<Document>       $docs
     * @param array<string, mixed> $dbConfig
     */
    public static function fromDocuments(array $docs, EmbeddingsInterface $embeddings, array $dbConfig = []): static
    {
        throw new \RuntimeException(
            'the Langchain vectorstore implementation you are using forgot to override this, please report a bug',
        );
    }

    public function asRetriever(
        int|array|null $kOrFields = null,
        mixed $filter = null,
        array|CallbackManager|null $callbacks = null,
        ?array $tags = null,
        ?array $metadata = null,
        ?bool $verbose = null,
    ): VectorStoreRetriever {
        if (is_int($kOrFields)) {
            return new VectorStoreRetriever([
                'vectorStore' => $this,
                'k' => $kOrFields,
                'filter' => $filter,
                'tags' => [...($tags ?? []), $this->vectorstoreType()],
                'metadata' => $metadata,
                'verbose' => $verbose,
                'callbacks' => $callbacks,
            ]);
        }

        $params = [
            'vectorStore' => $this,
            'k' => $kOrFields['k'] ?? null,
            'filter' => $kOrFields['filter'] ?? null,
            'tags' => [...($kOrFields['tags'] ?? []), $this->vectorstoreType()],
            'metadata' => $kOrFields['metadata'] ?? null,
            'verbose' => $kOrFields['verbose'] ?? null,
            'callbacks' => $kOrFields['callbacks'] ?? null,
            'searchType' => $kOrFields['searchType'] ?? null,
        ];
        if (($kOrFields['searchType'] ?? null) === 'mmr') {
            $params['searchKwargs'] = $kOrFields['searchKwargs'] ?? null;
        }

        return new VectorStoreRetriever($params);
    }
}
