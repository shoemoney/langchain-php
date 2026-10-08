<?php

declare(strict_types=1);

namespace LangChain\Retrievers;

use LangChain\Schema\Document;
use LangChain\Tracers\CallbackManager;

/**
 * Base class for document compressors.
 *
 * Port of `BaseDocumentCompressor` from `@langchain/core/retrievers/document_compressors`.
 */
abstract class BaseDocumentCompressor
{
    /**
     * Compress the documents against the query.
     *
     * @param list<Document>                 $documents
     * @param array<array-key, mixed>|CallbackManager|null $callbacks
     *
     * @return list<Document>
     */
    abstract public function compressDocuments(array $documents, string $query, array|CallbackManager|null $callbacks = null): array;

    /**
     * Whether `$x` quacks like a compressor (has `compressDocuments`).
     */
    public static function isBaseDocumentCompressor(mixed $x): bool
    {
        return is_object($x) && method_exists($x, 'compressDocuments');
    }
}
