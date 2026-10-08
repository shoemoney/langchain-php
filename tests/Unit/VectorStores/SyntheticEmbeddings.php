<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\VectorStores;

use LangChain\Embeddings\Embeddings;

/**
 * Test double ported from `SyntheticEmbeddings` in `@langchain/core/utils/testing`.
 *
 * Deterministic vectors derived from the letters of the text.
 */
final class SyntheticEmbeddings extends Embeddings
{
    public function __construct(public int $vectorSize = 4)
    {
    }

    public function embedDocuments(array $documents): array
    {
        return array_map($this->embedQuery(...), $documents);
    }

    public function embedQuery(string $document): array
    {
        $doc = (string) preg_replace('/[^a-z ]/', '', strtolower($document));

        $padMod = strlen($doc) % $this->vectorSize;
        $padSize = strlen($doc) + ($padMod === 0 ? 0 : $this->vectorSize - $padMod);
        $doc = str_pad($doc, $padSize, ' ');

        $chunkSize = intdiv(strlen($doc), $this->vectorSize);
        if ($chunkSize === 0) {
            return [];
        }

        $ret = [];
        for ($co = 0; $co < strlen($doc); $co += $chunkSize) {
            $chunk = substr($doc, $co, $chunkSize);
            $sum = 0;
            for ($i = 0; $i < strlen($chunk); ++$i) {
                $sum += $chunk === ' ' ? 0 : ord($chunk[$i]);
            }
            $ret[] = ($sum % 26) / 26;
        }

        return $ret;
    }
}
