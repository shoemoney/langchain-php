<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\Redis;

use LangChain\Runnables\RunnableConfig;
use LangChain\Tests\Unit\Checkpoint\FakeRedisClient;
use LangGraph\Pregel\Checkpoint\MemorySaver;
use LangGraph\Pregel\Constants;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use LangGraph\Store\Redis\RedisStore;
use LangGraph\Store\Redis\RedisStoreIndexConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Real graphs run end to end with a {@see RedisStore} as their long-term memory: nodes read and write
 * it through the config, across threads, with vector recall, over the in-memory Redis client.
 */
#[CoversClass(RedisStore::class)]
final class RedisStoreGraphTest extends TestCase
{
    private static function schema(): \LangGraph\State\AnnotationRoot
    {
        return Annotation::root([
            'user' => Annotation::last(),
            'note' => Annotation::last(),
            'recalled' => Annotation::last(),
            'visits' => Annotation::last(),
        ]);
    }

    private function store(?RedisStoreIndexConfig $index = null): RedisStore
    {
        $store = new RedisStore(new FakeRedisClient(), $index);
        $store->setup();

        return $store;
    }

    public function testNodesReadAndWriteTheRedisStoreTheGraphWasCompiledWith(): void
    {
        $store = $this->store();
        $store->put(['users', 'ada'], 'prefs', ['language' => 'French']);

        $graph = (new StateGraph(self::schema()))
            ->addNode('remember', static function (array $state, RunnableConfig $config): array {
                $store = $config->configurable[Constants::CONFIG_KEY_STORE];
                $prefs = $store->get(['users', $state['user']], 'prefs');
                $store->put(['users', $state['user']], 'last_note', ['text' => $state['note'], 'tags' => ['a', 'b']]);

                return ['recalled' => $prefs?->value['language']];
            })
            ->addEdge(Constants::START, 'remember')
            ->addEdge('remember', Constants::END)
            ->compile(['store' => $store]);

        $result = $graph->invoke(['user' => 'ada', 'note' => 'hello']);

        self::assertSame('French', $result['recalled']);
        self::assertSame(['text' => 'hello', 'tags' => ['a', 'b']], $store->get(['users', 'ada'], 'last_note')?->value);
        self::assertSame([['users', 'ada']], $store->listNamespaces());
    }

    public function testTheStoreOutlivesAThreadAndIsSharedAcrossThemWithTheCheckpointerOn(): void
    {
        $store = $this->store();
        $graph = (new StateGraph(self::schema()))
            ->addNode('count', static function (array $state, RunnableConfig $config): array {
                $store = $config->configurable[Constants::CONFIG_KEY_STORE];
                $seen = $store->get(['visits'], $state['user'])?->value['n'] ?? 0;
                $store->put(['visits'], $state['user'], ['n' => $seen + 1]);

                return ['visits' => $seen + 1];
            })
            ->addEdge(Constants::START, 'count')
            ->addEdge('count', Constants::END)
            ->compile(['store' => $store, 'checkpointer' => new MemorySaver()]);

        $first = $graph->invoke(['user' => 'ada'], new RunnableConfig(configurable: ['thread_id' => 't1']));
        $second = $graph->invoke(['user' => 'ada'], new RunnableConfig(configurable: ['thread_id' => 't2']));
        $other = $graph->invoke(['user' => 'grace'], new RunnableConfig(configurable: ['thread_id' => 't3']));

        self::assertSame([1, 2, 1], [$first['visits'], $second['visits'], $other['visits']]);
        self::assertSame(2, $store->get(['visits'], 'ada')?->value['n']);
    }

    public function testAGraphRecallsMemoriesBySemanticSearchThroughTheStore(): void
    {
        $store = $this->store(new RedisStoreIndexConfig(dims: 4, embeddings: CallbackEmbeddings::characters(), fields: ['text']));
        $graph = (new StateGraph(self::schema()))
            ->addNode('write', static function (array $state, RunnableConfig $config): array {
                $store = $config->configurable[Constants::CONFIG_KEY_STORE];
                foreach ($state['note'] as $i => $text) {
                    $store->put(['memories', $state['user']], "m{$i}", ['text' => $text]);
                }

                return [];
            })
            ->addNode('recall', static function (array $state, RunnableConfig $config): array {
                $hits = $config->configurable[Constants::CONFIG_KEY_STORE]->search(['memories', $state['user']], ['query' => 'likes tea', 'limit' => 5]);

                return ['recalled' => array_map(static fn ($hit): array => [$hit->key, round($hit->score, 3)], $hits)];
            })
            ->addEdge(Constants::START, 'write')
            ->addEdge('write', 'recall')
            ->addEdge('recall', Constants::END)
            ->compile(['store' => $store]);

        $result = $graph->invoke(['user' => 'ada', 'note' => ['likes tea', 'owns a bicycle', 'speaks French']]);

        self::assertCount(3, $result['recalled']);
        self::assertSame('m0', $result['recalled'][0][0], 'the memory identical to the query ranks first');
        self::assertSame(1.0, $result['recalled'][0][1]);
        foreach ($result['recalled'] as [, $score]) {
            self::assertGreaterThan(0, $score);
            self::assertLessThanOrEqual(1, $score);
        }
        self::assertSame(3, $store->stats()['vectorDocuments']);
    }
}
