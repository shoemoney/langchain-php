<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel\Messages;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Channels\LastValue;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Debug;
use LangGraph\Pregel\PregelExecutableTask;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The `debug.ts` mappers upstream's `debug.test.ts` does not exercise
 * (`mapDebugTaskResults`, `mapDebugCheckpoint`, the `print*` renderers), pinned
 * here so the ported helpers are not untested code.
 */
#[CoversClass(Debug::class)]
final class PregelDebugMappersTest extends TestCase
{
    private static function task(string $id = 't1', string $name = 'node', ?RunnableConfig $config = null): PregelExecutableTask
    {
        return new PregelExecutableTask(id: $id, name: $name, input: [], triggers: ['x'], config: $config);
    }

    public function testTaskResultsKeepOnlyTheStreamedChannels(): void
    {
        $results = iterator_to_array(Debug::mapDebugTaskResults(
            [[self::task(), [['out', 1], ['private', 2]]]],
            ['out'],
        ), false);

        self::assertSame([['id' => 't1', 'name' => 'node', 'result' => ['out' => 1], 'interrupts' => []]], $results);
    }

    public function testAChannelWrittenTwiceFoldsIntoAWritesList(): void
    {
        $results = iterator_to_array(Debug::mapDebugTaskResults(
            [[self::task(), [['out', 1], ['out', 2], ['out', 3]]]],
            'out',
        ), false);

        self::assertSame(['out' => ['$writes' => [1, 2, 3]]], $results[0]['result']);
    }

    public function testTaskResultsCarryInterruptsAndSkipHiddenTasks(): void
    {
        $hidden = self::task('t2', 'hidden', new RunnableConfig(tags: [Constants::TAG_HIDDEN]));
        $results = iterator_to_array(Debug::mapDebugTaskResults(
            [
                [self::task(), [[Constants::INTERRUPT, ['value' => 'ask']]]],
                [$hidden, [['out', 9]]],
            ],
            'out',
        ), false);

        self::assertCount(1, $results);
        self::assertSame([['value' => 'ask']], $results[0]['interrupts']);
    }

    public function testHiddenTasksAreSkippedFromTheTaskPayloads(): void
    {
        $hidden = self::task('t2', 'hidden', new RunnableConfig(tags: [Constants::TAG_HIDDEN]));

        $payloads = iterator_to_array(Debug::mapDebugTasks([self::task(), $hidden]), false);

        self::assertSame(['node'], array_column($payloads, 'name'));
    }

    public function testACheckpointPayloadListsNextTasksAndFormatsConfigsInSnakeCase(): void
    {
        $channel = new LastValue();
        $channel->update([5]);
        $config = new RunnableConfig(recursionLimit: 7, configurable: ['thread_id' => 'th', 'checkpoint_ns' => '']);

        $payload = iterator_to_array(Debug::mapDebugCheckpoint(
            $config,
            ['count' => $channel],
            ['count'],
            ['step' => 1],
            [self::task('t1', 'a'), self::task('t2', 'b')],
            [],
            null,
            ['count'],
        ), false)[0];

        self::assertSame(['count' => 5], $payload['values']);
        self::assertSame(['a', 'b'], $payload['next']);
        self::assertSame(['step' => 1], $payload['metadata']);
        self::assertSame(7, $payload['config']['recursion_limit']);
        self::assertSame('th', $payload['config']['configurable']['thread_id']);
        self::assertNull($payload['parentConfig']);
        self::assertCount(2, $payload['tasks']);
    }

    public function testASubgraphTaskGetsAStateConfigAddressingItsOwnNamespace(): void
    {
        $subgraph = (new StateGraph(['x' => new \LangGraph\Channels\AnyValue()]))
            ->addNode('n', static fn (array $s): array => ['x' => 1])
            ->addEdge(Constants::START, 'n')
            ->addEdge('n', Constants::END)
            ->compile();
        $task = new PregelExecutableTask(id: 'sub1', name: 'child', input: [], triggers: ['x'], proc: $subgraph);

        $payload = iterator_to_array(Debug::mapDebugCheckpoint(
            new RunnableConfig(configurable: ['thread_id' => 'th', 'checkpoint_ns' => 'parent:p1']),
            [],
            [],
            [],
            [$task],
            [],
            null,
            [],
        ), false)[0];

        self::assertSame(
            ['configurable' => ['thread_id' => 'th', 'checkpoint_ns' => 'parent:p1|child:sub1']],
            $payload['tasks'][0]['state'],
        );
    }

    public function testStepWritesAreRenderedPerWhitelistedChannel(): void
    {
        ob_start();
        Debug::printStepWrites(3, [['out', 1], ['out', 2], ['skip', 9]], ['out']);
        $text = (string) ob_get_clean();

        self::assertStringContainsString('[3:writes]', $text);
        self::assertStringContainsString('writes to 1 channel:', $text);
        self::assertStringContainsString('out', $text);
        self::assertStringContainsString('1, 2', $text);
        self::assertStringNotContainsString('skip', $text);
    }

    public function testStepTasksAreRenderedWithPluralisation(): void
    {
        ob_start();
        Debug::printStepTasks(0, [self::task('t1', 'a')]);
        Debug::printStepTasks(1, [self::task('t1', 'a'), self::task('t2', 'b')]);
        $text = (string) ob_get_clean();

        self::assertStringContainsString('with 1 task:', $text);
        self::assertStringContainsString('with 2 tasks:', $text);
    }

    public function testFilterToUserTagsDropsSeqStepMarkers(): void
    {
        self::assertNull(Debug::filterToUserTags(null));
        self::assertNull(Debug::filterToUserTags([]));
        self::assertNull(Debug::filterToUserTags(['seq:step:1']));
        self::assertSame(['a'], Debug::filterToUserTags(['seq:step:2', 'a']));
    }
}
