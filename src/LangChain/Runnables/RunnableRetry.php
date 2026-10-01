<?php

declare(strict_types=1);

namespace LangChain\Runnables;

/**
 * Retries a bound runnable on failure.
 *
 * Port of `RunnableRetry` (`libs/langchain-core/src/runnables/base.ts`), reached through
 * {@see Runnable::withRetry()}. Upstream's class `extends RunnableBinding`, which is what this one does.
 *
 * Read from upstream rather than assumed:
 *   - `maxAttemptNumber` defaults to **3**;
 *   - EVERY failed attempt calls the `onFailedAttempt` handler with the error, and the error from the
 *     final attempt propagates to the caller INSTEAD of going to the handler — so an N-attempt run that
 *     fails entirely reports N-1 failures to the handler and throws the Nth;
 *   - attempts after the first are tagged `retry:attempt:<n>` on the run, so a trace shows the retry
 *     rather than three indistinguishable runs.
 *
 * @extends RunnableBinding
 */
final class RunnableRetry extends RunnableBinding
{
    /** Upstream's `protected maxAttemptNumber = 3`. */
    public const DEFAULT_MAX_ATTEMPTS = 3;

    /** @var (callable(\Throwable): void)|null */
    private $onFailedAttempt;

    /**
     * @param array{maxAttemptNumber?: int, stopAfterAttempt?: int, onFailedAttempt?: callable} $fields
     */
    public function __construct(
        RunnableInterface $bound,
        array $kwargs = [],
        ?array $config = null,
        private int $maxAttemptNumber = self::DEFAULT_MAX_ATTEMPTS,
        ?callable $onFailedAttempt = null,
    ) {
        parent::__construct($bound, $kwargs, $config);
        $this->onFailedAttempt = $onFailedAttempt;
    }

    public function getName(): string
    {
        return 'RunnableRetry';
    }

    public function withAttempts(int $attempts, ?callable $onFailedAttempt = null): self
    {
        $clone = clone $this;
        $clone->maxAttemptNumber = max(1, $attempts);
        if ($onFailedAttempt !== null) {
            $clone->onFailedAttempt = $onFailedAttempt;
        }

        return $clone;
    }

    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        $attempt = 0;
        $last = null;

        while ($attempt < $this->maxAttemptNumber) {
            ++$attempt;

            try {
                return $this->bound->invoke($input, $this->configForAttempt($attempt, $config));
            } catch (\Throwable $e) {
                $last = $e;
                if ($this->onFailedAttempt !== null) {
                    ($this->onFailedAttempt)($e);
                }
            }
        }

        // Every attempt failed. Upstream rethrows the last error rather than wrapping it, so a caller
        // catching this exception sees the provider's own failure.
        throw $last ?? new \RuntimeException('RunnableRetry exhausted its attempts with no error');
    }

    public function stream(mixed $input, ?RunnableConfig $config = null): \Generator
    {
        // Streaming is NOT retried here. Retrying a stream means deciding whether a partial yield is
        // discardable, which is a design question upstream answers in its own async implementation;
        // silently buffering to make it retryable would change the contract of `stream()`.
        yield from $this->bound->stream($input, $config);
    }

    /**
     * Upstream's `_patchConfigForRetry` adds `retry:attempt:<n>` for attempts after the first.
     */
    private function configForAttempt(int $attempt, ?RunnableConfig $config): ?RunnableConfig
    {
        if ($attempt <= 1) {
            return $config;
        }

        return RunnableConfig::mergeConfigs(
            $config ?? new RunnableConfig(),
            new RunnableConfig(tags: ['retry:attempt:' . $attempt]),
        );
    }
}
