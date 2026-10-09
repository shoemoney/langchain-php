<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\MongoDB;

use LangGraph\Store\MongoDB\MongoStoreCollectionInterface;

/**
 * An in-memory MongoDB collection for {@see \LangGraph\Store\MongoDB\MongoDBStore}.
 *
 * It stands in for what would be `FakeMongoCollection` plus the store's extra calls, but is a
 * standalone class: `FakeMongoCollection` is `final` and not this item's to edit, so it cannot
 * be extended. It keeps the same guarantees, because a fake that quietly ignored any of them
 * would let the contract pass by accident:
 *
 *  - `sort` is honoured (multi-key, ascending/descending) and `limit` is applied AFTER the sort;
 *  - strings order with `strcmp`, never as numbers; lists order element by element;
 *  - filters match by equality (a scalar also matches a list that contains it, as MongoDB does),
 *    dotted paths including list positions (`namespace.0`), `$eq $ne $gt $gte $lt $lte $in $nin`,
 *    and `$expr` over `$and $eq $gte $lte $size $arrayElemAt`; MongoDB's null rule applies and
 *    comparison operators only compare like types;
 *  - `aggregate` runs `$match $group $sort $skip $limit $vectorSearch $addFields $project`;
 *    `$vectorSearch` needs a created search index, scores `(1 + cosine) / 2` like Atlas and
 *    supports `queryVector` only (a server-side `query.text` throws).
 *
 * Every call is recorded in {@see self::$calls}. There is no TTL monitor: a past `expiresAt`
 * is only data here, exactly as the real thing until its background thread runs.
 */
final class FakeMongoStoreCollection implements MongoStoreCollectionInterface
{
    /** @var list<array<string, mixed>> */
    public array $documents = [];

    /** @var list<array{method: string, args: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<array<string, mixed>> Definitions passed to createSearchIndex. */
    public array $searchIndexes = [];

    /** When set, createSearchIndex throws it (to exercise setup's error handling). */
    public ?\Throwable $searchIndexFailure = null;

    public function find(array $filter = [], array $sort = [], ?int $limit = null): array
    {
        $this->calls[] = ['method' => 'find', 'args' => ['filter' => $filter, 'sort' => $sort, 'limit' => $limit]];

        return $this->query($filter, $sort, $limit);
    }

    public function findOne(array $filter, array $sort = []): ?array
    {
        $this->calls[] = ['method' => 'findOne', 'args' => ['filter' => $filter, 'sort' => $sort]];

        return $this->query($filter, $sort, 1)[0] ?? null;
    }

    public function updateOne(array $filter, array $update, array $options = []): void
    {
        $this->calls[] = ['method' => 'updateOne', 'args' => ['filter' => $filter, 'update' => $update, 'options' => $options]];

        $this->applyUpdate($filter, $update, (bool) ($options['upsert'] ?? false));
    }

    public function insertMany(array $documents): void
    {
        $this->calls[] = ['method' => 'insertMany', 'args' => ['documents' => $documents]];

        foreach ($documents as $doc) {
            $this->documents[] = self::normalise($doc);
        }
    }

    public function deleteMany(array $filter): int
    {
        $this->calls[] = ['method' => 'deleteMany', 'args' => ['filter' => $filter]];

        $before = count($this->documents);
        $this->documents = array_values(array_filter(
            $this->documents,
            fn (array $doc): bool => !self::matches($doc, $filter),
        ));

        return $before - count($this->documents);
    }

    public function createIndex(array $keys, array $options = []): string
    {
        $this->calls[] = ['method' => 'createIndex', 'args' => ['keys' => $keys, 'options' => $options]];

        return (string) ($options['name'] ?? implode('_', array_keys($keys)));
    }

    public function aggregate(array $pipeline): array
    {
        $this->calls[] = ['method' => 'aggregate', 'args' => ['pipeline' => $pipeline]];

        $docs = $this->documents;
        foreach ($pipeline as $stage) {
            $name = (string) array_key_first($stage);
            $spec = $stage[$name];
            $docs = match ($name) {
                '$match' => array_values(array_filter($docs, fn (array $doc): bool => self::matches($doc, $spec))),
                '$group' => self::group($docs, $spec),
                '$sort' => self::sorted($docs, $spec),
                '$skip' => array_slice($docs, (int) $spec),
                '$limit' => array_slice($docs, 0, (int) $spec),
                '$vectorSearch' => $this->vectorSearch($docs, $spec),
                '$addFields' => self::addFields($docs, $spec),
                '$project' => self::project($docs, $spec),
                default => throw new \LogicException("FakeMongoStoreCollection does not implement {$name}."),
            };
        }

        return array_map(static function (array $doc): array {
            unset($doc['__score']);

            return $doc;
        }, $docs);
    }

    public function bulkWrite(array $operations): void
    {
        $this->calls[] = ['method' => 'bulkWrite', 'args' => ['operations' => $operations]];

        foreach ($operations as $operation) {
            if (isset($operation['updateOne'])) {
                $spec = $operation['updateOne'];
                $this->applyUpdate($spec['filter'], $spec['update'], (bool) ($spec['upsert'] ?? false));
            } elseif (isset($operation['deleteOne'])) {
                foreach ($this->documents as $i => $doc) {
                    if (self::matches($doc, $operation['deleteOne']['filter'])) {
                        array_splice($this->documents, $i, 1);

                        break;
                    }
                }
            } else {
                throw new \LogicException('FakeMongoStoreCollection does not implement bulk operation ' . (string) array_key_first($operation) . '.');
            }
        }
    }

    public function createSearchIndex(array $definition): string
    {
        $this->calls[] = ['method' => 'createSearchIndex', 'args' => ['definition' => $definition]];

        if ($this->searchIndexFailure !== null) {
            throw $this->searchIndexFailure;
        }

        $name = (string) $definition['name'];
        foreach ($this->searchIndexes as $existing) {
            if ($existing['name'] === $name) {
                throw new \RuntimeException("Search index {$name} already exists.");
            }
        }
        $this->searchIndexes[] = $definition;

        return $name;
    }

    public function findOneAndUpdate(array $filter, array $update, array $options = []): ?array
    {
        $this->calls[] = ['method' => 'findOneAndUpdate', 'args' => ['filter' => $filter, 'update' => $update, 'options' => $options]];

        foreach ($this->documents as $i => $doc) {
            if (self::matches($doc, $filter)) {
                $before = $doc;
                $this->documents[$i] = self::updated($doc, $update);

                return ($options['returnDocument'] ?? 'before') === 'after' ? $this->documents[$i] : $before;
            }
        }

        return null;
    }

    /**
     * @return list<array{method: string, args: array<string, mixed>}>
     */
    public function callsTo(string $method): array
    {
        return array_values(array_filter($this->calls, static fn (array $call): bool => $call['method'] === $method));
    }

    /**
     * @param  array<string, mixed>   $filter
     * @param  array<string, int>     $sort
     * @return list<array<string, mixed>>
     */
    private function query(array $filter, array $sort, ?int $limit): array
    {
        $matched = array_values(array_filter($this->documents, fn (array $doc): bool => self::matches($doc, $filter)));
        $matched = self::sorted($matched, $sort);

        return $limit === null ? $matched : array_slice($matched, 0, $limit);
    }

    /**
     * Stable sort: ties keep insertion order.
     *
     * @param  list<array<string, mixed>> $docs
     * @param  array<string, int>         $sort
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $docs, array $sort): array
    {
        if ($sort === []) {
            return $docs;
        }
        $decorated = [];
        foreach ($docs as $position => $doc) {
            $decorated[] = [$position, $doc];
        }
        usort($decorated, static function (array $a, array $b) use ($sort): int {
            foreach ($sort as $field => $direction) {
                $cmp = self::compare(self::lookup($a[1], (string) $field), self::lookup($b[1], (string) $field));
                if ($cmp !== 0) {
                    return $direction < 0 ? -$cmp : $cmp;
                }
            }

            return $a[0] <=> $b[0];
        });

        return array_map(static fn (array $pair): array => $pair[1], $decorated);
    }

    /**
     * @param array<string, mixed> $filter
     * @param array<string, mixed> $update
     */
    private function applyUpdate(array $filter, array $update, bool $upsert): void
    {
        foreach ($this->documents as $i => $doc) {
            if (self::matches($doc, $filter)) {
                $this->documents[$i] = self::updated($doc, $update);

                return;
            }
        }

        if (!$upsert) {
            return;
        }

        $doc = [];
        foreach ($filter as $field => $value) {
            $isOperatorMap = is_array($value) && $value !== [] && !array_is_list($value) && str_starts_with((string) array_key_first($value), '$');
            if (!str_contains((string) $field, '.') && !$isOperatorMap) {
                $doc[$field] = self::normalise($value);
            }
        }
        $this->documents[] = self::updated($doc, $update, insert: true);
    }

    /**
     * @param  array<string, mixed> $doc
     * @param  array<string, mixed> $update
     * @return array<string, mixed>
     */
    private static function updated(array $doc, array $update, bool $insert = false): array
    {
        foreach ($update['$set'] ?? [] as $field => $value) {
            $doc[$field] = self::normalise($value);
        }
        if ($insert) {
            foreach ($update['$setOnInsert'] ?? [] as $field => $value) {
                $doc[$field] = self::normalise($value);
            }
        }
        foreach (array_keys($update['$currentDate'] ?? []) as $field) {
            $doc[$field] = new \DateTimeImmutable();
        }

        return $doc;
    }

    /**
     * @param  list<array<string, mixed>> $docs
     * @param  array<string, mixed>       $spec
     * @return list<array<string, mixed>>
     */
    private static function group(array $docs, array $spec): array
    {
        $groups = [];
        foreach ($docs as $doc) {
            $id = self::evaluate($spec['_id'], $doc);
            $groups[serialize($id)] ??= ['_id' => $id];
        }

        return array_values($groups);
    }

    /**
     * @param  list<array<string, mixed>> $docs
     * @param  array<string, mixed>       $stage
     * @return list<array<string, mixed>>
     */
    private function vectorSearch(array $docs, array $stage): array
    {
        $indexed = array_filter($this->searchIndexes, static fn (array $def): bool => $def['name'] === ($stage['index'] ?? null));
        if ($indexed === []) {
            throw new \RuntimeException("No search index named {$stage['index']} exists.");
        }
        if (!isset($stage['queryVector'])) {
            throw new \LogicException('FakeMongoStoreCollection cannot embed query text server-side.');
        }

        $scored = [];
        foreach ($docs as $position => $doc) {
            $vector = self::lookup($doc, (string) $stage['path']);
            if (!is_array($vector) || !self::matches($doc, $stage['filter'] ?? [])) {
                continue;
            }
            $doc['__score'] = (1 + self::cosine($stage['queryVector'], $vector)) / 2;
            $scored[] = [$position, $doc];
        }
        usort($scored, static fn (array $a, array $b): int => [$b[1]['__score'], $a[0]] <=> [$a[1]['__score'], $b[0]]);

        return array_map(static fn (array $pair): array => $pair[1], array_slice($scored, 0, (int) $stage['limit']));
    }

    /**
     * @param list<float|int> $a
     * @param list<float|int> $b
     */
    private static function cosine(array $a, array $b): float
    {
        $dot = $na = $nb = 0.0;
        foreach ($a as $i => $x) {
            $y = $b[$i] ?? 0;
            $dot += $x * $y;
            $na += $x * $x;
            $nb += $y * $y;
        }

        return $na === 0.0 || $nb === 0.0 ? 0.0 : $dot / (sqrt($na) * sqrt($nb));
    }

    /**
     * @param  list<array<string, mixed>> $docs
     * @param  array<string, mixed>       $fields
     * @return list<array<string, mixed>>
     */
    private static function addFields(array $docs, array $fields): array
    {
        foreach ($docs as $i => $doc) {
            foreach ($fields as $field => $expression) {
                $docs[$i][$field] = is_array($expression) && ($expression['$meta'] ?? null) === 'vectorSearchScore'
                    ? ($doc['__score'] ?? null)
                    : self::evaluate($expression, $doc);
            }
        }

        return $docs;
    }

    /**
     * @param  list<array<string, mixed>> $docs
     * @param  array<string, int>         $fields
     * @return list<array<string, mixed>>
     */
    private static function project(array $docs, array $fields): array
    {
        return array_map(static function (array $doc) use ($fields): array {
            foreach ($fields as $field => $keep) {
                if ($keep === 0) {
                    unset($doc[$field]);
                }
            }

            return $doc;
        }, $docs);
    }

    /**
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $filter
     */
    private static function matches(array $doc, array $filter): bool
    {
        foreach ($filter as $field => $expected) {
            if ($field === '$expr') {
                if (self::evaluate($expected, $doc) !== true) {
                    return false;
                }

                continue;
            }
            $actual = self::lookup($doc, (string) $field);
            $isOperatorMap = is_array($expected) && $expected !== [] && !array_is_list($expected) && str_starts_with((string) array_key_first($expected), '$');
            if ($isOperatorMap) {
                foreach ($expected as $operator => $operand) {
                    if (!self::operatorHolds((string) $operator, $actual, $operand)) {
                        return false;
                    }
                }

                continue;
            }
            if (!self::equalsOrContains($actual, $expected)) {
                return false;
            }
        }

        return true;
    }

    private static function operatorHolds(string $operator, mixed $actual, mixed $operand): bool
    {
        return match ($operator) {
            '$eq' => self::equalsOrContains($actual, $operand),
            '$ne' => !self::equalsOrContains($actual, $operand),
            '$in' => array_reduce((array) $operand, fn (bool $hit, mixed $v): bool => $hit || self::equalsOrContains($actual, $v), false),
            '$nin' => !array_reduce((array) $operand, fn (bool $hit, mixed $v): bool => $hit || self::equalsOrContains($actual, $v), false),
            '$gt' => self::comparable($actual, $operand) && self::compare($actual, $operand) > 0,
            '$gte' => self::comparable($actual, $operand) && self::compare($actual, $operand) >= 0,
            '$lt' => self::comparable($actual, $operand) && self::compare($actual, $operand) < 0,
            '$lte' => self::comparable($actual, $operand) && self::compare($actual, $operand) <= 0,
            default => throw new \LogicException("FakeMongoStoreCollection does not implement {$operator}."),
        };
    }

    /** MongoDB compares across types only inside a type bracket: numbers, strings, dates. */
    private static function comparable(mixed $a, mixed $b): bool
    {
        return (is_int($a) || is_float($a)) && (is_int($b) || is_float($b))
            || is_string($a) && is_string($b)
            || $a instanceof \DateTimeInterface && $b instanceof \DateTimeInterface;
    }

    /** Equality, where a scalar also matches a list containing it and null matches a missing field. */
    private static function equalsOrContains(mixed $actual, mixed $expected): bool
    {
        if (self::equals($actual, $expected)) {
            return true;
        }

        if (is_array($actual) && !is_array($expected)) {
            foreach ($actual as $element) {
                if (self::equals($element, $expected)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function equals(mixed $a, mixed $b): bool
    {
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b;
        }
        if ($a instanceof \DateTimeInterface && $b instanceof \DateTimeInterface) {
            return $a == $b;
        }
        if (is_array($a) && is_array($b)) {
            if (array_keys($a) !== array_keys($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (!self::equals($value, $b[$key])) {
                    return false;
                }
            }

            return true;
        }

        return $a === $b;
    }

    /**
     * Evaluate an aggregation expression against a document.
     *
     * @param array<string, mixed> $doc
     */
    private static function evaluate(mixed $expression, array $doc): mixed
    {
        if (is_string($expression) && str_starts_with($expression, '$')) {
            return self::lookup($doc, substr($expression, 1));
        }
        if (!is_array($expression) || array_is_list($expression)) {
            return $expression;
        }

        $operator = (string) array_key_first($expression);
        $operand = $expression[$operator];
        $args = is_array($operand) && array_is_list($operand)
            ? array_map(static fn (mixed $arg): mixed => self::evaluate($arg, $doc), $operand)
            : [self::evaluate($operand, $doc)];

        return match ($operator) {
            '$and' => !in_array(false, $args, true),
            '$eq' => self::equals($args[0], $args[1]),
            '$gte' => self::comparable($args[0], $args[1]) && self::compare($args[0], $args[1]) >= 0,
            '$lte' => self::comparable($args[0], $args[1]) && self::compare($args[0], $args[1]) <= 0,
            '$size' => is_array($args[0]) ? count($args[0]) : throw new \RuntimeException('$size needs an array.'),
            '$arrayElemAt' => self::elementAt($args[0], (int) $args[1]),
            default => throw new \LogicException("FakeMongoStoreCollection does not implement {$operator}."),
        };
    }

    /** Negative indices count from the end; out of range is missing (null). */
    private static function elementAt(mixed $list, int $index): mixed
    {
        if (!is_array($list)) {
            return null;
        }
        $index = $index < 0 ? count($list) + $index : $index;

        return $list[$index] ?? null;
    }

    /**
     * @param array<string, mixed> $doc
     */
    private static function lookup(array $doc, string $path): mixed
    {
        $value = $doc;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /** String-aware three-way compare: `<=>` would order two numeric-looking strings as numbers. */
    private static function compare(mixed $a, mixed $b): int
    {
        if (is_string($a) && is_string($b)) {
            return strcmp($a, $b) <=> 0;
        }
        if (is_array($a) && is_array($b)) {
            foreach ($a as $i => $value) {
                if (!array_key_exists($i, $b)) {
                    return 1;
                }
                $cmp = self::compare($value, $b[$i]);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }

            return count($a) <=> count($b);
        }

        return $a <=> $b;
    }

    /** Deep-copy a value, turning objects other than dates into arrays as BSON would round-trip them. */
    private static function normalise(mixed $value): mixed
    {
        if ($value instanceof \DateTimeInterface) {
            return $value;
        }
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            return array_map(self::normalise(...), $value);
        }

        return $value;
    }
}
