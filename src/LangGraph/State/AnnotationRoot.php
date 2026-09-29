<?php

declare(strict_types=1);

namespace LangGraph\State;

use LangGraph\Channels\BaseChannel;
use LangGraph\Channels\BinaryOperatorAggregate;
use LangGraph\Channels\LastValue;

/**
 * Declare a graph's state shape: a key per channel, and a rule per key.
 *
 * Port of `Annotation` / `AnnotationRoot` from
 * `langgraph-core/src/graph/annotation.ts`.
 *
 * The two declaration forms map onto the two ways a channel can behave:
 *
 * ```php
 * Annotation::root([
 *     'output' => Annotation::last(),                 // LastValue: last writer wins
 *     'items'  => Annotation::withReducer($add, fn () => []),  // BinaryOperatorAggregate
 * ]);
 * ```
 *
 * With no reducer, a channel is a *last-value* channel: two nodes writing it in
 * one superstep is an error, because there is no defensible way to pick a
 * winner. With a reducer, a channel is an *aggregate*: every write is folded in,
 * and two writers is normal. Choosing between them is choosing between "there
 * should be one of these" and "there should be many", and it is the single
 * most consequential decision in defining a graph — a last-value channel that
 * should have been an aggregate raises `InvalidUpdateError` at runtime, which
 * is the correct place to find out.
 */
final class AnnotationRoot
{
    public string $lcGraphName = 'AnnotationRoot';

    /**
     * @param array<string, BaseChannel> $spec The channels this schema declares.
     */
    public function __construct(
        public readonly array $spec = [],
    ) {
    }

    /** Structural test, so a serialised schema is still recognised. */
    public static function isInstance(mixed $value): bool
    {
        return $value instanceof self
            || (is_object($value)
                && property_exists($value, 'lcGraphName')
                && $value->lcGraphName === 'AnnotationRoot');
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->spec);
    }
}
