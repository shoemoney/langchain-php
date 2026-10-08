<?php

declare(strict_types=1);

namespace LangChain\Runnables\Graph;

/**
 * The input or output "schema" node of a drawable {@see Graph}.
 *
 * Port of `RunnableIOSchema` from `langchain-core/src/runnables/types.ts`. `schema` is a JSON Schema
 * array; the empty array stands for upstream's `z.any()`.
 */
final class RunnableIOSchema
{
    /**
     * @param array<string, mixed> $schema
     */
    public function __construct(
        public ?string $name = null,
        public array $schema = [],
    ) {
    }
}
