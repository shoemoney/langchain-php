<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Checkpoint\MongoDB\MongoDBSaver;
use LangGraph\Pregel\Constants;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * {@see MongoDBSaver}, driven by a real Pregel graph.
 *
 * A saver can satisfy the whole contract and still fail under the engine: a type mismatch
 * against what the loop calls, or a write batch shaped differently from what the loop
 * produces. None of that shows until a graph runs.
 */
#[CoversClass(MongoDBSaver::class)]
final class MongoDBSaverGraphTest extends TestCase
{
    public function testARunIsPersistedAndResumable(): void
    {
        $client = new MongoDBFakeClient();
        $saver = new MongoDBSaver($client);
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

        $config = self::config('mongo-run-1');
        $result = $graph->invoke(['value' => 5], $config);

        self::assertSame(111, $result['value']);
        self::assertSame(['seeded'], $result['log']);
        self::assertSame(['seed', 'bump', 'finish'], $runs);
        self::assertNotSame([], $client->checkpoints()->callsTo('updateOne'), 'the engine never reached the saver');
        self::assertNotSame([], $client->writes()->callsTo('updateOne'), 'the engine never saved a write');

        $head = $saver->getTuple(['thread_id' => 'mongo-run-1']);
        self::assertNotNull($head);
        self::assertSame(111, $head->checkpoint->channelValues['value']);
        self::assertSame(['seeded'], $head->checkpoint->channelValues['log']);

        $runs = [];
        $resumed = $graph->invoke(['value' => 1], $config);

        self::assertSame(['seed', 'bump', 'finish'], $runs);
        self::assertSame(['seeded', 'seeded'], $resumed['log']);
    }

    public function testGetStateAndHistoryReadThroughTheSaver(): void
    {
        $saver = new MongoDBSaver(new MongoDBFakeClient());

        $builder = new StateGraph(['value' => 'int']);
        $builder->addNode('bump', static fn (array $s): array => ['value' => $s['value'] + 1]);
        $builder->addEdge(Constants::START, 'bump');
        $builder->addEdge('bump', Constants::END);
        $graph = $builder->compile(['checkpointer' => $saver]);

        $graph->invoke(['value' => 1], self::config('mongo-state-1'));

        $snapshot = $graph->getState(['configurable' => ['thread_id' => 'mongo-state-1']]);
        self::assertSame(2, $snapshot->values['value']);
        self::assertSame([], $snapshot->next);
        self::assertArrayHasKey('checkpoint_id', $snapshot->config['configurable']);

        $history = $graph->getStateHistory(['configurable' => ['thread_id' => 'mongo-state-1']]);
        self::assertNotEmpty($history);
        self::assertSame(2, $history[0]['values']['value']);
    }

    public function testAnInterruptedRunResumesFromTheSavedCheckpoint(): void
    {
        $saver = new MongoDBSaver(new MongoDBFakeClient());
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

        $config = self::config('mongo-interrupt-1');
        $graph->invoke(['value' => 0], $config);

        self::assertSame(['a'], $runs);

        $paused = $graph->getState(['configurable' => ['thread_id' => 'mongo-interrupt-1']]);
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
