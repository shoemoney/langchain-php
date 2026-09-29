<?php

declare(strict_types=1);

namespace LangChain\TextSplitters;

use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableConfig;
use LangChain\Schema\Document;

/**
 * A document transformation that maps a list of documents to a list of documents.
 *
 * Port of `BaseDocumentTransformer` from `@langchain_core/documents/transformers`.
 *
 * The input and output lists are not required to be the same length — that
 * freedom is the whole point, and a text splitter that turns one document into
 * forty is the canonical example. `invoke()` is wired straight to
 * {@see self::transformDocuments()}, which is what makes a transformer usable
 * anywhere a `Runnable` is, and therefore pipeable.
 */
abstract class BaseDocumentTransformer extends Runnable
{
    /**
     * Transform a list of documents.
     *
     * @param list<Document>                    $documents A sequence of documents to be transformed.
     * @param TextSplitterChunkHeaderOptions|null $options   Extra per-chunk decoration.
     * @return list<Document> A list of transformed documents.
     */
    abstract public function transformDocuments(
        array $documents,
        ?TextSplitterChunkHeaderOptions $options = null
    ): array;

    /**
     * Method to invoke the document transformation.
     *
     * This calls {@see self::transformDocuments()} with the provided input, so a
     * transformer can be dropped into an LCEL chain in the transformer slot.
     *
     * @param list<Document> $input The input documents to be transformed.
     * @return list<Document>
     */
    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        return $this->transformDocuments($input);
    }
}
