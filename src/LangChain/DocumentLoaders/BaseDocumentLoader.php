<?php

declare(strict_types=1);

namespace LangChain\DocumentLoaders;

use LangChain\Schema\Document;

/**
 * Base class for document loaders; `load()` is left to the subclass.
 *
 * Port of `BaseDocumentLoader` from `@langchain/core/document_loaders/base`.
 * Upstream's core class no longer carries `loadAndSplit()`, and neither does
 * this one.
 */
abstract class BaseDocumentLoader implements DocumentLoader
{
    /**
     * Load the documents.
     *
     * @return list<Document>
     */
    abstract public function load(): array;
}
