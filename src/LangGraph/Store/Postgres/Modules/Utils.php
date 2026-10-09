<?php

declare(strict_types=1);

namespace LangGraph\Store\Postgres\Modules;

use LangGraph\Store\InvalidNamespaceError;

/**
 * Namespace validation for the Postgres store.
 *
 * Port of `store/modules/utils.ts`.
 *
 * `%`, `_` and `\` are rejected because searches match namespaces with
 * `namespace_path LIKE 'prefix%'`; those characters would turn a prefix into a
 * glob, so a prefix of `["%"]` would match every tenant's namespace.
 */
final class Utils
{
    private const LIKE_RESERVED_PATTERN = '/[%_\\\\]/';

    private function __construct()
    {
    }

    /**
     * @param array<mixed> $namespace
     *
     * @throws InvalidNamespaceError
     */
    public static function validateNamespace(array $namespace): void
    {
        if ($namespace === []) {
            throw new InvalidNamespaceError('Namespace cannot be empty.');
        }
        $shown = implode(',', array_map(static fn (mixed $l): string => is_scalar($l) ? (string) $l : get_debug_type($l), $namespace));

        foreach ($namespace as $label) {
            if (!is_string($label)) {
                throw new InvalidNamespaceError(sprintf(
                    "Invalid namespace label '%s' found in %s. Namespace labels must be strings, but got %s.",
                    is_scalar($label) ? (string) $label : get_debug_type($label),
                    $shown,
                    get_debug_type($label),
                ));
            }
            if (str_contains($label, '.')) {
                throw new InvalidNamespaceError(sprintf(
                    "Invalid namespace label '%s' found in %s. Namespace labels cannot contain periods ('.').",
                    $label,
                    $shown,
                ));
            }
            if ($label === '') {
                throw new InvalidNamespaceError(sprintf('Namespace labels cannot be empty strings. Got %s in %s', $label, $shown));
            }
            if (preg_match(self::LIKE_RESERVED_PATTERN, $label) === 1) {
                throw new InvalidNamespaceError(sprintf(
                    "Invalid namespace label '%s' found in %s. Namespace labels cannot contain SQL LIKE wildcards ('%%', '_') "
                    . "or the backslash escape character ('\\\\'); these would cause search() to match namespaces outside the requested prefix.",
                    $label,
                    $shown,
                ));
            }
        }

        if ($namespace[0] === 'langgraph') {
            throw new InvalidNamespaceError(sprintf('Root label for namespace cannot be "langgraph". Got: %s', $shown));
        }
    }
}
