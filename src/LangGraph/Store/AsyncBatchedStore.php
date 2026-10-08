<?php

declare(strict_types=1);

namespace LangGraph\Store;

/**
 * Wraps a store so the engine can hand the same object to every task.
 *
 * Port of `AsyncBatchedStore` from `store/batch.ts`.
 *
 * Upstream collects the calls that several concurrent tasks make within one
 * event-loop tick and forwards them to the wrapped store as a single `batch`.
 * This port runs tasks one after another, so there is never a second call to
 * coalesce with: each call is forwarded immediately as a one-operation batch.
 * That is a known non-exact behaviour (the results are identical, only the
 * number of `batch` calls on the wrapped store differs).
 *
 * Wrapping is idempotent: wrapping an `AsyncBatchedStore` wraps the store it
 * already wraps, never a wrapper of a wrapper.
 */
final class AsyncBatchedStore extends BaseStore
{
    public readonly string $lgName;

    private readonly BaseStore $store;

    private bool $running = false;

    public function __construct(BaseStore $store)
    {
        $this->lgName = 'AsyncBatchedStore';
        $this->store = $store instanceof self ? $store->store : $store;
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    /** The store this one forwards to. */
    public function unwrap(): BaseStore
    {
        return $this->store;
    }

    /**
     * Not supported: operations are forwarded one by one to the wrapped store.
     *
     * @throws \LogicException Always.
     */
    public function batch(array $operations): array
    {
        throw new \LogicException(
            'The `batch` method is not implemented on `AsyncBatchedStore`.'
            . "\n Instead, it calls the `batch` method on the wrapped store."
            . "\n If you are seeing this error, something is wrong."
        );
    }

    public function get(array $namespace, string $key): ?Item
    {
        return $this->store->batch([new GetOperation($namespace, $key)])[0];
    }

    public function search(array $namespacePrefix, array $options = []): array
    {
        return $this->store->search($namespacePrefix, $options);
    }

    public function put(array $namespace, string $key, array $value, false|array|null $index = null): void
    {
        $this->store->batch([new PutOperation($namespace, $key, $value, $index)]);
    }

    public function delete(array $namespace, string $key): void
    {
        $this->store->batch([new PutOperation($namespace, $key, null)]);
    }

    public function start(): void
    {
        $this->running = true;
    }

    public function stop(): void
    {
        $this->running = false;
    }
}
