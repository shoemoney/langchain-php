<?php

declare(strict_types=1);

namespace LangChain\Tracers;

/**
 * The smallest JSON Patch (RFC 6902) applier that `RunLogPatch` / `RunLog` need.
 *
 * Upstream applies patches with `fast-json-patch`; this port's general-purpose patch utility lands in
 * a separate work package, so the tracers carry their own. It supports exactly the operations the log
 * stream emits (`add`, `replace`) plus `remove`, and addresses arrays the PHP way: a document is a
 * nested array, a list is an array whose keys are 0..n-1.
 *
 * Documents are values: `apply()` never mutates what it is given, it returns the patched copy. That is
 * a deliberate difference from `fast-json-patch`'s default of mutating in place — a `RunLog` that kept
 * sharing structure with the log it was concatenated from would be a trap in PHP, where arrays already
 * copy on write.
 *
 * @internal
 */
final class RunLogPatchApplier
{
    private function __construct()
    {
    }

    /**
     * @param list<array{op: string, path: string, value?: mixed}> $ops
     */
    public static function apply(mixed $document, array $ops): mixed
    {
        foreach ($ops as $operation) {
            $document = self::applyOne($document, $operation);
        }

        return $document;
    }

    /**
     * @param array{op: string, path: string, value?: mixed} $operation
     */
    private static function applyOne(mixed $document, array $operation): mixed
    {
        $op = $operation['op'];
        $path = $operation['path'];

        if ($path === '') {
            return match ($op) {
                'add', 'replace' => $operation['value'] ?? null,
                'remove' => null,
                default => throw new \InvalidArgumentException(\sprintf('Unsupported JSON patch operation "%s".', $op)),
            };
        }

        if ($path[0] !== '/') {
            throw new \InvalidArgumentException(\sprintf('Invalid JSON patch path "%s".', $path));
        }

        $keys = array_map(
            static fn (string $segment): string => str_replace(['~1', '~0'], ['/', '~'], $segment),
            explode('/', substr($path, 1)),
        );

        if (!\is_array($document)) {
            throw new \InvalidArgumentException(\sprintf('Cannot apply "%s" at "%s" to a non-container document.', $op, $path));
        }

        self::walk($document, $keys, $op, $operation['value'] ?? null, $path);

        return $document;
    }

    /**
     * @param array<array-key, mixed> $node
     * @param list<string>            $keys
     */
    private static function walk(array &$node, array $keys, string $op, mixed $value, string $path): void
    {
        $key = array_shift($keys);

        if ($keys !== []) {
            if (!\array_key_exists($key, $node) || !\is_array($node[$key])) {
                throw new \InvalidArgumentException(\sprintf('Path "%s" cannot be resolved.', $path));
            }
            self::walk($node[$key], $keys, $op, $value, $path);

            return;
        }

        switch ($op) {
            case 'add':
                if ($key === '-' && array_is_list($node)) {
                    $node[] = $value;
                } elseif (array_is_list($node) && ctype_digit($key) && (int) $key <= \count($node)) {
                    array_splice($node, (int) $key, 0, [$value]);
                } else {
                    $node[$key] = $value;
                }
                break;
            case 'replace':
                $node[$key] = $value;
                break;
            case 'remove':
                if (array_is_list($node) && ctype_digit($key)) {
                    array_splice($node, (int) $key, 1);
                } else {
                    unset($node[$key]);
                }
                break;
            default:
                throw new \InvalidArgumentException(\sprintf('Unsupported JSON patch operation "%s".', $op));
        }
    }
}
