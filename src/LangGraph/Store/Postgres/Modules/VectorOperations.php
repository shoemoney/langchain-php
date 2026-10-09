<?php

declare(strict_types=1);

namespace LangGraph\Store\Postgres\Modules;

use LangChain\Embeddings\EmbeddingsInterface;

/**
 * Embedding generation and text extraction for vector indexing.
 *
 * Port of `store/modules/vector-operations.ts`. A text is embedded only when it
 * is non-blank after trimming, and a vector whose length differs from the
 * configured `dims` is skipped, exactly as upstream.
 */
final class VectorOperations
{
    public function __construct(private readonly DatabaseCore $core)
    {
    }

    /**
     * Replace the stored vectors of one item.
     *
     * @param array<string, mixed>    $value
     * @param list<string>|false|null $index Field paths to index, false to skip.
     */
    public function indexItemVectors(string $namespacePath, string $key, array $value, array|false|null $index = null): void
    {
        $indexConfig = $this->core->indexConfig;
        if ($indexConfig === null || $index === false) {
            return;
        }

        $this->core->query(
            'DELETE FROM ' . $this->core->vectorsTable() . ' WHERE namespace_path = ? AND key = ?',
            [$namespacePath, $key],
        );

        $fields = ($index === null || $index === []) ? ($indexConfig->fields ?? ['$']) : $index;
        /** @var list<array{fieldPath: string, text: string}> $textsToEmbed */
        $textsToEmbed = [];

        foreach ($fields as $fieldPath) {
            $extracted = $this->extractTextAtPath($value, $fieldPath);
            foreach ($extracted as $i => $text) {
                $trimmed = trim($text);
                if ($trimmed !== '') {
                    $textsToEmbed[] = [
                        'fieldPath' => count($extracted) > 1 ? "{$fieldPath}[{$i}]" : $fieldPath,
                        'text' => $trimmed,
                    ];
                }
            }
        }

        if ($textsToEmbed === []) {
            return;
        }

        $embeddings = $this->generateEmbeddings(array_column($textsToEmbed, 'text'));

        foreach ($textsToEmbed as $i => ['fieldPath' => $fieldPath, 'text' => $text]) {
            $embedding = $embeddings[$i] ?? null;
            if (is_array($embedding) && count($embedding) === $indexConfig->dims) {
                $this->core->query(
                    'INSERT INTO ' . $this->core->vectorsTable()
                    . ' (namespace_path, key, field_path, text_content, embedding) VALUES (?, ?, ?, ?, ?::vector)',
                    [$namespacePath, $key, $fieldPath, $text, self::toVectorLiteral($embedding)],
                );
            }
        }
    }

    /**
     * @param  list<string>      $texts
     * @return list<list<float>>
     */
    public function generateEmbeddings(array $texts): array
    {
        $embed = $this->requireIndexConfig()->embed;

        if ($embed instanceof EmbeddingsInterface) {
            return $embed->embedDocuments($texts);
        }
        if (is_callable($embed)) {
            return $embed($texts);
        }

        throw new \RuntimeException('Invalid embedding configuration');
    }

    /**
     * @return list<float>
     */
    public function generateQueryEmbedding(string $text): array
    {
        $embed = $this->requireIndexConfig()->embed;

        if ($embed instanceof EmbeddingsInterface) {
            return $embed->embedQuery($text);
        }
        if (is_callable($embed)) {
            return $embed([$text])[0] ?? [];
        }

        throw new \RuntimeException('Invalid embedding configuration');
    }

    /**
     * A pgvector text literal such as `[0.1,0.2]`.
     *
     * @param array<int, int|float> $embedding
     */
    public static function toVectorLiteral(array $embedding): string
    {
        return '[' . implode(',', array_map(
            static fn (int|float $v): string => (string) json_encode($v),
            array_values($embedding),
        )) . ']';
    }

    /**
     * Text at a dotted path (`a.b`, `items[0]`, `items[-1]`, `items[*].text`, `$`).
     *
     * @return list<string>
     */
    public function extractTextAtPath(mixed $obj, string $path): array
    {
        if ($path === '$') {
            return [self::jsonStringify($obj)];
        }

        $parts = explode('.', $path);
        $current = $obj;
        $results = [];

        foreach ($parts as $i => $part) {
            if (str_contains($part, '[')) {
                [$field, $arrayPart] = explode('[', $part, 2);
                $arrayIndex = str_replace(']', '', $arrayPart);

                if ($field !== '' && is_array($current)) {
                    $current = $current[$field] ?? null;
                }

                if ($arrayIndex === '*') {
                    if (is_array($current)) {
                        $remaining = implode('.', array_slice($parts, $i + 1));
                        foreach ($current as $item) {
                            if ($remaining !== '') {
                                if ($item !== null) {
                                    array_push($results, ...$this->extractTextAtPath($item, $remaining));
                                }
                            } elseif (is_string($item)) {
                                $results[] = $item;
                            } elseif (is_array($item)) {
                                $results[] = self::jsonStringify($item);
                            } elseif ($item !== null) {
                                $results[] = QueryBuilder::stringify($item);
                            }
                        }
                    }

                    return $results;
                }
                if ($arrayIndex === '-1') {
                    if (is_array($current) && $current !== []) {
                        $current = $current[array_key_last($current)];
                    }
                } else {
                    $index = (int) $arrayIndex;
                    if (is_array($current) && $index >= 0 && $index < count($current)) {
                        $current = array_values($current)[$index];
                    }
                }
            } elseif (is_array($current)) {
                $current = $current[$part] ?? null;
            }

            if ($current === null) {
                return [];
            }
        }

        if (is_string($current)) {
            $results[] = $current;
        } elseif (is_array($current)) {
            $results[] = self::jsonStringify($current);
        } else {
            $results[] = QueryBuilder::stringify($current);
        }

        return $results;
    }

    private static function jsonStringify(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function requireIndexConfig(): IndexConfig
    {
        return $this->core->indexConfig ?? throw new \RuntimeException('Vector search not configured');
    }
}
