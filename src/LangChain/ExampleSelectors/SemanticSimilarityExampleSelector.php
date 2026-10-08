<?php

declare(strict_types=1);

namespace LangChain\ExampleSelectors;

use LangChain\Embeddings\EmbeddingsInterface;
use LangChain\Schema\Document;
use LangChain\VectorStores\VectorStore;
use LangChain\VectorStores\VectorStoreInterface;
use LangChain\VectorStores\VectorStoreRetrieverInterface;

/**
 * Selects the examples semantically closest to the input, via a vector store.
 *
 * Port of `SemanticSimilarityExampleSelector` from
 * `@langchain/core/example_selectors/semantic_similarity`.
 *
 * Construct it with either `vectorStore` (+ `k`, `filter`) or
 * `vectorStoreRetriever`, never both; `exampleKeys` / `inputKeys` are optional.
 */
class SemanticSimilarityExampleSelector extends BaseExampleSelector
{
    public VectorStoreRetrieverInterface $vectorStoreRetriever;

    /** @var list<string>|null */
    public ?array $exampleKeys;

    /** @var list<string>|null */
    public ?array $inputKeys;

    /**
     * @param array{
     *     vectorStore?: VectorStoreInterface,
     *     vectorStoreRetriever?: VectorStoreRetrieverInterface,
     *     k?: int,
     *     filter?: mixed,
     *     exampleKeys?: list<string>,
     *     inputKeys?: list<string>,
     * } $data
     */
    public function __construct(array $data)
    {
        $this->exampleKeys = $data['exampleKeys'] ?? null;
        $this->inputKeys = $data['inputKeys'] ?? null;

        if (isset($data['vectorStore'])) {
            $this->vectorStoreRetriever = $data['vectorStore']->asRetriever([
                'k' => $data['k'] ?? 4,
                'filter' => $data['filter'] ?? null,
            ]);
        } elseif (isset($data['vectorStoreRetriever'])) {
            $this->vectorStoreRetriever = $data['vectorStoreRetriever'];
        } else {
            throw new \InvalidArgumentException('You must specify one of "vectorStore" and "vectorStoreRetriever".');
        }
    }

    /**
     * Store the example as a document: its sorted input values as text, itself as metadata.
     *
     * @param array<string, mixed> $example
     */
    public function addExample(array $example): null
    {
        $inputKeys = $this->inputKeys ?? array_keys($example);
        $stringExample = self::joinSortedValues(self::pickKeys($example, $inputKeys));

        $this->vectorStoreRetriever->addDocuments([new Document($stringExample, $example)]);

        return null;
    }

    /**
     * @param array<string, mixed> $inputVariables
     *
     * @return list<array<string, mixed>>
     */
    public function selectExamples(array $inputVariables): array
    {
        $inputKeys = $this->inputKeys ?? array_keys($inputVariables);
        $query = self::joinSortedValues(self::pickKeys($inputVariables, $inputKeys));

        /** @var list<Document> $exampleDocs */
        $exampleDocs = $this->vectorStoreRetriever->invoke($query);

        $examples = array_map(static fn (Document $doc): array => $doc->metadata, $exampleDocs);
        if ($this->exampleKeys !== null) {
            $exampleKeys = $this->exampleKeys;

            return array_map(
                static fn (array $example): array => array_combine(
                    $exampleKeys,
                    array_map(static fn (string $key): mixed => $example[$key] ?? null, $exampleKeys),
                ),
                $examples,
            );
        }

        return $examples;
    }

    /**
     * Build the selector from raw examples, embedding them into a new store.
     *
     * @param list<array<string, string>>       $examples
     * @param class-string<VectorStore>         $vectorStoreCls
     * @param array<string, mixed>              $options `k`, `inputKeys`, `exampleKeys`, and the store's own config
     */
    public static function fromExamples(
        array $examples,
        EmbeddingsInterface $embeddings,
        string $vectorStoreCls,
        array $options = [],
    ): self {
        $inputKeys = $options['inputKeys'] ?? null;
        $stringExamples = array_map(
            static fn (array $example): string => self::joinSortedValues($inputKeys !== null ? self::pickKeys($example, $inputKeys) : $example),
            $examples,
        );

        $vectorStore = $vectorStoreCls::fromTexts($stringExamples, $examples, $embeddings, $options);

        return new self([
            'vectorStore' => $vectorStore,
            'k' => $options['k'] ?? 4,
            'exampleKeys' => $options['exampleKeys'] ?? null,
            'inputKeys' => $options['inputKeys'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $values
     * @param list<string>         $keys
     *
     * @return array<string, mixed>
     */
    private static function pickKeys(array $values, array $keys): array
    {
        $picked = [];
        foreach ($keys as $key) {
            $picked[$key] = $values[$key] ?? null;
        }

        return $picked;
    }

    /**
     * Values ordered by key, joined with a space (upstream `sortedValues(...).join(" ")`).
     *
     * @param array<string, mixed> $values
     */
    private static function joinSortedValues(array $values): string
    {
        ksort($values, SORT_STRING);

        return implode(' ', array_map(
            static fn (mixed $v): string => match (true) {
                $v === null => '',
                is_bool($v) => $v ? 'true' : 'false',
                is_scalar($v) || $v instanceof \Stringable => (string) $v,
                default => (string) json_encode($v),
            },
            array_values($values),
        ));
    }
}
