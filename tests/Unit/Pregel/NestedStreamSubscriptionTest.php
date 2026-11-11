<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangChain\Utils\AsyncLocalStorage;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use function LangGraph\Pregel\interrupt;
use LangGraph\Pregel\Pregel;
use LangGraph\Pregel\Utils\Config;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Pregel::class)]
final class NestedStreamSubscriptionTest extends TestCase
{
    protected function tearDown(): void { AsyncLocalStorage::setGlobalInstance(null); }
    private static function graph(string $name, mixed $body): Pregel
    {
        return (new StateGraph(Annotation::root(['value' => Annotation::withReducer(static fn ($a, $b) => $b, static fn () => 0)])))->addNode($name, $body)->addEdge(Constants::START, $name)->compile();
    }
    public static function selections(): iterable
    {
        foreach ([false, true] as $explicit) { foreach ([false, true] as $subgraphs) { yield ($explicit ? 'explicit' : 'default') . '-' . ($subgraphs ? 'nested' : 'root') => [$explicit, $subgraphs]; } }
    }
    #[DataProvider('selections')]
    public function testLocalModesAndParentSubscriptionsStayIndependent(bool $explicit, bool $subgraphs): void
    {
        $local = [];
        $child = self::graph('leaf', static function (): array { Config::getWriter()('writer'); return ['value' => 1]; });
        $child->streamMode = ['updates'];
        $parent = self::graph('parent', static function (array $state, RunnableConfig $config) use ($child, $explicit, &$local): array {
            $call = $explicit ? $config->with(['options' => array_merge($config->options, ['streamMode' => ['updates']])]) : $config;
            foreach ($child->stream($state, $call) as $chunk) { $local[] = $chunk[0]; }
            return ['value' => 1];
        });
        $chunks = iterator_to_array($parent->stream(['value' => 0], new RunnableConfig(options: ['streamMode' => ['tasks', 'custom'], 'subgraphs' => $subgraphs])));
        self::assertSame($explicit ? ['updates'] : ['values', 'values'], $local);
        self::assertNotContains('updates', array_column($chunks, $subgraphs ? 1 : 0));
        self::assertNotContains('values', array_column($chunks, $subgraphs ? 1 : 0));
        $custom = array_values(array_filter($chunks, static fn ($c) => $c[$subgraphs ? 1 : 0] === 'custom'));
        self::assertCount(1, $custom);
        self::assertSame('writer', $custom[0][$subgraphs ? 2 : 1]);
        if ($subgraphs) { self::assertStringStartsWith('parent:', $custom[0][0][0]); }
        self::assertNull(Config::getConfig());
    }

    public function testDeclaredSubgraphRetainsTheSuppliedReadAndParentProvenance(): void
    {
        $child = self::graph('leaf', static function (array $s, RunnableConfig $c): array {
            self::assertArrayHasKey(Constants::CONFIG_KEY_READ, $c->configurable);
            self::assertSame('parent', $c->configurable['tenant']);
            return ['value' => 1];
        });
        $parent = self::graph('child', $child);
        $chunks = iterator_to_array($parent->stream(['value' => 0], new RunnableConfig(configurable: ['tenant' => 'parent'], options: ['streamMode' => ['tasks'], 'subgraphs' => true])));
        $leaf = array_values(array_filter($chunks, static fn ($c) => $c[2]['name'] === 'leaf'));
        self::assertCount(2, $leaf);
        self::assertStringStartsWith('child:', $leaf[0][0][0]);
    }
    public function testGraphBoundModeWinsNestedDefaultWhileRootConfiguredModeIsPreserved(): void
    {
        $child = self::graph('leaf', static fn (): array => ['value' => 1]);
        $child->streamMode = ['updates'];
        self::assertSame(['updates'], array_column(iterator_to_array($child->stream(['value' => 0])), 0));
        $bound = $child->withConfig(['options' => ['streamMode' => ['updates']]]);
        $local = [];
        $parent = self::graph('parent', static function (array $s) use ($bound, &$local): array {
            foreach ($bound->stream($s) as $chunk) { $local[] = $chunk[0]; }
            return ['value' => 1];
        });
        $chunks = iterator_to_array($parent->stream(['value' => 0], new RunnableConfig(options: ['streamMode' => ['updates'], 'subgraphs' => true])));
        self::assertSame(['updates'], $local);
        self::assertSame(['leaf', 'parent'], array_map(static fn ($c) => array_key_first($c[2]), $chunks));
        // PHP preserves final-state invoke even when explicit mode is updates.
        self::assertSame(['value' => 1], $bound->invoke(['value' => 0]));
    }

    public function testImperativeChildInterruptResumesUnderParentCheckpointer(): void
    {
        $child = self::graph('leaf', static fn (): array => ['value' => interrupt('question')]);
        $parent = (new StateGraph(Annotation::root(['value' => Annotation::withReducer(static fn ($a, $b) => $b, static fn () => 0)])))
            ->addNode('parent', static fn (array $s): array => $child->invoke($s))
            ->addEdge(Constants::START, 'parent')->compile(['checkpointer' => new MemorySaver()]);
        $config = new RunnableConfig(configurable: ['thread_id' => 'imperative-resume'], options: ['streamMode' => ['tasks', 'updates', 'checkpoints'], 'subgraphs' => true]);
        $paused = iterator_to_array($parent->stream(['value' => 0], $config));
        $leaf = array_values(array_filter($paused, static fn ($c) => $c[1] === 'tasks' && $c[2]['name'] === 'leaf' && isset($c[2]['result'])));
        self::assertSame('question', $leaf[0][2]['interrupts'][0]['value']);
        self::assertStringStartsWith('parent:', $leaf[0][0][0]);
        self::assertNull(Config::getConfig());
        $run = $parent->stream(new Command(resume: 'answer'), $config);
        $resumed = iterator_to_array($run);
        self::assertSame(['value' => 'answer'], $run->getReturn());
        $results = array_values(array_filter($resumed, static fn ($c) => $c[1] === 'tasks' && $c[2]['name'] === 'leaf' && isset($c[2]['result'])));
        self::assertSame($leaf[0][2]['id'], $results[0][2]['id']);
        self::assertSame($leaf[0][0], $results[0][0]);
        self::assertSame(['value' => 'answer'], $results[0][2]['result']);
        self::assertNull(Config::getConfig());
    }

}
