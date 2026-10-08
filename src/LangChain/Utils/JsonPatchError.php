<?php

declare(strict_types=1);

namespace LangChain\Utils;

/**
 * An RFC 6902 failure.
 *
 * Port of `PatchError` / `JsonPatchError` from `fast-json-patch`'s `helpers.ts`.
 * Upstream stores the machine-readable code in the JS `name` property; PHP
 * exceptions already own `getCode()` (an int), so the string code lives in
 * {@see self::$errorName}.
 */
final class JsonPatchError extends \Exception
{
    /**
     * @param string $errorName one of SEQUENCE_NOT_AN_ARRAY, OPERATION_NOT_AN_OBJECT, OPERATION_OP_INVALID,
     *                          OPERATION_PATH_INVALID, OPERATION_FROM_REQUIRED, OPERATION_VALUE_REQUIRED,
     *                          OPERATION_VALUE_CANNOT_CONTAIN_UNDEFINED, OPERATION_PATH_CANNOT_ADD,
     *                          OPERATION_PATH_UNRESOLVABLE, OPERATION_FROM_UNRESOLVABLE,
     *                          OPERATION_PATH_ILLEGAL_ARRAY_INDEX, OPERATION_VALUE_OUT_OF_BOUNDS,
     *                          TEST_OPERATION_FAILED
     */
    public function __construct(
        string $message,
        public readonly string $errorName,
        public readonly ?int $index = null,
        public readonly mixed $operation = null,
        public readonly mixed $tree = null,
    ) {
        parent::__construct(self::format($message, [
            'name' => $errorName,
            'index' => $index,
            'operation' => $operation,
            'tree' => $tree,
        ]));
    }

    /**
     * Upstream's `patchErrorMessageFormatter`: the message, then one `key: value`
     * line per defined argument, objects pretty-printed.
     *
     * @param array<string, mixed> $args
     */
    private static function format(string $message, array $args): string
    {
        $parts = [$message];
        foreach ($args as $key => $value) {
            if ($value === null) {
                continue;
            }
            $parts[] = $key . ': ' . (is_array($value)
                ? json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_PARTIAL_OUTPUT_ON_ERROR)
                : (string) $value);
        }

        return implode("\n", $parts);
    }
}
