<?php

declare(strict_types=1);

namespace LangChain\Messages;

use LangChain\Utils\Js;

/**
 * Merge primitives for message content and chunk kwargs.
 *
 * Port of the `_mergeDicts` / `_mergeLists` / `_mergeObj` family from
 * `@langchain/core/messages/base`. These functions are what make streaming
 * work: a token stream arrives as a sequence of partial chunks, and merging
 * them into one message requires deciding, per field, whether to concatenate,
 * sum, replace, recurse, or skip.
 *
 * The rules, faithfully carried over:
 *
 *  - `type` is never merged (the first one wins);
 *  - `id` / `name` / `output_version` / `model_provider` take the incoming value
 *    when it is set, rather than concatenating;
 *  - strings concatenate, numbers sum, objects recurse, lists merge by index;
 *  - keys in {@see DEFAULT_MERGE_IGNORE_KEYS} keep the original value — they
 *    identify the chunk rather than accumulate across it.
 */
final class MessageMerge
{
    /**
     * Keys preserved rather than merged when concatenating chunks.
     *
     * @var list<string>
     */
    public const DEFAULT_MERGE_IGNORE_KEYS = ['index', 'created', 'timestamp'];

    /** Fields that adopt the incoming value instead of concatenating. */
    /**
     * Fields that identify a message rather than accumulate across it.
     *
     * Upstream declares exactly these as the named members of
     * `ResponseMetadata` (`messages/metadata.ts:4-9`) alongside an open index
     * signature — they are identity, not content. `model_name` was missing here,
     * so three streamed OpenAI deltas folded into
     * `model_name: "gpt-4ogpt-4ogpt-4o"`.
     */
    private const REPLACE_KEYS = ['id', 'name', 'output_version', 'model_provider', 'model_name'];

    private function __construct()
    {
    }

    /**
     * Concatenate two message contents.
     *
     * Content is either a string or a list of content blocks, and the two
     * combine asymmetrically: a string always loses to a block list, which is
     * normalised upward into blocks so the result is never a mixed shape.
     */
    public static function mergeContent(mixed $first, mixed $second): mixed
    {
        if (is_string($first)) {
            if ($first === '') {
                return $second;
            }
            if (is_string($second)) {
                return $first . $second;
            }
            if (is_array($second)) {
                if ($second === []) {
                    return $first;
                }
                if (self::anyIsData($second)) {
                    return array_merge(
                        [ContentBlock::text($first, ['source_type' => 'text'])],
                        $second
                    );
                }

                return array_merge([ContentBlock::text($first)], $second);
            }

            return [ContentBlock::text($first), $second];
        }

        if (is_array($second)) {
            $left = self::contentBlocks($first);
            $merged = self::mergeLists($left, $second);
            if ($merged !== null) {
                return $merged;
            }

            return array_merge($left, $second);
        }

        if ($second === '') {
            return $first;
        }
        if (is_array($first) && self::anyIsData($first)) {
            return array_merge($first, [
                ['type' => ContentBlock::FILE, 'source_type' => 'text', 'text' => $second],
            ]);
        }

        return array_merge(self::contentBlocks($first), [ContentBlock::text($second)]);
    }

    /**
     * 'Merge' two statuses: if either is 'error', the result is 'error'.
     */
    public static function mergeStatus(?string $left, ?string $right): ?string
    {
        if ($left === 'error' || $right === 'error') {
            return 'error';
        }

        return 'success';
    }

    /**
     * Normalise content to a list of blocks.
     *
     * Some serializers (notably the Anthropic-shaped one) yield a single block
     * rather than a one-element list; spreading such a value as a list throws
     * in JS, so it is wrapped here.
     *
     * @return list<mixed>
     */
    public static function contentBlocks(mixed $content): array
    {
        if (is_array($content)) {
            return self::isListOf($content) ? array_values($content) : [$content];
        }
        if (is_string($content)) {
            return $content === '' ? [] : [ContentBlock::text($content)];
        }
        if ($content === null) {
            return [];
        }

        return [$content];
    }

    /**
     * @param array<mixed> $value
     */
    private static function isListOf(array $value): bool
    {
        foreach (array_keys($value) as $k) {
            if (!is_int($k)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<mixed> $list
     */
    private static function anyIsData(array $list): bool
    {
        foreach ($list as $item) {
            if (ContentBlock::isData($item)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Merge two plain records field by field.
     *
     * @param array<string, mixed>|null $left
     * @param array<string, mixed>|null $right
     * @param list<string> $ignoreKeys
     * @return array<string, mixed>|null
     */
    public static function mergeDicts(?array $left, ?array $right, array $ignoreKeys = self::DEFAULT_MERGE_IGNORE_KEYS): ?array
    {
        if ($left === null && $right === null) {
            return null;
        }
        if ($left === null || $right === null) {
            return $left ?? $right;
        }

        $merged = $left;
        foreach ($right as $key => $value) {
            if (!array_key_exists($key, $merged) || $merged[$key] === null) {
                $merged[$key] = $value;
                continue;
            }
            if ($value === null) {
                continue;
            }

            $existing = $merged[$key];

            if (Js::typeOf($existing) !== Js::typeOf($value) || Js::isList((array) $existing) !== Js::isList((array) $value)) {
                throw new \InvalidArgumentException(
                    "field[{$key}] already exists in the message chunk, but with a different type."
                );
            }

            if (is_string($existing)) {
                if ($key === 'type') {
                    // The first type wins; chunks of different types never merge.
                    continue;
                }
                if (in_array($key, self::REPLACE_KEYS, true)) {
                    if ($value !== '' && $value !== null) {
                        $merged[$key] = $value;
                    }
                    continue;
                }
                if (in_array($key, $ignoreKeys, true)) {
                    continue;
                }
                $merged[$key] = $existing . $value;
                continue;
            }

            if (is_int($existing) || is_float($existing)) {
                if (in_array($key, $ignoreKeys, true)) {
                    continue;
                }
                $merged[$key] = $existing + $value;
                continue;
            }

            if (is_array($existing)) {
                if (Js::isList($existing)) {
                    $merged[$key] = self::mergeLists($existing, $value, $ignoreKeys);
                } else {
                    /** @var array<string, mixed> $existing */
                    /** @var array<string, mixed> $value */
                    $merged[$key] = self::mergeDicts($existing, $value, $ignoreKeys);
                }
                continue;
            }

            if ($existing === $value) {
                continue;
            }

            // Deliberately silent, matching the JS `console.warn`: an
            // unsupported field type must not abort a stream merge.
        }

        return $merged;
    }

    /**
     * Find the index in $merged that $item should merge into.
     *
     * Matching priority, per the TS original:
     *  1. both sides carry an index → match on index, disambiguated by id;
     *  2. neither side carries an index but both carry an id → match on id alone
     *     (handles providers that omit `index` on streaming tool-call deltas);
     *  3. otherwise → no match, so the item is appended.
     *
     * @param list<mixed> $merged
     * @return int -1 when no target exists
     */
    private static function findMergeTarget(array $merged, mixed $item): int
    {
        $itemHasIndex = self::hasMergeableIndex($item);
        $itemHasId = self::hasMergeableId($item);

        if (!$itemHasIndex && !$itemHasId) {
            return -1;
        }

        foreach ($merged as $i => $leftItem) {
            $leftHasIndex = self::hasMergeableIndex($leftItem);
            $leftHasId = self::hasMergeableId($leftItem);

            if ($itemHasIndex && $leftHasIndex) {
                if (($leftItem['index'] ?? null) !== ($item['index'] ?? null)) {
                    continue;
                }
                if (self::hasMismatchedMergeableType($leftItem, $item)) {
                    continue;
                }
                if ($leftHasId && $itemHasId) {
                    if (($leftItem['id'] ?? null) === ($item['id'] ?? null)) {
                        return $i;
                    }
                    continue;
                }

                return $i;
            }

            if (!$itemHasIndex && !$leftHasIndex && $itemHasId && $leftHasId) {
                if (($leftItem['id'] ?? null) === ($item['id'] ?? null)) {
                    return $i;
                }
            }
        }

        return -1;
    }

    private static function hasMergeableIndex(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        if (!array_key_exists('index', $value)) {
            return false;
        }
        $index = $value['index'];

        return is_int($index) || is_string($index);
    }

    private static function hasMergeableId(mixed $value): bool
    {
        if (!is_array($value)) {
            return false;
        }
        if (!array_key_exists('id', $value)) {
            return false;
        }
        $id = $value['id'];

        return $id !== null && $id !== '';
    }

    private static function getMergeableTypeBase(string $type): string
    {
        return str_ends_with($type, '_delta')
            ? substr($type, 0, -strlen('_delta'))
            : $type;
    }

    private static function hasMismatchedMergeableType(mixed $left, mixed $right): bool
    {
        if (!is_array($left) || !is_array($right)) {
            return false;
        }
        if (!isset($left['type'], $right['type'])) {
            return false;
        }
        if (!is_string($left['type']) || !is_string($right['type'])) {
            return false;
        }

        return self::getMergeableTypeBase($left['type']) !== self::getMergeableTypeBase($right['type']);
    }

    /**
     * Merge two lists of content blocks, folding matching items together.
     *
     * @param list<mixed>|null $left
     * @param list<mixed>|null $right
     * @param list<string> $ignoreKeys
     * @return list<mixed>|null
     */
    public static function mergeLists(?array $left, ?array $right, array $ignoreKeys = self::DEFAULT_MERGE_IGNORE_KEYS): ?array
    {
        if ($left === null && $right === null) {
            return null;
        }
        if ($left === null || $right === null) {
            return $left ?? $right;
        }

        $merged = array_values($left);
        foreach ($right as $item) {
            $target = self::findMergeTarget($merged, $item);
            if ($target !== -1) {
                /** @var array<string, mixed> $merged[$target] */
                /** @var array<string, mixed> $item */
                $merged[$target] = self::mergeDicts($merged[$target], $item, $ignoreKeys) ?? [];
                continue;
            }
            // An empty text delta carries no information and is dropped.
            if (is_array($item) && array_key_exists('text', $item) && $item['text'] === '') {
                continue;
            }
            $merged[] = $item;
        }

        return $merged;
    }

    /**
     * Merge two arbitrary values of the same type.
     *
     * @param list<string> $ignoreKeys
     */
    public static function mergeObj(mixed $left, mixed $right, array $ignoreKeys = self::DEFAULT_MERGE_IGNORE_KEYS): mixed
    {
        if ($left === null && $right === null) {
            return null;
        }
        if ($left === null || $right === null) {
            return $left ?? $right;
        }

        $leftType = Js::typeOf($left);
        $rightType = Js::typeOf($right);
        if ($leftType !== $rightType) {
            throw new \InvalidArgumentException(
                "Cannot merge objects of different types.\nLeft {$leftType}\nRight {$rightType}"
            );
        }

        if (is_string($left) && is_string($right)) {
            return $left . $right;
        }
        if (is_array($left) && is_array($right)) {
            if (Js::isList($left)) {
                return self::mergeLists($left, $right, $ignoreKeys);
            }

            return self::mergeDicts($left, $right, $ignoreKeys);
        }
        if ($left === $right) {
            return $left;
        }

        throw new \InvalidArgumentException(
            'Can not merge objects of different types.'
        );
    }
}
