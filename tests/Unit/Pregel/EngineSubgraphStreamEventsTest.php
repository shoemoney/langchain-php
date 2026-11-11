<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangChain\Messages\AIMessage;
use LangChain\Tools\DynamicStructuredTool;
use LangChain\Tools\Schema;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Pregel\Utils\Config;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Pregel;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangGraph\Pregel\interrupt;

#[CoversClass(Pregel::class)]
final class EngineSubgraphStreamEventsTest extends TestCase
{
    use TimeTravelFixtures;

    private static function wrap(string $name, Pregel $child, array $options = []): Pregel
    {
        return (new StateGraph(self::stateSchema()))->addNode($name, $child)->addEdge(Constants::START, $name)->compile($options);
    }

    private static function leaf(callable $body): Pregel
    {
        return (new StateGraph(self::stateSchema()))->addNode('leaf', $body)->addEdge(Constants::START, 'leaf')->compile();
    }

    public function testThreeLevelGraphKeepsNamespaceProvenanceAndModeSelection(): void
    {
        $leaf = self::leaf(static function (): array { Config::getWriter()('leaf-progress'); return ['value' => ['leaf']]; });
        $graph = self::wrap('middle', self::wrap('inner', $leaf));
        $chunks = iterator_to_array($graph->stream(['value' => []], new RunnableConfig(options: ['subgraphs' => true, 'streamMode' => ['updates', 'tasks', 'custom']])));
        $leafUpdates = array_values(array_filter($chunks, static fn (array $c): bool => $c[1] === 'updates' && isset($c[2]['leaf'])));
        self::assertCount(1, $leafUpdates);
        self::assertCount(2, $leafUpdates[0][0]);
        self::assertStringStartsWith('middle:', $leafUpdates[0][0][0]);
        self::assertStringStartsWith('inner:', $leafUpdates[0][0][1]);
        $custom = array_values(array_filter($chunks, static fn (array $c): bool => $c[1] === 'custom'));
        self::assertSame([[$leafUpdates[0][0], 'custom', 'leaf-progress']], $custom);
        self::assertNotContains('values', array_column($chunks, 1));
        $leafTasks = array_values(array_filter($chunks, static fn (array $c): bool => $c[1] === 'tasks' && $c[2]['name'] === 'leaf'));
        self::assertCount(2, $leafTasks);
        self::assertSame($leafUpdates[0][0], $leafTasks[0][0]);
        self::assertSame($leafTasks[0][2]['id'], $leafTasks[1][2]['id']);
    }

    public function testUnrequestedSubgraphUpdatesAreHiddenAndRootEnvelopeStaysLegacy(): void
    {
        $graph = self::wrap('child', self::leaf(static fn (): array => ['value' => ['leaf']]));
        $chunks = iterator_to_array($graph->stream(['value' => []], new RunnableConfig(options: ['streamMode' => ['updates']])));
        self::assertSame([['updates', ['child' => ['value' => ['leaf']]]]], $chunks);
    }

    public function testNestedInterruptAndResumePreserveNamespaceAndTaskId(): void
    {
        $graph = self::wrap('child', self::leaf(static fn (): array => ['value' => [interrupt('nested question')]]), ['checkpointer' => new MemorySaver()]);
        $config = new RunnableConfig(configurable: ['thread_id' => 'nested-pause'], options: ['subgraphs' => true, 'streamMode' => ['tasks', 'updates', 'checkpoints']]);
        $paused = iterator_to_array($graph->stream(['value' => []], $config));
        $leafStarts = array_values(array_filter($paused, static fn (array $c): bool => $c[1] === 'tasks' && $c[2]['name'] === 'leaf' && isset($c[2]['input'])));
        self::assertCount(1, $leafStarts);
        $leafResults = array_values(array_filter($paused, static fn (array $c): bool => $c[1] === 'tasks' && $c[2]['name'] === 'leaf' && isset($c[2]['result'])));
        self::assertSame('nested question', $leafResults[0][2]['interrupts'][0]['value']);
        self::assertSame($leafStarts[0][0], $leafResults[0][0]);
        $resumed = iterator_to_array($graph->stream(new Command(resume: 'answer'), $config));
        $results = array_values(array_filter($resumed, static fn (array $c): bool => $c[1] === 'tasks' && $c[2]['name'] === 'leaf' && isset($c[2]['result'])));
        self::assertSame($leafResults[0][2]['id'], $results[0][2]['id']);
        self::assertSame($leafStarts[0][0], $results[0][0]);
        self::assertSame(['value' => ['answer']], $results[0][2]['result']);
        self::assertNotEmpty(array_filter($paused, static fn (array $c): bool => $c[1] === 'checkpoints' && count($c[0]) === 1));
        $parentCheckpoint = array_values(array_filter($paused, static fn (array $c): bool => $c[1] === 'checkpoints' && $c[0] === [] && $c[2]['next'] === ['child']))[0][2];
        self::assertSame('nested-pause', $parentCheckpoint['tasks'][0]['state']['configurable']['thread_id']);
        self::assertSame($leafStarts[0][0][0], $parentCheckpoint['tasks'][0]['state']['configurable']['checkpoint_ns']);
    }

    public function testNestedMessagesAndToolsRetainTaskNamespacesWithoutDuplication(): void
    {
        $tool = new DynamicStructuredTool(['name' => 'echo_tool', 'description' => 'Echo', 'schema' => Schema::object(['text' => Schema::string()], ['text'])], static fn (array $input): string => $input['text']);
        $leaf = self::leaf(static function (array $state, RunnableConfig $config) use ($tool): array {
            $output = $tool->invoke(['text' => 'hello'], $config);

            return ['value' => [new AIMessage($output)]];
        });
        $graph = self::wrap('child', $leaf);
        $chunks = iterator_to_array($graph->stream(['value' => []], new RunnableConfig(options: ['subgraphs' => true, 'streamMode' => ['messages', 'tools']])));
        $messages = array_values(array_filter($chunks, static fn (array $c): bool => $c[1] === 'messages'));
        self::assertCount(1, $messages);
        self::assertSame('hello', $messages[0][2][0]->content);
        self::assertCount(2, $messages[0][0]);
        self::assertStringStartsWith('child:', $messages[0][0][0]);
        self::assertStringStartsWith('leaf:', $messages[0][0][1]);
        $tools = array_values(array_filter($chunks, static fn (array $c): bool => $c[1] === 'tools'));
        self::assertSame(['on_tool_start', 'on_tool_end'], array_column(array_column($tools, 2), 'event'));
        self::assertSame($messages[0][0], $tools[0][0]);
        self::assertSame($tools[0][0], $tools[1][0]);
    }

    public function testScalarCustomKeepsPhpEnvelopeWhilePayloadMatchesSourceBareValue(): void
    {
        $graph = self::wrap('child', self::leaf(static function (): array { Config::getWriter()('w'); return ['value' => []]; }));
        foreach ([false, true] as $subgraphs) {
            $chunks = iterator_to_array($graph->stream(['value' => []], new RunnableConfig(options: ['streamMode' => 'custom', 'subgraphs' => $subgraphs])));
            self::assertCount(1, $chunks);
            if ($subgraphs) {
                self::assertCount(3, $chunks[0]);
                self::assertSame('custom', $chunks[0][1]);
                self::assertSame('w', $chunks[0][2]);
                // Source scalar shape is [namespace, payload]; PHP deliberately retains mode.
                self::assertSame([$chunks[0][0], 'w'], [$chunks[0][0], $chunks[0][2]]);
            } else {
                self::assertSame(['custom', 'w'], $chunks[0]);
                // Source scalar shape is the bare payload 'w'.
                self::assertSame('w', $chunks[0][1]);
            }
        }
    }

    public function testNestedErrorKeepsChildEventsBeforeException(): void
    {
        $graph = self::wrap('child', self::leaf(static fn () => throw new \RuntimeException('nested boom')));
        $chunks = [];
        try {
            foreach ($graph->stream(['value' => []], new RunnableConfig(options: ['subgraphs' => true, 'streamMode' => ['tasks']])) as $chunk) { $chunks[] = $chunk; }
            self::fail('Expected nested error');
        } catch (\RuntimeException $e) { self::assertSame('nested boom', $e->getMessage()); }
        $leaf = array_values(array_filter($chunks, static fn (array $c): bool => $c[2]['name'] === 'leaf'));
        self::assertCount(2, $leaf);
        self::assertCount(1, $leaf[0][0]);
        self::assertSame($leaf[0][0], $leaf[1][0]);
        self::assertSame([], $leaf[1][2]['result']);
    }
}
