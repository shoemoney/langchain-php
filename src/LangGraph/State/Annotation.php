<?php

declare(strict_types=1);

namespace LangGraph\State;

use LangGraph\Channels\BaseChannel;
use LangGraph\Channels\BinaryOperatorAggregate;
use LangGraph\Channels\LastValue;

/**
 * Builds the channels a state schema declares.
 *
 * Port of the `Annotation` function and `getChannel` from
 * `graph/annotation.ts`.
 *
 * Called with no argument, or with one that names no reducer, the result is a
 * {@see LastValue}: two writers in a step is an error. Called with a reducer,
 * it is a {@see BinaryOperatorAggregate}: every write is folded, in order.
 */
final class Annotation
{
    private function __construct()
    {
    }

    /**
     * A channel holding the most recent write.
     *
     * @param (callable(): mixed)|null $initialValueFactory Seeds a channel
     *        that is written before it is ever read, so the first node does not
     *        have to handle an absent value.
     */
    public static function last(?callable $initialValueFactory = null): BaseChannel
    {
        return new LastValue($initialValueFactory);
    }

    /**
     * A channel folding every write through a reducer.
     *
     * @param callable             $operator               `fn(mixed $left, mixed $right): mixed`
     * @param (callable(): mixed)|null $initialValueFactory Seeds the folded value.
     */
    public static function withReducer(callable $operator, ?callable $initialValueFactory = null): BaseChannel
    {
        return new BinaryOperatorAggregate($operator, $initialValueFactory);
    }

    /**
     * Build a channel from a reducer spec.
     *
     * Port of `getChannel`. Accepts both the `reducer` and the older `value`
     * key so a schema written against either spelling works.
     *
     * The TypeScript original takes its schema from a type-level `Annotation`
     * that vanishes entirely at runtime, so every JS graph writes
     * `new StateGraph({ value: Annotation<number> })` and the channel is
     * inferred. PHP has no such erasure, which leaves this port having to spell
     * the channel out — so this method also accepts the shorthand forms a PHP
     * user would reach for, rather than forcing the verbose channel objects:
     *
     * ```php
     * ['value' => null]              // LastValue
     * ['value' => 'int']             // LastValue, seeded with 0
     * ['value' => 'list']            // aggregate, seeded with []
     * ['value' => ['reducer' => $add]]
     * ```
     *
     * @param array{reducer?: callable, value?: callable, default?: callable}|string|null $annotation
     */
    public static function fromSpec(array|string|null $annotation): BaseChannel
    {
        if ($annotation === null) {
            return new LastValue();
        }

        if (is_string($annotation)) {
            return self::fromTypeName($annotation);
        }

        $operator = $annotation['reducer'] ?? $annotation['value'] ?? null;
        if ($operator === null) {
            return new LastValue();
        }

        return new BinaryOperatorAggregate($operator, $annotation['default'] ?? null);
    }

    /**
     * The shorthand: a bare type name, mapped the way a Python `Annotated` state
     * schema maps it — a list-shaped type accumulates, everything else is a
     * last-value channel.
     */
    private static function fromTypeName(string $type): BaseChannel
    {
        return match (strtolower($type)) {
            'list', 'array_list', 'seq' => self::appendable(),
            'messages' => self::appendable(),
            'int' => new LastValue(static fn (): int => 0),
            'float' => new LastValue(static fn (): float => 0.0),
            'string', 'str' => new LastValue(static fn (): string => ''),
            'bool', 'boolean' => new LastValue(static fn (): bool => false),
            'array', 'dict', 'map', 'object' => new LastValue(static fn (): array => []),
            'any', 'mixed' => new LastValue(),
            default => throw new \InvalidArgumentException(
                "Unknown state key type \"{$type}\". Use a channel, a reducer spec, "
                . 'or one of: int, float, string, bool, array, list, any.'
            ),
        };
    }

    /**
     * A list-typed state key: every write appends rather than replaces.
     */
    public static function appendable(): BaseChannel
    {
        return new BinaryOperatorAggregate(
            static fn (mixed $left, mixed $right): array => array_merge(
                is_array($left) ? $left : [],
                is_array($right) ? $right : [$right]
            ),
            static fn (): array => []
        );
    }

    /**
     * Declare a state schema.
     *
     * @param array<string, array{reducer?: callable, value?: callable, default?: callable}|string|null|BaseChannel> $spec
     */
    public static function root(array $spec): AnnotationRoot
    {
        $channels = [];
        foreach ($spec as $name => $definition) {
            $channels[$name] = $definition instanceof BaseChannel
                ? $definition
                : self::fromSpec($definition);
        }

        return new AnnotationRoot($channels);
    }
}
