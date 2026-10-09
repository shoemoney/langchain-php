<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\Redis;

use LangChain\Embeddings\Embeddings;

/**
 * Embeddings computed by a closure, standing in for the mock `embedDocuments` objects the
 * upstream tests build inline.
 */
final class CallbackEmbeddings extends Embeddings
{
    /** @var list<string> every text embedded, in order */
    public array $embedded = [];

    /** @param \Closure(string): list<float> $embed */
    public function __construct(private readonly \Closure $embed)
    {
    }

    /** The 4-dimensional, unit-length, character-sum embedding of the first upstream describe block. */
    public static function characters(): self
    {
        return new self(static function (string $text): array {
            $embedding = [0.0, 0.0, 0.0, 0.0];
            for ($i = 0; $i < strlen($text); $i++) {
                $embedding[$i % 4] += ord($text[$i]);
            }
            $norm = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $embedding)));

            return array_map(static fn (float $v): float => $v / $norm, $embedding);
        });
    }

    /** The 3-dimensional, hash-of-the-text embedding of the distance-metric describe block. */
    public static function hashed(): self
    {
        return new self(static function (string $text): array {
            $hash = array_sum(array_map('ord', str_split($text)));

            return [sin($hash) * 0.5 + 0.5, cos($hash) * 0.5 + 0.5, sin($hash * 2) * 0.5 + 0.5];
        });
    }

    public function embedDocuments(array $documents): array
    {
        $vectors = [];
        foreach ($documents as $document) {
            $this->embedded[] = $document;
            $vectors[] = ($this->embed)($document);
        }

        return $vectors;
    }

    public function embedQuery(string $document): array
    {
        return $this->embedDocuments([$document])[0];
    }
}
