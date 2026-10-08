<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel\Messages;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Channels\BaseChannel;
use LangGraph\Channels\LastValue;
use LangGraph\Errors\EmptyChannelError;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Debug;
use LangGraph\Pregel\PregelExecutableTask;
use LangGraph\Pregel\PregelTaskDescription;
use LangGraph\Pregel\TaskPath;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `pregel/debug.test.ts` (18 tests, all converted).
 *
 * The describe blocks `wrap` (1), `_readChannels` (3), `tasksWithWrites` (5)
 * and `mapDebugTasks` (9) map onto the same-named method groups below.
 */
#[CoversClass(Debug::class)]
final class PregelDebugTest extends TestCase
{
    private static function makeTask(?RunnableConfig $config = null, string $id = 't1', string $name = 'tools'): PregelExecutableTask
    {
        return new PregelExecutableTask(id: $id, name: $name, input: [], triggers: ['x'], config: $config);
    }

    /** @return list<PregelTaskDescription> */
    private static function descriptions(string ...$names): array
    {
        $out = [];
        foreach ($names as $i => $name) {
            $out[] = new PregelTaskDescription('task' . ($i + 1), $name, [], TaskPath::pull($name));

        }

        return $out;
    }

    private static function pull(string $name): array
    {
        return [Constants::PULL, $name];
    }

    // ---- wrap -------------------------------------------------------------

    public function testWrapWrapsTextWithColorCodes(): void
    {
        $color = ['start' => "\x1b[34m", 'end' => "\x1b[0m"];

        self::assertSame($color['start'] . 'test text' . $color['end'], Debug::wrap($color, 'test text'));
    }

    // ---- readChannels (_readChannels) -------------------------------------

    public function testReadChannelsReadsValuesFromChannels(): void
    {
        $channel1 = new LastValue();
        $channel2 = new LastValue();
        $channel1->update(['value1']);
        $channel2->update(['42']);

        $results = iterator_to_array(Debug::readChannels(['channel1' => $channel1, 'channel2' => $channel2]), false);

        self::assertSame([['channel1', 'value1'], ['channel2', '42']], $results);
    }

    public function testReadChannelsSkipsEmptyChannels(): void
    {
        $channel1 = new LastValue();
        $channel1->update(['value1']);

        $results = iterator_to_array(
            Debug::readChannels(['channel1' => $channel1, 'emptyChannel' => $this->throwingChannel(new EmptyChannelError('Empty channel'))]),
            false,
        );

        self::assertSame([['channel1', 'value1']], $results);
    }

    public function testReadChannelsPropagatesNonEmptyChannelErrors(): void
    {
        $channel1 = new LastValue();
        $channel1->update(['value1']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Other error');

        iterator_to_array(
            Debug::readChannels(['channel1' => $channel1, 'errorChannel' => $this->throwingChannel(new \RuntimeException('Other error'))]),
            false,
        );
    }

    private function throwingChannel(\Throwable $error): BaseChannel
    {
        return new class ($error) extends BaseChannel {
            public function __construct(private readonly \Throwable $error)
            {
            }

            public function fromCheckpoint(mixed $checkpoint = null): BaseChannel
            {
                return $this;
            }

            public function update(array $values): bool
            {
                return true;
            }

            public function get(): mixed
            {
                throw $this->error;
            }

            public function checkpoint(): mixed
            {
                return null;
            }
        };
    }

    // ---- tasksWithWrites --------------------------------------------------

    public function testTasksWithWritesReturnsTaskDescriptionsWithNoWrites(): void
    {
        $result = Debug::tasksWithWrites(self::descriptions('Task 1', 'Task 2'), [], null, ['Task 1', 'Task 2']);

        self::assertEquals([
            ['id' => 'task1', 'name' => 'Task 1', 'path' => self::pull('Task 1'), 'interrupts' => []],
            ['id' => 'task2', 'name' => 'Task 2', 'path' => self::pull('Task 2'), 'interrupts' => []],
        ], $result);
    }

    public function testTasksWithWritesIncludesErrorInformation(): void
    {
        $result = Debug::tasksWithWrites(
            self::descriptions('Task 1', 'Task 2'),
            [['task1', Constants::ERROR, ['message' => 'Test error']]],
            null,
            ['Task 1', 'Task 2'],
        );

        self::assertEquals([
            [
                'id' => 'task1',
                'name' => 'Task 1',
                'path' => self::pull('Task 1'),
                'error' => ['message' => 'Test error'],
                'interrupts' => [],
            ],
            ['id' => 'task2', 'name' => 'Task 2', 'path' => self::pull('Task 2'), 'interrupts' => []],
        ], $result);
    }

    public function testTasksWithWritesIncludesStateInformation(): void
    {
        $result = Debug::tasksWithWrites(
            self::descriptions('Task 1', 'Task 2'),
            [],
            ['task1' => ['configurable' => ['key' => 'value']]],
            ['Task 1', 'Task 2'],
        );

        self::assertEquals([
            [
                'id' => 'task1',
                'name' => 'Task 1',
                'path' => self::pull('Task 1'),
                'interrupts' => [],
                'state' => ['configurable' => ['key' => 'value']],
            ],
            ['id' => 'task2', 'name' => 'Task 2', 'path' => self::pull('Task 2'), 'interrupts' => []],
        ], $result);
    }

    public function testTasksWithWritesIncludesInterrupts(): void
    {
        $result = Debug::tasksWithWrites(
            self::descriptions('Task 1'),
            [['task1', Constants::INTERRUPT, ['value' => 'Interrupted', 'when' => 'during']]],
            null,
            ['task1'],
        );

        self::assertEquals([[
            'id' => 'task1',
            'name' => 'Task 1',
            'path' => self::pull('Task 1'),
            'interrupts' => [['value' => 'Interrupted', 'when' => 'during']],
        ]], $result);
    }

    public function testTasksWithWritesIncludesResults(): void
    {
        $result = Debug::tasksWithWrites(
            self::descriptions('Task 1', 'Task 2', 'Task 3'),
            [['task1', 'Task 1', 'Result'], ['task2', 'Task 2', 'Result 2']],
            null,
            ['Task 1', 'Task 2'],
        );

        self::assertEquals([
            [
                'id' => 'task1',
                'name' => 'Task 1',
                'path' => self::pull('Task 1'),
                'interrupts' => [],
                'result' => ['Task 1' => 'Result'],
            ],
            [
                'id' => 'task2',
                'name' => 'Task 2',
                'path' => self::pull('Task 2'),
                'interrupts' => [],
                'result' => ['Task 2' => 'Result 2'],
            ],
            // `result: undefined` upstream: the key is absent, not null.
            ['id' => 'task3', 'name' => 'Task 3', 'path' => self::pull('Task 3'), 'interrupts' => []],
        ], $result);
        self::assertArrayNotHasKey('result', $result[2]);
    }

    // ---- mapDebugTasks ----------------------------------------------------

    /** @return array<string, mixed> */
    private static function firstPayload(PregelExecutableTask $task): array
    {
        $payloads = iterator_to_array(Debug::mapDebugTasks([$task]), false);
        self::assertNotSame([], $payloads);

        return $payloads[0];
    }

    public function testMapDebugTasksForwardsUserMeaningfulMetadataWhenPresent(): void
    {
        $payload = self::firstPayload(self::makeTask(new RunnableConfig(metadata: ['lc_agent_name' => 'weather_agent'])));

        self::assertSame('t1', $payload['id']);
        self::assertSame('tools', $payload['name']);
        self::assertSame(['lc_agent_name' => 'weather_agent'], $payload['metadata']);
    }

    public function testMapDebugTasksOmitsMetadataWhenTheConfigMetadataIsEmpty(): void
    {
        self::assertArrayNotHasKey('metadata', self::firstPayload(self::makeTask(new RunnableConfig(metadata: []))));
    }

    public function testMapDebugTasksOmitsMetadataWhenConfigHasNoMetadataKey(): void
    {
        self::assertArrayNotHasKey('metadata', self::firstPayload(self::makeTask(new RunnableConfig())));
    }

    public function testMapDebugTasksHandlesAMissingConfigWithoutCrashing(): void
    {
        $payloads = iterator_to_array(Debug::mapDebugTasks([self::makeTask(null)]), false);

        self::assertCount(1, $payloads);
        self::assertArrayNotHasKey('metadata', $payloads[0]);
    }

    public function testMapDebugTasksDropsInternalFrameworkMetadataKeys(): void
    {
        $payload = self::firstPayload(self::makeTask(new RunnableConfig(metadata: [
            'lc_agent_name' => 'weather_agent',
            'ls_integration' => 'langchain_create_agent',
            'my_user_key' => 'x',
            'thread_id' => 'thread-1',
            'langgraph_step' => 1,
            'langgraph_node' => 'tools',
            'langgraph_path' => ['__pregel_pull', 'tools'],
            'langgraph_checkpoint_ns' => 'tools:abc',
            'checkpoint_ns' => '',
        ])));

        self::assertSame(
            ['lc_agent_name' => 'weather_agent', 'ls_integration' => 'langchain_create_agent', 'my_user_key' => 'x'],
            $payload['metadata'],
        );
    }

    public function testMapDebugTasksDoesNotMutateTheSourceConfigMetadata(): void
    {
        $config = new RunnableConfig(metadata: ['lc_agent_name' => 'a']);
        $payload = self::firstPayload(self::makeTask($config));

        $config->metadata['lc_agent_name'] = 'MUTATED';

        self::assertSame('a', $payload['metadata']['lc_agent_name']);
    }

    public function testMapDebugTasksFoldsFilteredConfigTagsIntoMetadataUnderTags(): void
    {
        $payload = self::firstPayload(self::makeTask(new RunnableConfig(
            tags: ['seq:step:1', 'user-tag', 'session-123'],
            metadata: ['lc_agent_name' => 'weather_agent'],
        )));

        self::assertSame(
            ['lc_agent_name' => 'weather_agent', 'tags' => ['user-tag', 'session-123']],
            $payload['metadata'],
        );
    }

    public function testMapDebugTasksOmitsTagsWhenTheOnlyTagsAreInternalSeqStepMarkers(): void
    {
        $payload = self::firstPayload(self::makeTask(new RunnableConfig(
            tags: ['seq:step:1'],
            metadata: ['lc_agent_name' => 'a'],
        )));

        self::assertSame(['lc_agent_name' => 'a'], $payload['metadata']);
    }

    public function testMapDebugTasksSurfacesFilteredTagsEvenWithoutOtherMetadata(): void
    {
        $payload = self::firstPayload(self::makeTask(new RunnableConfig(tags: ['user-tag'])));

        self::assertSame(['tags' => ['user-tag']], $payload['metadata']);
    }
}
