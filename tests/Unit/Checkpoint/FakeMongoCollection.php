<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\MongoDB\MongoBinary;
use LangGraph\Checkpoint\MongoDB\MongoCollectionInterface;

/**
 * An in-memory MongoDB collection.
 *
 * Implements the query behaviour the saver depends on, because a fake that quietly ignored
 * any of it would let the contract pass by accident:
 *
 *  - `sort` is honoured (multi-key, ascending/descending). A "latest checkpoint" read that
 *    passed only because documents happened to come back in insertion order would be a lie.
 *  - `limit` is applied AFTER the sort.
 *  - filters match by equality, dotted paths (`metadata_search.source`), `$lt`, and
 *    MongoDB's null rule (an equality-to-null filter also matches a missing field).
 *  - `updateOne` implements `$set`, `$setOnInsert` and `$currentDate`; an upsert seeds the new
 *    document from the filter's equality fields; a plain update never touches an existing
 *    document's `$setOnInsert` fields.
 *
 * Every call is recorded in {@see self::$calls} so a test can assert what the saver sent.
 */
final class FakeMongoCollection implements MongoCollectionInterface
{
    /** @var list<array<string, mixed>> */
    public array $documents = [];

    /** @var list<array{method: string, args: array<string, mixed>}> */
    public array $calls = [];

    public function find(array $filter = [], array $sort = [], ?int $limit = null): array
    {
        $this->calls[] = ['method' => 'find', 'args' => ['filter' => $filter, 'sort' => $sort, 'limit' => $limit]];

        $matched = array_values(array_filter($this->documents, fn (array $doc): bool => self::matches($doc, $filter)));
        if ($sort !== []) {
            // Stable: ties keep insertion order.
            $decorated = [];
            foreach ($matched as $position => $doc) {
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
            $matched = array_map(static fn (array $pair): array => $pair[1], $decorated);
        }
        if ($limit !== null) {
            $matched = array_slice($matched, 0, $limit);
        }

        return $matched;
    }

    public function findOne(array $filter, array $sort = []): ?array
    {
        $this->calls[] = ['method' => 'findOne', 'args' => ['filter' => $filter, 'sort' => $sort]];

        $savedCalls = $this->calls;
        $found = $this->find($filter, $sort, 1)[0] ?? null;
        $this->calls = $savedCalls;

        return $found;
    }

    public function updateOne(array $filter, array $update, array $options = []): void
    {
        $this->calls[] = ['method' => 'updateOne', 'args' => ['filter' => $filter, 'update' => $update, 'options' => $options]];

        foreach ($this->documents as $i => $doc) {
            if (self::matches($doc, $filter)) {
                foreach ($update['$set'] ?? [] as $field => $value) {
                    $this->documents[$i][$field] = self::normalise($value);
                }
                if (isset($update['$currentDate'])) {
                    foreach (array_keys($update['$currentDate']) as $field) {
                        $this->documents[$i][$field] = new \DateTimeImmutable();
                    }
                }

                return;
            }
        }

        if (!($options['upsert'] ?? false)) {
            return;
        }

        $doc = [];
        foreach ($filter as $field => $value) {
            if (!str_contains((string) $field, '.') && !is_array($value)) {
                $doc[$field] = $value;
            }
        }
        foreach (['$set', '$setOnInsert'] as $operator) {
            foreach ($update[$operator] ?? [] as $field => $value) {
                $doc[$field] = self::normalise($value);
            }
        }
        foreach (array_keys($update['$currentDate'] ?? []) as $field) {
            $doc[$field] = new \DateTimeImmutable();
        }
        $this->documents[] = $doc;
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

    /**
     * @return list<array{method: string, args: array<string, mixed>}>
     */
    public function callsTo(string $method): array
    {
        return array_values(array_filter($this->calls, static fn (array $call): bool => $call['method'] === $method));
    }

    /**
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $filter
     */
    private static function matches(array $doc, array $filter): bool
    {
        foreach ($filter as $field => $expected) {
            $actual = self::lookup($doc, (string) $field);
            if (is_array($expected) && $expected !== [] && array_is_list($expected) === false && str_starts_with((string) array_key_first($expected), '$')) {
                foreach ($expected as $operator => $operand) {
                    $ok = match ($operator) {
                        '$lt' => $actual !== null && self::compare($actual, $operand) < 0,
                        default => throw new \LogicException("FakeMongoCollection does not implement {$operator}."),
                    };
                    if (!$ok) {
                        return false;
                    }
                }

                continue;
            }
            if ($actual !== $expected) {
                return false;
            }
        }

        return true;
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

    /** String-aware three-way compare: `<=>` would order two numeric-looking ids as numbers. */
    private static function compare(mixed $a, mixed $b): int
    {
        if (is_string($a) && is_string($b)) {
            return strcmp($a, $b) <=> 0;
        }

        return $a <=> $b;
    }

    /** Deep-copy a value, turning objects other than binary/dates into arrays as BSON would round-trip them. */
    private static function normalise(mixed $value): mixed
    {
        if ($value instanceof MongoBinary || $value instanceof \DateTimeInterface) {
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
