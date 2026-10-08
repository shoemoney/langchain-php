<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Checkpoint\Redis\RedisSaver;
use LangGraph\Checkpoint\Redis\ShallowRedisSaver;
use LangGraph\Pregel\Constants;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Redis savers, driven by a real Pregel graph.
 *
 * A saver can satisfy the whole contract and still never work under the engine: a type
 * mismatch against what the loop calls, a `newVersions` map shaped differently from what
 * the loop produces, a channel that only lives in a blob. None of that shows until a graph
 * runs, and the unit tests above never run one.
 */
#[CoversClass(RedisSaver::class)]
#[CoversClass(ShallowRedisSaver::class)]
final class RedisSaverGraphTest extends TestCase
{
    /**
     * @return array<string, array{0: callable(FakeRedisClient): RedisSaver|ShallowRedisSaver}>
     */
    public static function savers(): array
    {
        return [
            'full' => [static fn (FakeRedisClient $client): RedisSaver => new RedisSaver($client)],
            'shallow' => [static fn (FakeRedisClient $client): ShallowRedisSaver => new ShallowRedisSaver($client)],
        ];
    }

    /**
     * A three-node run whose later nodes never touch `log`, so on resume that channel exists
     * only in a blob (full saver) or inline (shallow). The run is resumable and its state is
     * the same either way.
     */
    #[DataProvider('savers')]
    public function testARunIsPersistedAndResumable(callable $make): void
    {
        $client = new FakeRedisClient();
        $saver = $make($client);
        $runs = [];

        $builder = new StateGraph(['value' => 'int', 'log' => 'list']);
        $builder->addNode('seed', static function (array $state) use (&$runs): array {
            $runs[] = 'seed';

            return ['value' => $state['value'] * 2, 'log' => ['seeded']];
        });
        $builder->addNode('bump', static function (array $state) use (&$runs): array {
            $runs[] = 'bump';

            return ['value' => $state['value'] + 1];
        });
        $builder->addNode('finish', static function (array $state) use (&$runs): array {
            $runs[] = 'finish';

            return ['value' => $state['value'] + 100];
        });
        $builder->addEdge(Constants::START, 'seed');
        $builder->addEdge('seed', 'bump');
        $builder->addEdge('bump', 'finish');
        $builder->addEdge('finish', Constants::END);
        $graph = $builder->compile(['checkpointer' => $saver]);

        $config = self::config('redis-run-1');
        $result = $graph->invoke(['value' => 5], $config);

        self::assertSame(111, $result['value']);
        self::assertSame(['seeded'], $result['log']);
        self::assertSame(['seed', 'bump', 'finish'], $runs);
        self::assertNotSame([], $client->callsTo('jsonSet'), 'the engine never reached the saver');

        // The saved head carries EVERY channel, including the ones its last node did not write.
        $head = $saver->getTuple(['thread_id' => 'redis-run-1']);
        self::assertNotNull($head);
        self::assertSame(111, $head->checkpoint->channelValues['value']);
        self::assertSame(['seeded'], $head->checkpoint->channelValues['log']);

        // A second invocation on the same thread continues from the saved state.
        $runs = [];
        $resumed = $graph->invoke(['value' => 1], $config);

        self::assertSame(['seed', 'bump', 'finish'], $runs);
        self::assertSame(['seeded', 'seeded'], $resumed['log']);
    }

    #[DataProvider('savers')]
    public function testGetStateAndHistoryReadThroughTheSaver(callable $make): void
    {
        $saver = $make(new FakeRedisClient());

        $builder = new StateGraph(['value' => 'int']);
        $builder->addNode('bump', static fn (array $s): array => ['value' => $s['value'] + 1]);
        $builder->addEdge(Constants::START, 'bump');
        $builder->addEdge('bump', Constants::END);
        $graph = $builder->compile(['checkpointer' => $saver]);

        $graph->invoke(['value' => 1], self::config('redis-state-1'));

        $snapshot = $graph->getState(['configurable' => ['thread_id' => 'redis-state-1']]);
        self::assertSame(2, $snapshot->values['value']);
        self::assertSame([], $snapshot->next);
        self::assertArrayHasKey('checkpoint_id', $snapshot->config['configurable']);

        $history = $graph->getStateHistory(['configurable' => ['thread_id' => 'redis-state-1']]);
        self::assertNotEmpty($history);
        self::assertSame(2, $history[0]['values']['value']);
    }

    /**
     * The interrupt flow: a node's writes are saved before the superstep commits, then the
     * checkpoint, then a resume. This is the `putWrites`-before-`put` ordering that
     * `has_writes` exists to survive.
     */
    #[DataProvider('savers')]
    public function testAnInterruptedRunResumesFromTheSavedCheckpoint(callable $make): void
    {
        $saver = $make(new FakeRedisClient());
        $runs = [];

        $builder = new StateGraph(['value' => 'int']);
        $builder->addNode('a', static function (array $s) use (&$runs): array {
            $runs[] = 'a';

            return ['value' => $s['value'] + 1];
        });
        $builder->addNode('b', static function (array $s) use (&$runs): array {
            $runs[] = 'b';

            return ['value' => $s['value'] + 10];
        });
        $builder->addEdge(Constants::START, 'a');
        $builder->addEdge('a', 'b');
        $builder->addEdge('b', Constants::END);
        $graph = $builder->compile(['checkpointer' => $saver, 'interruptBefore' => ['b']]);

        $config = self::config('redis-interrupt-1');
        $graph->invoke(['value' => 0], $config);

        self::assertSame(['a'], $runs);

        $paused = $graph->getState(['configurable' => ['thread_id' => 'redis-interrupt-1']]);
        self::assertSame(1, $paused->values['value']);
        self::assertSame(['b'], $paused->next);

        self::assertSame(11, $graph->invoke(null, $config)['value']);
        self::assertSame(['a', 'b'], $runs);
    }

    private static function config(string $threadId): RunnableConfig
    {
        $config = new RunnableConfig();
        $config->configurable = ['thread_id' => $threadId];

        return $config;
    }
}
