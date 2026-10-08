<?php

declare(strict_types=1);

namespace LangChain\Retrievers;

use LangChain\Runnables\RunnableInterface;

/**
 * A runnable that maps a query string to the documents relevant to it.
 *
 * Port of `BaseRetrieverInterface` from `@langchain/core/retrievers`
 * (`RunnableInterface<string, DocumentInterface[]>`).
 */
interface BaseRetrieverInterface extends RunnableInterface
{
}
