<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Utils;

/**
 * Port of `utils/env.ts`.
 *
 * `override()` is the PHP stand-in for `vi.spyOn(envUtils, "getEnvironmentVariable")`: the process
 * environment is global state a test should not have to mutate.
 */
final class Env
{
    /** @var (\Closure(string): ?string)|null */
    private static ?\Closure $override = null;

    public static function getEnvironmentVariable(string $name): ?string
    {
        if (self::$override !== null) {
            return (self::$override)($name);
        }

        $value = getenv($name);

        return $value === false ? null : $value;
    }

    /**
     * @param (\Closure(string): ?string)|null $resolver Null restores the real environment.
     */
    public static function override(?\Closure $resolver): void
    {
        self::$override = $resolver;
    }
}
