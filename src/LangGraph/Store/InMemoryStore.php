<?php

declare(strict_types=1);

namespace LangGraph\Store;

/**
 * In-memory key-value store with optional vector search.
 *
 * Port of `InMemoryStore` from `store/memory.ts`. Supports the basic key-value
 * operations and, when configured with an {@see IndexConfig}, embeds documents
 * on put and ranks searches by cosine similarity.
 *
 * ```php
 * $store = new InMemoryStore();
 * $store->put(['users', '123'], 'prefs', ['theme' => 'dark']);
 * $item = $store->get(['users', '123'], 'prefs');
 *
 * $store = new InMemoryStore(new IndexConfig(dims: 1536, embeddings: $embeddings));
 * $store->put(['docs'], 'doc1', ['text' => 'Python tutorial']);
 * $hits = $store->search(['docs'], ['query' => 'python programming']);
 * ```
 *
 * All data lives in this process and is lost when it exits. Namespaces are keyed
 * by their labels joined with `:`, so, as upstream, `["a:b"]` and `["a", "b"]`
 * collide, and a search prefix is matched as a string prefix of that key.
 */
class InMemoryStore extends BaseStore
{
    /** @var array<string, array<string, Item>> namespace key -> item key -> item */
    private array $data = [];

    /** @var array<string, array<string, array<string, list<float>>>> namespace key -> item key -> field -> vector */
    private array $vectors = [];

    /** @var list<array{0: string, 1: list<string>}> */
    private array $tokenizedFields = [];

    public function __construct(public readonly ?IndexConfig $indexConfig = null)
    {
        if ($indexConfig !== null) {
            foreach ($indexConfig->fields ?? ['$'] as $field) {
                $this->tokenizedFields[] = [$field, $field === '$' ? [$field] : StoreUtils::tokenizePath($field)];
            }
        }
    }

    public function batch(array $operations): array
    {
        /** @var array<int, mixed> $results */
        $results = [];
        /** @var array<string, PutOperation> $putOps */
        $putOps = [];
        /** @var array<int, array{0: SearchOperation, 1: list<Item>}> $searchOps */
        $searchOps = [];

        // First pass: answer gets and listings, and set aside searches and puts.
        foreach ($operations as $i => $op) {
            if ($op instanceof GetOperation) {
                $results[$i] = $this->getOperation($op);
            } elseif ($op instanceof SearchOperation) {
                $searchOps[$i] = [$op, $this->filterItems($op)];
                $results[$i] = null;
            } elseif ($op instanceof PutOperation) {
                $putOps[implode(':', $op->namespace) . ':' . $op->key] = $op;
                $results[$i] = null;
            } elseif ($op instanceof ListNamespacesOperation) {
                $results[$i] = $this->listNamespacesOperation($op);
            }
        }

        if ($searchOps !== []) {
            $embeddings = $this->indexConfig?->embeddings;
            $queryVectors = [];
            if ($embeddings !== null) {
                foreach ($searchOps as [$op]) {
                    if ($op->query !== null && $op->query !== '' && !array_key_exists($op->query, $queryVectors)) {
                        $queryVectors[$op->query] = $embeddings->embedQuery($op->query);
                    }
                }
            }

            foreach ($searchOps as $i => [$op, $candidates]) {
                if ($embeddings !== null && $op->query !== null && $op->query !== '') {
                    $results[$i] = $this->scoreResults($candidates, $queryVectors[$op->query], $op->offset, $op->limit);
                } else {
                    $results[$i] = array_slice(
                        array_map(static fn (Item $item): SearchItem => SearchItem::fromItem($item), $candidates),
                        $op->offset,
                        $op->limit,
                    );
                }
            }
        }

        // Embed before applying any put so a failing embedding leaves the store untouched.
        if ($putOps !== [] && $this->indexConfig !== null) {
            $toEmbed = $this->extractTexts(array_values($putOps));
            if ($toEmbed !== []) {
                $texts = array_map(static fn (int|string $text): string => (string) $text, array_keys($toEmbed));
                $this->insertVectors($texts, array_values($toEmbed), $this->indexConfig->embeddings->embedDocuments($texts));
            }
        }

        foreach ($putOps as $op) {
            $this->putOperation($op);
        }

        ksort($results);

        return array_values($results);
    }

    private function getOperation(GetOperation $op): ?Item
    {
        return $this->data[implode(':', $op->namespace)][$op->key] ?? null;
    }

    private function putOperation(PutOperation $op): void
    {
        $namespaceKey = implode(':', $op->namespace);
        $this->data[$namespaceKey] ??= [];

        if ($op->value === null) {
            unset($this->data[$namespaceKey][$op->key]);

            return;
        }

        $now = new \DateTimeImmutable();
        $existing = $this->data[$namespaceKey][$op->key] ?? null;
        if ($existing !== null) {
            $existing->value = $op->value;
            $existing->updatedAt = $now;

            return;
        }

        $this->data[$namespaceKey][$op->key] = new Item($op->value, $op->key, $op->namespace, $now, $now);
    }

    /**
     * @return list<list<string>>
     */
    private function listNamespacesOperation(ListNamespacesOperation $op): array
    {
        $namespaces = array_map(
            static fn (int|string $ns): array => explode(':', (string) $ns),
            array_keys($this->data),
        );

        if ($op->matchConditions !== null && $op->matchConditions !== []) {
            $namespaces = array_values(array_filter(
                $namespaces,
                function (array $ns) use ($op): bool {
                    foreach ($op->matchConditions ?? [] as $condition) {
                        if (!$this->doesMatch($condition, $ns)) {
                            return false;
                        }
                    }

                    return true;
                },
            ));
        }

        if ($op->maxDepth !== null) {
            $unique = [];
            foreach ($namespaces as $ns) {
                $unique[implode(':', array_slice($ns, 0, $op->maxDepth))] = true;
            }
            $namespaces = array_map(
                static fn (int|string $ns): array => explode(':', (string) $ns),
                array_keys($unique),
            );
        }

        usort($namespaces, static fn (array $a, array $b): int => self::localeCompare(implode(':', $a), implode(':', $b)));

        return array_slice($namespaces, $op->offset, $op->limit);
    }

    /**
     * @param list<string> $key
     */
    private function doesMatch(MatchCondition $condition, array $key): bool
    {
        $path = $condition->path;
        if (count($path) > count($key)) {
            return false;
        }

        if ($condition->matchType === MatchCondition::PREFIX) {
            foreach ($path as $index => $label) {
                if ($label !== '*' && $key[$index] !== $label) {
                    return false;
                }
            }

            return true;
        }

        if ($condition->matchType === MatchCondition::SUFFIX) {
            $offset = count($key) - count($path);
            foreach ($path as $index => $label) {
                if ($label !== '*' && $key[$offset + $index] !== $label) {
                    return false;
                }
            }

            return true;
        }

        throw new \InvalidArgumentException("Unsupported match type: {$condition->matchType}");
    }

    /**
     * @return list<Item>
     */
    private function filterItems(SearchOperation $op): array
    {
        $prefix = implode(':', $op->namespacePrefix);
        $candidates = [];
        foreach ($this->data as $namespace => $items) {
            if (str_starts_with((string) $namespace, $prefix)) {
                array_push($candidates, ...array_values($items));
            }
        }

        if ($op->filter === null) {
            return $candidates;
        }

        return array_values(array_filter(
            $candidates,
            static function (Item $item) use ($op): bool {
                foreach ($op->filter ?? [] as $key => $value) {
                    if (!StoreUtils::compareValues($item->value[$key] ?? null, $value)) {
                        return false;
                    }
                }

                return true;
            },
        ));
    }

    /**
     * Rank candidates by their best-scoring vector; unembedded items trail the ranked ones.
     *
     * @param  list<Item>   $candidates
     * @param  list<float>  $queryVector
     * @return list<SearchItem>
     */
    private function scoreResults(array $candidates, array $queryVector, int $offset, int $limit): array
    {
        $flatItems = [];
        $flatVectors = [];
        $scoreless = [];

        foreach ($candidates as $item) {
            $vectors = $this->getVectors($item);
            if ($vectors === []) {
                $scoreless[] = $item;
                continue;
            }
            foreach ($vectors as $vector) {
                $flatItems[] = $item;
                $flatVectors[] = $vector;
            }
        }

        $scores = self::cosineSimilarities($queryVector, $flatVectors);

        $sorted = [];
        foreach ($scores as $i => $score) {
            $sorted[] = [$score, $flatItems[$i], $i];
        }
        // Descending by score; the index keeps equal scores in insertion order (stable, as in JS).
        usort($sorted, static fn (array $a, array $b): int => ($b[0] <=> $a[0]) ?: ($a[2] <=> $b[2]));

        $seen = [];
        $kept = [];
        foreach ($sorted as [$score, $item]) {
            $key = implode(':', $item->namespace) . ':' . $item->key;
            if (isset($seen[$key])) {
                continue;
            }

            $ix = count($seen);
            if ($ix >= $offset + $limit) {
                break;
            }
            $seen[$key] = true;
            if ($ix < $offset) {
                continue;
            }
            $kept[] = [$score, $item];
        }

        if ($scoreless !== [] && count($kept) < $limit) {
            foreach (array_slice($scoreless, 0, $limit - count($kept)) as $item) {
                $key = implode(':', $item->namespace) . ':' . $item->key;
                if (!isset($seen[$key])) {
                    $seen[$key] = true;
                    $kept[] = [null, $item];
                }
            }
        }

        return array_map(
            static fn (array $pair): SearchItem => SearchItem::fromItem($pair[1], $pair[0]),
            $kept,
        );
    }

    /**
     * Collect the distinct texts to embed and where each came from.
     *
     * @param  list<PutOperation> $ops
     * @return array<string, list<array{0: list<string>, 1: string, 2: string}>> text -> [namespace, key, field path]
     */
    private function extractTexts(array $ops): array
    {
        $toEmbed = [];

        foreach ($ops as $op) {
            if ($op->value === null || $op->index === false) {
                continue;
            }

            $paths = $op->index === null
                ? $this->tokenizedFields
                : array_map(static fn (string $ix): array => [$ix, StoreUtils::tokenizePath($ix)], $op->index);

            foreach ($paths as [$path, $field]) {
                $texts = StoreUtils::getTextAtPath($op->value, $field);
                if ($texts === []) {
                    continue;
                }
                if (count($texts) > 1) {
                    foreach ($texts as $i => $text) {
                        $toEmbed[$text][] = [$op->namespace, $op->key, "{$path}.{$i}"];
                    }
                } else {
                    $toEmbed[$texts[0]][] = [$op->namespace, $op->key, $path];
                }
            }
        }

        return $toEmbed;
    }

    /**
     * @param list<string>                                              $texts
     * @param list<list<array{0: list<string>, 1: string, 2: string}>>  $metadata
     * @param list<list<float>>                                         $embeddings
     */
    private function insertVectors(array $texts, array $metadata, array $embeddings): void
    {
        foreach ($texts as $index => $text) {
            $embedding = $embeddings[$index] ?? null;
            if ($embedding === null) {
                throw new \RuntimeException("No embedding found for text: {$text}");
            }

            foreach ($metadata[$index] as [$namespace, $key, $field]) {
                $this->vectors[implode(':', $namespace)][$key][$field] = $embedding;
            }
        }
    }

    /**
     * @return list<list<float>>
     */
    private function getVectors(Item $item): array
    {
        return array_values($this->vectors[implode(':', $item->namespace)][$item->key] ?? []);
    }

    /**
     * @param  list<float>       $x
     * @param  list<list<float>> $ys
     * @return list<float>
     */
    private static function cosineSimilarities(array $x, array $ys): array
    {
        $magnitude1 = sqrt(array_sum(array_map(static fn (float|int $v): float => $v * $v, $x)));
        $scores = [];
        foreach ($ys as $vector) {
            $dot = 0.0;
            $squares = 0.0;
            foreach ($vector as $i => $value) {
                $dot += $value * ($x[$i] ?? 0.0);
                $squares += $value * $value;
            }
            $magnitude2 = sqrt($squares);
            $scores[] = $magnitude1 != 0.0 && $magnitude2 != 0.0 ? $dot / ($magnitude1 * $magnitude2) : 0.0;
        }

        return $scores;
    }

    /**
     * An approximation of `a.localeCompare(b)`: case-insensitive first, then lowercase before uppercase.
     *
     * ICU collation is not available to every PHP build; for the ASCII labels namespaces are made of this
     * gives the same order. Punctuation ordering can differ from ICU.
     */
    private static function localeCompare(string $a, string $b): int
    {
        return strcasecmp($a, $b) ?: strcmp($b, $a);
    }
}
