<?php

declare(strict_types=1);

namespace LangChain\Runnables;

/**
 * Run the first branch whose condition matches the input.
 *
 * Port of `RunnableBranch` from `@langchain_core/runnables`, including the
 * `branch()/default()` builder used for conditional routing:
 *
 * ```php
 * RunnableBranch::when(fn ($x) => $x > 10, $big, $small)->default($fallback);
 * ```
 *
 * Conditions are evaluated in declaration order and the *first* match wins, so
 * order is load-bearing — this is why the default branch goes last.
 */
class RunnableBranch extends Runnable
{
    /** @var list<array{0: callable, 1: RunnableInterface}> */
    private array $branches;

    private ?RunnableInterface $default = null;

    /**
     * @param list<array{0: callable, 1: RunnableInterface}> $branches
     */
    public function __construct(array $branches = [], ?RunnableInterface $default = null)
    {
        $this->branches = $branches;
        $this->default = $default;
    }

    /**
     * Add a conditional branch.
     */
    public function when(callable $condition, mixed $runnable): self
    {
        $this->branches[] = [$condition, coerceToRunnable($runnable)];

        return $this;
    }

    /**
     * Set the branch used when no condition matches.
     */
    public function default(mixed $runnable): self
    {
        $this->default = coerceToRunnable($runnable);

        return $this;
    }

    public function getName(): string
    {
        return 'RunnableBranch';
    }

    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        foreach ($this->branches as [$condition, $runnable]) {
            if ($condition($input)) {
                return $runnable->invoke($input, $config);
            }
        }

        if ($this->default !== null) {
            return $this->default->invoke($input, $config);
        }

        throw new \RuntimeException(
            'No branch matched and no default branch was set on RunnableBranch.'
        );
    }

    public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array
    {
        return array_map(
            fn (mixed $input): mixed => $this->invoke($input, $config),
            array_values($inputs)
        );
    }

    /**
     * @param callable $condition
     * @param mixed    $runnable
     */
    public static function branch(callable $condition, mixed $runnable): self
    {
        return (new self())->when($condition, $runnable);
    }
}
