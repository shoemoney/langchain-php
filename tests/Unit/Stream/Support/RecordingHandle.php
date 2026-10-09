<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Stream\Support;

use LangGraph\Stream\StreamHandle;

/**
 * The upstream tests' `MockSubgraphStream`: remembers what the mux settled it with.
 */
final class RecordingHandle implements StreamHandle
{
    /** @var list<mixed> */
    public array $resolved = [];

    /** @var list<mixed> */
    public array $rejected = [];

    /**
     * @param list<string> $path
     */
    public function __construct(public readonly array $path = [])
    {
    }

    public function resolveValues(mixed $values): void
    {
        $this->resolved[] = $values;
    }

    public function rejectValues(mixed $error): void
    {
        $this->rejected[] = $error;
    }
}
