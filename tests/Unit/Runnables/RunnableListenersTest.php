<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables;

use LangChain\Prompts\ChatPromptTemplate;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use LangChain\Tracers\Run;
use LangChain\Utils\Testing\FakeListChatModel;
use PHPUnit\Framework\TestCase;

/** Source: core/runnables/tests/runnable.test.ts "Listeners work". */
final class RunnableListenersTest extends TestCase
{
    public function testSequenceListenersObserveOnlyTheRootAndFreshRunsOnEachCall(): void
    {
        $starts = [];
        $ends = [];
        $chain = ChatPromptTemplate::fromMessages([['human', '{input}']])
            ->pipe(new FakeListChatModel(['responses' => ['hello']]))
            ->withListeners([
                'onStart' => static function (Run $run, RunnableConfig $config) use (&$starts): void {
                    $starts[] = [$run->id, $run->name(), $config->metadata];
                },
                'onEnd' => static function (Run $run) use (&$ends): void {
                    $ends[] = [$run->id, $run->error, $run->outputs];
                },
            ]);

        foreach (['first', 'second'] as $value) {
            self::assertSame('hello', $chain->invoke(['input' => $value], new RunnableConfig(metadata: ['call' => $value]))->content);
        }
        self::assertCount(2, $starts);
        self::assertCount(2, $ends);
        self::assertSame('RunnableSequence', $starts[0][1]);
        self::assertNotSame($starts[0][0], $starts[1][0]);
        self::assertSame(['call' => 'first'], $starts[0][2]);
        self::assertSame($starts[0][0], $ends[0][0]);
        self::assertNull($ends[0][1]);
        self::assertNotEmpty($ends[0][2]);
    }

    public function testSynchronousHandlersFinishInStartExecutionEndOrder(): void
    {
        $events = [];
        $runnable = (new RunnableLambda(static function (mixed $input) use (&$events): string {
            $events[] = 'execute';

            return strtoupper($input);
        }))->withListeners([
            'onStart' => static function () use (&$events): void { $events[] = 'start'; },
            'onEnd' => static function (Run $run) use (&$events): void {
                $events[] = 'end';
                self::assertSame(['output' => 'HI'], $run->outputs);
            },
        ]);

        self::assertSame('HI', $runnable->invoke('hi'));
        self::assertSame(['start', 'execute', 'end'], $events);
    }

    public function testErrorListenerReceivesFailedRootAndOriginalExceptionEscapes(): void
    {
        $events = [];
        $failure = new \RuntimeException('listener probe failed');
        $runnable = (new RunnableLambda(static function () use ($failure): never { throw $failure; }))
            ->withListeners([
                'onStart' => static function (Run $run) use (&$events): void { $events[] = ['start', $run->id]; },
                'onEnd' => static function () use (&$events): void { $events[] = ['end']; },
                'onError' => static function (Run $run) use (&$events): void {
                    $events[] = ['error', $run->id];
                    self::assertStringContainsString('listener probe failed', $run->error);
                },
            ]);
        try {
            $runnable->invoke('input');
            self::fail('Expected original error.');
        } catch (\RuntimeException $error) {
            self::assertSame($failure, $error);
        }
        self::assertSame('start', $events[0][0]);
        self::assertSame('error', $events[1][0]);
        self::assertSame($events[0][1], $events[1][1]);
        self::assertCount(2, $events);
    }

    public function testStreamListenersCompleteAfterTheStreamIsConsumed(): void
    {
        $events = [];
        $model = (new FakeListChatModel(['responses' => ['hi']]))->withListeners([
            'onStart' => static function () use (&$events): void { $events[] = 'start'; },
            'onEnd' => static function () use (&$events): void { $events[] = 'end'; },
        ]);
        $chunks = iterator_to_array($model->stream('question'));

        self::assertSame('hi', implode('', array_map(static fn ($pair): string => $pair[1]->content, $chunks)));
        self::assertSame(['start', 'end'], $events);
    }

    public function testBindingListenerUsesBoundAndCallConfigAndReplacesPriorFactoriesAsSourceDoes(): void
    {
        $seen = [];
        $prior = 0;
        $runnable = (new RunnableLambda(static fn ($input) => $input))
            ->withConfig(['tags' => ['bound'], 'metadata' => ['key' => 'bound']])
            ->withListeners(['onStart' => static function () use (&$prior): void { ++$prior; }])
            ->withListeners(['onStart' => static function (Run $run, RunnableConfig $config) use (&$seen): void {
                $seen = [$run->name(), $config->tags, $config->metadata];
            }]);

        $runnable->invoke('hi', new RunnableConfig(runName: 'call trace', tags: ['call'], metadata: ['key' => 'call']));
        self::assertSame(0, $prior);
        self::assertSame(['call trace', ['bound', 'call'], ['key' => 'call']], $seen);
    }
}
