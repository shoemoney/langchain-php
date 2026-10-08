<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\StructuredQuery;

use LangChain\OutputParsers\JsonOutputParser;
use LangChain\Runnables\RunnableLambda;
use LangChain\Runnables\RunnableSequence;
use LangChain\Schema\Document;
use LangChain\StructuredQuery\BasicTranslator;
use LangChain\StructuredQuery\Comparison;
use LangChain\StructuredQuery\FilterDirective;
use LangChain\StructuredQuery\FunctionalTranslator;
use LangChain\StructuredQuery\Operation;
use LangChain\StructuredQuery\StructuredQuery;
use LangChain\Utils\Testing\FakeListChatModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The self-query shape from the upstream docs: an LLM emits a structured
 * query as JSON, the IR is built from it, and a translator turns it into a
 * filter. The retriever half is not ported (vector stores are not), so the
 * filter is applied to an in-memory document list.
 */
#[CoversClass(FunctionalTranslator::class)]
#[CoversClass(BasicTranslator::class)]
final class SelfQueryEndToEndTest extends TestCase
{
    private const LLM_ANSWER = <<<'JSON'
        ```json
        {
          "query": "aliens",
          "filter": {"operator": "and", "args": [
            {"comparator": "gt", "attribute": "rating", "value": "8.5"},
            {"operator": "or", "args": [
              {"comparator": "eq", "attribute": "genre", "value": "science fiction"},
              {"comparator": "gte", "attribute": "year", "value": 2000}
            ]}
          ]}
        }
        ```
        JSON;

    /** @param array<string, mixed> $node */
    private static function toDirective(array $node): FilterDirective
    {
        if (isset($node['operator'])) {
            return new Operation($node['operator'], array_map(self::toDirective(...), $node['args']));
        }

        return new Comparison($node['comparator'], $node['attribute'], $node['value']);
    }

    /** @return list<Document> */
    private static function movies(): array
    {
        return [
            new Document('Alien', ['rating' => 9.0, 'genre' => 'science fiction', 'year' => 1979]),
            new Document('Arrival', ['rating' => 8.6, 'genre' => 'drama', 'year' => 2016]),
            new Document('Plan 9', ['rating' => 2.1, 'genre' => 'science fiction', 'year' => 1959]),
            new Document('Heat', ['rating' => 8.3, 'genre' => 'crime', 'year' => 1995]),
            new Document('Untitled', ['genre' => 'science fiction']),
        ];
    }

    private static function chain(object $translator): RunnableSequence
    {
        $model = new FakeListChatModel(['responses' => [self::LLM_ANSWER]]);

        return RunnableSequence::from([
            $model,
            new JsonOutputParser(),
            new RunnableLambda(static fn (array $json): StructuredQuery => new StructuredQuery(
                $json['query'],
                self::toDirective($json['filter'])
            )),
            new RunnableLambda(static fn (StructuredQuery $q): mixed => $q->accept($translator)),
        ]);
    }

    public function testChainBuildsAFunctionalFilterThatSelectsDocuments(): void
    {
        $out = self::chain(new FunctionalTranslator())->invoke('movies about aliens rated above 8.5, scifi or after 2000');

        self::assertArrayHasKey('filter', $out);
        $titles = array_map(
            static fn (Document $d): string => $d->pageContent,
            array_values(array_filter(self::movies(), $out['filter']))
        );
        // "8.5" arrives as a string and is cast to a number; Heat fails the rating, Plan 9 too.
        self::assertSame(['Alien', 'Arrival'], $titles);
    }

    public function testChainBuildsAMongoStyleFilterForTheSameQuery(): void
    {
        $out = self::chain(new BasicTranslator())->invoke('same question');

        self::assertSame([
            'filter' => ['$and' => [
                ['rating' => ['$gt' => 8.5]],
                ['$or' => [
                    ['genre' => ['$eq' => 'science fiction']],
                    ['year' => ['$gte' => 2000]],
                ]],
            ]],
        ], $out);
    }

    public function testMergingTheGeneratedFilterWithADefaultFilter(): void
    {
        $translator = new FunctionalTranslator();
        $generated = self::chain($translator)->invoke('q')['filter'];
        $default = $translator->visitComparison(new Comparison('eq', 'genre', 'science fiction'));
        $merged = $translator->mergeFilters($default, $generated, 'and');

        $titles = array_map(
            static fn (Document $d): string => $d->pageContent,
            array_values(array_filter(self::movies(), $merged))
        );
        self::assertSame(['Alien'], $titles);
    }
}
