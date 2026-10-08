<?php

declare(strict_types=1);

namespace LangChain\DocumentLoaders;

use LangChain\Schema\Document;

/**
 * Anything that can produce documents.
 *
 * Port of the `DocumentLoader` interface from `@langchain/core/document_loaders/base`.
 */
interface DocumentLoader
{
    /**
     * @return list<Document>
     */
    public function load(): array;
}
