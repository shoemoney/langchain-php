<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Tracers;

use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\LanguageModels\Outputs\LLMResult;
use LangChain\Messages\HumanMessage;
use LangChain\Schema\Document;
use LangChain\Tracers\BaseCallbackHandler;
use LangChain\Tracers\BaseRunManager;
use LangChain\Tracers\BaseTracer;
use LangChain\Tracers\CallbackHandler;
use LangChain\Tracers\CallbackManager;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangChain\Tracers\CallbackManagerForToolRun;
use LangChain\Tracers\ConsoleCallbackHandler;
use LangChain\Tracers\Run;
use LangChain\Tracers\RunCollectorCallbackHandler;
use LangChain\Tracers\RunEvent;
use LangChain\Tracers\Serialized;
use LangChain\Utils\Testing\FakeTracer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of `tracers/tests/tracer.test.ts`, `callbacks/tests/manager.test.ts`, and
 * `callbacks/tests/run_collector.test.ts`.
 *
 * The behaviour under test is the two-phase run protocol. A run is recorded on
 * start and only *filed* on end, and which of those happens depends on whether
 * its parent is still open. Get that wrong and either a half-finished run
 * reaches a trace, or a complete one never does — and both look like "the trace
 * is a bit off" rather than like an error.
 */
#[CoversClass(BaseTracer::class)]
#[CoversClass(Run::class)]
#[CoversClass(RunEvent::class)]
#[CoversClass(Serialized::class)]
#[CoversClass(BaseCallbackHandler::class)]
#[CoversClass(CallbackHandler::class)]
#[CoversClass(CallbackManager::class)]
#[CoversClass(CallbackManagerForLLMRun::class)]
#[CoversClass(CallbackManagerForToolRun::class)]
#[CoversClass(BaseRunManager::class)]
#[CoversClass(ConsoleCallbackHandler::class)]
#[CoversClass(RunCollectorCallbackHandler::class)]
#[CoversClass(FakeTracer::class)]
final class TracersTest extends TestCase
{
    private static function serialized(array $id = ['test']): Serialized
    {
        return new Serialized($id);
    }

    protected function setUp(): void
    {
        BaseRunManager::clearHandlerErrors();
    }

    // ---- single runs of each kind ---------------------------------------

    public function testAnLLMRunIsRecordedWithBothEvents(): void
    {
        $tracer = new FakeTracer();
        $runId = 'run-1';

        $tracer->handleLLMStart(self::serialized(), ['test'], $runId);
        $tracer->handleLLMEnd(new LLMResult([[]]), $runId);

        $this->assertCount(1, $tracer->runs);
        $run = $tracer->runs[0];

        $this->assertSame($runId, $run->id);
        $this->assertSame('test', $run->name());
        $this->assertSame(1, $run->executionOrder);
        $this->assertSame(1, $run->childExecutionOrder);
        $this->assertSame(['prompts' => ['test']], $run->inputs);
        $this->assertSame(['generations' => [[]]], $run->outputs);
        $this->assertSame('llm', $run->runType);
        $this->assertSame([], $run->childRuns);
        $this->assertSame([], $run->tags);
        $this->assertNotNull($run->endTime);
        $this->assertSame(['start', 'end'], array_map(
            static fn (RunEvent $e): string => $e->name,
            $run->events,
        ));
    }

    public function testAChatModelRunStoresItsMessagesStructured(): void
    {
        $tracer = new FakeTracer();
        $runId = 'run-1';

        $tracer->handleChatModelStart(self::serialized(), [[new HumanMessage('Avast')]], $runId);
        $tracer->handleLLMEnd(new LLMResult([[]]), $runId);

        $this->assertCount(1, $tracer->runs);
        $inputs = $tracer->runs[0]->inputs;

        // Messages stay as messages. Serialising them to a transcript here would
        // make every chat run's inputs a string blob.
        $this->assertArrayHasKey('messages', $inputs);
        $this->assertInstanceOf(HumanMessage::class, $inputs['messages'][0][0]);
        $this->assertSame('Avast', $inputs['messages'][0][0]->text());
        $this->assertSame('llm', $tracer->runs[0]->runType);
    }

    public function testEndingARunThatNeverStartedIsAnError(): void
    {
        // Silently ignoring this would leave the caller believing a run was
        // traced when nothing was recorded.
        $tracer = new FakeTracer();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No LLM run to end.');
        $tracer->handleLLMEnd(new LLMResult([[]]), 'never-started');
    }

    public function testAChainRunRecordsInputsAndOutputs(): void
    {
        $tracer = new FakeTracer();

        $tracer->handleChainStart(self::serialized(), ['foo' => 'bar'], 'chain');
        $tracer->handleChainEnd(['foo' => 'bar'], 'chain');

        $run = $tracer->runs[0];
        $this->assertSame('chain', $run->runType);
        $this->assertSame(['foo' => 'bar'], $run->inputs);
        $this->assertSame(['foo' => 'bar'], $run->outputs);
    }

    public function testAToolRunRecordsItsInputAndOutput(): void
    {
        $tracer = new FakeTracer();

        $tracer->handleToolStart(self::serialized(), 'test', 'tool');
        $tracer->handleToolEnd('output', 'tool');

        $run = $tracer->runs[0];
        $this->assertSame('tool', $run->runType);
        // A string input is wrapped so the run always has an object-shaped
        // `inputs`, which is what the trace schema requires.
        $this->assertSame(['input' => 'test'], $run->inputs);
        $this->assertSame(['output' => 'output'], $run->outputs);
    }

    public function testARetrieverRunRecordsItsDocuments(): void
    {
        $tracer = new FakeTracer();
        $document = new Document('test', ['test' => 'test']);

        $tracer->handleRetrieverStart(self::serialized(), 'bar', 'retriever');
        $tracer->handleRetrieverEnd([$document], 'retriever');

        $run = $tracer->runs[0];
        $this->assertSame('retriever', $run->runType);
        $this->assertSame(['query' => 'bar'], $run->inputs);
        $this->assertSame(['documents' => [$document]], $run->outputs);
    }

    public function testAToolRunWithStructuredInputKeepsItStructured(): void
    {
        $tracer = new FakeTracer();

        $tracer->handleToolStart(self::serialized(), ['command' => 'ls'], 'tool');
        $tracer->handleToolEnd('output', 'tool');

        $this->assertSame(['command' => 'ls'], $tracer->runs[0]->inputs);
    }

    // ---- nesting ---------------------------------------------------------

    public function testNestedRunsFormATreeAndOnlyTheRootIsPersisted(): void
    {
        $tracer = new FakeTracer();

        $tracer->handleChainStart(self::serialized(), ['foo' => 'bar'], 'chain');
        $tracer->handleToolStart(self::serialized(['test_tool']), 'test', 'tool', 'chain');
        $tracer->handleLLMStart(self::serialized(['test_llm']), ['test'], 'llm', 'tool');
        $tracer->handleLLMEnd(new LLMResult([[]]), 'llm');
        $tracer->handleToolEnd('output', 'tool');
        $tracer->handleLLMStart(self::serialized(['test_llm2']), ['test'], 'llm2', 'chain');
        $tracer->handleLLMEnd(new LLMResult([[]]), 'llm2');
        $tracer->handleChainEnd(['foo' => 'bar'], 'chain');

        // One run, three levels deep. Persisting the children as siblings too
        // would lose the causality entirely.
        $this->assertCount(1, $tracer->runs);

        $root = $tracer->runs[0];
        $this->assertSame('chain', $root->id);
        $this->assertSame('chain', $root->runType);
        $this->assertCount(2, $root->childRuns);
        // The parent's counter reflects the highest child order seen, so a later
        // sibling always sorts after an earlier one.
        $this->assertSame(4, $root->childExecutionOrder);

        $tool = $root->childRuns[0];
        $this->assertSame('tool', $tool->id);
        $this->assertSame('chain', $tool->parentRunId);
        $this->assertSame('test_tool', $tool->name());
        $this->assertSame(2, $tool->executionOrder);
        $this->assertCount(1, $tool->childRuns);

        $llm = $tool->childRuns[0];
        $this->assertSame('llm', $llm->id);
        $this->assertSame('tool', $llm->parentRunId);
        $this->assertSame(3, $llm->executionOrder);
    }

    public function testANestedRunInheritsTheRootTraceId(): void
    {
        $tracer = new FakeTracer();

        $tracer->handleChainStart(self::serialized(), [], 'chain');
        $tracer->handleToolStart(self::serialized(['test_tool']), 'test', 'tool', 'chain');
        $tracer->handleToolEnd('output', 'tool');
        $tracer->handleChainEnd([], 'chain');

        $root = $tracer->runs[0];
        $child = $root->childRuns[0];

        // The trace id is what a backend groups by; a child that minted its own
        // would show up as an unrelated run.
        $this->assertSame('chain', $child->traceId);
        $this->assertSame('chain', $root->traceId);
    }

    public function testChildDottedOrdersExtendTheirParents(): void
    {
        $tracer = new FakeTracer();

        $tracer->handleChainStart(self::serialized(), [], 'chain');
        $tracer->handleToolStart(self::serialized(['test_tool']), 'test', 'tool', 'chain');
        $tracer->handleToolEnd('output', 'tool');
        $tracer->handleChainEnd([], 'chain');

        $root = $tracer->runs[0];
        $child = $root->childRuns[0];

        // A child's key is its parent's key plus its own, so sorting by
        // dotted order is a pre-order walk with no pointer chasing.
        $this->assertStringStartsWith((string) $root->dottedOrder . '.', (string) $child->dottedOrder);
        $this->assertStringEndsWith('Ztool', (string) $child->dottedOrder);
    }

    public function testDottedOrdersSortIntoTreeOrder(): void
    {
        $tracer = new FakeTracer();

        $tracer->handleChainStart(self::serialized(), [], 'chain');
        $tracer->handleToolStart(self::serialized(['a']), 'test', 'a', 'chain');
        $tracer->handleToolEnd('out', 'a');
        $tracer->handleToolStart(self::serialized(['b']), 'test', 'b', 'chain');
        $tracer->handleToolEnd('out', 'b');
        $tracer->handleChainEnd([], 'chain');

        $orders = array_map(
            static fn (Run $r): ?string => $r->dottedOrder,
            $tracer->runs[0]->childRuns,
        );

        $sorted = $orders;
        sort($sorted, \SORT_STRING);
        $this->assertSame($orders, $sorted);
        $this->assertNotSame($orders[0], $orders[1]);
    }

    public function testASiblingAfterAPersistedParentIsPersistedAsItsOwnRoot(): void
    {
        $tracer = new FakeTracer();

        // A root run, then a child whose parent is already closed. Persisting
        // the first removes it from the open map, so the child finds no parent.
        $tracer->handleLLMStart(self::serialized(), ['a'], 'a');
        $tracer->handleLLMEnd(new LLMResult([[]]), 'a');
        $tracer->handleLLMStart(self::serialized(), ['b'], 'b');
        $tracer->handleLLMEnd(new LLMResult([[]]), 'b');

        // Two roots, not one nested. The alternative — a run with a
        // `parent_run_id` and no dotted order — is rejected outright downstream.
        $this->assertCount(2, $tracer->runs);
        $this->assertNull($tracer->runs[1]->parentRunId);
        $this->assertSame('b', $tracer->runs[1]->traceId);
    }

    public function testAnOrphanChildIsDroppedFromItsParentAndPersisted(): void
    {
        $tracer = new FakeTracer();

        // The parent is never started, so the child cannot be nested anywhere.
        $tracer->handleToolStart(self::serialized(['orphan']), 'test', 'orphan-tool', 'never-started');
        $tracer->handleToolEnd('output', 'orphan-tool');

        $this->assertCount(1, $tracer->runs);
        $this->assertNull($tracer->runs[0]->parentRunId);
        $this->assertNotNull($tracer->runs[0]->dottedOrder);
    }

    public function testACompletedRunIsRemovedFromTheOpenMap(): void
    {
        $tracer = new FakeTracer();

        $tracer->handleLLMStart(self::serialized(), ['a'], 'a');
        $tracer->handleLLMEnd(new LLMResult([[]]), 'a');
        // Ending it twice would persist it twice, corrupting every count
        // downstream.
        $this->expectException(\RuntimeException::class);
        $tracer->handleLLMEnd(new LLMResult([[]]), 'a');
    }

    // ---- errors and tokens -----------------------------------------------

    public function testAFailedRunRecordsItsError(): void
    {
        $tracer = new FakeTracer();

        $tracer->handleLLMStart(self::serialized(), ['a'], 'a');
        $tracer->handleLLMError(new \RuntimeException('provider exploded'), 'a');

        $run = $tracer->runs[0];
        $this->assertStringContainsString('provider exploded', (string) $run->error);
        $this->assertSame(['start', 'error'], array_map(static fn (RunEvent $e): string => $e->name, $run->events));
    }

    public function testAFailedToolRunRecordsItsError(): void
    {
        $tracer = new FakeTracer();

        $tracer->handleToolStart(self::serialized(['t']), 'test', 't');
        $tracer->handleToolError(new \RuntimeException('tool exploded'), 't');

        $this->assertStringContainsString('tool exploded', (string) $tracer->runs[0]->error);
        $this->assertSame('tool', $tracer->runs[0]->runType);
    }

    public function testAFailedRetrieverRunRecordsItsError(): void
    {
        $tracer = new FakeTracer();

        $tracer->handleRetrieverStart(self::serialized(), 'q', 'r');
        $tracer->handleRetrieverError(new \RuntimeException('retriever exploded'), 'r');

        $this->assertStringContainsString('retriever exploded', (string) $tracer->runs[0]->error);
    }

    public function testTokenEventsAreRecordedWithTheirIndex(): void
    {
        $tracer = new FakeTracer();

        $tracer->handleLLMStart(self::serialized(), ['a'], 'a');
        $tracer->handleLLMNewToken('Hel', ['prompt' => 0, 'completion' => 0], 'a');

        // The run is still open, so nothing is persisted yet.
        $this->assertCount(0, $tracer->runs);

        $tracer->handleLLMEnd(new LLMResult([[]]), 'a');
        $events = $tracer->runs[0]->events;
        $tokenEvent = $events[1];

        $this->assertSame('new_token', $tokenEvent->name);
        $this->assertSame('Hel', $tokenEvent->kwargs['token']);
        $this->assertSame(['prompt' => 0, 'completion' => 0], $tokenEvent->kwargs['idx']);
    }

    public function testATokenForAnUnknownRunIsAnError(): void
    {
        $tracer = new FakeTracer();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid "runId" provided to "handleLLMNewToken" callback.');
        $tracer->handleLLMNewToken('x', ['prompt' => 0, 'completion' => 0], 'nope');
    }

    public function testATokenEventCanCarryItsChunk(): void
    {
        $tracer = new FakeTracer();
        $chunk = new ChatGenerationChunk(new \LangChain\Messages\AIMessageChunk('Hel'), 'Hel');

        $tracer->handleLLMStart(self::serialized(), ['a'], 'a');
        $tracer->handleLLMNewToken('Hel', ['prompt' => 0, 'completion' => 0], 'a', null, [], ['chunk' => $chunk]);
        $tracer->handleLLMEnd(new LLMResult([[]]), 'a');

        $this->assertSame($chunk, $tracer->runs[0]->events[1]->kwargs['chunk']);
    }

    public function testTextAndAgentEventsOnlyApplyToChainRuns(): void
    {
        $tracer = new FakeTracer();

        // Text on an LLM run has no event log to append to, and that is not an
        // error worth raising — text is chatty and best-effort.
        $tracer->handleLLMStart(self::serialized(), ['a'], 'a');
        $tracer->handleText('ignored', 'a');
        $tracer->handleAgentAction(['tool' => 'x'], 'a');
        $tracer->handleLLMEnd(new LLMResult([[]]), 'a');

        $this->assertSame(['start', 'end'], array_map(
            static fn (RunEvent $e): string => $e->name,
            $tracer->runs[0]->events,
        ));
    }

    public function testAnAgentActionIsRecordedOnAChainRun(): void
    {
        $tracer = new FakeTracer();

        $tracer->handleChainStart(self::serialized(), [], 'chain');
        $tracer->handleAgentAction(['tool' => 'search'], 'chain');
        $tracer->handleAgentEnd(['output' => 'done'], 'chain');
        $tracer->handleChainEnd([], 'chain');

        $run = $tracer->runs[0];
        $this->assertSame([['tool' => 'search']], $run->actions);
        $this->assertSame(
            ['start', 'agent_action', 'agent_end', 'end'],
            array_map(static fn (RunEvent $e): string => $e->name, $run->events),
        );
    }

    public function testMetadataIsFiledUnderExtra(): void
    {
        $tracer = new FakeTracer();

        $tracer->handleLLMStart(self::serialized(), ['a'], 'a', null, [], ['env' => 'test'], ['user' => 'u1']);
        $tracer->handleLLMEnd(new LLMResult([[]]), 'a');

        // The run schema has no top-level `metadata`, so it goes in `extra`.
        $this->assertSame(['user' => 'u1'], $tracer->runs[0]->extra['metadata']);
        $this->assertSame(['env' => 'test'], $tracer->runs[0]->tags);
    }

    public function testAnExplicitRunNameOverridesTheComponentName(): void
    {
        $tracer = new FakeTracer();

        $tracer->handleLLMStart(self::serialized(['Component']), ['a'], 'a', null, [], [], [], 'my-run');
        $tracer->handleLLMEnd(new LLMResult([[]]), 'a');

        $this->assertSame('my-run', $tracer->runs[0]->name());
    }

    public function testExtraParamsAreMergedIntoExtraOnEnd(): void
    {
        $tracer = new FakeTracer();

        $tracer->handleLLMStart(self::serialized(), ['a'], 'a');
        $tracer->handleLLMEnd(new LLMResult([[]]), 'a', null, [], ['cached' => true]);

        $this->assertTrue($tracer->runs[0]->extra['cached']);
    }

    // ---- run collector ---------------------------------------------------

    public function testTheRunCollectorStampsTheExampleId(): void
    {
        $collector = new RunCollectorCallbackHandler('example-42');

        $collector->handleLLMStart(self::serialized(), ['a'], 'a');
        $collector->handleLLMEnd(new LLMResult([[]]), 'a');

        $this->assertCount(1, $collector->tracedRuns);
        $this->assertSame('example-42', $collector->tracedRuns[0]->extra['reference_example_id']);
    }

    public function testTheRunCollectorNestsChildrenBeforePersisting(): void
    {
        $collector = new RunCollectorCallbackHandler();

        $collector->handleChainStart(self::serialized(), [], 'chain');
        $collector->handleLLMStart(self::serialized(['llm']), ['a'], 'llm', 'chain');
        $collector->handleLLMEnd(new LLMResult([[]]), 'llm');
        $collector->handleChainEnd([], 'chain');

        // Still one run: the child was nested, not persisted separately.
        $this->assertCount(1, $collector->tracedRuns);
        $this->assertCount(1, $collector->tracedRuns[0]->childRuns);
    }

    public function testTheExampleIdIsNullByDefaultRatherThanAbsent(): void
    {
        $collector = new RunCollectorCallbackHandler();

        $collector->handleLLMStart(self::serialized(), ['a'], 'a');
        $collector->handleLLMEnd(new LLMResult([[]]), 'a');

        // Present-but-null so a consumer need not branch on key existence.
        $this->assertArrayHasKey('reference_example_id', $collector->tracedRuns[0]->extra);
        $this->assertNull($collector->tracedRuns[0]->extra['reference_example_id']);
    }

    // ---- callback manager ------------------------------------------------

    public function testConfiguringWithNoArgumentsYieldsNoManager(): void
    {
        // The null is load-bearing: components skip callback plumbing entirely
        // when it is null, which is the common case.
        $this->assertNull(CallbackManager::configure());
    }

    public function testConfiguringWithAnEmptyArrayYieldsAnEmptyManager(): void
    {
        $manager = CallbackManager::configure([]);

        $this->assertNotNull($manager);
        $this->assertCount(0, $manager->handlers);
    }

    public function testConfiguringWithOneHandlerKeepsIt(): void
    {
        $handler = CallbackHandler::fromMethods([]);

        $manager = CallbackManager::configure([$handler]);

        $this->assertSame($handler, $manager?->handlers[0]);
    }

    public function testHandlersCanBeAddedRemovedAndReplaced(): void
    {
        $manager = new CallbackManager();
        $a = CallbackHandler::fromMethods([]);
        $b = CallbackHandler::fromMethods([]);

        $manager->addHandler($a);
        $manager->addHandler($b);
        $this->assertCount(2, $manager->handlers);

        $manager->removeHandler($a);
        $this->assertCount(1, $manager->handlers);
        $this->assertSame($b, $manager->handlers[0]);

        $manager->setHandlers([$a]);
        $this->assertSame([$a], $manager->handlers);
    }

    public function testANonInheritingHandlerIsNotPassedToChildren(): void
    {
        $manager = new CallbackManager();
        $local = CallbackHandler::fromMethods([]);
        $inherited = CallbackHandler::fromMethods([]);

        $manager->addHandler($local, false);
        $manager->addHandler($inherited, true);

        // A handler attached to one step must not start receiving events from
        // whatever that step happens to invoke.
        $this->assertCount(2, $manager->handlers);
        $this->assertSame([$inherited], $manager->inheritableHandlers);
    }

    public function testTagsAreDeduplicatedByAdd(): void
    {
        $manager = new CallbackManager();

        $manager->addTags(['a', 'b']);
        $manager->addTags(['a', 'c']);

        // Idempotent, so merging an inherited tag list with a local one cannot
        // double-count. Position is not preserved: `add` removes before pushing.
        $this->assertSame(['b', 'a', 'c'], $manager->tags);

        $manager->removeTags(['b']);
        $this->assertNotContains('b', $manager->tags);
    }

    public function testNonInheritingTagsStayLocal(): void
    {
        $manager = new CallbackManager();

        $manager->addTags(['shared']);
        $manager->addTags(['local'], false);

        $this->assertSame(['shared', 'local'], $manager->tags);
        $this->assertSame(['shared'], $manager->inheritableTags);
    }

    public function testMetadataCanBeAddedAndRemoved(): void
    {
        $manager = new CallbackManager();

        $manager->addMetadata(['a' => 1, 'b' => 2]);
        $manager->addMetadata(['c' => 3], false);

        $this->assertSame(['a' => 1, 'b' => 2, 'c' => 3], $manager->metadata);
        $this->assertSame(['a' => 1, 'b' => 2], $manager->inheritableMetadata);

        $manager->removeMetadata(['a' => 1]);
        $this->assertArrayNotHasKey('a', $manager->metadata);
        $this->assertArrayNotHasKey('a', $manager->inheritableMetadata);
    }

    public function testCopyPreservesTheInheritanceClassification(): void
    {
        $manager = new CallbackManager();
        $inheriting = CallbackHandler::fromMethods([]);
        $local = CallbackHandler::fromMethods([]);
        $manager->addHandler($inheriting, true);
        $manager->addHandler($local, false);

        $copy = $manager->copy();

        // Re-derived rather than copied wholesale: a child must not start
        // re-classifying handlers its parent had already decided about.
        $this->assertSame([$inheriting], $copy->inheritableHandlers);
        $this->assertCount(2, $copy->handlers);
    }

    public function testCopyAddsExtraHandlers(): void
    {
        $manager = new CallbackManager();
        $extra = CallbackHandler::fromMethods([]);

        $copy = $manager->copy([$extra]);

        $this->assertCount(1, $copy->handlers);
        $this->assertSame($extra, $copy->handlers[0]);
    }

    public function testVerboseConfigurationAddsAConsoleHandler(): void
    {
        $manager = CallbackManager::configure([], null, null, null, null, null, ['verbose' => true]);

        $this->assertNotNull($manager);
        $this->assertTrue($manager->hasHandlerNamed('console_callback_handler'));
    }

    public function testVerboseConfigurationDoesNotDoubleAddTheConsoleHandler(): void
    {
        $existing = new ConsoleCallbackHandler();

        $manager = CallbackManager::configure([$existing], null, null, null, null, null, ['verbose' => true]);

        $this->assertCount(1, $manager?->handlers);
    }

    public function testAnInheritedManagerIsReusedRatherThanRebuilt(): void
    {
        $inherited = new CallbackManager();
        $handler = CallbackHandler::fromMethods([]);
        $inherited->addHandler($handler);

        $manager = CallbackManager::configure($inherited);

        $this->assertNotNull($manager);
        $this->assertSame($handler, $manager->handlers[0]);
    }

    public function testAToolRunManagerCarriesTheToolCallId(): void
    {
        $received = null;
        $handler = CallbackHandler::fromMethods([
            'handleToolStart' => static function (
                Serialized $tool,
                array|string $input,
                string $runId,
                ?string $parentRunId,
                array $tags,
                array $metadata,
                ?string $runName,
                ?string $toolCallId,
            ) use (&$received): void {
                $received = $toolCallId;
            },
        ]);

        $manager = new CallbackManager();
        $manager->addHandler($handler);
        $runManager = $manager->handleToolStart(self::serialized(['weather']), 'test', null, [], [], null, 'call-7');

        $this->assertSame('call-7', $received);
        $this->assertInstanceOf(CallbackManagerForToolRun::class, $runManager);
    }

    public function testAStringToolInputReachesTheHandlerAsAString(): void
    {
        $received = null;
        $handler = CallbackHandler::fromMethods([
            'handleToolStart' => static function (Serialized $tool, array|string $input) use (&$received): void {
                $received = $input;
            },
        ]);

        $manager = new CallbackManager();
        $manager->addHandler($handler);
        $manager->handleToolStart(self::serialized(['weather']), ['location' => 'SF']);

        // The handler-facing input is always a string, even for a structured
        // tool — a handler logging events has no use for a nested structure.
        $this->assertSame('{"location":"SF"}', $received);
    }

    public function testEachPromptGetsItsOwnRunManager(): void
    {
        $manager = new CallbackManager();
        $runManagers = $manager->handleLLMStart(self::serialized(), ['a', 'b']);

        $this->assertCount(2, $runManagers);
        $this->assertNotSame($runManagers[0]->runId, $runManagers[1]->runId);
    }

    public function testACallerSuppliedRunIdAppliesToTheFirstPromptOnly(): void
    {
        $manager = new CallbackManager();
        $runManagers = $manager->handleLLMStart(self::serialized(), ['a', 'b'], 'caller-run');

        // Two runs sharing an id would collide in every tracer's run map.
        $this->assertSame('caller-run', $runManagers[0]->runId);
        $this->assertNotSame('caller-run', $runManagers[1]->runId);
    }

    public function testAChatModelStartFallsBackToTheLegacyLLMHook(): void
    {
        // A handler written against the older, string-only hook must still see
        // chat runs, rendered as a transcript.
        $received = null;
        $handler = CallbackHandler::fromMethods([
            'handleLLMStart' => static function (Serialized $llm, array $prompts) use (&$received): void {
                $received = $prompts;
            },
        ]);

        $manager = new CallbackManager();
        $manager->addHandler($handler);
        $manager->handleChatModelStart(self::serialized(), [[new HumanMessage('Avast')]]);

        $this->assertSame(['Human: Avast'], $received);
    }

    public function testAPreferChatModelHandlerTakesPrecedenceOverTheLegacyOne(): void
    {
        $chatReceived = null;
        $llmReceived = null;
        $handler = CallbackHandler::fromMethods([
            'handleChatModelStart' => static function () use (&$chatReceived): void {
                $chatReceived = true;
            },
            'handleLLMStart' => static function () use (&$llmReceived): void {
                $llmReceived = true;
            },
        ]);

        $manager = new CallbackManager();
        $manager->addHandler($handler);
        $manager->handleChatModelStart(self::serialized(), [[new HumanMessage('Avast')]]);

        $this->assertTrue($chatReceived);
        $this->assertNull($llmReceived);
    }

    // ---- handler failure containment -------------------------------------

    public function testAThrowingHandlerDoesNotBreakTheRun(): void
    {
        $events = [];
        $broken = CallbackHandler::fromMethods([
            'handleToolStart' => static function (): void {
                throw new \RuntimeException('observer exploded');
            },
        ]);
        $working = CallbackHandler::fromMethods([
            'handleToolStart' => static function () use (&$events): void {
                $events[] = 'start';
            },
        ]);

        $manager = new CallbackManager();
        $manager->addHandler($broken);
        $manager->addHandler($working);
        $manager->handleToolStart(self::serialized(['t']), 'test');

        // The observer is decoration. A broken one must not take down the run it
        // is observing, nor stop the healthy handlers behind it.
        $this->assertSame(['start'], $events);
        $this->assertNotSame([], BaseRunManager::handlerErrors());
        $this->assertStringContainsString('observer exploded', BaseRunManager::handlerErrors()[0]);
    }

    public function testARaiseErrorHandlerPropagatesItsFailure(): void
    {
        $broken = CallbackHandler::fromMethods([
            'handleToolStart' => static function (): void {
                throw new \RuntimeException('observer exploded');
            },
        ]);
        $broken->raiseError = true;

        $manager = new CallbackManager();
        $manager->addHandler($broken);

        // Opting in means the handler is making a statement about correctness,
        // not decorating the run — so its failure is the caller's problem.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('observer exploded');
        $manager->handleToolStart(self::serialized(['t']), 'test');
    }

    public function testIgnoreFlagsSuppressTheRightEventFamily(): void
    {
        $manager = new CallbackManager();
        $llmHandler = CallbackHandler::fromMethods(['handleLLMNewToken' => static function (): void {
        }]);
        $llmHandler->ignoreLLM = true;
        $manager->addHandler($llmHandler);

        $runManager = $manager->handleLLMStart(self::serialized(), ['a'], 'a')[0];
        $runManager->handleLLMNewToken('ignored');

        $this->assertSame([], BaseRunManager::handlerErrors());
    }

    public function testAHandlerBuiltFromMethodsOnlyImplementsWhatItDeclares(): void
    {
        $handler = CallbackHandler::fromMethods(['handleText' => static function (): void {
        }]);

        $this->assertTrue($handler->implements('handleText'));
        $this->assertFalse($handler->implements('handleLLMStart'));
        // The base class defines every hook, so "implements" for a subclass
        // means "overrode" — which is the check the chat-model fallback needs.
        $this->assertFalse((new PlainHandler())->implements('handleLLMStart'));
    }

    public function testASubclassHandlerReportsItsOverriddenHooks(): void
    {
        $handler = new PlainHandler();

        $this->assertTrue($handler->implements('handleToolStart'));
        $this->assertFalse($handler->implements('handleLLMStart'));
        $this->assertSame('plain', $handler->name);
    }

    public function testHandlerFlagsAreConfigurableThroughTheConstructor(): void
    {
        $handler = new PlainHandler([
            'name' => 'configured',
            'ignoreLLM' => true,
            'ignoreChain' => true,
            'ignoreAgent' => true,
            'ignoreRetriever' => true,
            'ignoreCustomEvent' => true,
        ]);

        $this->assertSame('configured', $handler->name);
        $this->assertTrue($handler->ignoreLLM);
        $this->assertTrue($handler->ignoreChain);
        $this->assertTrue($handler->ignoreAgent);
        $this->assertTrue($handler->ignoreRetriever);
        $this->assertTrue($handler->ignoreCustomEvent);
    }

    public function testRaiseErrorForcesHandlersToBeAwaited(): void
    {
        $handler = new PlainHandler(['raiseError' => true]);

        $this->assertTrue($handler->raiseError);
        $this->assertTrue($handler->awaitHandlers);
    }

    // ---- console tracer --------------------------------------------------

    public function testTheConsoleTracerWritesABreadcrumbLinedTrace(): void
    {
        $stream = fopen('php://memory', 'w+b');
        $this->assertIsResource($stream);

        $tracer = new ConsoleCallbackHandler([], $stream);
        $tracer->handleChainStart(self::serialized(['RunnableSequence']), ['foo' => 'bar'], 'chain');
        $tracer->handleToolStart(self::serialized(['weather']), 'the input', 'tool', 'chain');
        $tracer->handleToolEnd('the output', 'tool');
        $tracer->handleChainEnd(['foo' => 'baz'], 'chain');

        rewind($stream);
        $output = (string) stream_get_contents($stream);

        $this->assertStringContainsString('[chain/start]', $output);
        $this->assertStringContainsString('[tool/start]', $output);
        $this->assertStringContainsString('[tool/end]', $output);
        $this->assertStringContainsString('[chain/end]', $output);
        // The breadcrumb is what makes an out-of-order console trace readable.
        $this->assertStringContainsString('2:tool:weather', $output);
        $this->assertStringContainsString('1:chain:RunnableSequence', $output);
        $this->assertStringContainsString('the input', $output);
        $this->assertStringContainsString('the output', $output);
    }

    public function testTheConsoleTracerReportsAnError(): void
    {
        $stream = fopen('php://memory', 'w+b');
        $this->assertIsResource($stream);

        $tracer = new ConsoleCallbackHandler([], $stream);
        $tracer->handleChainStart(self::serialized(), [], 'chain');
        $tracer->handleChainError(new \RuntimeException('chain exploded'), 'chain');

        rewind($stream);
        $output = (string) stream_get_contents($stream);

        $this->assertStringContainsString('[chain/error]', $output);
        $this->assertStringContainsString('chain exploded', $output);
    }

    public function testAnOpenRunHasNoElapsedTime(): void
    {
        $stream = fopen('php://memory', 'w+b');
        $this->assertIsResource($stream);

        $tracer = new ConsoleCallbackHandler([], $stream);
        $tracer->handleLLMStart(self::serialized(), ['a'], 'a');
        $tracer->handleLLMNewToken('x', ['prompt' => 0, 'completion' => 0], 'a');
        $tracer->handleLLMEnd(new LLMResult([[]]), 'a');

        rewind($stream);
        $output = (string) stream_get_contents($stream);

        $this->assertStringContainsString('[llm/start]', $output);
        $this->assertStringContainsString('[llm/end]', $output);
    }

    // ---- value types -----------------------------------------------------

    public function testSerializedReportsItsLastIdSegmentAsTheName(): void
    {
        $this->assertSame('Weather', (new Serialized(['langchain', 'tools', 'Weather']))->name());
        $this->assertSame('unknown', (new Serialized([]))->name());
    }

    public function testSerializedRoundTripsThroughItsArrayForm(): void
    {
        $serialized = new Serialized(['a', 'b'], ['x' => 1]);

        $roundTripped = Serialized::fromArray($serialized->toArray());

        $this->assertSame($serialized->toArray(), $roundTripped->toArray());
    }

    public function testARunEventOmitsEmptyKwargs(): void
    {
        $this->assertSame(['name' => 'start', 'time' => 'T'], (new RunEvent('start', 'T'))->toArray());
        $this->assertSame(
            ['name' => 'new_token', 'time' => 'T', 'kwargs' => ['token' => 'x']],
            (new RunEvent('new_token', 'T', ['token' => 'x']))->toArray(),
        );
    }

    public static function dottedOrderShapes(): array
    {
        return [
            'root order 1' => [1, 'run-a'],
            'root order 12' => [12, 'run-a'],
        ];
    }

    #[DataProvider('dottedOrderShapes')]
    public function testADottedOrderIsTwentyTwoCharactersPlusTheRunId(int $order, string $runId): void
    {
        $dotted = Run::dottedOrder(1620000000000.0, $runId, $order);

        // 8 date + T + 6 time + 6 zero-padded order + Z, then the run id.
        $this->assertSame('20210503T000000' . str_pad((string) $order, 6, '0', \STR_PAD_LEFT) . 'Z' . $runId, $dotted);
        $this->assertSame(22 + strlen($runId), strlen($dotted));
    }

    public function testADottedOrderSortsAsTextTheWayItSortsNumerically(): void
    {
        // The zero-padding is what makes this true; without it order 10 would
        // sort before order 2.
        $ten = Run::dottedOrder(1620000000000.0, 'a', 10);
        $two = Run::dottedOrder(1620000000000.0, 'b', 2);

        $this->assertGreaterThan($two, $ten);
    }

    public function testDottedOrdersDifferByRunIdAtTheSameInstantAndOrder(): void
    {
        $a = Run::dottedOrder(1620000000000.0, 'aaa', 1);
        $b = Run::dottedOrder(1620000000000.0, 'bbb', 1);

        // The id is the last tie-break, so same-millisecond same-order siblings
        // still have stable distinct keys.
        $this->assertNotSame($a, $b);
    }

    public function testTheMicrosecondDatestringCarriesTheExecutionOrder(): void
    {
        $this->assertSame(
            '2021-05-03T00:00:00.000002Z',
            Run::microsecondPrecisionDatestring(1620000000000.0, 2),
        );
    }
}

/**
 * A handler that overrides exactly one hook, used to test the
 * "did this handler implement this?" question the chat-model fallback asks.
 */
final class PlainHandler extends BaseCallbackHandler
{
    public string $name = 'plain';

    /** @var list<string> */
    public array $started = [];

    public function __construct(array $fields = [])
    {
        parent::__construct($fields);
        if (isset($fields['name'])) {
            $this->name = (string) $fields['name'];
        }
    }

    public function handleToolStart(
        Serialized $tool,
        array|string $input,
        string $runId,
        ?string $parentRunId = null,
        array $tags = [],
        array $metadata = [],
        ?string $runName = null,
        ?string $toolCallId = null,
    ): void {
        $this->started[] = $runId;
    }
}
