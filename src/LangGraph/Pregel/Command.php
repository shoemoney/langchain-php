<?php

declare(strict_types=1);

namespace LangGraph\Pregel;

/**
 * A node's return value when it wants to *steer* the graph, not just update it.
 *
 * Port of `Command` from `langgraph-core/src/constants.ts`.
 *
 * Where a plain `['items' => [...]]` return updates state and lets the declared
 * edges decide where to go next, a `Command` can do four things at once:
 *
 *  - `update`  — write state, exactly as if the node had returned that array;
 *  - `goto`    — route to a node, a list of nodes, or {@see Send} packets with
 *                their own inputs (dynamic fan-out);
 *  - `resume`  — supply the value a pending `interrupt()` will return;
 *  - `graph`   — address the command at a parent graph instead of this one.
 *
 * The constructor normalises `goto` to a *list*, exactly as the TS does, so the
 * rest of the engine only ever handles one shape.
 */
final class Command
{
    public const LG_NAME = 'Command';

    /** Address the command at the closest parent graph. */
    public const PARENT = '__parent__';

    /**
     * @param string|list<string|Send>|null $graph  Target graph, or {@see self::PARENT}.
     * @param array<string, mixed>|list<array{0: string, 1: mixed}>|mixed $update State write.
     * @param mixed                        $resume Value to resume an interrupt with.
     * @param list<string|Send>|string|Send|null $goto Where to go next.
     */
    public function __construct(
        public readonly mixed $graph = null,
        public readonly mixed $update = null,
        public readonly mixed $resume = null,
        public readonly mixed $goto = null,
    ) {
    }

    /**
     * The update as `[channel, value]` pairs.
     *
     * Port of `_updateAsTuples`. A map is the normal form; an explicit list of
     * pairs passes through; anything else — including `null` — is routed to the
     * single `__root__` channel that a whole-state graph writes to.
     *
     * @return list<array{0: string, 1: mixed}>
     */
    public function updateAsTuples(): array
    {
        $update = $this->update;

        if ($update === null || $update === false || $update === '') {
            return [];
        }

        if (is_array($update) && !array_is_list($update)) {
            $out = [];
            foreach ($update as $key => $value) {
                $out[] = [(string) $key, $value];
            }

            return $out;
        }

        if (is_array($update) && array_is_list($update)) {
            $allPairs = $update !== [];
            foreach ($update as $item) {
                if (!is_array($item) || !array_is_list($item) || count($item) !== 2 || !is_string($item[0])) {
                    $allPairs = false;
                    break;
                }
            }
            if ($allPairs) {
                /** @var list<array{0: string, 1: mixed}> $update */
                return $update;
            }
        }

        return [['__root__', $update]];
    }

    /**
     * `goto` normalised to a list.
     *
     * The TS normalises in the constructor; PHP properties are readonly, so the
     * normalisation happens on read instead. Callers always go through here, so
     * the engine sees one shape.
     *
     * @return list<string|Send>
     */
    public function gotoList(): array
    {
        if ($this->goto === null || $this->goto === []) {
            return [];
        }

        if (is_string($this->goto)) {
            return [$this->goto];
        }

        if (Send::isSendInterface($this->goto)) {
            $send = Send::fromMixed($this->goto);

            return $send === null ? [] : [$send];
        }

        if (is_array($this->goto)) {
            $out = [];
            foreach ($this->goto as $item) {
                if (is_string($item)) {
                    $out[] = $item;
                } elseif (($send = Send::fromMixed($item)) !== null) {
                    $out[] = $send;
                }
            }

            return $out;
        }

        return [];
    }

    /**
     * The plain-object form, for serialisation and for the conformance tests
     * that compare a `Command` against a JSON literal.
     *
     * @return array{lg_name: string, update: mixed, resume: mixed, goto: mixed}
     */
    public function toArray(): array
    {
        $goto = [];
        foreach ($this->gotoList() as $target) {
            $goto[] = $target instanceof Send ? $target->toArray() : $target;
        }

        return [
            'lg_name' => self::LG_NAME,
            'update' => $this->update,
            'resume' => $this->resume,
            'goto' => $goto,
        ];
    }

    /** Port of `isCommand`. Accepts the instance and its serialised form. */
    public static function isCommand(mixed $x): bool
    {
        if ($x instanceof self) {
            return true;
        }

        return is_array($x) && ($x['lg_name'] ?? null) === self::LG_NAME;
    }

    /**
     * Rebuild a `Command` from its serialised form.
     *
     * Needed because a `Command` can travel through a checkpoint and come back
     * as an array.
     */
    public static function fromMixed(mixed $x): ?self
    {
        if ($x instanceof self) {
            return $x;
        }

        if (is_array($x) && ($x['lg_name'] ?? null) === self::LG_NAME) {
            return new self(
                graph: $x['graph'] ?? null,
                update: $x['update'] ?? null,
                resume: $x['resume'] ?? null,
                goto: $x['goto'] ?? null,
            );
        }

        return null;
    }
}
