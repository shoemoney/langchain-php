<?php

declare(strict_types=1);

namespace LangChain\Runnables;

/**
 * A linear chain of runnables: `a | b | c`.
 *
 * Port of `RunnableSequence` from `@langchain_core/runnables`.
 *
 * `invoke` threads each step's output into the next. The step names double as
 * trace labels, so a chain reports as `RunnableSequence > prompt > model` in a
 * trace rather than as an anonymous blob — which is most of what makes LCEL
 * debuggable.
 */
class RunnableSequence extends Runnable
{
    /** @var list<RunnableInterface> */
    public array $steps;

    /** @var list<string> Explicit step labels, when the caller supplied them. */
    public array $names;

    /**
     * @param list<RunnableInterface> $steps
     * @param list<string>            $names
     */
    public function __construct(array $steps = [], array $names = [])
    {
        $this->steps = array_values($steps);
        $this->names = array_values($names);
    }

    /**
     * Build a sequence from runnable-likes: a RunnableInterface, a callable, or
     * a 2-tuple of `[name, runnableLike]`.
     *
     * @param list<mixed> $steps
     */
    public static function from(array $steps): self
    {
        $runnables = [];
        $names = [];
        foreach ($steps as $step) {
            if (is_array($step) && count($step) === 2 && is_string($step[0])) {
                $names[] = $step[0];
                $runnables[] = coerceToRunnable($step[1]);
                continue;
            }
            $r = coerceToRunnable($step);
            $names[] = $r->getName();
            $runnables[] = $r;
        }

        return new self($runnables, $names);
    }

    public function getName(): string
    {
        return 'RunnableSequence';
    }

    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        foreach ($this->steps as $step) {
            $input = $step->invoke($input, $config);
        }

        return $input;
    }

    /**
     * Stream from the first step that can stream, then feed the rest eagerly.
     *
     * This mirrors the TS behaviour: only the *first* step streams, because
     * once its output is a stream there is no way to synchronise a downstream
     * step that expects a complete value.
     */
    public function stream(mixed $input, ?RunnableConfig $config = null): \Generator
    {
        $steps = $this->steps;
        if ($steps === []) {
            return;
        }

        $first = array_shift($steps);
        $lastOutput = null;
        $anyOutput = false;

        foreach ($first->stream($input, $config) as $pair) {
            [$channel, $chunk] = $pair;
            $anyOutput = true;
            if ($channel === self::CHANNEL_DEFAULT) {
                $lastOutput = $chunk;
            }
            yield $pair;
        }

        if (!$anyOutput) {
            $lastOutput = $first->invoke($input, $config);
        }

        foreach ($steps as $step) {
            $lastOutput = $step->invoke($lastOutput, $config);
        }

        if ($lastOutput !== null) {
            yield [self::CHANNEL_DEFAULT, $lastOutput];
        }
    }

    public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array
    {
        $out = [];
        foreach ($inputs as $i => $input) {
            $out[$i] = $this->invoke($input, $config);
        }

        return $out;
    }

    public function pipe(RunnableInterface $next): RunnableSequence
    {
        $seq = new self(
            array_merge($this->steps, [$next]),
            array_merge($this->names, [$next->getName()])
        );

        return $seq;
    }
}
