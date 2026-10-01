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
            // See conditionMatches(): upstream invokes each condition WITH the
            // caller's config (branch.ts:152-161).
            if ($this->conditionMatches($condition, $input, $config)) {
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

    /**
     * Evaluate one branch condition WITH the caller's config.
     *
     * Upstream coerces each condition to a RunnableLike and invokes it as
     * `condition.invoke(input, patchConfig(config, {callbacks:
     * runManager?.getChild(`condition:${i + 1}`)}))` (branch.ts:152-161) — so a
     * condition sees the run config, and one that is itself a chain can emit
     * callbacks under its own child tag.
     *
     * This port stored conditions as plain callables and called them as
     * `$condition($input)`, so a condition could never observe the config: it
     * could not read tags, metadata or `configurable`, and could not take part
     * in tracing. That is the divergence this fixes.
     *
     * PHP conditions are callables rather than RunnableLikes, so the faithful
     * equivalent of `invoke(input, config)` is passing the config as the second
     * argument — but only to conditions that DECLARE it. A one-argument
     * condition is still called with one argument, exactly as before, so every
     * existing condition keeps working and none has to be rewritten. Arity comes
     * from reflection rather than a guess, and a variadic condition gets both.
     *
     * The child callback tags upstream also applies are deliberately NOT
     * reproduced: they belong to tracing, which this port does not yet thread
     * through branches. Recorded as a known non-exact behaviour rather than
     * half-built.
     *
     * @param callable(mixed, ?RunnableConfig=): mixed $condition
     */
    private function conditionMatches(callable $condition, mixed $input, ?RunnableConfig $config): bool
    {
        $reflection = $this->reflectCondition($condition);

        if ($reflection === null || $reflection->isVariadic() || $reflection->getNumberOfParameters() >= 2) {
            return (bool) $condition($input, $config);
        }

        return (bool) $condition($input);
    }

    private function reflectCondition(callable $condition): ?\ReflectionFunctionAbstract
    {
        if ($condition instanceof \Closure) {
            return new \ReflectionFunction($condition);
        }

        if (is_array($condition)) {
            return new \ReflectionMethod($condition[0], (string) $condition[1]);
        }

        if (is_string($condition) && str_contains($condition, '::')) {
            return new \ReflectionMethod($condition);
        }

        return new \ReflectionFunction($condition);
    }

    /**
     * Upstream `RunnableBranch` does not override `batch` at all — `branch.ts:67` declares only
     * `lc_name`, `lc_namespace`, `lc_serializable`, `default` and `branches`, so it inherits
     * `Runnable.batch()`, which honours `batchOptions.returnExceptions` (`base.ts:281`, `base.ts:3081`).
     *
     * This port previously carried a hand-rolled `array_map` over `invoke()`. Because
     * `array_map` aborts on the first Throwable, the third `$options` argument was accepted and then
     * silently discarded: `batch($inputs, $config, ['returnExceptions' => true])` threw where upstream
     * returns a list of results and errors. Delegating to the shared helper makes the behaviour identical
     * to every other composition and to upstream, with no change to the non-exception path — the helper
     * calls the same `$this->invoke($input, $config)` this override did.
     */
    public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array
    {
        return $this->batchEach($inputs, $config, $options);
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
