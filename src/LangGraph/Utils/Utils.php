<?php

declare(strict_types=1);

namespace LangGraph\Utils;

use LangChain\Runnables\RunnableConfig;

/**
 * The small helpers from `langgraph-core/src/utils.ts`.
 *
 * `RunnableCallable` lives in its own file ({@see RunnableCallable}); the
 * async-only helpers (`isAsyncGeneratorFunction`) have no PHP counterpart, since
 * the engine is synchronous.
 */
final class Utils
{
    private function __construct()
    {
    }

    /**
     * Re-yield a generator, tagging each value with a prefix.
     *
     * Port of `prefixGenerator`. With no prefix the generator is passed through
     * untouched; with one, every value `v` becomes `[prefix, v]`.
     *
     * @template T
     * @param  iterable<T>   $generator
     * @return \Generator<int, mixed>
     */
    public static function prefixGenerator(iterable $generator, ?string $prefix = null): \Generator
    {
        if ($prefix === null) {
            yield from $generator;

            return;
        }

        foreach ($generator as $value) {
            yield [$prefix, $value];
        }
    }

    /**
     * Drain an iterable into a list.
     *
     * Port of `gatherIterator` (and `gatherIteratorSync`, which PHP does not
     * need to tell apart). Keys are discarded, as `Array.from` does.
     *
     * @template T
     * @param  iterable<T> $iterable
     * @return list<T>
     */
    public static function gatherIterator(iterable $iterable): array
    {
        $out = [];
        foreach ($iterable as $item) {
            $out[] = $item;
        }

        return $out;
    }

    /**
     * Port of `gatherIteratorSync`.
     *
     * @template T
     * @param  iterable<T> $iterable
     * @return list<T>
     */
    public static function gatherIteratorSync(iterable $iterable): array
    {
        return self::gatherIterator($iterable);
    }

    /**
     * A copy of the config whose `configurable` has `$patch` laid over it.
     *
     * Port of `patchConfigurable`. The input is never mutated: parallel legs
     * share one config, and a patch applied to it would leak into siblings.
     *
     * @param array<string, mixed> $patch
     */
    public static function patchConfigurable(?RunnableConfig $config, array $patch): RunnableConfig
    {
        if ($config === null) {
            return new RunnableConfig(configurable: $patch);
        }

        $copy = clone $config;
        $copy->configurable = array_merge($config->configurable, $patch);

        return $copy;
    }

    /**
     * Whether a value is a generator function (a callable that returns a generator).
     *
     * Port of `isGeneratorFunction`. PHP marks these by their body containing
     * `yield`, which reflection exposes as `isGenerator()`.
     */
    public static function isGeneratorFunction(mixed $value): bool
    {
        if (!\is_callable($value)) {
            return false;
        }

        try {
            $reflection = match (true) {
                $value instanceof \Closure => new \ReflectionFunction($value),
                \is_array($value) => new \ReflectionMethod($value[0], $value[1]),
                \is_string($value) && str_contains($value, '::') => new \ReflectionMethod(...explode('::', $value, 2)),
                \is_string($value) => new \ReflectionFunction($value),
                default => new \ReflectionMethod($value, '__invoke'),
            };
        } catch (\ReflectionException) {
            return false;
        }

        return $reflection->isGenerator();
    }
}
