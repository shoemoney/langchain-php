<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Anthropic\Tools;

/**
 * Shared helpers for the plain-array Anthropic tool definitions.
 *
 * The TypeScript builders return object literals whose `undefined` members vanish on
 * serialisation; PHP has no `undefined`, so absent options are dropped here instead of being
 * carried as `null` (which JSON would send as an explicit `null` and the API may reject).
 */
final class ServerToolDefinition
{
    /**
     * @param array<string, mixed> $definition
     *
     * @return array<string, mixed>
     */
    public static function withoutNulls(array $definition): array
    {
        return array_filter($definition, static fn (mixed $v): bool => $v !== null);
    }
}
