<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Sdk\Support;

use LangGraph\Sdk\ThreadsClient;

/**
 * `fetch()` is protected, as upstream; the JS tests reach it with `(client.threads as any).fetch`.
 * PHP's equivalent is a subclass that re-exposes it.
 */
final class ExposedThreadsClient extends ThreadsClient
{
    /**
     * @param array<string, mixed> $options
     */
    public function rawFetch(string $path, array $options = []): mixed
    {
        return $this->fetch($path, $options);
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function rawPrepare(string $path, array $options = []): array
    {
        return $this->prepareFetchOptions($path, $options);
    }
}
