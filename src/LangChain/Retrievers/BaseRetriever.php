<?php

declare(strict_types=1);

namespace LangChain\Retrievers;

use LangChain\LanguageModels\BaseLangChain;
use LangChain\Runnables\RunnableConfig;
use LangChain\Schema\Document;
use LangChain\Tracers\CallbackManagerForRetrieverRun;
use LangChain\Tracers\Serialized;

/**
 * Abstract base for a document retrieval system: a string query in, documents out.
 *
 * Port of `BaseRetriever` from `@langchain/core/retrievers`. Subclasses
 * implement {@see self::getRelevantDocuments()} (upstream `_getRelevantDocuments`)
 * and a `lcNamespace()`. `invoke()` brackets that call with
 * `handleRetrieverStart` / `handleRetrieverEnd` / `handleRetrieverError`.
 *
 * `callbacks`, `tags`, `metadata` and `verbose` come from {@see BaseLangChain}.
 *
 * @extends BaseLangChain<string, list<Document>>
 */
abstract class BaseRetriever extends BaseLangChain implements BaseRetrieverInterface
{
    /**
     * Retrieve the documents relevant to a query.
     *
     * Upstream leaves this non-abstract (it throws) to avoid breaking existing
     * subclasses; the port keeps that shape.
     *
     * @return list<Document>
     */
    protected function getRelevantDocuments(string $query, ?CallbackManagerForRetrieverRun $runManager = null): array
    {
        throw new \RuntimeException('Not implemented!');
    }

    /**
     * @return list<Document>
     */
    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        $config ??= new RunnableConfig();

        $callbackManager = $this->callbackManagerFor($config);
        if ($callbackManager !== null) {
            $callbackManager->parentRunId = $config->runIdParent;
        }

        $query = (string) $input;
        $runManager = $callbackManager?->handleRetrieverStart(
            new Serialized(static::lcId(), $this->kwargs()),
            $query,
            $config->runId[0] ?? null,
            [],
            [],
            $config->runName,
        );

        try {
            $results = $this->getRelevantDocuments($query, $runManager);
            $runManager?->handleRetrieverEnd($results);

            return $results;
        } catch (\Throwable $error) {
            $runManager?->handleRetrieverError($error);

            throw $error;
        }
    }
}
