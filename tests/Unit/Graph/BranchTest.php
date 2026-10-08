<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Graph;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Errors\InvalidUpdateError;
use LangGraph\Graph\Branch;
use LangGraph\Graph\Graph;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\RunnableBranchWriter;
use LangGraph\Pregel\Send;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Branch::class)]
#[CoversClass(RunnableBranchWriter::class)]
final class BranchTest extends TestCase
{
    public function testAPathMapListMapsEachNameToItself(): void
    {
        $branch = new Branch(static fn () => 'x', ['x', 'y']);

        $this->assertSame(['x' => 'x', 'y' => 'y'], $branch->ends);
    }

    public function testAPathMapRecordMapsAnswersToDestinations(): void
    {
        $branch = new Branch(static fn (int $n): string => $n > 0 ? 'pos' : 'neg', ['pos' => 'plus', 'neg' => 'minus']);

        $this->assertSame(['plus'], $branch->destinations(1));
        $this->assertSame(['minus'], $branch->destinations(-1));
    }

    public function testWithoutAPathMapTheAnswerIsTheDestination(): void
    {
        $branch = new Branch(static fn () => ['a', 'b']);

        $this->assertNull($branch->ends);
        $this->assertSame(['a', 'b'], $branch->destinations(null));
    }

    public function testAnAnswerMissingFromThePathMapThrows(): void
    {
        $branch = new Branch(static fn () => 'other', ['x']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Branch condition returned unknown or null destination');

        $branch->destinations(null);
    }

    public function testANullEntryInsideAListThrows(): void
    {
        $branch = new Branch(static fn () => ['a', null]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Branch condition returned unknown or null destination');

        $branch->destinations(null);
    }

    public function testDecliningToRouteYieldsNoDestinations(): void
    {
        foreach ([null, [], '', false] as $answer) {
            $this->assertSame([], (new Branch(static fn () => $answer))->destinations(null));
        }
    }

    public function testSendPacketsPassThroughThePathMapUntouched(): void
    {
        $send = new Send('work', ['x' => 1]);
        $branch = new Branch(static fn () => [$send], ['work']);

        $this->assertSame([$send], $branch->destinations(null));
    }

    public function testASendToEndIsRejected(): void
    {
        $branch = new Branch(static fn () => new Send(Constants::END, 1));

        $this->expectException(InvalidUpdateError::class);
        $this->expectExceptionMessage('Cannot send a packet to the END node');

        $branch->destinations(null);
    }

    public function testRunPassesTheDestinationsToTheWriterAndReturnsTheInput(): void
    {
        $seen = null;
        $runnable = (new Branch(static fn (string $s): string => 'to_' . $s))->run(
            static function (array $dests) use (&$seen): null {
                $seen = $dests;

                return null;
            },
        );

        $this->assertSame('in', $runnable->invoke('in', new RunnableConfig()));
        $this->assertSame(['to_in'], $seen);
    }

    public function testRunUsesTheReaderInPlaceOfTheNodeOutput(): void
    {
        $seen = null;
        $runnable = (new Branch(static fn (array $state): string => $state['next']))->run(
            static function (array $dests) use (&$seen): null {
                $seen = $dests;

                return null;
            },
            static fn (RunnableConfig $config): array => ['next' => 'from_reader'],
        );

        $runnable->invoke('node output', new RunnableConfig());
        $this->assertSame(['from_reader'], $seen);
    }

    public function testFromOptionsReadsPathAndPathMap(): void
    {
        $branch = Branch::fromOptions(['source' => 'a', 'path' => static fn () => 'k', 'pathMap' => ['k' => 'b']]);

        $this->assertSame(['b'], $branch->destinations(null));
    }

    public function testTheBranchWriterDelegatesPathEvaluationToBranch(): void
    {
        $writes = [];
        $config = new RunnableConfig(configurable: [
            Constants::CONFIG_KEY_SEND => static function (array $entries) use (&$writes): void {
                $writes = $entries;
            },
        ]);

        $writer = new RunnableBranchWriter(new Branch(static fn () => 'k', ['k' => 'dest']), false, 'src');

        $this->assertSame('in', $writer->invoke('in', $config));
        $this->assertSame([['branch:to:dest', 'src']], $writes);
    }

    public function testTheBranchWriterStillAcceptsABareCallable(): void
    {
        $writes = [];
        $config = new RunnableConfig(configurable: [
            Constants::CONFIG_KEY_SEND => static function (array $entries) use (&$writes): void {
                $writes = $entries;
            },
        ]);

        (new RunnableBranchWriter(static fn () => ['a', Constants::END], false, 'src'))->invoke('in', $config);

        $this->assertSame([['branch:to:a', 'src']], $writes);
    }

    public function testAConditionalEdgeIsStoredAsABranchNamedAfterItsPath(): void
    {
        $graph = (new Graph())->addNode('a', static fn ($x) => $x);
        $graph->addConditionalEdges('a', static fn ($x) => 'a');
        $graph->addConditionalEdges('a', \LangChain\Runnables\RunnableSequence::from([static fn ($x) => 'a']));

        $this->assertSame(['condition', 'RunnableSequence'], array_keys($graph->branches['a']));
        $this->assertContainsOnlyInstancesOf(Branch::class, $graph->branches['a']);
    }

    public function testADuplicateConditionNameIsRefused(): void
    {
        $graph = (new Graph())->addNode('a', static fn ($x) => $x);
        $graph->addConditionalEdges('a', static fn ($x) => 'a');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Condition `condition` already present for node `a`');

        $graph->addConditionalEdges('a', static fn ($x) => 'a');
    }

    public function testAConditionalEdgeCanBeGivenAsBranchOptions(): void
    {
        $graph = (new Graph())->addNode('a', static fn ($x) => $x);
        $graph->addConditionalEdges(['source' => 'a', 'path' => static fn ($x) => 'a', 'pathMap' => ['a']]);

        $this->assertSame(['a' => 'a'], $graph->branches['a']['condition']->ends);
    }

    public function testAPlainGraphRoutesThroughAConditionalEdge(): void
    {
        $graph = (new Graph())
            ->addNode('classify', static fn (int $n): int => $n)
            ->addNode('small', static fn (int $n): string => 'small:' . $n)
            ->addNode('big', static fn (int $n): string => 'big:' . $n)
            ->addEdge(Constants::START, 'classify')
            ->addConditionalEdges('classify', static fn (int $n): string => $n < 10 ? 'lt' : 'gte', ['lt' => 'small', 'gte' => 'big'])
            ->addEdge('small', Constants::END)
            ->addEdge('big', Constants::END)
            ->compile();

        $this->assertSame('small:3', $graph->invoke(3));
        $this->assertSame('big:30', $graph->invoke(30));
    }

    public function testAPlainGraphConditionalEdgeCanRouteToEnd(): void
    {
        $graph = (new Graph())
            ->addNode('a', static fn (int $n): int => $n + 1)
            ->addNode('b', static fn (int $n): int => $n * 100)
            ->addEdge(Constants::START, 'a')
            ->addConditionalEdges('a', static fn (int $n): string => $n > 5 ? Constants::END : 'b', [Constants::END, 'b'])
            ->addEdge('b', Constants::END)
            ->compile();

        $this->assertSame(10, $graph->invoke(9));
        $this->assertSame(300, $graph->invoke(2));
    }

    public function testAConditionalEdgeOutOfStartIsAttachedToAHiddenStartNode(): void
    {
        $graph = (new Graph())
            ->addNode('a', static fn (string $s): string => 'a:' . $s)
            ->addNode('b', static fn (string $s): string => 'b:' . $s)
            ->addConditionalEdges(Constants::START, static fn (string $s): string => $s === 'go-a' ? 'a' : 'b', ['a', 'b'])
            ->addEdge('a', Constants::END)
            ->addEdge('b', Constants::END)
            ->compile();

        $this->assertArrayHasKey(Constants::START, $graph->nodes);
        $this->assertSame('a:go-a', $graph->invoke('go-a'));
        $this->assertSame('b:other', $graph->invoke('other'));
    }

    public function testStateGraphUsesTheSameBranchClass(): void
    {
        $graph = (new StateGraph(['n' => 'int']))
            ->addNode('a', static fn (array $s): array => ['n' => 1]);
        $graph->addConditionalEdges('a', static fn () => Constants::END, ['to_end' => Constants::END]);

        $this->assertInstanceOf(Branch::class, $graph->branches['a'][Constants::END]);
    }
}
