<?php

declare(strict_types=1);

namespace LangChain\Runnables;

/**
 * Selects one or more named fields from a runnable's output.
 *
 * Port of `RunnablePick` (`libs/langchain-core/src/runnables/base.ts`), reached through
 * {@see Runnable::pick()}, which upstream defines as `this.pipe(new RunnablePick(keys))`.
 *
 * Upstream's `_pick`, transcribed:
 *   - a STRING key returns `input[key]`;
 *   - an ARRAY of keys returns `{key: input[key]}` for every key whose value is not `undefined`;
 *   - if NOTHING survives, the result is `undefined`, and upstream's `_transform` then yields nothing —
 *     so the all-missing case is a BEHAVIOUR, not an edge case.
 *
 * In PHP "undefined" is a missing array key, and "no value to yield" is `null`, so those two upstream
 * outcomes are represented as `null` here. That is a documented divergence in shape, not in meaning:
 * a caller that had to branch on `undefined` now branches on `null`.
 */
final class RunnablePick implements RunnableInterface
{
    /** @param string|list<string> $keys */
    public function __construct(
        public readonly string|array $keys,
    ) {
    }

    public static function lcNamespace(): array
    {
        return ['langchain_core', 'runnables'];
    }

    public function getName(): string
    {
        return 'RunnablePick';
    }

    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        if (!\is_array($input)) {
            // Upstream indexes `input[key]` unconditionally; a non-array would be a TypeError there.
            // Returning null keeps the all-missing contract above rather than raising on the way in.
            return null;
        }

        if (\is_string($this->keys)) {
            return $input[$this->keys] ?? null;
        }

        $picked = [];
        foreach ($this->keys as $key) {
            // Upstream filters on `v[1] !== undefined`, so a present-but-null value IS kept.
            if (\array_key_exists($key, $input)) {
                $picked[$key] = $input[$key];
            }
        }

        return $picked === [] ? null : $picked;
    }

    public function stream(mixed $input, ?RunnableConfig $config = null): \Generator
    {
        $picked = $this->invoke($input, $config);

        // Upstream's `_transform` yields only when the pick was not undefined — the same rule.
        if ($picked !== null) {
            yield [self::CHANNEL_DEFAULT, $picked];
        }
    }

    /**
     * @param list<mixed> $inputs
     * @param array<string, mixed>|null $options
     *
     * @return list<mixed>
     */
    public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array
    {
        return array_map(fn (mixed $input): mixed => $this->invoke($input, $config), array_values($inputs));
    }

    /**
     * Same faithful contract as `Runnable::transform()` — gather the chunks, invoke once, yield raw.
     * `RunnableInterface::transform()` is implemented directly here rather than inherited, so the body
     * cannot simply be deleted; `Runnable::concatOutputs()` is public precisely so this class can reach it.
     * Upstream reference: `base.ts:655-671` plus `_streamIterator` at `base.ts:297-302`.
     */
    public function transform(iterable $input, ?RunnableConfig $config = null): \Generator
    {
        $final = null;
        $seen = false;

        foreach ($input as $chunk) {
            $final = $seen ? Runnable::concatOutputs($final, $chunk) : $chunk;
            $seen = true;
        }

        yield $this->invoke($final, $config);
    }
}
