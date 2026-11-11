<?php

declare(strict_types=1);

namespace LangChain\Load;

/** Literal escaping from core load/validation.ts, using PHP arrays and public object fields. */
final class Validation
{
    public const LC_ESCAPED_KEY = '__lc_escaped__';

    /** @param array|object $value */
    public static function needsEscaping(array|object $value): bool
    {
        $fields = is_array($value) ? $value : get_object_vars($value);

        return array_key_exists('lc', $fields) || (count($fields) === 1 && array_key_exists(self::LC_ESCAPED_KEY, $fields));
    }

    /** Escape values, never the generated root constructor or its kwargs dictionary. */
    public static function escapeKwargs(array $kwargs, object $owner): array
    {
        $path = new \SplObjectStorage();
        $path->offsetSet($owner);
        foreach ($kwargs as $key => $value) {
            $kwargs[$key] = self::escapeIfNeeded($value, $path);
        }

        return $kwargs;
    }

    /**
     * Genuine LangChain objects retain their own JSON serialization. Literal dictionaries
     * needing escaping are wrapped verbatim, before child traversal, just as upstream.
     * The ancestor set permits shared references while replacing ordinary object cycles.
     * PHP reference arrays have no WeakSet identity: traversal is bounded at 512 levels.
     */
    public static function escapeIfNeeded(mixed $value, ?\SplObjectStorage $path = null): mixed
    {
        return self::escapeValue($value, $path ?? new \SplObjectStorage(), 0);
    }

    private static function escapeValue(mixed $value, \SplObjectStorage $path, int $depth): mixed
    {
        self::checkDepth($depth);
        if (is_object($value)) {
            if ($path->offsetExists($value)) {
                return ['lc' => 1, 'type' => 'not_implemented', 'id' => is_callable([$value, 'lcId']) ? $value->lcId() : ['Object']];
            }
            if (is_callable([$value, 'toJson']) && is_callable([$value, 'lcId'])) {
                return $value;
            }
            // Preserve PHP-native JSON behavior instead of flattening typed values.
            if ($value instanceof \UnitEnum || $value instanceof \DateTimeInterface) {
                return $value;
            }
            if ($value instanceof \JsonSerializable) {
                $path->offsetSet($value);
                try {
                    return self::escapeValue($value->jsonSerialize(), $path, $depth + 1);
                } finally {
                    $path->offsetUnset($value);
                }
            }
            if (self::needsEscaping($value)) {
                return [self::LC_ESCAPED_KEY => $value];
            }
            $path->offsetSet($value);
            try {
                $result = new \stdClass();
                foreach (get_object_vars($value) as $key => $item) {
                    $result->{$key} = self::escapeValue($item, $path, $depth + 1);
                }

                return $result;
            } finally {
                $path->offsetUnset($value);
            }
        }
        if (is_array($value)) {
            if (!array_is_list($value) && self::needsEscaping($value)) {
                return [self::LC_ESCAPED_KEY => $value];
            }
            foreach ($value as $key => $item) {
                $value[$key] = self::escapeValue($item, $path, $depth + 1);
            }
        }

        return $value;
    }

    /** Unwrap a singleton once, preserving every nested marker as literal user data. */
    public static function unescapeValue(mixed $value): mixed
    {
        return self::unescape($value, 0);
    }

    private static function unescape(mixed $value, int $depth): mixed
    {
        self::checkDepth($depth);
        if (is_array($value)) {
            if (count($value) === 1 && array_key_exists(self::LC_ESCAPED_KEY, $value)) {
                return $value[self::LC_ESCAPED_KEY];
            }
            foreach ($value as $key => $item) {
                $value[$key] = self::unescape($item, $depth + 1);
            }
        } elseif (is_object($value)) {
            // PHP checkpoint decoding has already revived typed child constructors.
            // JSON dictionaries decode as arrays; only stdClass is a plain object here.
            // Preserve restored LC/lc:2 objects and PHP-native values at this boundary.
            if (!$value instanceof \stdClass) {
                return $value;
            }
            $fields = get_object_vars($value);
            if (count($fields) === 1 && array_key_exists(self::LC_ESCAPED_KEY, $fields)) {
                return $fields[self::LC_ESCAPED_KEY];
            }
            $result = new \stdClass();
            foreach ($fields as $key => $item) {
                $result->{$key} = self::unescape($item, $depth + 1);
            }

            return $result;
        }

        return $value;
    }

    private static function checkDepth(int $depth): void
    {
        if ($depth > 512) {
            throw new \RuntimeException('Maximum literal serialization traversal depth (512) exceeded.');
        }
    }
}
