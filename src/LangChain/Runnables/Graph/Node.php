<?php

declare(strict_types=1);

namespace LangChain\Runnables\Graph;

use LangChain\Runnables\RunnableInterface;

/**
 * A node of a drawable {@see Graph}.
 *
 * Port of the `Node` type from `langchain-core/src/runnables/types.ts`.
 */
final class Node
{
    /**
     * @param array<string, mixed>|null $metadata
     */
    public function __construct(
        public string $id,
        public RunnableInterface|RunnableIOSchema $data,
        public string $name,
        public ?array $metadata = null,
    ) {
    }
}
