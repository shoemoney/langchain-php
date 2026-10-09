<?php

declare(strict_types=1);

namespace LangGraph\Mcp\Connection;

use LangGraph\Mcp\McpClientError;

/**
 * Port of `langchain-mcp-adapters/src/utils/misc.ts`.
 *
 * Header sets follow the semantics of the Fetch `Headers` class upstream leans on: names are
 * case-insensitive and come back lower-cased, values are trimmed, and a name given twice is
 * joined with `, `.
 */
final class Misc
{
    private function __construct()
    {
    }

    /**
     * A stable string for a header set: names lower-cased and sorted so the same headers always
     * produce the same string. `null` for no headers at all.
     *
     * @param array<string, string>|null $headers
     */
    public static function serializeHeaders(?array $headers): ?string
    {
        if ($headers === null || $headers === []) {
            return null;
        }

        $normalized = self::normalize($headers);
        ksort($normalized, \SORT_STRING);
        $pairs = [];
        foreach ($normalized as $name => $value) {
            $pairs[] = [$name, $value];
        }

        return (string) json_encode($pairs, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /**
     * Merge header sets; later sources win. Names deduplicate case-insensitively and come back
     * lower-cased.
     *
     * @param array<string, string>|null $base
     * @param array<string, string>|null $overrides
     *
     * @return array<string, string>
     */
    public static function mergeHeaders(?array $base, ?array $overrides): array
    {
        $headers = self::normalize($base ?? []);
        foreach ($overrides ?? [] as $name => $value) {
            $headers[self::name((string) $name)] = self::value((string) $name, (string) $value);
        }

        return $headers;
    }

    /** `String(error)`: the class name and message, as the adapter renders a failure into its own message. */
    public static function describeError(mixed $error): string
    {
        if ($error instanceof \Throwable) {
            return (new \ReflectionClass($error))->getShortName() . ': ' . $error->getMessage();
        }

        return match (true) {
            is_string($error) => $error,
            is_scalar($error) => var_export($error, true),
            default => '[object Object]',
        };
    }

    /**
     * @param array<string, string> $headers
     *
     * @return array<string, string>
     */
    private static function normalize(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $name => $value) {
            $key = self::name((string) $name);
            $value = self::value((string) $name, (string) $value);
            $normalized[$key] = isset($normalized[$key]) ? $normalized[$key] . ', ' . $value : $value;
        }

        return $normalized;
    }

    private static function name(string $name): string
    {
        if (preg_match("/^[!#$%&'*+\\-.^_`|~0-9A-Za-z]+$/", $name) !== 1) {
            throw new McpClientError("Invalid header name \"{$name}\"");
        }

        return strtolower($name);
    }

    private static function value(string $name, string $value): string
    {
        if (preg_match('/[\x00\r\n]/', $value) === 1) {
            throw new McpClientError("Invalid value for header \"{$name}\"");
        }

        return trim($value, " \t");
    }
}
