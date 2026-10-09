<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\MongoDB;

use LangChain\Embeddings\Embeddings;

/**
 * Deterministic 10-dimension embeddings derived from a hash of the text, as upstream's
 * `createTestEmbeddings`. Not semantic: it only guarantees the same text gives the same vector.
 * Records every call so a test can assert what was embedded.
 */
final class HashEmbeddings extends Embeddings
{
    /** @var list<list<string>> One entry per embedDocuments call. */
    public array $documentCalls = [];

    /** @var list<string> */
    public array $queryCalls = [];

    public function embedDocuments(array $documents): array
    {
        $this->documentCalls[] = $documents;

        return array_map(self::vectorOf(...), $documents);
    }

    public function embedQuery(string $document): array
    {
        $this->queryCalls[] = $document;

        return self::vectorOf($document);
    }

    /** @return list<float> */
    private static function vectorOf(string $text): array
    {
        $hash = 0;
        foreach (str_split($text) as $char) {
            // Signed 32-bit arithmetic, as JS `(hash << 5) - hash + code` then `hash & hash`.
            $hash = (($hash << 5) - $hash) + ord($char);
            $hash = ($hash & 0xFFFFFFFF) >= 0x80000000 ? ($hash & 0xFFFFFFFF) - 0x100000000 : ($hash & 0xFFFFFFFF);
        }

        $vector = [];
        for ($i = 0; $i < 10; $i++) {
            $vector[] = sin(($hash + $i) * 0.1);
        }

        return $vector;
    }
}
