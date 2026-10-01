<?php

declare(strict_types=1);

namespace LangChain\Runnables;

/**
 * Try a runnable, then each fallback in turn, on failure.
 *
 * Port of `RunnableWithFallbacks` from `@langchain_core/runnables`.
 *
 * Used for provider resilience: call the preferred model, and when it rate-limits
 * or times out, retry against a cheaper or different one. If every option fails,
 * the *last* exception propagates — losing the original cause would make a
 * fallback chain unactionable in production.
 */
class RunnableWithFallbacks extends Runnable
{
    /**
     * @param list<RunnableInterface> $fallbacks
     */
    public function __construct(
        public RunnableInterface $primary,
        public array $fallbacks = [],
    ) {
    }

    public function getName(): string
    {
        return 'RunnableWithFallbacks';
    }

    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        $chain = array_merge([$this->primary], $this->fallbacks);
        $last = null;

        foreach ($chain as $runnable) {
            try {
                return $runnable->invoke($input, $config);
            } catch (\Throwable $e) {
                $last = $e;
            }
        }

        throw $last ?? new \RuntimeException('Fallback chain was empty.');
    }

    public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array
    {
        return $this->batchEach($inputs, $config, $options);
    }
}
