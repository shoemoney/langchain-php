<?php

declare(strict_types=1);

namespace LangChain\Utils;

/**
 * Runtime detection and environment-variable access.
 *
 * Port of `@langchain/core/utils/env`.
 *
 * The browser, web-worker, jsdom and Deno probes exist upstream because one
 * package has to run in all of them. PHP has exactly one runtime, so those
 * predicates are kept (callers port straight across) but answer `false`, and
 * {@see self::getEnv()} reports `"php"` where upstream reports `"node"`.
 */
final class Env
{
    /** @var array{library: string, libraryVersion?: string, runtime: string, runtimeVersion?: string}|null */
    private static ?array $runtimeEnvironment = null;

    private function __construct()
    {
    }

    public static function isBrowser(): bool
    {
        return false;
    }

    public static function isWebWorker(): bool
    {
        return false;
    }

    public static function isJsDom(): bool
    {
        return false;
    }

    public static function isDeno(): bool
    {
        return false;
    }

    /** The server-side runtime check; PHP is always the server-side runtime. */
    public static function isNode(): bool
    {
        return false;
    }

    /**
     * Which runtime this is: `"php"` here, `"node"`/`"browser"`/... upstream.
     */
    public static function getEnv(): string
    {
        return 'php';
    }

    /**
     * Library and runtime identifiers, computed once.
     *
     * @return array{library: string, libraryVersion?: string, runtime: string, runtimeVersion?: string}
     */
    public static function getRuntimeEnvironment(): array
    {
        return self::$runtimeEnvironment ??= [
            'library' => 'langchain-php',
            'runtime' => self::getEnv(),
        ];
    }

    /**
     * An environment variable, or null when it is not set.
     *
     * Unset and empty are different answers and stay different: an empty string
     * is returned as an empty string, because a deliberately blank key is a
     * configuration choice and collapsing it to "missing" would hide that.
     * Upstream wraps the lookup in a try/catch for Deno permission errors; PHP's
     * `getenv()` cannot throw, so there is nothing to swallow.
     */
    public static function getEnvironmentVariable(string $name): ?string
    {
        $value = getenv($name);
        if (is_string($value)) {
            return $value;
        }

        $fromSuperglobal = $_ENV[$name] ?? null;

        return is_string($fromSuperglobal) ? $fromSuperglobal : null;
    }
}
