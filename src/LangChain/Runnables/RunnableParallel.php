<?php

declare(strict_types=1);

namespace LangChain\Runnables;

/**
 * Run several runnables over the same input, in parallel, keyed by name.
 *
 * Port of `RunnableParallel` (and the `map()` builder) from
 * `@langchain_core/runnables`.
 *
 * The input is a keyed array; each key is one branch, and the output is a keyed
 * array of that branch's result. This is the fan-out primitive behind the
 * classic `{"context": retriever, "question": passthrough}` RAG pattern.
 */
class RunnableParallel extends Runnable
{
    /** @var array<string, RunnableInterface> */
    public array $branches;

    /**
     * @param array<string, RunnableInterface> $branches
     */
    public function __construct(array $branches = [])
    {
        $this->branches = $branches;
    }

    /**
     * @param array<string, mixed> $branches
     */
    public static function from(array $branches): self
    {
        $runnables = [];
        foreach ($branches as $key => $branch) {
            $runnables[$key] = coerceToRunnable($branch, (string) $key);
        }

        return new self($runnables);
    }

    public function getName(): string
    {
        return 'RunnableParallel';
    }

    /**
     * Add a branch, for the fluent `->map()->add(...)` style.
     */
    public function add(string $key, mixed $branch): self
    {
        $this->branches[$key] = coerceToRunnable($branch, $key);

        return $this;
    }

    /**
     * Every branch receives the **whole** input, not `input[$key]`.
     *
     * This is the TypeScript `RunnableMap` semantics, and it is deliberate: it
     * is what lets a retriever and a passthrough coexist in the same map, each
     * picking the parts of the input it cares about. Narrowing to `input[$key]`
     * here would make the canonical `{context, question}` RAG map impossible.
     */
    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        if (!is_array($input)) {
            throw new \InvalidArgumentException(
                'RunnableParallel expects a keyed array input, got ' . get_debug_type($input) . '.'
            );
        }

        $out = [];
        foreach ($this->branches as $key => $branch) {
            $out[$key] = $branch->invoke($input, $config?->forChild("map:key:{$key}"));
        }

        return $out;
    }

    public function stream(mixed $input, ?RunnableConfig $config = null): \Generator
    {
        foreach ($this->invoke($input, $config) as $key => $value) {
            yield [$key, $value];
        }
    }

    public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array
    {
        return array_map(
            fn (mixed $input): mixed => $this->invoke($input, $config),
            array_values($inputs)
        );
    }

    public function pipe(RunnableInterface $next): RunnableSequence
    {
        return new RunnableSequence([$this, $next]);
    }
}
