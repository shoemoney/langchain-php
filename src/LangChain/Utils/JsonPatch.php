<?php

declare(strict_types=1);

namespace LangChain\Utils;

/**
 * RFC 6902 JSON Patch: apply, validate and diff.
 *
 * Port of `@langchain/core/utils/fast-json-patch` (`src/core.ts`, `src/duplex.ts`
 * and `src/helpers.ts`, itself an inlining of `fast-json-patch`). This is the one
 * home for JSON Patch in the port; `OutputParsers\JsonPatch` only delegates here.
 *
 * Documents are decoded JSON in PHP's native shape (`json_decode($s, true)`):
 * a JSON object is an associative array, a JSON array is a list. Three
 * consequences differ from the JavaScript original and are deliberate:
 *
 * 1. Value semantics. PHP arrays are copied on assignment, so nothing here ever
 *    mutates the caller's document. `mutateDocument` is accepted for signature
 *    parity and has no observable effect; read the result's `newDocument`.
 * 2. `[]` is both the empty JSON array and the empty JSON object. While applying
 *    a patch an empty array is treated as a list only when the addressed key is
 *    an integer or `-`; any other key makes it an object. When diffing, `[]` is a
 *    list (see {@see Js::isList()}).
 * 3. There is no `undefined`, so `hasUndefined` / `OPERATION_VALUE_CANNOT_CONTAIN_UNDEFINED`
 *    do not exist. A missing `value` key is `OPERATION_VALUE_REQUIRED`, and a PHP
 *    `null` is the JSON `null`.
 *
 * The DOM-driven half of `duplex.ts` (`observe` / `unobserve` / `generate(observer)`,
 * which watch a live object through `WeakMap` identity and browser events) has no
 * PHP equivalent and is not ported; {@see self::compare()} is the diff it is built on.
 *
 * Operation results are arrays: `newDocument` always, plus `removed`, `test` or
 * `value` (for the internal `_get`) when the operation produces them.
 */
final class JsonPatch
{
    private const OPS = ['add', 'remove', 'replace', 'move', 'copy', 'test', '_get'];

    private function __construct()
    {
    }

    // ---------------------------------------------------------------- helpers.ts

    /**
     * Escape one JSON Pointer path segment (RFC 6901 section 3).
     */
    public static function escapePathComponent(string $key): string
    {
        if (!str_contains($key, '/') && !str_contains($key, '~')) {
            return $key;
        }

        return str_replace(['~', '/'], ['~0', '~1'], $key);
    }

    public static function unescapePathComponent(string $key): string
    {
        return str_replace(['~1', '~0'], ['/', '~'], $key);
    }

    /**
     * Deep copy. Arrays already copy by value; objects are flattened through JSON,
     * which is what upstream's `JSON.parse(JSON.stringify(...))` does.
     */
    public static function deepClone(mixed $value): mixed
    {
        if (is_object($value)) {
            return json_decode((string) json_encode($value), true);
        }

        return $value;
    }

    /**
     * Upstream's `isInteger`: digits only. The empty string counts, as it does upstream.
     */
    public static function isInteger(string $value): bool
    {
        return $value === '' || ctype_digit($value);
    }

    /**
     * The JSON Pointer of the first sub-value of `$root` that is strictly equal to
     * `$target`. Arrays have no identity in PHP, so this matches by value.
     *
     * @throws \RuntimeException when nothing matches
     */
    public static function getPath(mixed $root, mixed $target): string
    {
        if ($root === $target) {
            return '/';
        }
        $path = self::getPathRecursive($root, $target);
        if ($path === '') {
            throw new \RuntimeException('Object not found in root');
        }

        return '/' . $path;
    }

    private static function getPathRecursive(mixed $root, mixed $target): string
    {
        if (!is_array($root)) {
            return '';
        }
        foreach ($root as $key => $value) {
            if ($value === $target) {
                return self::escapePathComponent((string) $key) . '/';
            }
            if (is_array($value)) {
                $found = self::getPathRecursive($value, $target);
                if ($found !== '') {
                    return self::escapePathComponent((string) $key) . '/' . $found;
                }
            }
        }

        return '';
    }

    // ------------------------------------------------------------------ core.ts

    /**
     * Structural equality with JavaScript number semantics (`1 == 1.0`) and strict
     * types otherwise (`1` is not `"1"`), object key order ignored.
     *
     * Upstream's `_areEquals`; contrast {@see self::deepEquals()}, which is the
     * streaming parsers' strict guard.
     */
    public static function areEquals(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }
        if ((is_int($a) || is_float($a)) && (is_int($b) || is_float($b))) {
            return $a == $b || (is_nan((float) $a) && is_nan((float) $b));
        }
        if (is_array($a) && is_array($b)) {
            if (Js::isList($a) !== Js::isList($b)) {
                return false;
            }
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $key => $value) {
                if (!array_key_exists($key, $b) || !self::areEquals($value, $b[$key])) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * Read the value at a JSON Pointer.
     *
     * @throws JsonPatchError when an intermediate segment cannot be resolved
     */
    public static function getValueByPointer(mixed $document, string $pointer): mixed
    {
        if ($pointer === '') {
            return $document;
        }

        return self::applyOperation($document, ['op' => '_get', 'path' => $pointer])['value'] ?? null;
    }

    /**
     * Apply one operation.
     *
     * @param array<string, mixed>   $operation
     * @param bool|callable          $validateOperation `false` for none, `true` for the default {@see self::validator()},
     *                                                  or a callable `(operation, index, document, existingPathFragment)`
     * @return array<string, mixed>                     `newDocument` and, when produced, `removed` / `test` / `value`
     *
     * @throws JsonPatchError
     * @throws \TypeError when `$banPrototypeModifications` and the path touches `__proto__` / `constructor/prototype`
     */
    public static function applyOperation(
        mixed $document,
        array $operation,
        bool|callable $validateOperation = false,
        bool $mutateDocument = true,
        bool $banPrototypeModifications = true,
        int $index = 0,
    ): array {
        if ($validateOperation !== false) {
            if (is_callable($validateOperation)) {
                $validateOperation($operation, 0, $document, $operation['path'] ?? null);
            } else {
                self::validator($operation, 0);
            }
        }

        $op = $operation['op'] ?? null;

        if (($operation['path'] ?? null) === '') {
            return self::applyRoot($document, $operation, $op, $validateOperation !== false, $index);
        }

        $path = $operation['path'] ?? '';
        $keys = explode('/', is_string($path) ? $path : '');
        $existing = null;
        $validateFn = is_callable($validateOperation) ? $validateOperation : self::validator(...);

        [$container, $ret] = self::walk(
            $document,
            $keys,
            1,
            count($keys),
            $operation,
            $document,
            $validateOperation !== false,
            $validateFn,
            $banPrototypeModifications,
            $index,
            $existing,
        );

        if (!array_key_exists('newDocument', $ret)) {
            $ret['newDocument'] = $container;
        }

        return $ret;
    }

    /**
     * Apply a whole patch.
     *
     * @param list<array<string, mixed>> $patch
     * @param bool|callable              $validateOperation
     * @return array{newDocument: mixed, results: list<array<string, mixed>>}
     *
     * @throws JsonPatchError
     */
    public static function applyPatch(
        mixed $document,
        array $patch,
        bool|callable $validateOperation = false,
        bool $mutateDocument = true,
        bool $banPrototypeModifications = true,
    ): array {
        if ($validateOperation !== false && !array_is_list($patch)) {
            throw new JsonPatchError('Patch sequence must be an array', 'SEQUENCE_NOT_AN_ARRAY');
        }

        $results = [];
        foreach (array_values($patch) as $i => $operation) {
            $results[$i] = self::applyOperation($document, $operation, $validateOperation, true, $banPrototypeModifications, $i);
            $document = $results[$i]['newDocument'];
        }

        return ['newDocument' => $document, 'results' => $results];
    }

    /**
     * Apply one operation and return the new document; suits `array_reduce`.
     *
     * @param array<string, mixed> $operation
     *
     * @throws JsonPatchError
     */
    public static function applyReducer(mixed $document, array $operation, int $index = 0): mixed
    {
        $result = self::applyOperation($document, $operation);
        if (($result['test'] ?? null) === false) {
            throw new JsonPatchError('Test operation failed', 'TEST_OPERATION_FAILED', $index, $operation, $document);
        }

        return $result['newDocument'];
    }

    /**
     * Validate one operation. Throws on the first problem.
     *
     * @param mixed $operation
     *
     * @throws JsonPatchError
     */
    public static function validator(mixed $operation, int $index, mixed $document = null, ?string $existingPathFragment = null): void
    {
        if (!is_array($operation) || array_is_list($operation) && $operation !== []) {
            throw new JsonPatchError('Operation is not an object', 'OPERATION_NOT_AN_OBJECT', $index, $operation, $document);
        }
        $op = $operation['op'] ?? null;
        if (!is_string($op) || !in_array($op, self::OPS, true)) {
            throw new JsonPatchError('Operation `op` property is not one of operations defined in RFC-6902', 'OPERATION_OP_INVALID', $index, $operation, $document);
        }
        $path = $operation['path'] ?? null;
        if (!is_string($path)) {
            throw new JsonPatchError('Operation `path` property is not a string', 'OPERATION_PATH_INVALID', $index, $operation, $document);
        }
        if (!str_starts_with($path, '/') && $path !== '') {
            throw new JsonPatchError('Operation `path` property must start with "/"', 'OPERATION_PATH_INVALID', $index, $operation, $document);
        }
        if (($op === 'move' || $op === 'copy') && !is_string($operation['from'] ?? null)) {
            throw new JsonPatchError('Operation `from` property is not present (applicable in `move` and `copy` operations)', 'OPERATION_FROM_REQUIRED', $index, $operation, $document);
        }
        if (($op === 'add' || $op === 'replace' || $op === 'test') && !array_key_exists('value', $operation)) {
            throw new JsonPatchError('Operation `value` property is not present (applicable in `add`, `replace` and `test` operations)', 'OPERATION_VALUE_REQUIRED', $index, $operation, $document);
        }
        if ($document === null || self::isFalsy($document)) {
            return;
        }

        if ($op === 'add') {
            $pathLen = count(explode('/', $path));
            $existingPathLen = count(explode('/', (string) $existingPathFragment));
            if ($pathLen !== $existingPathLen + 1 && $pathLen !== $existingPathLen) {
                throw new JsonPatchError('Cannot perform an `add` operation at the desired path', 'OPERATION_PATH_CANNOT_ADD', $index, $operation, $document);
            }
        } elseif ($op === 'replace' || $op === 'remove' || $op === '_get') {
            if ($path !== $existingPathFragment) {
                throw new JsonPatchError('Cannot perform the operation at a path that does not exist', 'OPERATION_PATH_UNRESOLVABLE', $index, $operation, $document);
            }
        } elseif ($op === 'move' || $op === 'copy') {
            $error = self::validate([['op' => '_get', 'path' => $operation['from']]], $document);
            if ($error !== null && $error->errorName === 'OPERATION_PATH_UNRESOLVABLE') {
                throw new JsonPatchError('Cannot perform the operation from a path that does not exist', 'OPERATION_FROM_UNRESOLVABLE', $index, $operation, $document);
            }
        }
    }

    /**
     * Validate a sequence, additionally against `$document` when one is given.
     *
     * @param mixed         $sequence
     * @param null|callable $externalValidator
     * @return null|JsonPatchError the first problem, or null when valid
     */
    public static function validate(mixed $sequence, mixed $document = null, ?callable $externalValidator = null): ?JsonPatchError
    {
        try {
            if (!is_array($sequence) || !array_is_list($sequence)) {
                throw new JsonPatchError('Patch sequence must be an array', 'SEQUENCE_NOT_AN_ARRAY');
            }
            if ($document !== null && !self::isFalsy($document)) {
                self::applyPatch(self::deepClone($document), self::deepClone($sequence), $externalValidator ?? true);
            } else {
                $validator = $externalValidator ?? self::validator(...);
                foreach ($sequence as $i => $operation) {
                    $validator($operation, $i, $document, null);
                }
            }
        } catch (JsonPatchError $e) {
            return $e;
        }

        return null;
    }

    // ----------------------------------------------------------------- duplex.ts

    /**
     * The operations that turn `$prev` into `$next` (upstream `compare`).
     *
     * Two rules from the original matter for the streaming parsers and are
     * preserved: existing keys are walked in reverse (removals and replacements
     * are emitted before additions) and equal-length objects stop after the
     * replace pass. A `null` in `$next` counts as absent, matching the parsers'
     * "undefined" for a not-yet-streamed value.
     *
     * With `$invertible`, each change is preceded by a `test` of the old value.
     *
     * @return list<array<string, mixed>>
     */
    public static function compare(mixed $prev, mixed $next, bool $invertible = false): array
    {
        $patches = [];
        self::generate($prev, $next, $patches, '', $invertible);

        return $patches;
    }

    /**
     * Structural equality, with types compared strictly.
     *
     * This is the guard that stops a streaming parser from re-emitting a value
     * it already emitted: `1` and `"1"`, and `{"a":1}` and `{"a":"1"}`, are
     * different documents.
     */
    public static function deepEquals(mixed $a, mixed $b): bool
    {
        if ($a === $b) {
            return true;
        }

        if (is_array($a) && is_array($b)) {
            if (Js::isList($a) !== Js::isList($b)) {
                return false;
            }

            if (count($a) !== count($b)) {
                return false;
            }

            foreach ($a as $key => $value) {
                if (!array_key_exists($key, $b) || !self::deepEquals($value, $b[$key])) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    /**
     * JavaScript truthiness, which differs from PHP's in one load-bearing way:
     * an empty array *and* an empty object are both truthy in JS, so `[]` is not
     * a stand-in for `undefined` and must not be treated as "no value yet".
     */
    public static function isFalsy(mixed $value): bool
    {
        return match (true) {
            $value === null, $value === false => true,
            is_array($value) => false,
            is_string($value) => $value === '',
            default => $value === 0 || $value === 0.0,
        };
    }

    /**
     * @param list<array<string, mixed>> $patches
     */
    private static function generate(mixed $mirror, mixed $obj, array &$patches, string $path, bool $invertible): void
    {
        if ($mirror === $obj) {
            return;
        }

        $mirrorIsList = is_array($mirror) && Js::isList($mirror);
        $objIsList = is_array($obj) && Js::isList($obj);

        /** @var array<array-key, mixed> $mirrorMap */
        $mirrorMap = is_array($mirror) ? $mirror : [];
        /** @var array<array-key, mixed> $objMap */
        $objMap = is_array($obj) ? $obj : [];

        $newKeys = array_keys($objMap);
        $oldKeys = array_keys($mirrorMap);
        $deleted = false;

        for ($t = count($oldKeys) - 1; $t >= 0; $t--) {
            $key = $oldKeys[$t];
            $oldVal = $mirrorMap[$key];
            $child = $path . '/' . self::escapePathComponent((string) $key);

            if (array_key_exists($key, $objMap) && $objMap[$key] !== null) {
                $newVal = $objMap[$key];

                // An empty PHP array is still an array (and an empty JS object is
                // still an object), so the recursion happens for both. That is
                // what turns an empty array gaining an element into a patch on the
                // element rather than on the array.
                if (is_array($oldVal) && is_array($newVal) && Js::isList($oldVal) === Js::isList($newVal)) {
                    self::generate($oldVal, $newVal, $patches, $child, $invertible);
                } elseif ($oldVal !== $newVal) {
                    if ($invertible) {
                        $patches[] = ['op' => 'test', 'path' => $child, 'value' => $oldVal];
                    }
                    $patches[] = ['op' => 'replace', 'path' => $child, 'value' => $newVal];
                }
            } elseif ($mirrorIsList === $objIsList) {
                if ($invertible) {
                    $patches[] = ['op' => 'test', 'path' => $child, 'value' => $oldVal];
                }
                $patches[] = ['op' => 'remove', 'path' => $child];
                $deleted = true;
            } else {
                if ($invertible) {
                    $patches[] = ['op' => 'test', 'path' => $path, 'value' => $mirror];
                }
                $patches[] = ['op' => 'replace', 'path' => $path, 'value' => $obj];
            }
        }

        if (!$deleted && count($newKeys) === count($oldKeys)) {
            return;
        }

        foreach ($newKeys as $key) {
            if (!array_key_exists($key, $mirrorMap) && $objMap[$key] !== null) {
                $patches[] = [
                    'op' => 'add',
                    'path' => $path . '/' . self::escapePathComponent((string) $key),
                    'value' => $objMap[$key],
                ];
            }
        }
    }

    // ------------------------------------------------------------ apply internals

    /**
     * @param array<string, mixed> $operation
     * @return array<string, mixed>
     */
    private static function applyRoot(mixed $document, array $operation, mixed $op, bool $validating, int $index): array
    {
        $ret = ['newDocument' => $document];
        switch ($op) {
            case 'add':
                $ret['newDocument'] = $operation['value'] ?? null;

                return $ret;
            case 'replace':
                $ret['newDocument'] = $operation['value'] ?? null;
                $ret['removed'] = $document;

                return $ret;
            case 'move':
            case 'copy':
                $ret['newDocument'] = self::getValueByPointer($document, (string) ($operation['from'] ?? ''));
                if ($op === 'move') {
                    $ret['removed'] = $document;
                }

                return $ret;
            case 'test':
                $ret['test'] = self::areEquals($document, $operation['value'] ?? null);
                if ($ret['test'] === false) {
                    throw new JsonPatchError('Test operation failed', 'TEST_OPERATION_FAILED', $index, $operation, $document);
                }

                return $ret;
            case 'remove':
                $ret['removed'] = $document;
                $ret['newDocument'] = null;

                return $ret;
            case '_get':
                $ret['value'] = $document;

                return $ret;
            default:
                if ($validating) {
                    throw new JsonPatchError('Operation `op` property is not one of operations defined in RFC-6902', 'OPERATION_OP_INVALID', $index, $operation, $document);
                }

                return $ret;
        }
    }

    /**
     * Descend one path segment. Returns the (possibly rebuilt) container and the
     * operation result; a result carrying `newDocument` replaces the whole root
     * (move and copy do that).
     *
     * @param list<string>         $keys
     * @param array<string, mixed> $operation
     * @return array{0: mixed, 1: array<string, mixed>}
     */
    private static function walk(
        mixed $container,
        array $keys,
        int $t,
        int $len,
        array $operation,
        mixed $root,
        bool $validating,
        callable $validateFn,
        bool $ban,
        int $index,
        ?string &$existing,
    ): array {
        $key = $keys[$t];
        if ($key !== '' && str_contains($key, '~')) {
            $key = self::unescapePathComponent($key);
        }

        if ($ban && ($key === '__proto__' || ($key === 'prototype' && $t > 0 && $keys[$t - 1] === 'constructor'))) {
            throw new \TypeError('JSON-Patch: modifying `__proto__` or `constructor/prototype` prop is banned for security reasons, if this was on purpose, please set `banPrototypeModifications` flag false and pass it to this function. More info in fast-json-patch README');
        }

        if ($validating && $existing === null) {
            if (!is_array($container) || !array_key_exists($key, $container)) {
                $existing = implode('/', array_slice($keys, 0, $t));
            } elseif ($t === $len - 1) {
                $existing = (string) $operation['path'];
            }
            if ($existing !== null) {
                $validateFn($operation, 0, $root, $existing);
            }
        }

        $next = $t + 1;
        $op = (string) ($operation['op'] ?? '');

        $isList = is_array($container) && Js::isList($container)
            && ($container !== [] || $key === '-' || self::isInteger($key));

        if ($isList) {
            /** @var array<int, mixed> $container */
            if ($key === '-') {
                $index2 = count($container);
            } else {
                if ($validating && !self::isInteger($key)) {
                    throw new JsonPatchError('Expected an unsigned base-10 integer value, making the new referenced value the array element with the zero-based index', 'OPERATION_PATH_ILLEGAL_ARRAY_INDEX', $index, $operation, $root);
                }
                $index2 = self::isInteger($key) ? (int) $key : $key;
            }
            if ($next >= $len) {
                if ($validating && $op === 'add' && is_int($index2) && $index2 > count($container)) {
                    throw new JsonPatchError('The specified index MUST NOT be greater than the number of elements in the array', 'OPERATION_VALUE_OUT_OF_BOUNDS', $index, $operation, $root);
                }

                return self::finish($container, $index2, $operation, $root, $index, true);
            }
            $key = $index2;
        } elseif ($next >= $len) {
            if (!is_array($container)) {
                throw new JsonPatchError('Cannot perform operation at the desired path', 'OPERATION_PATH_UNRESOLVABLE', $index, $operation, $root);
            }

            return self::finish($container, $key, $operation, $root, $index, false);
        }

        if (!is_array($container) || !array_key_exists($key, $container) || !is_array($container[$key])) {
            throw new JsonPatchError('Cannot perform operation at the desired path', 'OPERATION_PATH_UNRESOLVABLE', $index, $operation, $root);
        }

        [$child, $ret] = self::walk($container[$key], $keys, $next, $len, $operation, $root, $validating, $validateFn, $ban, $index, $existing);
        if (!array_key_exists('newDocument', $ret)) {
            $container[$key] = $child;
        }

        return [$container, $ret];
    }

    /**
     * Run the operation against the container that owns the last path segment.
     *
     * @param array<array-key, mixed> $container
     * @param array<string, mixed>    $operation
     * @return array{0: mixed, 1: array<string, mixed>}
     */
    private static function finish(array $container, int|string $key, array $operation, mixed $root, int $index, bool $isList): array
    {
        $op = (string) ($operation['op'] ?? '');
        $ret = [];

        switch ($op) {
            case 'add':
                if (!$isList && ($key === '__proto__' || $key === 'constructor')) {
                    throw self::protoError();
                }
                if ($isList && is_int($key)) {
                    array_splice($container, $key, 0, [$operation['value'] ?? null]);
                } else {
                    $container[$key] = $operation['value'] ?? null;
                }
                break;
            case 'remove':
                if (!$isList && ($key === '__proto__' || $key === 'constructor')) {
                    throw self::protoError();
                }
                $ret['removed'] = $container[$key] ?? null;
                if ($isList && is_int($key)) {
                    array_splice($container, $key, 1);
                } else {
                    unset($container[$key]);
                }
                break;
            case 'replace':
                if (!$isList && ($key === '__proto__' || $key === 'constructor')) {
                    throw self::protoError();
                }
                $ret['removed'] = $container[$key] ?? null;
                $container[$key] = $operation['value'] ?? null;
                break;
            case 'move':
                $path = (string) $operation['path'];
                $removed = self::getValueByPointer($root, $path);
                $taken = self::applyOperation($root, ['op' => 'remove', 'path' => $operation['from'] ?? '']);
                $placed = self::applyOperation($taken['newDocument'], ['op' => 'add', 'path' => $path, 'value' => $taken['removed'] ?? null]);
                $ret = ['newDocument' => $placed['newDocument'], 'removed' => $removed];
                break;
            case 'copy':
                $copied = self::deepClone(self::getValueByPointer($root, (string) ($operation['from'] ?? '')));
                $placed = self::applyOperation($root, ['op' => 'add', 'path' => $operation['path'], 'value' => $copied]);
                $ret = ['newDocument' => $placed['newDocument']];
                break;
            case 'test':
                $ret['test'] = self::areEquals($container[$key] ?? null, $operation['value'] ?? null);
                if ($ret['test'] === false) {
                    throw new JsonPatchError('Test operation failed', 'TEST_OPERATION_FAILED', $index, $operation, $root);
                }
                break;
            case '_get':
                $ret['value'] = $container[$key] ?? null;
                break;
            default:
                throw new JsonPatchError('Operation `op` property is not one of operations defined in RFC-6902', 'OPERATION_OP_INVALID', $index, $operation, $root);
        }

        return [$container, $ret];
    }

    private static function protoError(): \TypeError
    {
        return new \TypeError('JSON-Patch: modifying `__proto__` or `constructor` prop is banned for security reasons');
    }
}
