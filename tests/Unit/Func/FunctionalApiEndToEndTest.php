<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Func;

use LangChain\OutputParsers\StringOutputParser;
use LangChain\Prompts\ChatPromptTemplate;
use LangChain\Runnables\RunnableConfig;
use LangChain\Utils\Testing\FakeListChatModel;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Func\Func;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\Retry\RetryPolicy;
use PHPUnit\Framework\Attributes\CoversClass;

use function LangGraph\Pregel\interrupt;

/**
 * The failure modes an inline-invoking `task()` would hide.
 *
 * A task that just called its function would pass the decorator tests while never being
 * checkpointed, retried by the engine, or reused on resume. These cases pin the three
 * properties that make a task a task, and run a real prompt | model | parser chain through one.
 */
#[CoversClass(Func::class)]
final class FunctionalApiEndToEndTest extends FuncTestCase
{
    public function testATaskRetriedAfterFailureIsCheckpointedOnceOnItsLastAttempt(): void
    {
        $saver = new MemorySaver();
        $attempts = 0;
        $flaky = Func::task(
            ['name' => 'flaky', 'retry' => new RetryPolicy(maxAttempts: 4, logWarning: false)],
            static function (string $x) use (&$attempts): string {
                if (++$attempts < 3) {
                    throw new \RuntimeException("boom {$attempts}");
                }

                return strtoupper($x);
            },
        );
        $graph = Func::entrypoint(
            ['name' => 'wf', 'checkpointer' => $saver],
            static fn (string $x): string => self::await($flaky($x)),
        );
        $config = self::config();

        self::assertSame('HI', $graph->invoke('hi', $config));
        self::assertSame(3, $attempts);

        // The failed attempts left nothing behind: exactly one RETURN write was recorded, and no ERROR.
        $channels = array_column($saver->getTuple(['configurable' => $config->configurable])->pendingWrites, 1);
        self::assertSame(0, \count(array_filter($channels, static fn (string $c): bool => $c === Constants::ERROR)));
    }

    public function testATaskThatExhaustsItsRetriesFailsTheRunAndIsRecordedAsFailed(): void
    {
        $attempts = 0;
        $broken = Func::task(
            ['name' => 'broken', 'retry' => ['maxAttempts' => 3, 'logWarning' => false]],
            static function () use (&$attempts): never {
                ++$attempts;
                throw new \RuntimeException('still broken');
            },
        );
        $saver = new MemorySaver();
        $graph = Func::entrypoint(['name' => 'wf', 'checkpointer' => $saver], static fn () => $broken());

        try {
            $graph->invoke([], self::config());
            self::fail('expected the task error to fail the run');
        } catch (\RuntimeException $e) {
            self::assertSame('still broken', $e->getMessage());
        }

        self::assertSame(3, $attempts);
        $channels = array_column($saver->getTuple(['configurable' => self::config()->configurable])->pendingWrites, 1);
        self::assertContains(Constants::ERROR, $channels);
    }

    public function testAFinishedTaskIsNotRunAgainWhenTheWorkflowIsRetried(): void
    {
        // The entrypoint dies AFTER its task finished. Re-invoking the thread resumes from the
        // checkpoint: the task's recorded result is reused rather than the function being called.
        $saver = new MemorySaver();
        $taskRuns = 0;
        $expensive = Func::task('expensive', static function (int $n) use (&$taskRuns): int {
            ++$taskRuns;

            return $n * 100;
        });

        $failOnce = true;
        $graph = Func::entrypoint(
            ['name' => 'wf', 'checkpointer' => $saver],
            static function (int $n) use ($expensive, &$failOnce): int {
                $value = self::await($expensive($n));
                if ($failOnce) {
                    $failOnce = false;
                    throw new \RuntimeException('transient');
                }

                return $value + 1;
            },
        );
        $config = self::config();

        try {
            $graph->invoke(7, $config);
            self::fail('the first run should fail');
        } catch (\RuntimeException $e) {
            self::assertSame('transient', $e->getMessage());
        }

        self::assertSame(701, $graph->invoke(null, $config));
        self::assertSame(1, $taskRuns, 'the finished task must come from its checkpointed result');
    }

    public function testPreviousStateAcrossInvocationsFeedsTasksAndTheEntrypoint(): void
    {
        $saver = new MemorySaver();
        $addTo = Func::task('addTo', static fn (int $n): int => $n + (Func::getPreviousState() ?? 0));
        $graph = Func::entrypoint(
            ['name' => 'running-total', 'checkpointer' => $saver],
            static fn (int $n): int => self::await($addTo($n)),
        );
        $config = self::config();

        self::assertSame(5, $graph->invoke(5, $config));
        self::assertSame(8, $graph->invoke(3, $config));
        self::assertSame(10, $graph->invoke(2, $config));
        self::assertSame(1, $graph->invoke(1, self::config(threadId: 'fresh')));
    }

    public function testTasksCalledFromATaskAreCheckpointedTooAndResumeFromWhereTheyStopped(): void
    {
        $runs = ['outer' => 0, 'inner' => 0];
        $inner = Func::task('inner', static function (int $n) use (&$runs): int {
            ++$runs['inner'];

            return $n + 1;
        });
        $outer = Func::task('outer', static function (int $n) use ($inner, &$runs): int {
            ++$runs['outer'];
            $a = self::await($inner($n));
            $answer = interrupt('continue?');

            return $a + $answer;
        });

        $graph = Func::entrypoint(['name' => 'wf', 'checkpointer' => new MemorySaver()], static fn (int $n): int => self::await($outer($n)));
        $config = self::config();

        $interrupts = self::interruptsOf($graph, 10, $config);
        self::assertSame('continue?', $interrupts[0]['value']);

        self::assertSame(111, $graph->invoke(new Command(resume: 100), $config));
        // `outer` re-ran to take the answer; `inner` finished before the interrupt and was reused.
        self::assertSame(['outer' => 2, 'inner' => 1], $runs);
    }

    public function testARealPromptModelParserChainRunsInsideATaskAndIsCheckpointed(): void
    {
        $model = new FakeListChatModel(['responses' => ['Paris', 'Rome']]);
        $chain = ChatPromptTemplate::fromTemplate('What is the capital of {country}?')
            ->pipe($model)
            ->pipe(new StringOutputParser());

        $capital = Func::task('capital', static fn (string $country): string => $chain->invoke(['country' => $country]));

        $failOnce = true;
        $graph = Func::entrypoint(
            ['name' => 'geography', 'checkpointer' => new MemorySaver()],
            static function (array $countries) use ($capital, &$failOnce): array {
                $answers = self::all(array_map($capital, $countries));
                if ($failOnce) {
                    $failOnce = false;
                    throw new \RuntimeException('transient');
                }

                return array_combine($countries, $answers);
            },
        );
        $config = self::config();

        try {
            $graph->invoke(['France', 'Italy'], $config);
            self::fail('the first run should fail');
        } catch (\RuntimeException) {
        }

        // The model was asked twice in the failed run and never again: the retry reads both
        // answers from the checkpoint (a re-run would have wrapped the list around to "Paris").
        self::assertSame(['France' => 'Paris', 'Italy' => 'Rome'], $graph->invoke(null, $config));
    }

    public function testATaskCanBeCalledFromAStateGraphNode(): void
    {
        $double = Func::task('double', static fn (int $n): int => $n * 2);

        $graph = (new \LangGraph\State\StateGraph(['value' => 'int']))
            ->addNode('node', static fn (array $state): array => ['value' => self::await($double($state['value']))])
            ->addEdge(Constants::START, 'node')
            ->addEdge('node', Constants::END)
            ->compile(['checkpointer' => new MemorySaver()]);

        self::assertSame(['value' => 42], $graph->invoke(['value' => 21], new RunnableConfig(configurable: ['thread_id' => 'g'])));
    }
}
