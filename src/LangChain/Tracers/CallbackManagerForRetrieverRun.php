<?php

declare(strict_types=1);

namespace LangChain\Tracers;

use LangChain\Schema\Document;

/**
 * The per-run view for a retriever.
 *
 * Port of `CallbackManagerForRetrieverRun` from `@langchain/core/callbacks/manager`.
 */
final class CallbackManagerForRetrieverRun extends BaseRunManager
{
    /**
     * @param list<Document> $documents
     */
    public function handleRetrieverEnd(array $documents): void
    {
        foreach ($this->handlers as $handler) {
            if ($handler->ignoreRetriever) {
                continue;
            }
            $this->dispatch($handler, 'handleRetrieverEnd', [$documents, $this->runId, $this->parentRunId, $this->tags]);
        }
    }

    public function handleRetrieverError(\Throwable $error): void
    {
        foreach ($this->handlers as $handler) {
            if ($handler->ignoreRetriever) {
                continue;
            }
            $this->dispatch($handler, 'handleRetrieverError', [$error, $this->runId, $this->parentRunId, $this->tags]);
        }
    }
}
