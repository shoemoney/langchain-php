<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\ExampleSelectors;

use LangChain\Embeddings\Embeddings;

/**
 * Bag-of-words embeddings over a fixed vocabulary, so similarity is predictable by eye.
 */
final class KeywordEmbeddings extends Embeddings
{
    private const VOCABULARY = ['happy', 'sad', 'tall', 'short', 'sunny', 'gloomy', 'windy', 'calm'];

    /** @var list<string> */
    public array $embedded = [];

    public function embedDocuments(array $documents): array
    {
        return array_map($this->vectorOf(...), $documents);
    }

    public function embedQuery(string $document): array
    {
        return $this->vectorOf($document);
    }

    /** @return list<float> */
    private function vectorOf(string $text): array
    {
        $this->embedded[] = $text;
        $words = preg_split('/\s+/', strtolower(trim($text))) ?: [];

        return array_map(
            static fn (string $term): float => (float) count(array_keys($words, $term, true)),
            self::VOCABULARY,
        );
    }
}
