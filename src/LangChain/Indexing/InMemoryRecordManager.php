<?php

declare(strict_types=1);

namespace LangChain\Indexing;

/**
 * A record manager that keeps its records in a PHP array.
 *
 * Not in the JS packages (they ship only the interface and abstract class);
 * this follows the Python `InMemoryRecordManager` and exists so `Index::index()`
 * has a manager to run against. The clock is injectable so tests can move time
 * deterministically; by default it is `microtime(true)`.
 */
class InMemoryRecordManager extends RecordManager
{
    /** @var array<string, array{updatedAt: float, groupId: string|null}> */
    private array $records = [];

    /** @var callable(): float */
    private $clock;

    /**
     * @param (callable(): float)|null $clock
     */
    public function __construct(?callable $clock = null)
    {
        $this->clock = $clock ?? static fn (): float => microtime(true);
    }

    public function createSchema(): void
    {
    }

    public function getTime(): float
    {
        return ($this->clock)();
    }

    public function update(array $keys, array $updateOptions = []): void
    {
        $groupIds = $updateOptions['groupIds'] ?? array_fill(0, count($keys), null);
        $timeAtLeast = $updateOptions['timeAtLeast'] ?? null;

        if (count($keys) !== count($groupIds)) {
            throw new \InvalidArgumentException('Number of keys does not match number of group ids');
        }

        $updateTime = $this->getTime();
        if ($timeAtLeast !== null && $timeAtLeast > $updateTime) {
            throw new \InvalidArgumentException(sprintf(
                'Time sync issue: timeAtLeast (%s) is greater than the current time (%s).',
                $timeAtLeast,
                $updateTime,
            ));
        }

        foreach (array_values($keys) as $i => $key) {
            $this->records[$key] = ['updatedAt' => $updateTime, 'groupId' => array_values($groupIds)[$i]];
        }
    }

    public function exists(array $keys): array
    {
        return array_map(fn (string $key): bool => isset($this->records[$key]), array_values($keys));
    }

    public function listKeys(array $options = []): array
    {
        $before = $options['before'] ?? null;
        $after = $options['after'] ?? null;
        $groupIds = $options['groupIds'] ?? null;
        $limit = $options['limit'] ?? null;

        $keys = [];
        foreach ($this->records as $key => $record) {
            if ($after !== null && $record['updatedAt'] <= $after) {
                continue;
            }
            if ($before !== null && $record['updatedAt'] >= $before) {
                continue;
            }
            if ($groupIds !== null && !in_array($record['groupId'], $groupIds, true)) {
                continue;
            }
            $keys[] = (string) $key;
        }

        return $limit !== null ? array_slice($keys, 0, $limit) : $keys;
    }

    public function deleteKeys(array $keys): void
    {
        foreach ($keys as $key) {
            unset($this->records[$key]);
        }
    }
}
