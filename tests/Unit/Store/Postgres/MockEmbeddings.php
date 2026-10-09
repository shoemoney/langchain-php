<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\Postgres;

use LangChain\Embeddings\EmbeddingsInterface;

/**
 * Port of `createMockEmbedding` from `tests/store.int.test.ts`.
 *
 * Three texts map to fixed one-hot vectors so a search for them has a known
 * answer; every other text maps to a sine vector, or to the zero vector when
 * `$asEmpty` is set. Every call is recorded, including query embeddings, which
 * upstream's function-style `embed` also routes through the same function.
 */
final class MockEmbeddings implements EmbeddingsInterface
{
    /** @var list<list<string>> */
    public array $calls = [];

    public function __construct(private readonly int $dims, private readonly bool $asEmpty)
    {
    }

    public function embedDocuments(array $documents): array
    {
        $this->calls[] = $documents;
        $vectors = [];
        foreach ($documents as $index => $text) {
            $vectors[] = $this->vectorFor($text, $index);
        }

        return $vectors;
    }

    public function embedQuery(string $document): array
    {
        $this->calls[] = [$document];

        return $this->vectorFor($document, 0);
    }

    public function wasCalled(): bool
    {
        return $this->calls !== [];
    }

    /**
     * @return list<float>
     */
    private function vectorFor(string $text, int $index): array
    {
        $title = array_fill(0, $this->dims, 0.0);
        $title[0] = 1.0;
        $content = array_fill(0, $this->dims, 0.0);
        $content[$this->dims - 1] = 1.0;

        if ($text === 'Combined Options Test' || $text === 'combined options') {
            return $title;
        }
        if ($text === 'Testing both TTL and indexing options' || $text === 'testing both') {
            return $content;
        }
        if ($this->asEmpty) {
            return array_fill(0, $this->dims, 0.0);
        }

        $length = strlen($text);
        $embedding = [];
        for ($i = 0; $i < $this->dims; $i++) {
            $embedding[] = sin((($length > 0 ? ord($text[$i % $length]) : 0) + $index) * 0.1);
        }

        return $embedding;
    }
}
