<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Errors\GraphInterrupt;
use LangGraph\Errors\GraphValueError;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\InterruptOptions;
use LangGraph\Pregel\PregelScratchpad;
use LangGraph\Pregel\Send;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use LangGraph\Utils\Hash;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangGraph\Pregel\interrupt;

/**
 * Port of `langgraph-core/src/tests/interrupt.test.ts` and the interrupt sections of
 * `tests/pregel.test.ts`: "should handle dynamic interrupt", "doubly nested graph
 * interrupts", "should interrupt and resume with Command inside a subgraph", "should
 * fail fast when interrupt is called without a checkpointer" and "resume multiple
 * interrupts".
 *
 * Differences from upstream, all forced by this port:
 *  - `invoke()` returns the state, not `{..., __interrupt__: [...]}`, so the pending
 *    interrupts are read from the `updates` stream and from `getState()`;
 *  - a Zod `responseSchema` has no PHP counterpart; the JSON Schema form is passed
 *    through and the resume value is never validated, so the three Zod-parsing cases
 *    ("rejects ... then accepts a corrected one", "corrects an invalid ... resume") are
 *    not ported.
 */
#[CoversClass(InterruptOptions::class)]
#[CoversClass(GraphInterrupt::class)]
final class InterruptTest extends TestCase
{
    private static function config(string $threadId = '1'): RunnableConfig
    {
        return new RunnableConfig(configurable: ['thread_id' => $threadId]);
    }

    /**
     * Run a stream to completion, returning every chunk.
     *
     * @return list<array{0: string, 1: mixed}>
     */
    private static function collect(object $graph, mixed $input, RunnableConfig $config): array
    {
        return array_values(iterator_to_array($graph->stream($input, $config), false));
    }

    /**
     * The interrupts a run raised, in order, as seen on the `updates` stream.
     *
     * @param list<array{0: string, 1: mixed}> $chunks
     * @return list<array<string, mixed>>
     */
    private static function interruptsIn(array $chunks): array
    {
        $out = [];
        foreach ($chunks as [$mode, $chunk]) {
            if ($mode === 'updates' && \is_array($chunk) && isset($chunk[Constants::INTERRUPT])) {
                foreach ($chunk[Constants::INTERRUPT] as $interrupt) {
                    $out[] = $interrupt;
                }
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function pendingInterrupts(object $graph, RunnableConfig $config): array
    {
        $out = [];
        foreach ($graph->getState($config)->tasks as $task) {
            foreach ($task->interrupts as $interrupt) {
                $out[] = $interrupt;
            }
        }

        return $out;
    }

    private static function askGraph(InterruptOptions|array|null $options = null): object
    {
        return (new StateGraph(Annotation::root(['answer' => Annotation::last()])))
            ->addNode('node', static fn (): array => [
                'answer' => interrupt(['question' => 'approve?'], $options),
            ])
            ->addEdge(Constants::START, 'node')
            ->compile(['checkpointer' => new MemorySaver()]);
    }

    // ---- interrupt.test.ts: responseSchema ---------------------------------

    public function testNoResponseSchemaLeavesTheKeyOffTheInterruptAndResumes(): void
    {
        $graph = self::askGraph();
        $config = self::config();

        $chunks = self::collect($graph, ['answer' => null], $config);

        $expected = ['id' => Hash::xxh3(''), 'value' => ['question' => 'approve?']];
        self::assertCount(1, self::interruptsIn($chunks));
        self::assertSame(['question' => 'approve?'], self::interruptsIn($chunks)[0]['value']);
        self::assertArrayNotHasKey('response_schema', self::interruptsIn($chunks)[0]);
        self::assertSame(1, \count(self::pendingInterrupts($graph, $config)));
        self::assertArrayNotHasKey('response_schema', self::pendingInterrupts($graph, $config)[0]);

        $resumed = $graph->invoke(new Command(resume: ['approved' => true, 'extra' => 1]), $config);

        self::assertSame(['approved' => true, 'extra' => 1], $resumed['answer']);
        unset($expected);
    }

    public function testAJsonSchemaResponseSchemaIsSurfacedOnTheInterruptAndOnTheSnapshot(): void
    {
        $schema = ['type' => 'object', 'properties' => ['approved' => ['type' => 'boolean']]];

        foreach ([new InterruptOptions(responseSchema: $schema), ['responseSchema' => $schema]] as $options) {
            $graph = self::askGraph($options);
            $config = self::config();

            $chunks = self::collect($graph, ['answer' => null], $config);

            self::assertSame($schema, self::interruptsIn($chunks)[0]['response_schema']);
            self::assertSame($schema, self::pendingInterrupts($graph, $config)[0]['response_schema']);
            self::assertSame(
                ['approved' => true, 'extra' => 1],
                $graph->invoke(new Command(resume: ['approved' => true, 'extra' => 1]), $config)['answer'],
                'a JSON Schema is passed through, the resume value is not validated against it',
            );
        }
    }

    public function testGraphInterruptKeepsTheResponseSchema(): void
    {
        $e = new GraphInterrupt([['id' => 'i', 'value' => 'v', 'response_schema' => ['type' => 'string']]]);

        self::assertSame([['id' => 'i', 'value' => 'v', 'response_schema' => ['type' => 'string']]], $e->interrupts);
        self::assertSame([['id' => 'i', 'value' => 'v']], (new GraphInterrupt([['id' => 'i', 'value' => 'v']]))->interrupts);
    }

    // ---- "should handle dynamic interrupt" ---------------------------------

    private static function dynamicGraph(?MemorySaver $saver, int &$nodeCount): object
    {
        $schema = Annotation::root([
            'my_key' => Annotation::withReducer(static fn ($a, $b) => $a . $b, static fn (): string => ''),
            'market' => Annotation::last(),
        ]);

        $graph = (new StateGraph($schema))
            ->addNode('tool_two', static function (array $s) use (&$nodeCount): array {
                $nodeCount++;

                return ['my_key' => $s['market'] === 'DE' ? interrupt('Just because...') : ' all good'];
            })
            ->addEdge(Constants::START, 'tool_two');

        return $saver === null ? $graph->compile() : $graph->compile(['checkpointer' => $saver]);
    }

    public function testInterruptWithoutACheckpointerFailsFastWithAStableErrorCode(): void
    {
        $count = 0;
        $graph = self::dynamicGraph(null, $count);

        try {
            $graph->invoke(['my_key' => 'value', 'market' => 'DE']);
            self::fail('expected "No checkpointer set"');
        } catch (GraphValueError $e) {
            self::assertSame('No checkpointer set', $e->getMessage());
            self::assertSame('MISSING_CHECKPOINTER', $e->getLcErrorCode());
        }

        self::assertSame(1, $count, 'a failed interrupt is not retried');
    }

    public function testTheNonInterruptingBranchRunsWithoutACheckpointer(): void
    {
        $count = 0;

        self::assertSame(
            ['my_key' => 'value all good', 'market' => 'US'],
            self::dynamicGraph(null, $count)->invoke(['my_key' => 'value', 'market' => 'US']),
        );
    }

    public function testDynamicInterruptPausesThenResumesWithTheCommandValue(): void
    {
        $count = 0;
        $graph = self::dynamicGraph(new MemorySaver(), $count);
        $thread = self::config('2');

        $chunks = self::collect($graph, ['my_key' => "value \u{26F0}\u{FE0F}", 'market' => 'DE'], $thread);

        self::assertSame('Just because...', self::interruptsIn($chunks)[0]['value']);
        $snapshot = $graph->getState($thread);
        self::assertSame(['tool_two'], $snapshot->next);
        self::assertSame(['my_key' => "value \u{26F0}\u{FE0F}", 'market' => 'DE'], $snapshot->values);
        self::assertSame('Just because...', $snapshot->tasks[0]->interrupts[0]['value']);
        self::assertSame(32, \strlen((string) $snapshot->tasks[0]->interrupts[0]['id']));

        $resumed = self::collect($graph, new Command(resume: ' this is great'), $thread);

        $updates = array_values(array_filter($resumed, static fn (array $c): bool => $c[0] === 'updates'));
        self::assertSame([['updates', ['tool_two' => ['my_key' => ' this is great']]]], $updates);
        self::assertSame(2, $count, 'the node re-executes from the top on resume');
        self::assertSame([], $graph->getState($thread)->next);
    }

    public function testTheInterruptIdIsTheHashOfTheTasksCheckpointNamespace(): void
    {
        $ns = null;
        $graph = (new StateGraph(Annotation::root(['answer' => Annotation::last()])))
            ->addNode('node', static function () use (&$ns): array {
                $ns = PregelScratchpad::currentConfig()?->configurable[Constants::CONFIG_KEY_CHECKPOINT_NS];

                return ['answer' => interrupt('q')];
            })
            ->addEdge(Constants::START, 'node')
            ->compile(['checkpointer' => new MemorySaver()]);

        $chunks = self::collect($graph, ['answer' => null], self::config());

        self::assertStringStartsWith('node:', (string) $ns);
        self::assertSame(Hash::xxh3((string) $ns), self::interruptsIn($chunks)[0]['id']);
    }

    public function testTheInterruptIdIsStableAcrossAResumeAttempt(): void
    {
        $graph = self::askGraph();
        $config = self::config();

        $first = self::interruptsIn(self::collect($graph, ['answer' => null], $config));
        $again = self::pendingInterrupts($graph, $config);

        self::assertSame($first[0]['id'], $again[0]['id']);
    }

    // ---- multiple interrupts in one node ------------------------------------

    private static function twoQuestionGraph(int &$runs): object
    {
        return (new StateGraph(Annotation::root(['answer' => Annotation::last()])))
            ->addNode('node', static function () use (&$runs): array {
                $runs++;
                $first = interrupt('first');
                $second = interrupt('second');

                return ['answer' => [$first, $second]];
            })
            ->addEdge(Constants::START, 'node')
            ->compile(['checkpointer' => new MemorySaver()]);
    }

    public function testEachResumeAnswersTheNextInterruptInTheNode(): void
    {
        $runs = 0;
        $graph = self::twoQuestionGraph($runs);
        $config = self::config();

        self::assertSame('first', self::interruptsIn(self::collect($graph, ['answer' => null], $config))[0]['value']);

        $afterOne = self::collect($graph, new Command(resume: 'A'), $config);
        self::assertSame(['second'], array_column(self::interruptsIn($afterOne), 'value'), 'the first answer is consumed, the second question is asked');
        self::assertSame(['second'], array_column(self::pendingInterrupts($graph, $config), 'value'));

        $afterTwo = $graph->invoke(new Command(resume: 'B'), $config);

        self::assertSame(['A', 'B'], $afterTwo['answer']);
        self::assertSame(3, $runs, 'one run per question plus the run that finishes');
        self::assertSame([], $graph->getState($config)->next);
    }

    public function testTheAnswerToAnEarlierInterruptIsNotRepeatedForALaterOne(): void
    {
        $runs = 0;
        $graph = self::twoQuestionGraph($runs);
        $config = self::config();
        self::collect($graph, ['answer' => null], $config);
        self::collect($graph, new Command(resume: 'A'), $config);

        $result = $graph->invoke(new Command(resume: 'B'), $config);

        self::assertNotSame($result['answer'][0], $result['answer'][1]);
    }

    public function testTheResumeValueSoFarIsPersistedAgainstTheTask(): void
    {
        $runs = 0;
        $graph = self::twoQuestionGraph($runs);
        $config = self::config();
        self::collect($graph, ['answer' => null], $config);
        self::collect($graph, new Command(resume: 'A'), $config);

        $tuple = $graph->checkpointer->getTuple($config->configurable);
        $resumeWrites = array_values(array_filter(
            $tuple->pendingWrites,
            static fn (array $w): bool => $w[1] === Constants::RESUME && $w[0] !== Constants::NULL_TASK_ID,
        ));

        self::assertCount(1, $resumeWrites);
        self::assertSame(['A'], $resumeWrites[0][2]);
    }

    public function testResumeValuesKeyedByInterruptIdAnswerSequentialInterrupts(): void
    {
        $runs = 0;
        $graph = self::twoQuestionGraph($runs);
        $config = self::config();
        $id = self::interruptsIn(self::collect($graph, ['answer' => null], $config))[0]['id'];

        self::collect($graph, new Command(resume: [$id => 'A']), $config);
        $result = $graph->invoke(new Command(resume: [$id => 'B']), $config);

        self::assertSame(['A', 'B'], $result['answer']);
    }

    public function testAStaleGraphWideResumeIsNotReusedByTheNextInterrupt(): void
    {
        $runs = 0;
        $graph = self::twoQuestionGraph($runs);
        $config = self::config();
        self::collect($graph, ['answer' => null], $config);

        $second = self::collect($graph, new Command(resume: 'only-one'), $config);

        self::assertSame(['second'], array_column(self::interruptsIn($second), 'value'), 'one resume value answers one question');
    }

    // ---- "resume multiple interrupts" (parallel Sends into a subgraph) -------

    public function testParallelSubgraphInterruptsAreAnsweredTogetherByIdMap(): void
    {
        $child = (new StateGraph(Annotation::root([
            'prompt' => Annotation::last(),
            'humanInput' => Annotation::last(),
            'humanInputs' => Annotation::last(),
        ])))
            ->addNode('getHumanInput', static function (array $state): array {
                $humanInput = interrupt($state['prompt']);

                return ['humanInput' => $humanInput, 'humanInputs' => [$humanInput]];
            })
            ->addEdge(Constants::START, 'getHumanInput')
            ->compile();

        $graph = (new StateGraph(Annotation::root([
            'prompts' => Annotation::last(),
            'humanInputs' => Annotation::withReducer(static fn ($a, $b) => array_merge($a, $b), static fn (): array => []),
        ])))
            ->addNode('childGraph', $child)
            ->addNode('cleanup', static function (array $state): array {
                if (\count($state['humanInputs']) !== \count($state['prompts'])) {
                    throw new \RuntimeException('cleanup ran before every prompt was answered');
                }

                return [];
            })
            ->addConditionalEdges(
                Constants::START,
                static fn (array $s): array => array_map(
                    static fn (string $prompt): Send => new Send('childGraph', ['prompt' => $prompt]),
                    $s['prompts'],
                ),
                ['childGraph'],
            )
            ->addEdge('childGraph', 'cleanup')
            ->addEdge('cleanup', Constants::END)
            ->compile(['checkpointer' => new MemorySaver()]);

        $config = self::config();
        $prompts = ['a', 'b', 'c', 'd', 'e'];

        $interrupts = self::interruptsIn(self::collect($graph, ['prompts' => $prompts], $config));

        self::assertSame($prompts, array_column($interrupts, 'value'));
        self::assertCount(5, array_unique(array_column($interrupts, 'id')), 'every parallel task gets its own id');
        self::assertSame($interrupts, self::pendingInterrupts($graph, $config), 'getState reports what the stream reported');

        $resume = [];
        foreach ($interrupts as $interrupt) {
            $resume[$interrupt['id']] = 'response: ' . $interrupt['value'];
        }

        self::assertSame(
            [
                'prompts' => $prompts,
                'humanInputs' => ['response: a', 'response: b', 'response: c', 'response: d', 'response: e'],
            ],
            $graph->invoke(new Command(resume: $resume), $config),
        );
    }

    // ---- subgraphs -------------------------------------------------------------

    public function testInterruptAndResumeWithCommandInsideASubgraph(): void
    {
        $messages = static fn () => Annotation::root(['messages' => Annotation::appendable()]);

        $subgraph = (new StateGraph($messages()))
            ->addNode('one', static function (): array {
                if (interrupt('<INTERRUPTED>') !== '<RESUMED>') {
                    throw new \RuntimeException('Expected interrupt to return <RESUMED>');
                }

                return ['messages' => ['success']];
            })
            ->addEdge(Constants::START, 'one')
            ->compile();

        $graph = (new StateGraph($messages()))
            ->addNode('one', static fn (): array => [])
            ->addNode('subgraph', $subgraph)
            ->addNode('two', static function (array $state): array {
                if (\count($state['messages']) !== 1) {
                    throw new \RuntimeException('Expected 1 message, got ' . \count($state['messages']));
                }

                return [];
            })
            ->addEdge(Constants::START, 'one')
            ->addEdge('one', 'subgraph')
            ->addEdge('subgraph', 'two')
            ->addEdge('two', Constants::END)
            ->compile(['checkpointer' => new MemorySaver()]);

        $config = self::config('test_subgraph_interrupt_resume');

        $graph->invoke(['messages' => []], $config);

        $tasks = $graph->getState($config)->tasks;
        self::assertCount(1, $tasks[0]->interrupts);
        self::assertSame('<INTERRUPTED>', $tasks[0]->interrupts[0]['value']);

        $result = $graph->invoke(new Command(resume: '<RESUMED>'), $config);

        self::assertSame([], $graph->getState($config)->tasks);
        self::assertSame(['success'], $result['messages']);
    }

    public function testASubgraphPersistsUnderItsOwnNamespaceAndRaisesAnIdDerivedFromIt(): void
    {
        $subRuns = 0;
        $subNs = null;
        $subgraph = (new StateGraph(Annotation::root(['answer' => Annotation::last()])))
            ->addNode('ask', static function () use (&$subRuns, &$subNs): array {
                $subRuns++;
                $subNs = PregelScratchpad::currentConfig()?->configurable[Constants::CONFIG_KEY_CHECKPOINT_NS];

                return ['answer' => interrupt('sub-question')];
            })
            ->addEdge(Constants::START, 'ask')
            ->compile();

        $saver = new MemorySaver();
        $graph = (new StateGraph(Annotation::root(['answer' => Annotation::last()])))
            ->addNode('sub', $subgraph)
            ->addEdge(Constants::START, 'sub')
            ->compile(['checkpointer' => $saver]);

        $config = self::config();
        $interrupts = self::interruptsIn(self::collect($graph, ['answer' => null], $config));

        $namespaces = array_values(array_unique(array_map(
            static fn ($t): string => (string) ($t->config['configurable']['checkpoint_ns'] ?? ''),
            $saver->list(['thread_id' => '1']),
        )));
        sort($namespaces);
        self::assertCount(2, $namespaces, 'the root graph and the subgraph each have checkpoints');
        self::assertSame('', $namespaces[0]);
        self::assertStringStartsWith('sub:', $namespaces[1]);

        self::assertStringContainsString('|ask:', (string) $subNs, 'the node sits inside the subgraph namespace');
        self::assertSame(Hash::xxh3((string) $subNs), $interrupts[0]['id']);

        $result = $graph->invoke(new Command(resume: 'answer'), $config);

        self::assertSame('answer', $result['answer']);
        self::assertSame(2, $subRuns, 'raised once, re-executed once on resume');
    }

    public function testDoublyNestedGraphInterrupts(): void
    {
        $schema = static fn () => Annotation::root(['myKey' => Annotation::last()]);

        $grandchild = (new StateGraph($schema()))
            ->addNode('grandchild1', static fn (array $s): array => ['myKey' => $s['myKey'] . ' here'])
            ->addNode('grandchild2', static fn (array $s): array => ['myKey' => $s['myKey'] . ' and there'])
            ->addEdge(Constants::START, 'grandchild1')
            ->addEdge('grandchild1', 'grandchild2');

        $child = (new StateGraph($schema()))
            ->addNode('child1', $grandchild->compile(['interruptBefore' => ['grandchild2']]))
            ->addEdge(Constants::START, 'child1');

        $build = static fn (): object => (new StateGraph($schema()))
            ->addNode('parent1', static fn (array $s): array => ['myKey' => 'hi ' . $s['myKey']])
            ->addNode('child', $child->compile())
            ->addNode('parent2', static fn (array $s): array => ['myKey' => $s['myKey'] . ' and back again'])
            ->addEdge(Constants::START, 'parent1')
            ->addEdge('parent1', 'child')
            ->addEdge('child', 'parent2')
            ->compile(['checkpointer' => new MemorySaver()]);

        // updates
        $app = $build();
        $config = self::config('2');
        $updates = static fn (array $chunks): array => array_values(array_map(
            static fn (array $c): mixed => $c[1],
            array_filter($chunks, static fn (array $c): bool => $c[0] === 'updates'),
        ));

        self::assertSame(
            [['parent1' => ['myKey' => 'hi my value']], [Constants::INTERRUPT => []]],
            $updates(self::collect($app, ['myKey' => 'my value'], $config)),
        );
        self::assertSame(
            [
                ['child' => ['myKey' => 'hi my value here and there']],
                ['parent2' => ['myKey' => 'hi my value here and there and back again']],
            ],
            $updates(self::collect($app, null, $config)),
        );

        // values
        $app = $build();
        $config = self::config('3');
        $values = static fn (array $chunks): array => array_values(array_map(
            static fn (array $c): mixed => $c[1],
            array_filter($chunks, static fn (array $c): bool => $c[0] === 'values'),
        ));

        self::assertSame(
            [['myKey' => 'my value'], ['myKey' => 'hi my value'], [Constants::INTERRUPT => []]],
            $values(self::collect($app, ['myKey' => 'my value'], $config)),
        );
        self::assertSame(
            [
                ['myKey' => 'hi my value'],
                ['myKey' => 'hi my value here and there'],
                ['myKey' => 'hi my value here and there and back again'],
            ],
            $values(self::collect($app, null, $config)),
        );

        // invoke
        $app = $build();
        $config = self::config('1');
        $app->invoke(['myKey' => 'my value'], $config);
        self::assertSame(
            ['myKey' => 'hi my value here and there and back again'],
            $app->invoke(null, $config),
        );
    }

    // ---- engine regressions found while porting --------------------------------

    public function testResumingDoesNotMoveTheCheckpointsStepOrItsTaskIds(): void
    {
        $runs = 0;
        $graph = self::twoQuestionGraph($runs);
        $config = self::config();
        $saver = $graph->checkpointer;

        self::collect($graph, ['answer' => null], $config);
        $head = $saver->getTuple($config->configurable);
        $taskIdBefore = $graph->getState($config)->tasks[0]->id;

        self::collect($graph, new Command(resume: 'A'), $config);
        $headAfter = $saver->getTuple($config->configurable);

        self::assertSame($head->checkpoint->id, $headAfter->checkpoint->id);
        self::assertSame($head->metadata['step'], $headAfter->metadata['step'], 'an exiting save re-puts the checkpoint, it does not advance it');
        self::assertSame($taskIdBefore, $graph->getState($config)->tasks[0]->id);
    }

    public function testInterruptOnlyWritesDoNotMarkATaskAsFinished(): void
    {
        // The task that raised the interrupt has an INTERRUPT write and no result. It must be
        // prepared again on resume - the re-execution is how interrupt() returns its answer.
        $runs = 0;
        $graph = self::twoQuestionGraph($runs);
        $config = self::config();
        self::collect($graph, ['answer' => null], $config);

        self::assertSame(['node'], $graph->getState($config)->next);

        self::collect($graph, new Command(resume: 'A'), $config);

        self::assertSame(['node'], $graph->getState($config)->next, 'still pending, now on the second question');
    }
}
