<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Support;

/**
 * Stands in for the ported `initChatModel` (WP-20) in tests that run in a separate process: the process aliases
 * this class to the FQCN the middleware looks up lazily, and `$result` is what a "provider:model" string resolves
 * to (a `\Throwable` makes the call fail).
 */
final class StubInitChatModel
{
    public static mixed $result = null;

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    public static array $calls = [];

    /** @param array<string, mixed> $fields */
    public static function initChatModel(string $model, array $fields = []): mixed
    {
        self::$calls[] = [$model, $fields];

        if (self::$result instanceof \Throwable) {
            throw self::$result;
        }

        return self::$result;
    }

    /** Alias the stub as the real class; false when the real `initChatModel` is already ported (the caller then skips). */
    public static function install(): bool
    {
        if (class_exists('LangChain\\ChatModels\\InitChatModel')) {
            return false;
        }
        class_alias(self::class, 'LangChain\\ChatModels\\InitChatModel');
        self::$calls = [];

        return true;
    }
}
