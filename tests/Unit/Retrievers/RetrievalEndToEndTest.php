<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Retrievers;

use LangChain\DocumentLoaders\BaseDocumentLoader;
use LangChain\ExampleSelectors\SemanticSimilarityExampleSelector;
use LangChain\Indexing\Index;
use LangChain\OutputParsers\StringOutputParser;
use LangChain\Prompts\ChatPromptTemplate;
use LangChain\Retrievers\BaseRetriever;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use LangChain\Runnables\RunnableParallel;
use LangChain\Runnables\RunnablePassthrough;
use LangChain\Schema\Document;
use LangChain\Tests\Unit\ExampleSelectors\KeywordEmbeddings;
use LangChain\Tests\Unit\Indexing\IdTrackingMemoryVectorStore;
use LangChain\Tests\Unit\Indexing\TestClock;
use LangChain\Tracers\CallbackHandler;
use LangChain\Utils\Testing\FakeListChatModel;
use LangChain\VectorStores\MemoryVectorStore;
use LangGraph\Pregel\Constants;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The retrieval stack running inside real chains and a real graph.
 */
#[CoversNothing]
final class RetrievalEndToEndTest extends TestCase
{
    /** @return list<Document> */
    private static function corpus(): array
    {
        return [
            new Document('sunny weather is happy weather', ['source' => 'weather.txt']),
            new Document('tall buildings are not short', ['source' => 'buildings.txt']),
            new Document('windy days feel calm indoors', ['source' => 'weather.txt']),
        ];
    }

    private static function indexedStore(): IdTrackingMemoryVectorStore
    {
        $store = new IdTrackingMemoryVectorStore(new KeywordEmbeddings());
        $loader = new class(self::corpus()) extends BaseDocumentLoader {
            /** @param list<Document> $docs */
            public function __construct(private array $docs)
            {
            }

            public function load(): array
            {
                return $this->docs;
            }
        };
        Index::index([
            'docsSource' => $loader,
            'recordManager' => (new TestClock())->manager(),
            'vectorStore' => $store,
            'options' => ['cleanup' => 'incremental', 'sourceIdKey' => 'source'],
        ]);

        return $store;
    }

    public function testARagChainRetrievesIndexedDocumentsIntoThePrompt(): void
    {
        $retriever = self::indexedStore()->asRetriever(['k' => 1]);
        $seenPrompt = null;

        $chain = RunnableParallel::from([
            'context' => $retriever->pipe(RunnableLambda::from(
                static fn (array $docs): string => implode("\n", array_map(static fn (Document $d): string => $d->pageContent, $docs)),
            )),
            'question' => new RunnablePassthrough(),
        ])
            ->pipe(ChatPromptTemplate::fromMessages([['system', 'Answer from this context:\n{context}'], ['human', '{question}']]))
            ->pipe(RunnableLambda::from(static function (mixed $promptValue) use (&$seenPrompt): mixed {
                $seenPrompt = $promptValue->toStringValue();

                return $promptValue;
            }))
            ->pipe(new FakeListChatModel(['responses' => ['tall means not short']]))
            ->pipe(new StringOutputParser());

        $answer = $chain->invoke('tall');

        $this->assertSame('tall means not short', $answer);
        $this->assertStringContainsString('tall buildings are not short', (string) $seenPrompt);
        $this->assertStringNotContainsString('sunny weather', (string) $seenPrompt);
    }

    public function testTheRetrieverRunIsReportedWithItsParentRunInsideAChain(): void
    {
        $retriever = self::indexedStore()->asRetriever(['k' => 2]);
        $events = [];
        $handler = new CallbackHandler([
            'handleRetrieverStart' => static function ($serialized, string $query, string $runId, ?string $parent) use (&$events): void {
                $events[] = ['retriever.start', $query, $parent];
            },
            'handleRetrieverEnd' => static function (array $documents) use (&$events): void {
                $events[] = ['retriever.end', count($documents)];
            },
        ]);
        $parentRun = '0190b3a2-0000-7000-8000-0000000000aa';

        $chain = $retriever->pipe(RunnableLambda::from(static fn (array $docs): int => count($docs)));
        $count = $chain->invoke('happy', new RunnableConfig(callbacks: [$handler], runId: [$parentRun]));

        $this->assertSame(2, $count);
        $this->assertSame([['retriever.start', 'happy', $parentRun], ['retriever.end', 2]], $events);
    }

    public function testAGraphNodeCanRetrieveAndTheAnswerFlowsThroughState(): void
    {
        $retriever = self::indexedStore()->asRetriever(['k' => 1]);

        $graph = (new StateGraph([
            'question' => Annotation::last(),
            'context' => Annotation::last(),
            'answer' => Annotation::last(),
        ]))
            ->addNode('retrieve', static fn (array $state): array => [
                'context' => array_map(
                    static fn (Document $d): string => $d->pageContent,
                    $retriever->invoke($state['question']),
                ),
            ])
            ->addNode('respond', static fn (array $state): array => [
                'answer' => 'from: ' . $state['context'][0],
            ])
            ->addEdge(Constants::START, 'retrieve')
            ->addEdge('retrieve', 'respond')
            ->addEdge('respond', Constants::END)
            ->compile();

        $result = $graph->invoke(['question' => 'windy']);

        $this->assertSame(['windy days feel calm indoors'], $result['context']);
        $this->assertSame('from: windy days feel calm indoors', $result['answer']);
    }

    public function testASemanticExampleSelectorFeedsAFewShotStylePrompt(): void
    {
        $examples = [
            ['input' => 'happy', 'output' => 'sad'],
            ['input' => 'tall', 'output' => 'short'],
            ['input' => 'sunny', 'output' => 'gloomy'],
        ];
        $selector = SemanticSimilarityExampleSelector::fromExamples(
            $examples,
            new KeywordEmbeddings(),
            MemoryVectorStore::class,
            ['k' => 1],
        );

        $chain = RunnableLambda::from(static fn (string $adjective): array => [
            'adjective' => $adjective,
            'shots' => implode("\n", array_map(
                static fn (array $e): string => "Input: {$e['input']}\nOutput: {$e['output']}",
                $selector->selectExamples(['adjective' => $adjective]),
            )),
        ])
            ->pipe(ChatPromptTemplate::fromMessages([['human', "Give the antonym.\n{shots}\nInput: {adjective}\nOutput:"]]))
            ->pipe(new FakeListChatModel(['responses' => ['calm']]))
            ->pipe(new StringOutputParser());

        $this->assertSame('calm', $chain->invoke('short'));
    }

    public function testAFailingRetrieverFailsTheChainAndStillReportsItsError(): void
    {
        $retriever = new FakeRetriever();
        $retriever->failWith = new \RuntimeException('index offline');
        $errors = [];
        $handler = new CallbackHandler([
            'handleRetrieverError' => static function (\Throwable $e) use (&$errors): void {
                $errors[] = $e->getMessage();
            },
        ]);

        try {
            $retriever->pipe(RunnableLambda::from(static fn (array $d): int => count($d)))
                ->invoke('q', new RunnableConfig(callbacks: [$handler]));
            $this->fail('the chain must fail when retrieval fails');
        } catch (\RuntimeException $e) {
            $this->assertSame('index offline', $e->getMessage());
        }

        $this->assertSame(['index offline'], $errors);
        $this->assertInstanceOf(BaseRetriever::class, $retriever);
    }
}
