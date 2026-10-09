<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Func;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Cache\InMemoryCache;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Func\Func;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\State\Annotation;
use LangGraph\State\StateGraph;
use PHPUnit\Framework\Attributes\CoversClass;

use function LangGraph\Pregel\interrupt;

/**
 * Port of `describe("interrupt handling")` from `langgraph-core/src/tests/func.test.ts`.
 *
 * `invoke()` here returns the output channel, not upstream's `{__interrupt__: [...]}` envelope,
 * so interrupts are read from the `updates` stream and from `getState().tasks`.
 *
 * Not ported, with reasons:
 *  - the `SlowInMemoryCache` variant of "multiple interrupts with cache": it differs from the
 *    plain cache only by an artificial `await` delay, which a synchronous port cannot express.
 */
#[CoversClass(Func::class)]
final class InterruptHandlingTest extends FuncTestCase
{
    public function testCanCallAStateGraphFromATaskWithInterruptInParentGraph(): void
    {
        $checkpointer = new MemorySaver();
        $addA = (new StateGraph(Annotation::root([
            'data' => Annotation::withReducer(static fn ($a, $b) => $b, static fn (): array => []),
        ])))
            ->addNode('mynode', static fn (array $state): array => ['data' => array_map(static fn (string $it): string => $it . 'a', $state['data'])])
            ->addEdge(Constants::START, 'mynode')
            ->compile(['checkpointer' => $checkpointer]);

        $submapper = Func::task('submapper', static fn (int $data): string => (string) $data);
        $mapper = Func::task('mapper', static function (int $data) use ($submapper): string {
            $sub = self::await($submapper($data));

            return $sub . $sub;
        });

        $capturedOutput = null;
        $graph = Func::entrypoint(
            ['name' => 'graph', 'checkpointer' => $checkpointer],
            static function (array $data, RunnableConfig $config) use ($mapper, $addA, &$capturedOutput): array {
                $mapped = self::all(array_map(static fn (int $i) => $mapper($i), $data));
                $answer = interrupt('question');
                $final = array_map(static fn (string $m): string => $m . $answer, $mapped);
                $output = $addA->invoke(['data' => $final], $config)['data'];
                $capturedOutput = $output;

                return $output;
            },
        );
        $config = self::config();

        $chunks = self::updates($graph, [0, 1], $config);

        self::assertCount(5, $chunks);
        self::assertSame(['submapper' => '0'], $chunks[0]);
        self::assertSame(['mapper' => '00'], $chunks[1]);
        self::assertSame(['submapper' => '1'], $chunks[2]);
        self::assertSame(['mapper' => '11'], $chunks[3]);
        self::assertCount(1, $chunks[4][Constants::INTERRUPT]);
        self::assertSame('question', $chunks[4][Constants::INTERRUPT][0]['value']);
        self::assertNotNull($chunks[4][Constants::INTERRUPT][0]['id']);

        $result = $graph->invoke(new Command(resume: 'answer'), $config);

        self::assertSame(['00answera', '11answera'], $capturedOutput);
        self::assertSame($capturedOutput, $result);
    }

    public function testTaskWithInterrupts(): void
    {
        $taskCallCount = 0;
        $interruptingTask = Func::task('interruptTask', static function () use (&$taskCallCount): mixed {
            ++$taskCallCount;

            return interrupt('Please provide input');
        });

        $graphCallCount = 0;
        $saver = new MemorySaver();
        $graph = Func::entrypoint(
            ['checkpointer' => $saver, 'name' => 'interruptGraph'],
            static function (string $input) use ($interruptingTask, &$graphCallCount): string {
                ++$graphCallCount;
                $response = self::await($interruptingTask());

                return $input . $response;
            },
        );
        $config = self::config();

        $interrupts = self::interruptsOf($graph, 'the correct ', $config);

        self::assertCount(1, $interrupts);
        self::assertSame('Please provide input', $interrupts[0]['value']);
        self::assertNotNull($interrupts[0]['id']);
        self::assertSame(1, $taskCallCount);
        self::assertSame(1, $graphCallCount);

        $currTasks = $graph->getState($config)->tasks;
        self::assertCount(1, $currTasks[0]->interrupts);

        $result = $graph->invoke(new Command(resume: 'answer'), $config);

        self::assertCount(0, $graph->getState($config)->tasks);

        self::assertSame('the correct answer', $result);
        self::assertSame(2, $taskCallCount);
        self::assertSame(2, $graphCallCount);
    }

    public function testCanInterruptTheEntrypoint(): void
    {
        // equivalent to `test_interrupt_functional` in the python tests
        $fooCalls = 0;
        $foo = Func::task('foo', static function (array $state) use (&$fooCalls): array {
            ++$fooCalls;

            return ['a' => $state['a'] . 'foo'];
        });
        $bar = Func::task('bar', static fn (array $state): array => ['a' => $state['a'] . $state['b']]);

        $graph = Func::entrypoint(
            ['checkpointer' => new MemorySaver(), 'name' => 'interruptGraph'],
            static function (array $inputs) use ($foo, $bar): array {
                $fooResult = self::await($foo($inputs));
                $value = interrupt('Provide value for bar:');

                return self::await($bar([...$fooResult, 'b' => $value]));
            },
        );
        $config = self::config();

        $interrupts = self::interruptsOf($graph, ['a' => ''], $config);
        self::assertCount(1, $interrupts);
        self::assertSame('Provide value for bar:', $interrupts[0]['value']);

        self::assertSame(['a' => 'foobar'], $graph->invoke(new Command(resume: 'bar'), $config));
        // `foo` finished before the interrupt, so the resume reused its recorded result.
        self::assertSame(1, $fooCalls);
    }

    public function testCanInterruptTasks(): void
    {
        // equivalent to `test_interrupt_task_functional` in the python tests
        $foo = Func::task('foo', static fn (array $state): array => ['a' => $state['a'] . 'foo']);
        $bar = Func::task('bar', static fn (array $state): array => ['a' => $state['a'] . interrupt('Provide value for bar:')]);

        $graph = Func::entrypoint(
            ['checkpointer' => new MemorySaver(), 'name' => 'interruptGraph'],
            static function (array $inputs) use ($foo, $bar): array {
                $fooResult = self::await($foo($inputs));

                return self::await($bar($fooResult));
            },
        );
        $config = self::config();

        $interrupts = self::interruptsOf($graph, ['a' => ''], $config);
        self::assertCount(1, $interrupts);
        self::assertSame('Provide value for bar:', $interrupts[0]['value']);

        self::assertSame(['a' => 'foobar'], $graph->invoke(new Command(resume: 'bar'), $config));
    }

    public function testCanHandleFalsyReturnValuesFromTasks(): void
    {
        // equivalent to `test_falsy_return_from_task` in the python tests
        $falsyTask = Func::task('falsyTask', static fn (): bool => false);
        $graph = Func::entrypoint(
            ['checkpointer' => new MemorySaver(), 'name' => 'falsyGraph'],
            static function (array $state) use ($falsyTask): void {
                self::await($falsyTask());
                interrupt('test');
            },
        );
        $config = self::config();

        $interrupts = self::interruptsOf($graph, ['a' => 5], $config);
        self::assertCount(1, $interrupts);
        self::assertSame('test', $interrupts[0]['value']);

        self::assertNull($graph->invoke(new Command(resume: '123'), $config));
    }

    public function testHandlesMultipleInterruptsInAnImperativeStyle(): void
    {
        // equivalent to `test_multiple_interrupts_imperative` in the python tests
        $counter = 0;
        $double = Func::task('double', static function (int $x) use (&$counter): int {
            ++$counter;

            return 2 * $x;
        });

        $graph = Func::entrypoint(['checkpointer' => new MemorySaver(), 'name' => 'graph'], static function () use ($double): array {
            $values = [];
            foreach ([1, 2, 3] as $idx) {
                $values[] = self::await($double($idx));
                $values[] = interrupt(['a' => "boo{$idx}"]);
            }

            return ['values' => $values];
        });
        $config = self::config();

        self::assertSame([['a' => 'boo1']], array_column(self::interruptsOf($graph, [], $config), 'value'));
        self::assertSame([['a' => 'boo2']], array_column(self::interruptsOf($graph, new Command(resume: 'a'), $config), 'value'));
        self::assertSame([['a' => 'boo3']], array_column(self::interruptsOf($graph, new Command(resume: 'b'), $config), 'value'));

        self::assertSame(['values' => [2, 'a', 4, 'b', 6, 'c']], $graph->invoke(new Command(resume: 'c'), $config));

        // `double` ran three times in total: each resume reused the results already recorded.
        self::assertSame(3, $counter);
    }

    public function testHandlesMultipleInterruptsFromTasks(): void
    {
        $addParticipant = Func::task('add-participant', static function (string $name): string {
            $feedback = interrupt("Hey do you want to add {$name}?");

            if ($feedback === false) {
                return "The user changed their mind and doesnt want to add {$name}!";
            }
            if ($feedback === true) {
                return "Added {$name}!";
            }

            throw new \RuntimeException('Invalid feedback');
        });

        $program = Func::entrypoint(
            ['name' => 'program', 'checkpointer' => new MemorySaver()],
            static fn (): array => [
                self::await($addParticipant('James')),
                self::await($addParticipant('Will')),
            ],
        );
        $config = self::config();

        $first = self::interruptsOf($program, [], $config);
        self::assertCount(1, $first);
        self::assertSame('Hey do you want to add James?', $first[0]['value']);
        self::assertNotNull($first[0]['id']);

        $currTasks = $program->getState($config)->tasks;
        self::assertCount(1, $currTasks[0]->interrupts);
        self::assertSame('Hey do you want to add James?', $currTasks[0]->interrupts[0]['value']);
        self::assertNotNull($currTasks[0]->interrupts[0]['id']);

        // The graph-wide resume answers James's interrupt only; Will's task asks its own question.
        $second = self::interruptsOf($program, new Command(resume: true), $config);
        self::assertCount(1, $second);
        self::assertSame('Hey do you want to add Will?', $second[0]['value']);
        self::assertNotNull($second[0]['id']);
        self::assertNotSame($first[0]['id'], $second[0]['id']);

        $currTasks = $program->getState($config)->tasks;
        self::assertCount(1, $currTasks[0]->interrupts);
        self::assertSame('Hey do you want to add Will?', $currTasks[0]->interrupts[0]['value']);
        self::assertNotNull($currTasks[0]->interrupts[0]['id']);

        self::assertSame(['Added James!', 'Added Will!'], $program->invoke(new Command(resume: true), $config));
        self::assertCount(0, $program->getState($config)->tasks);
    }

    public function testMultipleInterruptsWithCache(): void
    {
        $cache = new InMemoryCache();
        $counter = 0;
        $double = Func::task(
            ['name' => 'double', 'cachePolicy' => ['ttl' => 1000]],
            static function (int $x) use (&$counter): int {
                ++$counter;

                return 2 * $x;
            },
        );

        $graph = Func::entrypoint(
            ['name' => 'graph', 'checkpointer' => new MemorySaver(), 'cache' => $cache],
            static function () use ($double): array {
                $values = [];
                foreach ([1, 1, 2, 2, 3, 3] as $idx) {
                    $first = self::await($double($idx));
                    $second = interrupt(['a' => 'boo']);
                    $values[] = [$first, $second];
                }

                return ['values' => $values];
            },
        );

        $expected = ['values' => [[2, 'a'], [2, 'b'], [4, 'c'], [4, 'd'], [6, 'e'], [6, 'f']]];
        $runThread = static function (string $threadId) use ($graph): mixed {
            $config = self::config(threadId: $threadId);
            $graph->invoke([], $config);
            foreach (['a', 'b', 'c', 'd', 'e'] as $answer) {
                $graph->invoke(new Command(resume: $answer), $config);
            }

            return $graph->invoke(new Command(resume: 'f'), $config);
        };

        self::assertSame($expected, $runThread('1'));
        self::assertSame(3, $counter);

        // A second thread asks for the same three inputs: all served from the cache.
        self::assertSame($expected, $runThread('2'));
        self::assertSame(3, $counter);

        $graph->clearCache();

        self::assertSame($expected, $runThread('3'));
        self::assertSame(6, $counter);
    }
}
