<?php

declare(strict_types=1);

namespace LangChain\OutputParsers;

use LangChain\Utils\JsonPatch as UtilsJsonPatch;

/**
 * Compatibility alias for the streaming parsers.
 *
 * The implementation lives in {@see UtilsJsonPatch} (`@langchain/core/utils/fast-json-patch`);
 * this class only forwards the three calls the cumulative parsers make.
 */
final class JsonPatch
{
    private function __construct()
    {
    }

    public static function deepEquals(mixed $a, mixed $b): bool
    {
        return UtilsJsonPatch::deepEquals($a, $b);
    }

    public static function isFalsy(mixed $value): bool
    {
        return UtilsJsonPatch::isFalsy($value);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function compare(mixed $prev, mixed $next): array
    {
        return UtilsJsonPatch::compare($prev, $next);
    }

    public static function escapePathComponent(string $key): string
    {
        return UtilsJsonPatch::escapePathComponent($key);
    }
}
