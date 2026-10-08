<?php

declare(strict_types=1);

namespace LangChain\VectorStores;

use LangChain\Retrievers\BaseRetrieverInterface;
use LangChain\Schema\Document;

/**
 * A retriever backed by a vector store, which can also write to it.
 *
 * Port of `VectorStoreRetrieverInterface` from `@langchain/core/vectorstores`.
 */
interface VectorStoreRetrieverInterface extends BaseRetrieverInterface
{
    /**
     * @param list<Document>       $documents
     * @param array<string, mixed> $options
     *
     * @return list<string>|null
     */
    public function addDocuments(array $documents, array $options = []): ?array;
}
