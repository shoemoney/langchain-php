<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Retrievers;

use LangChain\Retrievers\BaseRetriever;
use LangChain\Schema\Document;
use LangChain\Tracers\CallbackManagerForRetrieverRun;

/**
 * Test double ported from `FakeRetriever` in `@langchain/core/utils/testing`.
 *
 * Also records what `getRelevantDocuments` was given, and can be told to fail.
 */
final class FakeRetriever extends BaseRetriever
{
    /** @var list<Document> */
    public array $output;

    public ?\Throwable $failWith = null;

    public ?CallbackManagerForRetrieverRun $lastRunManager = null;

    /**
     * @param array{output?: list<Document>}&array<string, mixed> $fields
     */
    public function __construct(array $fields = [])
    {
        parent::__construct($fields);
        $this->output = $fields['output'] ?? [new Document('foo'), new Document('bar')];
    }

    /** @return list<string> */
    public static function lcNamespace(): array
    {
        return ['test', 'fake'];
    }

    protected function getRelevantDocuments(string $query, ?CallbackManagerForRetrieverRun $runManager = null): array
    {
        $this->lastRunManager = $runManager;
        if ($this->failWith !== null) {
            throw $this->failWith;
        }

        return $this->output;
    }
}
