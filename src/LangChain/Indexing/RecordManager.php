<?php

declare(strict_types=1);

namespace LangChain\Indexing;

use LangChain\Load\Serializable;

/**
 * Abstract base for record managers.
 *
 * Port of `RecordManager` from `@langchain/core/indexing/record_manager`.
 */
abstract class RecordManager extends Serializable implements RecordManagerInterface
{
    /** @return list<string> */
    public static function lcId(): array
    {
        $parts = explode('\\', static::class);

        return ['langchain', 'recordmanagers', (string) end($parts)];
    }

    abstract public function createSchema(): void;

    abstract public function getTime(): float;

    abstract public function update(array $keys, array $updateOptions = []): void;

    abstract public function exists(array $keys): array;

    abstract public function listKeys(array $options = []): array;

    abstract public function deleteKeys(array $keys): void;
}
