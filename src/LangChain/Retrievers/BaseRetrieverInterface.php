<?php

declare(strict_types=1);

namespace LangChain\Retrievers;

use LangChain\Runnables\RunnableConfig;
use LangChain\Schema\Document;

/**
 * A runnable that maps a query string to the documents relevant to it.
 *
 * Port of `BaseRetrieverInterface` from `@langchain/core/retrievers`
 * (`RunnableInterface<string, DocumentInterface[]>`). It does not extend
 * `RunnableInterface`: {@see BaseRetriever} already reaches that through
 * `Runnable`, and inheriting `CHANNEL_DEFAULT` down two paths is a class-load
 * fatal in PHP. It declares the one runnable method retrieval callers use.
 */
interface BaseRetrieverInterface
{
    /**
     * @return list<Document>
     */
    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed;
}
