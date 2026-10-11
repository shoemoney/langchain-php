<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangChain\Runnables\RunnableConfig;
use LangChain\Tests\Unit\Sdk\Support\RecordingTransport;
use LangChain\Utils\Http\HttpResponse;
use LangGraph\Pregel\PregelTaskDescription;
use LangGraph\Pregel\RemoteGraph;
use LangGraph\Pregel\StateSnapshot;
use LangGraph\Pregel\TaskPath;
use LangGraph\Sdk\Client;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Fixed remote snapshot observations through the actual SDK recording transport.
 *
 * Caller null checkpoint keys retain the established PHP-undefined adaptation.
 * Server null keys survive recursively. Malformed-entry exception rows are PHP
 * adaptations, not JS exception parity or mutation-kill credit. Existing public
 * interrupt ids and legacy array forms remain unchanged.
 */
#[CoversClass(RemoteGraph::class)]
#[CoversClass(PregelTaskDescription::class)]
#[CoversClass(StateSnapshot::class)]
final class RemoteGraphSnapshotFieldsTest extends TestCase
{
    private const MAIN = <<<'JSON'
{"values":0,"next":[],"checkpoint":{"thread_id":"t","checkpoint_ns":"","checkpoint_id":"c2"},"metadata":{"step":2},"created_at":"2026-10-10T00:00:00Z","parent_checkpoint":{"thread_id":"t","checkpoint_id":"c1"},"tasks":[{"id":"task1","name":"child","error":"0","interrupts":[{"id":"i1","value":false,"namespace":[]}],"checkpoint":{"thread_id":"child-t","checkpoint_ns":"child","checkpoint_id":"fallback","checkpoint_map":null,"extra":"raw-reference-only"},"state":{"values":false,"next":[],"checkpoint":null,"metadata":{},"created_at":null,"parent_checkpoint":null,"tasks":[]},"result":null}]}
JSON;

    private const NO_OUTER_LEAK = <<<'JSON'
{"values":{"outer":true},"next":["child"],"checkpoint":{"thread_id":"t","checkpoint_ns":"","checkpoint_id":"outer"},"metadata":{"step":3},"created_at":"2026-10-10T00:00:03Z","parent_checkpoint":{"thread_id":"t","checkpoint_id":"parent"},"tasks":[{"id":"no-cp","name":"child","state":{"values":"child","next":[],"checkpoint":null,"metadata":{"child":true},"created_at":"2026-10-10T00:00:04Z","parent_checkpoint":null,"tasks":[]}}]}
JSON;

    private const SERVER_CHAIN = <<<'JSON'
{"values":"outer","next":["server","sibling"],"checkpoint":null,"metadata":{"step":4},"created_at":"2026-10-10T00:00:05Z","parent_checkpoint":null,"tasks":[{"id":"server","name":"server","checkpoint":{"thread_id":"server-t","checkpoint_ns":null,"checkpoint_id":null,"checkpoint_map":null,"extra":"discard"},"state":{"values":null,"next":["grandchild"],"checkpoint":null,"metadata":{"level":1},"created_at":"2026-10-10T00:00:06Z","parent_checkpoint":{"thread_id":"server-t","checkpoint_ns":null,"checkpoint_id":null,"checkpoint_map":null},"tasks":[{"id":"grandchild","name":"grandchild","state":{"values":[0,false,"0"],"next":[],"checkpoint":null,"metadata":{"level":2},"created_at":"2026-10-10T00:00:07Z","parent_checkpoint":null,"tasks":[]},"result":false}]}},{"id":"sibling","name":"sibling","state":{"values":{"sibling":0},"next":["finish"],"checkpoint":{"thread_id":"sibling-t","checkpoint_ns":"sibling","checkpoint_id":"own"},"metadata":{"level":"sibling"},"created_at":"2026-10-10T00:00:08Z","parent_checkpoint":{"thread_id":"sibling-t","checkpoint_id":"before"},"tasks":[]},"result":0}]}
JSON;

    private const HISTORY = <<<'JSON'
[{"values":0,"next":[],"checkpoint":{"thread_id":"t","checkpoint_ns":"","checkpoint_id":"c2"},"metadata":{"step":2},"created_at":"2026-10-10T00:00:00Z","parent_checkpoint":{"thread_id":"t","checkpoint_id":"c1"},"tasks":[{"id":"task1","name":"child","error":"0","interrupts":[{"id":"i1","value":false,"namespace":[]}],"checkpoint":{"thread_id":"child-t","checkpoint_ns":"child","checkpoint_id":"fallback","checkpoint_map":null,"extra":"raw-reference-only"},"state":{"values":false,"next":[],"checkpoint":null,"metadata":{},"created_at":null,"parent_checkpoint":null,"tasks":[]},"result":null}]},{"values":{"previous":["x",0]},"next":[],"checkpoint":{"thread_id":"t","checkpoint_ns":"history","checkpoint_id":"c1","checkpoint_map":{"branch":"old"}},"metadata":{"step":1,"source":"update"},"created_at":"2026-10-10T00:00:01Z","parent_checkpoint":{"thread_id":"t","checkpoint_id":"c0"},"tasks":[{"id":"prior","name":"prior","interrupts":[],"checkpoint":{"thread_id":"ref","checkpoint_ns":null,"checkpoint_id":null,"checkpoint_map":null,"extra":"raw"},"result":false}]}]
JSON;

    private static function graph(string $json): array
    {
        $transport = new RecordingTransport([new HttpResponse(200, ['content-type' => 'application/json'], $json)]);
        $client = new Client(['apiUrl' => 'http://snapshot.invalid', 'apiKey' => null, 'timeoutMs' => null,
            'callerOptions' => ['maxRetries' => 0]], $transport);

        return [new RemoteGraph(graphId: 'snapshot', client: $client), $transport];
    }

    private static function caller(): RunnableConfig
    {
        return new RunnableConfig(tags: ['caller'], metadata: ['caller' => true],
            callbacks: [new \stdClass()], signal: static fn (): bool => false, configurable: ['thread_id' => 't', 'checkpoint_ns' => null,
            'checkpoint_id' => null, 'checkpoint_map' => null, 'other' => 'caller-only']);
    }

    /** Guard before each new-field read or named constructor argument on the baseline. */
    private function assertTaskContract(PregelTaskDescription $task): void
    {
        $this->assertTrue(property_exists($task, 'error'), 'public error must be present');
        $this->assertTrue(property_exists($task, 'state'), 'public state must be present');
        $this->assertTrue(property_exists($task, 'result'), 'public result must be present');
        $this->assertTrue(method_exists($task, 'hasResult'), 'result presence must be observable');
    }

    /** Compare all seven fields against handwritten literals, recursively, without an oracle. */
    private function assertSnapshotFields(StateSnapshot $actual, array $expected): void
    {
        $this->assertSame($expected['values'], $actual->values);
        $this->assertSame($expected['next'], $actual->next);
        $this->assertSame($expected['config'], $actual->config);
        $this->assertSame($expected['metadata'], $actual->metadata);
        $this->assertSame($expected['createdAt'], $actual->createdAt);
        $this->assertSame($expected['parentConfig'], $actual->parentConfig);
        $this->assertCount(count($expected['tasks']), $actual->tasks);
        foreach ($expected['tasks'] as $index => $row) {
            $task = $actual->tasks[$index];
            $this->assertInstanceOf(PregelTaskDescription::class, $task);
            $this->assertTaskContract($task);
            $this->assertSame($row['id'], $task->id);
            $this->assertSame($row['name'], $task->name);
            $this->assertNull($task->path);
            $this->assertSame($row['interrupts'], $task->interrupts);
            $this->assertSame($row['error'], $task->error);
            $this->assertSame($row['result'], $task->result);
            $this->assertSame($row['hasResult'], $task->hasResult());
            if ($row['stateKind'] === 'snapshot') {
                $this->assertInstanceOf(StateSnapshot::class, $task->state);
                $this->assertSnapshotFields($task->state, $row['state']);
            } else {
                $this->assertSame($row['state'], $task->state);
            }
        }
    }

    private static function mainExpected(): array
    {
        return ['values' => 0, 'next' => [],
            'config' => ['configurable' => ['thread_id' => 't', 'checkpoint_ns' => '', 'checkpoint_id' => 'c2', 'checkpoint_map' => []]],
            'metadata' => ['step' => 2], 'createdAt' => '2026-10-10T00:00:00Z',
            'parentConfig' => ['configurable' => ['thread_id' => 't', 'checkpoint_id' => 'c1', 'checkpoint_map' => []]],
            'tasks' => [['id' => 'task1', 'name' => 'child', 'interrupts' => [['id' => 'i1', 'value' => false, 'namespace' => []]],
                'error' => ['message' => '0'], 'result' => null, 'hasResult' => true, 'stateKind' => 'snapshot',
                'state' => ['values' => false, 'next' => [],
                    'config' => ['configurable' => ['thread_id' => 'child-t', 'checkpoint_ns' => 'child', 'checkpoint_id' => 'fallback', 'checkpoint_map' => []]],
                    'metadata' => [], 'createdAt' => null, 'parentConfig' => null, 'tasks' => []]]]];
    }

    public function testGetStateKeepsSevenFieldsAndTaskObservations(): void
    {
        [$graph, $transport] = self::graph(self::MAIN);
        $caller = self::caller();
        $before = get_object_vars($caller);
        $privateFields = new \ReflectionProperty(RunnableConfig::class, 'providedFields');
        $privateBefore = $privateFields->getValue($caller);
        $snapshot = $graph->getState($caller);
        $this->assertSnapshotFields($snapshot, self::mainExpected());
        $this->assertSame($before, get_object_vars($caller));
        $this->assertSame($privateBefore, $privateFields->getValue($caller));
        $this->assertCount(1, $transport->requests);
        $this->assertSame('POST', $transport->requests[0]['method']);
        $this->assertSame('http://snapshot.invalid/threads/t/state/checkpoint', $transport->requests[0]['url']);
        $this->assertSame(['checkpoint' => ['thread_id' => 't']], $transport->bodyOf());
        $this->assertSame(['content-type' => 'application/json'], $transport->requests[0]['headers']);
        $this->assertNull($transport->requests[0]['timeout']);
        $this->assertSame(['id' => 'task1', 'name' => 'child', 'path' => null,
            'interrupts' => [['id' => 'i1', 'value' => false, 'namespace' => []]]], $snapshot->tasks[0]->toArray());
        $this->assertSame(['values' => 0, 'next' => [],
            'config' => ['configurable' => ['thread_id' => 't', 'checkpoint_ns' => '', 'checkpoint_id' => 'c2', 'checkpoint_map' => []]],
            'metadata' => ['step' => 2], 'tasks' => [['id' => 'task1', 'name' => 'child',
                'interrupts' => [['id' => 'i1', 'value' => false, 'namespace' => []]]]]], $snapshot->toArray());
    }

    public function testHistoryIsLazyAndKeepsBothLiteralRows(): void
    {
        [$graph, $transport] = self::graph(self::HISTORY);
        $caller = self::caller();
        $before = get_object_vars($caller);
        $privateFields = new \ReflectionProperty(RunnableConfig::class, 'providedFields');
        $privateBefore = $privateFields->getValue($caller);
        $history = $graph->getStateHistory($caller);
        $this->assertSame([], $transport->requests);
        $history->rewind();
        $this->assertSnapshotFields($history->current(), self::mainExpected());
        $history->next();
        $this->assertSnapshotFields($history->current(), [
            'values' => ['previous' => ['x', 0]], 'next' => [],
            'config' => ['configurable' => ['thread_id' => 't', 'checkpoint_ns' => 'history', 'checkpoint_id' => 'c1', 'checkpoint_map' => ['branch' => 'old']]],
            'metadata' => ['step' => 1, 'source' => 'update'], 'createdAt' => '2026-10-10T00:00:01Z',
            'parentConfig' => ['configurable' => ['thread_id' => 't', 'checkpoint_id' => 'c0', 'checkpoint_map' => []]],
            'tasks' => [['id' => 'prior', 'name' => 'prior', 'interrupts' => [], 'error' => null,
                'result' => false, 'hasResult' => true, 'stateKind' => 'reference',
                'state' => ['configurable' => ['thread_id' => 'ref', 'checkpoint_ns' => null,
                    'checkpoint_id' => null, 'checkpoint_map' => null, 'extra' => 'raw']]]],
        ]);
        $history->next();
        $this->assertFalse($history->valid());
        $this->assertSame($before, get_object_vars($caller));
        $this->assertSame($privateBefore, $privateFields->getValue($caller));
        $this->assertCount(1, $transport->requests);
        $this->assertSame('POST', $transport->requests[0]['method']);
        $this->assertSame('http://snapshot.invalid/threads/t/history', $transport->requests[0]['url']);
        $this->assertSame(['limit' => 10, 'checkpoint' => ['thread_id' => 't']], $transport->bodyOf());
        $this->assertSame(['content-type' => 'application/json'], $transport->requests[0]['headers']);
        $this->assertNull($transport->requests[0]['timeout']);
    }

    public function testCallerNullFallbackDoesNotLeakOuterOrParentCheckpoint(): void
    {
        [$graph] = self::graph(self::NO_OUTER_LEAK);
        $this->assertSnapshotFields($graph->getState(self::caller()), [
            'values' => ['outer' => true], 'next' => ['child'],
            'config' => ['configurable' => ['thread_id' => 't', 'checkpoint_ns' => '', 'checkpoint_id' => 'outer', 'checkpoint_map' => []]],
            'metadata' => ['step' => 3], 'createdAt' => '2026-10-10T00:00:03Z',
            'parentConfig' => ['configurable' => ['thread_id' => 't', 'checkpoint_id' => 'parent', 'checkpoint_map' => []]],
            'tasks' => [['id' => 'no-cp', 'name' => 'child', 'interrupts' => [], 'error' => null,
                'result' => null, 'hasResult' => false, 'stateKind' => 'snapshot',
                'state' => ['values' => 'child', 'next' => [], 'config' => ['configurable' => ['thread_id' => 't']],
                    'metadata' => ['child' => true], 'createdAt' => '2026-10-10T00:00:04Z',
                    'parentConfig' => null, 'tasks' => []]]],
        ]);
    }

    public function testServerNullFallbackSurvivesGrandchildAndSiblingOwnCheckpointWins(): void
    {
        [$graph] = self::graph(self::SERVER_CHAIN);
        $this->assertSnapshotFields($graph->getState(self::caller()), [
            'values' => 'outer', 'next' => ['server', 'sibling'], 'config' => ['configurable' => ['thread_id' => 't']],
            'metadata' => ['step' => 4], 'createdAt' => '2026-10-10T00:00:05Z', 'parentConfig' => null,
            'tasks' => [
                ['id' => 'server', 'name' => 'server', 'interrupts' => [], 'error' => null,
                    'result' => null, 'hasResult' => false, 'stateKind' => 'snapshot',
                    'state' => ['values' => null, 'next' => ['grandchild'],
                        'config' => ['configurable' => ['thread_id' => 'server-t', 'checkpoint_ns' => null, 'checkpoint_id' => null, 'checkpoint_map' => []]],
                        'metadata' => ['level' => 1], 'createdAt' => '2026-10-10T00:00:06Z',
                        'parentConfig' => ['configurable' => ['thread_id' => 'server-t', 'checkpoint_ns' => null, 'checkpoint_id' => null, 'checkpoint_map' => []]],
                        'tasks' => [['id' => 'grandchild', 'name' => 'grandchild', 'interrupts' => [], 'error' => null,
                            'result' => false, 'hasResult' => true, 'stateKind' => 'snapshot',
                            'state' => ['values' => [0, false, '0'], 'next' => [],
                                'config' => ['configurable' => ['thread_id' => 'server-t', 'checkpoint_ns' => null, 'checkpoint_id' => null, 'checkpoint_map' => []]],
                                'metadata' => ['level' => 2], 'createdAt' => '2026-10-10T00:00:07Z', 'parentConfig' => null, 'tasks' => []]]]]],
                ['id' => 'sibling', 'name' => 'sibling', 'interrupts' => [], 'error' => null,
                    'result' => 0, 'hasResult' => true, 'stateKind' => 'snapshot',
                    'state' => ['values' => ['sibling' => 0], 'next' => ['finish'],
                        'config' => ['configurable' => ['thread_id' => 'sibling-t', 'checkpoint_ns' => 'sibling', 'checkpoint_id' => 'own', 'checkpoint_map' => []]],
                        'metadata' => ['level' => 'sibling'], 'createdAt' => '2026-10-10T00:00:08Z',
                        'parentConfig' => ['configurable' => ['thread_id' => 'sibling-t', 'checkpoint_id' => 'before', 'checkpoint_map' => []]], 'tasks' => []]],
            ],
        ]);
    }

    public function testOuterAndNestedCallerNullDefaultsAndEmptyServerCheckpointPresence(): void
    {
        [$graph, $transport] = self::graph(<<<'JSON'
{"checkpoint":null,"tasks":[{"id":"caller","state":{"tasks":[]}},{"id":"empty","checkpoint":{},"state":{"tasks":[]}},{"id":"ref","checkpoint":{}},{"id":"falsy-state","state":false},{"id":"empty-parent","state":{"parent_checkpoint":{},"tasks":[]}}]}
JSON);
        $snapshot = $graph->getState(self::caller());
        $this->assertSame(['configurable' => ['thread_id' => 't']], $snapshot->config);
        $this->assertSame(['checkpoint' => ['thread_id' => 't']], $transport->bodyOf());
        $this->assertCount(5, $snapshot->tasks);
        foreach ($snapshot->tasks as $task) {
            $this->assertTaskContract($task);
        }
        $this->assertInstanceOf(StateSnapshot::class, $snapshot->tasks[0]->state);
        $this->assertInstanceOf(StateSnapshot::class, $snapshot->tasks[1]->state);
        $this->assertInstanceOf(StateSnapshot::class, $snapshot->tasks[4]->state);
        $this->assertSnapshotFields($snapshot->tasks[0]->state, ['values' => [], 'next' => [],
            'config' => ['configurable' => ['thread_id' => 't']], 'metadata' => [], 'createdAt' => null, 'parentConfig' => null, 'tasks' => []]);
        $this->assertSnapshotFields($snapshot->tasks[1]->state, ['values' => [], 'next' => [],
            'config' => ['configurable' => []], 'metadata' => [], 'createdAt' => null, 'parentConfig' => null, 'tasks' => []]);
        $this->assertSame(['configurable' => []], $snapshot->tasks[2]->state);
        $this->assertNull($snapshot->tasks[3]->state);
        $this->assertSnapshotFields($snapshot->tasks[4]->state, ['values' => [], 'next' => [],
            'config' => ['configurable' => ['thread_id' => 't']], 'metadata' => [], 'createdAt' => null,
            'parentConfig' => ['configurable' => []], 'tasks' => []]);
    }

    public function testLiteralOuterCallerNullRow(): void
    {
        [$graph] = self::graph(<<<'JSON'
{"values":"outer","next":[],"checkpoint":null,"metadata":{},"created_at":null,"parent_checkpoint":null,"tasks":[]}
JSON);
        $this->assertSnapshotFields($graph->getState(self::caller()), ['values' => 'outer', 'next' => [],
            'config' => ['configurable' => ['thread_id' => 't']], 'metadata' => [],
            'createdAt' => null, 'parentConfig' => null, 'tasks' => []]);
    }

    public function testLiteralNestedCallerNullRowWithoutTaskCheckpoint(): void
    {
        [$graph] = self::graph(<<<'JSON'
{"values":"outer","next":[],"checkpoint":null,"metadata":{},"created_at":null,"parent_checkpoint":null,"tasks":[{"id":"b","name":"child","error":null,"interrupts":[],"checkpoint":null,"state":{"values":"child","next":[],"checkpoint":null,"metadata":{},"created_at":null,"parent_checkpoint":null,"tasks":[]}}]}
JSON);
        $this->assertSnapshotFields($graph->getState(self::caller()), ['values' => 'outer', 'next' => [],
            'config' => ['configurable' => ['thread_id' => 't']], 'metadata' => [], 'createdAt' => null, 'parentConfig' => null,
            'tasks' => [['id' => 'b', 'name' => 'child', 'interrupts' => [], 'error' => null,
                'result' => null, 'hasResult' => false, 'stateKind' => 'snapshot',
                'state' => ['values' => 'child', 'next' => [], 'config' => ['configurable' => ['thread_id' => 't']],
                    'metadata' => [], 'createdAt' => null, 'parentConfig' => null, 'tasks' => []]]]]);
    }

    public function testCallerSuppliedMapAndServerOwnNullFieldsRemainDistinct(): void
    {
        [$graph, $transport] = self::graph(<<<'JSON'
{"tasks":[]}
JSON);
        $caller = new RunnableConfig(configurable: ['thread_id' => 't', 'checkpoint_ns' => null,
            'checkpoint_id' => null, 'checkpoint_map' => []]);
        $this->assertSnapshotFields($graph->getState($caller), ['values' => [], 'next' => [],
            'config' => ['configurable' => ['thread_id' => 't', 'checkpoint_map' => []]],
            'metadata' => [], 'createdAt' => null, 'parentConfig' => null, 'tasks' => []]);
        $this->assertSame(['checkpoint' => ['thread_id' => 't', 'checkpoint_map' => []]], $transport->bodyOf());
        [$server] = self::graph(<<<'JSON'
{"checkpoint":{"thread_id":null,"checkpoint_ns":null,"checkpoint_id":null,"checkpoint_map":null},"parent_checkpoint":{"thread_id":null,"checkpoint_ns":null,"checkpoint_id":null},"created_at":"","metadata":[0,false],"tasks":[]}
JSON);
        $this->assertSnapshotFields($server->getState(self::caller()), ['values' => [], 'next' => [],
            'config' => ['configurable' => ['thread_id' => null, 'checkpoint_ns' => null, 'checkpoint_id' => null, 'checkpoint_map' => []]],
            'metadata' => [0, false], 'createdAt' => '',
            'parentConfig' => ['configurable' => ['thread_id' => null, 'checkpoint_ns' => null, 'checkpoint_id' => null, 'checkpoint_map' => []]], 'tasks' => []]);
    }

    public function testEmptyChildOwnCheckpointSuppressesServerAndCallerFallback(): void
    {
        [$graph] = self::graph(<<<'JSON'
{"tasks":[{"id":"own-empty","checkpoint":{"thread_id":"server-t","checkpoint_id":"server"},"state":{"checkpoint":{},"tasks":[]}}]}
JSON);
        $task = $graph->getState(self::caller())->tasks[0];
        $this->assertTaskContract($task);
        $this->assertInstanceOf(StateSnapshot::class, $task->state);
        $this->assertSnapshotFields($task->state, ['values' => [], 'next' => [], 'config' => ['configurable' => []],
            'metadata' => [], 'createdAt' => null, 'parentConfig' => null, 'tasks' => []]);
    }

    public static function falsyBranchRows(): iterable
    {
        yield 'null' => [null];
        yield 'false' => [false];
        yield 'zero' => [0];
        yield 'empty-string' => [''];
    }

    #[DataProvider('falsyBranchRows')]
    public function testFalsyStateAndParentFollowReferenceOrAbsentBranch(mixed $falsy): void
    {
        [$graph] = self::graph(json_encode(['parent_checkpoint' => $falsy, 'tasks' => [
            ['state' => $falsy, 'checkpoint' => ['thread_id' => 'ref']],
            ['state' => $falsy, 'checkpoint' => $falsy],
        ]], JSON_THROW_ON_ERROR));
        $snapshot = $graph->getState(self::caller());
        $this->assertNull($snapshot->parentConfig);
        $this->assertCount(2, $snapshot->tasks);
        $this->assertTaskContract($snapshot->tasks[0]);
        $this->assertTaskContract($snapshot->tasks[1]);
        $this->assertSame(['configurable' => ['thread_id' => 'ref']], $snapshot->tasks[0]->state);
        $this->assertNull($snapshot->tasks[1]->state);
    }

    public static function valuesRows(): iterable
    {
        yield 'absent' => [[], []];
        yield 'null' => [['values' => null], null];
        yield 'false' => [['values' => false], false];
        yield 'zero' => [['values' => 0], 0];
        yield 'string-zero' => [['values' => '0'], '0'];
        yield 'empty-string' => [['values' => ''], ''];
        yield 'list' => [['values' => [0, false, null]], [0, false, null]];
        yield 'map' => [['values' => ['a' => 0]], ['a' => 0]];
        yield 'mixed-numeric-map' => [['values' => [2 => false, 'key' => null]], [2 => false, 'key' => null]];
    }

    #[DataProvider('valuesRows')]
    public function testRawValuesAreNotCoerced(array $fields, mixed $expected): void
    {
        $json = json_encode($fields, JSON_THROW_ON_ERROR);
        [$graph] = self::graph($json);
        $snapshot = $graph->getState(self::caller());
        $this->assertSame($expected, $snapshot->values);
        $this->assertSame([], $snapshot->tasks);
        $this->assertSame($expected, $snapshot->toArray()['values']);
        [$historyGraph, $transport] = self::graph('[' . $json . ']');
        $history = $historyGraph->getStateHistory(self::caller());
        $this->assertSame([], $transport->requests);
        $history->rewind();
        $this->assertSame($expected, $history->current()->values);
        $this->assertSame($expected, $history->current()->toArray()['values']);
        $history->next();
        $this->assertFalse($history->valid());
    }

    public static function taskValueRows(): iterable
    {
        yield 'absent' => [[], null, null, false];
        yield 'null' => [['error' => null, 'result' => null], null, null, true];
        yield 'false' => [['error' => false, 'result' => false], null, false, true];
        yield 'zero' => [['error' => 0, 'result' => 0], null, 0, true];
        yield 'float-zero' => [['error' => 0.0, 'result' => 0.0], null, 0.0, true];
        yield 'empty-string' => [['error' => '', 'result' => ''], null, '', true];
        yield 'string-zero' => [['error' => '0', 'result' => '0'], ['message' => '0'], '0', true];
        yield 'ordinary-string' => [['error' => 'boom', 'result' => 'done'], ['message' => 'boom'], 'done', true];
        yield 'nonzero-number' => [['error' => 2, 'result' => 2], ['message' => 2], 2, true];
        yield 'true' => [['error' => true, 'result' => true], ['message' => true], true, true];
        yield 'empty-list' => [['error' => [], 'result' => []], ['message' => []], [], true];
        yield 'list' => [['error' => [false, 0], 'result' => [false, 0]], ['message' => [false, 0]], [false, 0], true];
        yield 'map' => [['error' => ['code' => 0], 'result' => ['code' => 0]], ['message' => ['code' => 0]], ['code' => 0], true];
        yield 'mixed-map' => [['error' => [2 => null, 'x' => false], 'result' => [2 => null, 'x' => false]],
            ['message' => [2 => null, 'x' => false]], [2 => null, 'x' => false], true];
    }

    #[DataProvider('taskValueRows')]
    public function testErrorTruthinessAndResultPresence(array $fields, mixed $error, mixed $result, bool $present): void
    {
        [$graph] = self::graph(json_encode(['tasks' => [['id' => 'task'] + $fields]], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
        $task = $graph->getState(self::caller())->tasks[0];
        $this->assertTaskContract($task);
        $this->assertSame($error, $task->error);
        $this->assertSame($result, $task->result);
        $this->assertSame($present, $task->hasResult());
        $this->assertNull($task->state);
    }

    public static function malformedRows(): iterable
    {
        yield 'nested-string-zero' => [['tasks' => [['state' => '0']]], 'Invalid nested task state: expected an array with a tasks list.'];
        yield 'nested-empty-array' => [['tasks' => [['state' => []]]], 'Invalid nested task state: expected an array with a tasks list.'];
        yield 'nested-missing-tasks' => [['tasks' => [['state' => ['values' => 1]]]], 'Invalid nested task state: expected an array with a tasks list.'];
        yield 'nested-null-tasks' => [['tasks' => [['state' => ['tasks' => null]]]], 'Invalid nested task state: expected an array with a tasks list.'];
        yield 'nested-scalar-tasks' => [['tasks' => [['state' => ['tasks' => '0']]]], 'Invalid nested task state: expected an array with a tasks list.'];
        yield 'nested-map-tasks' => [['tasks' => [['state' => ['tasks' => ['x' => []]]]]], 'Invalid nested task state: expected an array with a tasks list.'];
        yield 'snapshot-false-checkpoint' => [['checkpoint' => false], 'Invalid snapshot checkpoint: expected an array or null.'];
        yield 'snapshot-zero-checkpoint' => [['checkpoint' => 0], 'Invalid snapshot checkpoint: expected an array or null.'];
        yield 'snapshot-string-checkpoint' => [['checkpoint' => '0'], 'Invalid snapshot checkpoint: expected an array or null.'];
        yield 'parent-string-checkpoint' => [['parent_checkpoint' => '0'], 'Invalid parent checkpoint: expected an array or null.'];
        yield 'task-reference-string-checkpoint' => [['tasks' => [['checkpoint' => '0']]], 'Invalid task checkpoint: expected an array or null.'];
        yield 'task-state-string-checkpoint' => [['tasks' => [['checkpoint' => '0', 'state' => ['tasks' => []]]]], 'Invalid task checkpoint: expected an array or null.'];
    }

    /** Controlled rejections are adaptations; no exception-class parity or kill credit. */
    #[DataProvider('malformedRows')]
    public function testMalformedEntryAdaptationsAreControlled(array $row, string $message): void
    {
        $this->assertTaskContract(new PregelTaskDescription());
        [$graph] = self::graph(json_encode($row, JSON_THROW_ON_ERROR));
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $graph->getState(self::caller());
    }

    public function testHistoryYieldsValidPrefixBeforeMalformedEntryAdaptation(): void
    {
        $this->assertTaskContract(new PregelTaskDescription());
        [$graph, $transport] = self::graph(<<<'JSON'
[{"values":"A","checkpoint":{"thread_id":"t","checkpoint_id":"a"},"tasks":[]},{"tasks":[{"id":"bad","state":{"values":"bad"}}]},{"values":"C","tasks":[]}]
JSON);
        $history = $graph->getStateHistory(self::caller());
        $this->assertSame([], $transport->requests);
        $history->rewind();
        $prefix = [$history->current()];
        $this->assertSnapshotFields($prefix[0], ['values' => 'A', 'next' => [],
            'config' => ['configurable' => ['thread_id' => 't', 'checkpoint_id' => 'a', 'checkpoint_map' => []]],
            'metadata' => [], 'createdAt' => null, 'parentConfig' => null, 'tasks' => []]);
        try {
            $history->next();
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame('Invalid nested task state: expected an array with a tasks list.', $exception->getMessage());
            $this->assertCount(1, $prefix);
            $this->assertSame('A', $prefix[0]->values);
            $this->assertFalse($history->valid());
            $this->assertCount(1, $transport->requests);

            return;
        }
        $this->fail('The second entry must reject after the first entry was yielded.');
    }

    public function testFourArgumentConstructorsPathAndLegacyFormsRemainUnchanged(): void
    {
        $interrupts = [['id' => 'local-id', 'value' => 'pause']];
        foreach ([null, TaskPath::pull('node'), TaskPath::push(2)] as $path) {
            $task = new PregelTaskDescription('local', 'node', $interrupts, $path);
            $this->assertSame($path, $task->path);
            $this->assertSame(['id' => 'local', 'name' => 'node', 'path' => $path?->toArray(), 'interrupts' => $interrupts], $task->toArray());
            $this->assertSame(['id' => 'local', 'name' => 'node', 'interrupts' => $interrupts], $task->toLegacyArray());
            $this->assertTaskContract($task);
            $this->assertNull($task->error);
            $this->assertNull($task->state);
            $this->assertNull($task->result);
            $this->assertFalse($task->hasResult());
        }
        $default = new PregelTaskDescription();
        $named = new PregelTaskDescription(name: 'named', id: 'id', path: null);
        $this->assertSame(['id' => '', 'name' => '', 'path' => null, 'interrupts' => []], $default->toArray());
        $this->assertSame(['id' => 'id', 'name' => 'named', 'path' => null, 'interrupts' => []], $named->toArray());
    }

    public function testAppendedPublicValuesAndNullResultPresenceUseOneBit(): void
    {
        $this->assertTaskContract(new PregelTaskDescription());
        $child = new StateSnapshot(values: 'child');
        $task = new PregelTaskDescription('id', 'name', [], null, ['message' => '0'], $child, null, true);
        $this->assertSame(['message' => '0'], $task->error);
        $this->assertSame($child, $task->state);
        $this->assertNull($task->result);
        $this->assertTrue($task->hasResult());
        $this->assertFalse((new PregelTaskDescription(result: null))->hasResult());
        foreach ([false, 0, '', [], ['x' => null]] as $result) {
            $value = new PregelTaskDescription(result: $result);
            $this->assertSame($result, $value->result);
            $this->assertTrue($value->hasResult());
        }
    }

    public function testMapperDoesNotInvokeCallableValuesOrMutateCallerAndRawInput(): void
    {
        $this->assertTaskContract(new PregelTaskDescription());
        $calls = 0;
        $callable = static function () use (&$calls): void {
            ++$calls;
        };
        $fallback = ['thread_id' => 't', 'checkpoint_ns' => null, 'checkpoint_id' => null,
            'checkpoint_map' => null, 'callback' => $callable];
        $raw = ['values' => $callable, 'tasks' => [['id' => 'callable', 'result' => $callable]]];
        $rawBefore = $raw;
        $fallbackBefore = $fallback;
        [$graph] = self::graph('{}');
        $method = new \ReflectionMethod(RemoteGraph::class, 'createStateSnapshot');
        $snapshot = $method->invoke($graph, $raw, $fallback);
        $this->assertInstanceOf(StateSnapshot::class, $snapshot);
        $this->assertSame($callable, $snapshot->values);
        $this->assertTaskContract($snapshot->tasks[0]);
        $this->assertSame($callable, $snapshot->tasks[0]->result);
        $this->assertTrue($snapshot->tasks[0]->hasResult());
        $this->assertSame(['configurable' => ['thread_id' => 't']], $snapshot->config);
        $this->assertSame($rawBefore, $raw);
        $this->assertSame($fallbackBefore, $fallback);
        $this->assertSame(0, $calls);
    }
}
