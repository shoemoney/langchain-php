<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\State;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangGraph\Channels\BinaryOperatorAggregate;
use LangGraph\Channels\LastValue;
use LangGraph\Channels\LastValueAfterFinish;
use LangGraph\Channels\NamedBarrierValue;
use LangGraph\Channels\Overwrite;
use LangGraph\Errors\GraphValueError;
use LangGraph\Graph\MessagesAnnotation;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Retry\RetryPolicy;
use LangGraph\Pregel\Utils\Subgraph;
use LangGraph\State\Annotation;
use LangGraph\State\AnnotationRoot;
use LangGraph\State\StateGraph;
use LangGraph\State\StateGraphInputError;
use LangGraph\State\StateGraphNodeSpec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use const LangGraph\Pregel\Constants\X;

/**
 * Port of the `StateGraph` describe of `langgraph-core/src/graph/state.test.ts`, plus the parts of
 * `state.ts` it does not exercise (`addSequence`, joins, `validate`, `ends`, `defer`) and
 * `pregel/utils/subgraph.ts`.
 *
 * Zod and `StateSchema` cases are converted to `Annotation` or JSON Schema (this port is JSON-Schema
 * native, see COMPLETION_PLAN section 2). What that conversion drops, deliberately:
 *
 *  - every Zod coercion/validation assertion (parsing a bad input, `.min(2)` on a reducer input);
 *    the JSON Schema form chooses the channel keys but is not run as a validator;
 *  - the `expectTypeOf` type-inference blocks (no PHP type system to assert against).
 *
 * The stand-in for "the schema filters what a graph accepts" is that the input schema's keys are the
 * only keys the start node writes.
 */
#[CoversClass(StateGraph::class)]
#[CoversClass(StateGraphNodeSpec::class)]
#[CoversClass(StateGraphInputError::class)]
#[CoversClass(Subgraph::class)]
final class StateGraphParityTest extends TestCase
{
    private static function counting(): AnnotationRoot
    {
        return Annotation::root([
            'count' => Annotation::withReducer(static fn ($a, $b) => $a + $b, static fn (): int => 0),
            'name' => Annotation::last(),
        ]);
    }

    private static function items(): AnnotationRoot
    {
        return Annotation::root([
            'items' => Annotation::withReducer(
                static fn ($a, $b) => array_merge($a ?? [], $b ?? []),
                static fn (): array => [],
            ),
        ]);
    }

    // ---- constructor: direct schema ----------------------------------------

    public function testAcceptsAnnotationRootDirectly(): void
    {
        $graph = (new StateGraph(self::counting()))
            ->addNode('increment', static fn (): array => ['count' => 1])
            ->addEdge(Constants::START, 'increment')
            ->addEdge('increment', Constants::END)
            ->compile();

        $result = $graph->invoke(['name' => 'test']);

        self::assertSame(1, $result['count']);
        self::assertSame('test', $result['name']);
    }

    public function testAcceptsTheChannelMapShorthand(): void
    {
        $graph = (new StateGraph(['input' => 'string', 'value' => 'int']))
            ->addNode('a', static fn (array $s): array => ['value' => $s['value'] + 1])
            ->addEdge(Constants::START, 'a')
            ->compile();

        self::assertSame(['input' => 'x', 'value' => 2], $graph->invoke(['input' => 'x', 'value' => 1]));
    }

    // ---- constructor: object patterns --------------------------------------

    public function testAcceptsStateSchemaInputAndOutput(): void
    {
        $stateSchema = Annotation::root([
            'question' => Annotation::last(),
            'answer' => Annotation::last(),
            'language' => Annotation::last(),
        ]);
        $input = Annotation::root(['question' => Annotation::last()]);
        $output = Annotation::root(['answer' => Annotation::last()]);

        $graph = (new StateGraph(['stateSchema' => $stateSchema, 'input' => $input, 'output' => $output]))
            ->addNode('agent', static fn (array $state): array => [
                'answer' => 'Answer to: ' . $state['question'],
                'language' => 'en',
            ])
            ->addEdge(Constants::START, 'agent')
            ->addEdge('agent', Constants::END)
            ->compile();

        self::assertSame(['answer' => 'Answer to: What is LangGraph?'], $graph->invoke(['question' => 'What is LangGraph?']));
    }

    public function testAcceptsStateSchemaAloneAndInputOutputDefaultToState(): void
    {
        $graph = (new StateGraph(['stateSchema' => self::counting()]))
            ->addNode('increment', static fn (): array => ['count' => 1])
            ->addEdge(Constants::START, 'increment')
            ->addEdge('increment', Constants::END)
            ->compile();

        $result = $graph->invoke(['name' => 'test']);

        self::assertSame(1, $result['count']);
        self::assertSame('test', $result['name']);
    }

    public function testAcceptsInputAndOutputWithoutAStateSchema(): void
    {
        $input = Annotation::root(['question' => Annotation::last(), 'context' => Annotation::last()]);
        $output = Annotation::root([
            'question' => Annotation::last(),
            'context' => Annotation::last(),
            'answer' => Annotation::last(),
        ]);

        $graph = (new StateGraph(['input' => $input, 'output' => $output]))
            ->addNode('agent', static fn (array $s): array => ['answer' => 'Answer: ' . $s['question']])
            ->addEdge(Constants::START, 'agent')
            ->addEdge('agent', Constants::END)
            ->compile();

        $result = $graph->invoke(['question' => 'Hi', 'context' => 'ctx']);

        self::assertSame('Answer: Hi', $result['answer']);
        self::assertSame('Hi', $result['question']);
        self::assertSame('ctx', $result['context']);
    }

    public function testAcceptsTheStateKeyAsWellAsStateSchema(): void
    {
        $graph = (new StateGraph(['state' => self::counting(), 'output' => Annotation::root(['count' => Annotation::last()])]))
            ->addNode('increment', static fn (): array => ['count' => 2])
            ->addEdge(Constants::START, 'increment')
            ->compile();

        self::assertSame(['count' => 2], $graph->invoke(['name' => 'ignored']));
    }

    public function testAcceptsTheDeprecatedChannelsArgument(): void
    {
        $graph = (new StateGraph([
            'channels' => [
                'count' => ['reducer' => static fn (int $a, int $b): int => $a + $b, 'default' => static fn (): int => 0],
                'name' => null,
            ],
        ]))
            ->addNode('increment', static fn (): array => ['count' => 1])
            ->addEdge(Constants::START, 'increment')
            ->addEdge('increment', Constants::END)
            ->compile();

        $result = $graph->invoke(['name' => 'test']);

        self::assertSame(1, $result['count']);
        self::assertSame('test', $result['name']);
    }

    // ---- constructor: two-argument patterns ---------------------------------

    public function testAcceptsAContextSchemaAsTheSecondArgument(): void
    {
        $context = Annotation::root(['userId' => Annotation::last()]);

        $graph = (new StateGraph(self::counting(), ['context' => $context]))
            ->addNode('increment', static fn (): array => ['count' => 1])
            ->addEdge(Constants::START, 'increment')
            ->addEdge('increment', Constants::END)
            ->compile();

        self::assertSame(1, $graph->invoke([], new \LangChain\Runnables\RunnableConfig(configurable: ['userId' => 'test-user']))['count']);
        self::assertSame(['userId'], array_keys((new StateGraph(self::counting(), ['context' => $context]))->contextSchema ?? []));
    }

    public function testAcceptsAContextSchemaDirectlyAsTheSecondArgument(): void
    {
        $builder = new StateGraph(self::counting(), Annotation::root(['userId' => Annotation::last()]));

        self::assertSame(['userId'], array_keys($builder->contextSchema ?? []));
        self::assertArrayNotHasKey('userId', $builder->channels, 'a context schema is not part of the state');
    }

    public function testAcceptsInputAndOutputAsTheSecondArgument(): void
    {
        $state = Annotation::root(['question' => Annotation::last(), 'answer' => Annotation::last()]);

        $graph = (new StateGraph($state, [
            'input' => Annotation::root(['question' => Annotation::last()]),
            'output' => Annotation::root(['answer' => Annotation::last()]),
        ]))
            ->addNode('agent', static fn (array $s): array => ['answer' => 'Answer to: ' . $s['question']])
            ->addEdge(Constants::START, 'agent')
            ->addEdge('agent', Constants::END)
            ->compile();

        self::assertSame(['answer' => 'Answer to: Hi!'], $graph->invoke(['question' => 'Hi!']));
    }

    public function testAcceptsJsonSchemaInputAndOutput(): void
    {
        $state = Annotation::root(['question' => Annotation::last(), 'answer' => Annotation::last(), 'trace' => Annotation::last()]);

        $graph = (new StateGraph($state, [
            'input' => ['type' => 'object', 'properties' => ['question' => ['type' => 'string']]],
            'output' => ['type' => 'object', 'properties' => ['answer' => ['type' => 'string']]],
        ]))
            ->addNode('agent', static fn (array $s): array => ['answer' => 'Answer to: ' . $s['question'], 'trace' => 'x'])
            ->addEdge(Constants::START, 'agent')
            ->compile();

        self::assertSame(['answer' => 'Answer to: Hi!'], $graph->invoke(['question' => 'Hi!']));
    }

    public function testAJsonSchemaStateGivesOneLastValueChannelPerProperty(): void
    {
        $builder = new StateGraph(['type' => 'object', 'properties' => ['a' => ['type' => 'string'], 'b' => ['type' => 'number']]]);

        self::assertSame(['a', 'b'], array_keys($builder->channels));
        self::assertContainsOnlyInstancesOf(LastValue::class, $builder->channels);
    }

    public function testMixedSchemaKindsInOneGraph(): void
    {
        $graph = (new StateGraph([
            'state' => Annotation::root(['question' => Annotation::last(), 'answer' => Annotation::last()]),
            'input' => ['type' => 'object', 'properties' => ['question' => ['type' => 'string']]],
            'output' => Annotation::root(['answer' => Annotation::last()]),
        ]))
            ->addNode('agent', static fn (array $s): array => ['answer' => strtoupper($s['question'])])
            ->addEdge(Constants::START, 'agent')
            ->compile();

        self::assertSame(['answer' => 'HELLO'], $graph->invoke(['question' => 'hello']));
    }

    public function testAnUnusableSchemaIsRejected(): void
    {
        $this->expectException(StateGraphInputError::class);
        $this->expectExceptionMessage('Invalid StateGraph input');

        new StateGraph([]);
    }

    public function testAnUnusableInputSchemaIsRejected(): void
    {
        $this->expectException(StateGraphInputError::class);

        new StateGraph(self::counting(), ['input' => []]);
    }

    // ---- input and output behave as filters ---------------------------------

    public function testTheInputSchemaIsTheOnlyThingTheStartNodeWrites(): void
    {
        $graph = (new StateGraph([
            'stateSchema' => Annotation::root(['question' => Annotation::last(), 'answer' => Annotation::last()]),
            'input' => Annotation::root(['question' => Annotation::last()]),
        ]))
            ->addNode('echo', static fn (array $s): array => ['answer' => (string) ($s['answer'] ?? 'unset')])
            ->addEdge(Constants::START, 'echo')
            ->compile();

        $result = $graph->invoke(['question' => 'q', 'answer' => 'smuggled in']);

        self::assertSame('unset', $result['answer'], 'a key outside the input schema is not accepted as input');
    }

    public function testCollapsesMultipleSchemasIntoChannelsAndHidesInternalState(): void
    {
        $graph = (new StateGraph([
            'stateSchema' => Annotation::root([
                'question' => Annotation::last(),
                'answer' => Annotation::last(),
                'internal' => Annotation::last(),
            ]),
            'input' => Annotation::root(['question' => Annotation::last()]),
            'output' => Annotation::root(['answer' => Annotation::last()]),
        ]))
            ->addNode('process', static fn (array $s): array => [
                'answer' => 'Answer: ' . $s['question'],
                'internal' => 'internal data',
            ])
            ->addEdge(Constants::START, 'process')
            ->addEdge('process', Constants::END)
            ->compile();

        $result = $graph->invoke(['question' => 'test']);

        self::assertSame(['answer' => 'Answer: test'], $result);
        self::assertArrayNotHasKey('internal', $result);
    }

    public function testOutputsOnlyFieldsFromTheOutputSchema(): void
    {
        $graph = (new StateGraph([
            'state' => Annotation::root(['question' => Annotation::last(), 'answer' => Annotation::last(), 'metadata' => Annotation::last()]),
            'output' => ['type' => 'object', 'properties' => ['answer' => ['type' => 'string']]],
        ]))
            ->addNode('process', static fn (array $s): array => ['answer' => 'Answer: ' . $s['question'], 'metadata' => 'some metadata'])
            ->addEdge(Constants::START, 'process')
            ->addEdge('process', Constants::END)
            ->compile();

        $result = $graph->invoke(['question' => 'test']);

        self::assertSame(['answer' => 'Answer: test'], $result);
    }

    public function testTheCompiledGraphDeclaresEveryStateChannelAsStreamable(): void
    {
        $graph = (new StateGraph([
            'stateSchema' => Annotation::root(['question' => Annotation::last(), 'answer' => Annotation::last(), 'internal' => Annotation::last()]),
            'output' => Annotation::root(['answer' => Annotation::last()]),
        ]))
            ->addNode('process', static fn (): array => ['answer' => 'A'])
            ->addEdge(Constants::START, 'process')
            ->compile();

        // Upstream's `values` stream mode reads these. This engine's `values` mode reads the output
        // channels instead (IO::mapOutputValues), so only invoke()'s narrowing is observable here.
        self::assertSame(['question', 'answer', 'internal'], $graph->streamChannels);
        self::assertSame(['answer'], $graph->outputChannels);
    }

    public function testDetectsChannelConflictsWithDifferentReducers(): void
    {
        $schema1 = Annotation::root(['items' => Annotation::withReducer(static fn ($a, $b) => [...$a, ...$b], static fn (): array => [])]);
        $schema2 = Annotation::root(['items' => Annotation::withReducer(static fn ($a, $b) => $b, static fn (): array => [])]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Channel "items" already exists with a different type.');

        new StateGraph(['stateSchema' => $schema1, 'output' => $schema2]);
    }

    public function testASharedReducerAcrossSchemasIsNotAConflict(): void
    {
        $reducer = static fn ($a, $b) => [...$a, ...$b];
        $state = Annotation::root(['items' => Annotation::withReducer($reducer, static fn (): array => [])]);
        $output = Annotation::root(['items' => Annotation::withReducer($reducer, static fn (): array => [])]);

        $builder = new StateGraph(['stateSchema' => $state, 'output' => $output]);

        self::assertInstanceOf(BinaryOperatorAggregate::class, $builder->channels['items']);
    }

    public function testAPlainChannelNeverOverridesTheStatesReducer(): void
    {
        $state = Annotation::root(['items' => Annotation::withReducer(static fn ($a, $b) => [...$a, ...$b], static fn (): array => [])]);

        $builder = new StateGraph(['stateSchema' => $state, 'input' => Annotation::root(['items' => Annotation::last()])]);

        self::assertInstanceOf(BinaryOperatorAggregate::class, $builder->channels['items']);
    }

    public function testAllowsACommandWithAnUpdate(): void
    {
        $graph = (new StateGraph(self::counting()))
            ->addNode('increment', static fn () => new Command(update: ['count' => 1], goto: Constants::END))
            ->addEdge(Constants::START, 'increment')
            ->compile();

        self::assertSame(1, $graph->invoke([])['count']);
    }

    // ---- per-node input ------------------------------------------------------

    public function testAPerNodeInputNarrowsWhatTheNodeReads(): void
    {
        $seen = null;
        $graph = (new StateGraph(Annotation::root([
            'messages' => Annotation::last(),
            'count' => Annotation::last(),
        ])))
            ->addNode('process', static function (array $input) use (&$seen): array {
                $seen = $input;

                return ['messages' => [...$input['messages'], 'processed']];
            }, ['input' => Annotation::root(['messages' => Annotation::last()])])
            ->addEdge(Constants::START, 'process')
            ->addEdge('process', Constants::END)
            ->compile();

        $result = $graph->invoke(['messages' => ['hello'], 'count' => 1]);

        self::assertSame(['messages' => ['hello']], $seen, 'the node does not see `count`');
        self::assertSame(['hello', 'processed'], $result['messages']);
        self::assertSame(1, $result['count']);
    }

    public function testAPerNodeInputWithAJsonSchema(): void
    {
        $seen = null;
        $graph = (new StateGraph(Annotation::root(['a' => Annotation::last(), 'b' => Annotation::last()])))
            ->addNode('n', static function (array $input) use (&$seen): array {
                $seen = $input;

                return ['b' => 'done'];
            }, ['input' => ['type' => 'object', 'properties' => ['a' => ['type' => 'string']]]])
            ->addEdge(Constants::START, 'n')
            ->compile();

        $graph->invoke(['a' => 'x', 'b' => 'y']);

        self::assertSame(['a' => 'x'], $seen);
    }

    public function testAPerNodeInputWithAReducerInTheState(): void
    {
        $state = Annotation::root([
            'messages' => Annotation::withReducer(static fn ($a, $b) => [...$a, ...$b], static fn (): array => []),
            'count' => Annotation::last(),
            'query' => Annotation::last(),
        ]);
        $nodeInput = Annotation::root(['messages' => Annotation::last(), 'query' => Annotation::last()]);

        $seen = null;
        $graph = (new StateGraph($state))
            ->addNode('addQuery', static fn (array $s): array => ['query' => 'Query from: ' . $s['messages'][0]])
            ->addNode('process', static function (array $input) use (&$seen): array {
                $seen = $input;

                return ['messages' => ['processed']];
            }, ['input' => $nodeInput])
            ->addEdge(Constants::START, 'addQuery')
            ->addEdge('addQuery', 'process')
            ->addEdge('process', Constants::END)
            ->compile();

        $result = $graph->invoke(['messages' => ['hello'], 'count' => 1]);

        self::assertSame(['hello', 'processed'], $result['messages'], 'the state keeps its reducer');
        self::assertSame(1, $result['count']);
        self::assertArrayHasKey('query', $seen);
        self::assertArrayNotHasKey('count', $seen);
    }

    public function testAPerNodeInputAddsItsKeysToTheGraphChannels(): void
    {
        $builder = (new StateGraph(Annotation::root(['a' => Annotation::last()])))
            ->addNode('n', static fn (): array => [], ['input' => Annotation::root(['extra' => Annotation::last()])]);

        self::assertSame(['a', 'extra'], array_keys($builder->channels));
        self::assertSame(['extra'], array_keys($builder->nodeSpecs['n']->input));
    }

    // ---- Overwrite end to end -----------------------------------------------

    public function testOverwriteReplacesAReducerField(): void
    {
        $graph = (new StateGraph(self::items()))
            ->addNode('add', static fn (): array => ['items' => ['a', 'b']])
            ->addNode('replace', static fn (): array => ['items' => new Overwrite(['replaced'])])
            ->addEdge(Constants::START, 'add')
            ->addEdge('add', 'replace')
            ->addEdge('replace', Constants::END)
            ->compile();

        self::assertSame(['replaced'], $graph->invoke([])['items']);
    }

    public function testOverwriteInWireFormat(): void
    {
        $graph = (new StateGraph(self::items()))
            ->addNode('add', static fn (): array => ['items' => ['a', 'b']])
            ->addNode('replace', static fn (): array => ['items' => ['__overwrite__' => ['replaced']]])
            ->addEdge(Constants::START, 'add')
            ->addEdge('add', 'replace')
            ->addEdge('replace', Constants::END)
            ->compile();

        self::assertSame(['replaced'], $graph->invoke([])['items']);
    }

    public function testOverwriteANumericReducerField(): void
    {
        $graph = (new StateGraph(self::counting()))
            ->addNode('add', static fn (): array => ['count' => 5])
            ->addNode('reset', static fn (): array => ['count' => new Overwrite(0)])
            ->addNode('addMore', static fn (): array => ['count' => 3])
            ->addEdge(Constants::START, 'add')
            ->addEdge('add', 'reset')
            ->addEdge('reset', 'addMore')
            ->addEdge('addMore', Constants::END)
            ->compile();

        self::assertSame(3, $graph->invoke([])['count']);
    }

    public function testOverwriteToAnEmptyArray(): void
    {
        $graph = (new StateGraph(self::items()))
            ->addNode('add', static fn (): array => ['items' => ['a', 'b', 'c']])
            ->addNode('clear', static fn (): array => ['items' => new Overwrite([])])
            ->addEdge(Constants::START, 'add')
            ->addEdge('add', 'clear')
            ->addEdge('clear', Constants::END)
            ->compile();

        self::assertSame([], $graph->invoke([])['items']);
    }

    public function testAccumulationContinuesAfterAnOverwrite(): void
    {
        $graph = (new StateGraph(self::items()))
            ->addNode('add', static fn (): array => ['items' => ['a']])
            ->addNode('replace', static fn (): array => ['items' => new Overwrite(['x'])])
            ->addNode('addMore', static fn (): array => ['items' => ['y']])
            ->addEdge(Constants::START, 'add')
            ->addEdge('add', 'replace')
            ->addEdge('replace', 'addMore')
            ->addEdge('addMore', Constants::END)
            ->compile();

        self::assertSame(['x', 'y'], $graph->invoke([])['items']);
    }

    public function testOverwriteMessagesWithAMessagesReducerAnnotation(): void
    {
        $graph = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('agent', static fn (): array => ['messages' => [new AIMessage('first response')]])
            ->addNode('replace', static fn (): array => ['messages' => new Overwrite([new AIMessage('only this')])])
            ->addEdge(Constants::START, 'agent')
            ->addEdge('agent', 'replace')
            ->addEdge('replace', Constants::END)
            ->compile();

        $result = $graph->invoke(['messages' => [new HumanMessage('hello')]]);

        self::assertCount(1, $result['messages']);
        self::assertSame('only this', $result['messages'][0]->content);
    }

    public function testOverwriteMessagesInMessagesAnnotation(): void
    {
        $graph = (new StateGraph(MessagesAnnotation::root()))
            ->addNode('agent', static fn (): array => ['messages' => [new AIMessage('first')]])
            ->addNode('replace', static fn (): array => ['messages' => new Overwrite([new HumanMessage('fresh start')])])
            ->addEdge(Constants::START, 'agent')
            ->addEdge('agent', 'replace')
            ->addEdge('replace', Constants::END)
            ->compile();

        $result = $graph->invoke(['messages' => [new HumanMessage('hello')]]);

        self::assertCount(1, $result['messages']);
        self::assertSame('fresh start', $result['messages'][0]->content);
    }

    public function testDuplicateCommandUpdatesAreAllPreserved(): void
    {
        $graph = (new StateGraph(self::items()))
            ->addNode('append', static fn (): array => [
                new Command(update: ['items' => ['a1']]),
                new Command(update: ['items' => ['a2']]),
            ])
            ->addEdge(Constants::START, 'append')
            ->addEdge('append', Constants::END)
            ->compile();

        self::assertSame(['items' => ['a1', 'a2']], $graph->invoke([]));
    }

    // ---- addSequence -----------------------------------------------------------

    public function testAddSequenceChainsNodesInOrder(): void
    {
        $graph = (new StateGraph(self::items()))
            ->addSequence([
                ['first', static fn (): array => ['items' => ['1']]],
                ['second', static fn (): array => ['items' => ['2']]],
                ['third', static fn (): array => ['items' => ['3']]],
            ])
            ->addEdge(Constants::START, 'first')
            ->compile();

        self::assertSame(['items' => ['1', '2', '3']], $graph->invoke([]));
    }

    public function testAddSequenceAcceptsANameToActionMap(): void
    {
        $builder = (new StateGraph(self::items()))->addSequence([
            'a' => static fn (): array => ['items' => ['a']],
            'b' => static fn (): array => ['items' => ['b']],
        ]);

        self::assertSame([['a', 'b']], $builder->edges);
        self::assertSame(['items' => ['a', 'b']], $builder->addEdge(Constants::START, 'a')->compile()->invoke([]));
    }

    public function testAddSequenceAddsNoEdgeBeforeTheFirstNode(): void
    {
        $builder = (new StateGraph(self::items()))->addSequence([
            ['a', static fn (): array => []],
            ['b', static fn (): array => []],
            ['c', static fn (): array => []],
        ]);

        self::assertSame([['a', 'b'], ['b', 'c']], $builder->edges);
    }

    public function testAddSequencePassesPerNodeOptions(): void
    {
        $policy = new RetryPolicy(maxAttempts: 4, logWarning: false);
        $builder = (new StateGraph(self::items()))->addSequence([
            ['a', static fn (): array => [], ['retryPolicy' => $policy]],
            ['b', static fn (): array => []],
        ]);

        self::assertSame($policy, $builder->nodes['a']->retryPolicy);
        self::assertNull($builder->nodes['b']->retryPolicy);
    }

    public function testAddSequenceRejectsAnEmptyList(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Sequence requires at least one node.');

        (new StateGraph(self::items()))->addSequence([]);
    }

    public function testAddSequenceRejectsANameAlreadyInTheGraph(): void
    {
        $builder = (new StateGraph(self::items()))->addNode('a', static fn (): array => []);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Node names must be unique: node with the name "a" already exists.');

        $builder->addSequence([['b', static fn (): array => []], ['a', static fn (): array => []]]);
    }

    public function testAddSequenceCanFeedAConditionalEdge(): void
    {
        $graph = (new StateGraph(self::items()))
            ->addSequence([
                ['a', static fn (): array => ['items' => ['a']]],
                ['b', static fn (): array => ['items' => ['b']]],
            ])
            ->addNode('c', static fn (): array => ['items' => ['c']])
            ->addEdge(Constants::START, 'a')
            ->addConditionalEdges('b', static fn (): string => 'c', ['c'])
            ->compile();

        self::assertSame(['a', 'b', 'c'], $graph->invoke([])['items']);
    }

    // ---- joins (addEdge with several starts) -----------------------------------

    public function testAJoinWaitsForEveryStart(): void
    {
        $log = [];
        $graph = (new StateGraph(self::items()))
            ->addNode('left', static function () use (&$log): array {
                $log[] = 'left';

                return ['items' => ['L']];
            })
            ->addNode('right', static function () use (&$log): array {
                $log[] = 'right';

                return ['items' => ['R']];
            })
            ->addNode('join', static function (array $s) use (&$log): array {
                $log[] = 'join:' . implode(',', $s['items']);

                return ['items' => ['J']];
            })
            ->addEdge(Constants::START, 'left')
            ->addEdge(Constants::START, 'right')
            ->addEdge(['left', 'right'], 'join')
            ->addEdge('join', Constants::END)
            ->compile();

        $result = $graph->invoke([]);

        self::assertSame(['left', 'right', 'join:L,R'], $log, 'join runs once, after both starts');
        self::assertSame(['L', 'R', 'J'], $result['items']);
    }

    public function testAJoinRegistersABarrierChannel(): void
    {
        $graph = (new StateGraph(self::items()))
            ->addNode('a', static fn (): array => [])
            ->addNode('b', static fn (): array => [])
            ->addNode('c', static fn (): array => [])
            ->addEdge(Constants::START, 'a')
            ->addEdge(Constants::START, 'b')
            ->addEdge(['a', 'b'], 'c')
            ->compile();

        self::assertInstanceOf(NamedBarrierValue::class, $graph->channels['join:a+b:c']);
        self::assertContains('join:a+b:c', $graph->nodes['c']->triggers);
    }

    public function testAJoinCountsAsEdgesForReachability(): void
    {
        $builder = (new StateGraph(self::items()))
            ->addNode('a', static fn (): array => [])
            ->addNode('b', static fn (): array => [])
            ->addNode('c', static fn (): array => [])
            ->addEdge(Constants::START, 'a')
            ->addEdge(Constants::START, 'b')
            ->addEdge(['a', 'b'], 'c');

        self::assertSame([['a', 'c'], ['b', 'c']], array_slice($builder->allEdges(), -2));
        $builder->validate();
        $this->addToAssertionCount(1);
    }

    public function testAJoinRejectsEndAsAStartAndAsTheTarget(): void
    {
        $builder = (new StateGraph(self::items()))
            ->addNode('a', static fn (): array => [])
            ->addNode('b', static fn (): array => []);

        try {
            $builder->addEdge(['a', Constants::END], 'b');
            self::fail('END cannot start a join');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('END cannot be a start node', $e->getMessage());
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('END cannot be an end node');
        $builder->addEdge(['a', 'b'], Constants::END);
    }

    public function testAJoinRejectsUnknownNodes(): void
    {
        $builder = (new StateGraph(self::items()))->addNode('a', static fn (): array => []);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Need to add a node named "ghost" first');

        $builder->addEdge(['a', 'ghost'], 'a');
    }

    // ---- validate -----------------------------------------------------------------

    public function testValidateRejectsAnUnreachableNode(): void
    {
        $builder = (new StateGraph(self::items()))
            ->addNode('a', static fn (): array => [])
            ->addNode('orphan', static fn (): array => [])
            ->addEdge(Constants::START, 'a');

        try {
            $builder->validate();
            self::fail('an unreachable node must be rejected');
        } catch (GraphValueError $e) {
            self::assertStringContainsString('Node `orphan` is not reachable.', $e->getMessage());
            self::assertSame('UNREACHABLE_NODE', $e->getLcErrorCode());
        }
    }

    public function testEndsMakeANodeReachable(): void
    {
        $builder = (new StateGraph(self::items()))
            ->addNode('router', static fn () => new Command(goto: 'target'), ['ends' => ['target']])
            ->addNode('target', static fn (): array => ['items' => ['hit']])
            ->addEdge(Constants::START, 'router');

        $builder->validate();

        self::assertSame(['items' => ['hit']], $builder->compile()->invoke([]));
    }

    public function testValidateRejectsAnEndNamingAnUnknownNode(): void
    {
        $builder = (new StateGraph(self::items()))
            ->addNode('router', static fn (): array => [], ['ends' => ['nowhere']])
            ->addEdge(Constants::START, 'router');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Found edge ending at unknown node `nowhere`');

        $builder->validate();
    }

    public function testValidateRejectsAnInterruptNamingAnUnknownNode(): void
    {
        $builder = (new StateGraph(self::items()))
            ->addNode('a', static fn (): array => [])
            ->addEdge(Constants::START, 'a');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Interrupt node `ghost` is not present');

        $builder->validate(['ghost']);
    }

    public function testCompileRejectsAnInterruptNamingAnUnknownNode(): void
    {
        $builder = (new StateGraph(self::items()))
            ->addNode('a', static fn (): array => [])
            ->addEdge(Constants::START, 'a');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Interrupt node `ghost` is not present');

        $builder->compile(['interruptBefore' => ['ghost']]);
    }

    public function testValidateMarksTheBuilderCompiled(): void
    {
        $builder = (new StateGraph(self::items()))
            ->addNode('a', static fn (): array => [])
            ->addEdge(Constants::START, 'a');

        self::assertFalse($builder->compiled);
        $builder->validate();
        self::assertTrue($builder->compiled);
    }

    // ---- node options -------------------------------------------------------------

    public function testAnOptionsCachePolicyIsNormalised(): void
    {
        $builder = (new StateGraph(self::items()))
            ->addNode('on', static fn (): array => [], ['cachePolicy' => true])
            ->addNode('off', static fn (): array => [], ['cachePolicy' => false])
            ->addNode('ttl', static fn (): array => [], ['cachePolicy' => ['ttl' => 5]])
            ->addNode('none', static fn (): array => []);

        self::assertSame([], $builder->nodes['on']->cachePolicy);
        self::assertNull($builder->nodes['off']->cachePolicy);
        self::assertFalse($builder->nodeSpecs['off']->cachePolicy, 'false is kept on the spec: it opts out of a default');
        self::assertSame(['ttl' => 5], $builder->nodes['ttl']->cachePolicy);
        self::assertNull($builder->nodeSpecs['none']->cachePolicy);
    }

    public function testDeferUsesALastValueAfterFinishRoutingChannel(): void
    {
        $graph = (new StateGraph(self::items()))
            ->addNode('a', static fn (): array => ['items' => ['a']])
            ->addNode('final', static fn (): array => ['items' => ['final']], ['defer' => true])
            ->addEdge(Constants::START, 'a')
            ->addEdge('a', 'final')
            ->compile();

        self::assertInstanceOf(LastValueAfterFinish::class, $graph->channels['branch:to:final']);
        self::assertSame(['items' => ['a', 'final']], $graph->invoke([]));
    }

    public function testNodeTimeoutAndEndsAreRecordedOnTheCompiledNode(): void
    {
        $graph = (new StateGraph(self::items()))
            ->addNode('a', static fn (): array => [], ['timeout' => 1500, 'ends' => ['b']])
            ->addNode('b', static fn (): array => [])
            ->addEdge(Constants::START, 'a')
            ->compile();

        self::assertSame(1500, $graph->nodes['a']->timeout);
        self::assertSame(['b'], $graph->nodes['a']->ends);
    }

    // ---- subgraph detection -------------------------------------------------------

    private static function subgraph(): object
    {
        return (new StateGraph(self::items()))
            ->addNode('inner', static fn (): array => ['items' => ['inner']])
            ->addEdge(Constants::START, 'inner')
            ->compile();
    }

    public function testIsPregelLikeRecognisesACompiledGraph(): void
    {
        self::assertTrue(Subgraph::isPregelLike(self::subgraph()));
        self::assertFalse(Subgraph::isPregelLike(static fn (): int => 1));
        self::assertFalse(Subgraph::isPregelLike('graph'));
    }

    public function testIsPregelLikeAcceptsTheStructuralMarker(): void
    {
        $marked = new class () {
            public bool $lgIsPregel = true;
        };

        self::assertTrue(Subgraph::isPregelLike($marked));
    }

    public function testFindSubgraphPregelReturnsTheCandidateItself(): void
    {
        $graph = self::subgraph();

        self::assertSame($graph, Subgraph::findSubgraphPregel($graph));
    }

    public function testFindSubgraphPregelLooksInsideSequences(): void
    {
        $graph = self::subgraph();
        $inner = \LangChain\Runnables\RunnableSequence::from([
            \LangChain\Runnables\RunnableLambda::from(static fn ($x) => $x),
            $graph,
        ]);
        $outer = \LangChain\Runnables\RunnableSequence::from([
            \LangChain\Runnables\RunnableLambda::from(static fn ($x) => $x),
            $inner,
        ]);

        self::assertSame($graph, Subgraph::findSubgraphPregel($outer));
    }

    public function testFindSubgraphPregelReturnsNullWhenThereIsNone(): void
    {
        $sequence = \LangChain\Runnables\RunnableSequence::from([
            \LangChain\Runnables\RunnableLambda::from(static fn ($x) => $x),
            \LangChain\Runnables\RunnableLambda::from(static fn ($x) => $x),
        ]);

        self::assertNull(Subgraph::findSubgraphPregel($sequence));
        self::assertNull(Subgraph::findSubgraphPregel(\LangChain\Runnables\RunnableLambda::from(static fn ($x) => $x)));
    }

    public function testACompiledGraphAddedAsANodeIsRecordedAsASubgraph(): void
    {
        $inner = self::subgraph();
        $builder = (new StateGraph(self::items()))->addNode('sub', $inner);

        self::assertSame([$inner], $builder->nodes['sub']->subgraphs);
    }

    public function testASubgraphNodeRunsEndToEnd(): void
    {
        $graph = (new StateGraph(self::items()))
            ->addNode('before', static fn (): array => ['items' => ['before']])
            ->addNode('sub', self::subgraph())
            ->addEdge(Constants::START, 'before')
            ->addEdge('before', 'sub')
            ->compile();

        // The subgraph shares the parent's `items` reducer, so its result is appended onto the parent's
        // state a second time: the same arithmetic as upstream.
        self::assertSame(['before', 'before', 'inner'], $graph->invoke([])['items']);
    }

    // ---- end to end ----------------------------------------------------------------

    public function testTheNewSurfaceRunsTogether(): void
    {
        $state = Annotation::root([
            'question' => Annotation::last(),
            'log' => Annotation::withReducer(static fn ($a, $b) => [...$a, ...$b], static fn (): array => []),
            'answer' => Annotation::last(),
        ]);

        $graph = (new StateGraph([
            'stateSchema' => $state,
            'input' => Annotation::root(['question' => Annotation::last()]),
            'output' => Annotation::root(['answer' => Annotation::last()]),
        ]))
            ->setNodeDefaults(['retryPolicy' => new RetryPolicy(maxAttempts: 2, logWarning: false)])
            ->addSequence([
                ['plan', static fn (array $s): array => ['log' => ['plan:' . $s['question']]]],
                ['act', static fn (array $s): array => ['log' => ['act'], 'answer' => implode('|', $s['log'])]],
            ])
            ->addEdge(Constants::START, 'plan')
            ->compile();

        self::assertSame(['answer' => 'plan:why'], $graph->invoke(['question' => 'why']));
        self::assertSame(2, $graph->nodes['plan']->retryPolicy?->effectiveMaxAttempts());
    }
}
