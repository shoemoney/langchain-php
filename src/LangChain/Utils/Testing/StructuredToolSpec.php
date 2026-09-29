<?php

declare(strict_types=1);

namespace LangChain\Utils\Testing;

use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;

/**
 * The minimum a tool needs to be bindable to a model.
 *
 * Port of the `ToolSpec` interface from `@langchain/core/utils/testing`.
 *
 * The point of this shape is that `bindTools()` does not require a real tool.
 * A test asserting how a provider renders a tool spec needs a name, a
 * description, and a schema — not a working closure — and forcing one would mean
 * writing a throwaway tool class for every formatting assertion.
 */
final class StructuredToolSpec
{
    public function __construct(
        public string $name,
        public Schema $schema,
        public ?string $description = null,
    ) {
    }

    /**
     * Describe a real tool as a spec.
     */
    public static function fromTool(StructuredTool $tool): self
    {
        return new self($tool->name, $tool->schema, $tool->description);
    }
}
