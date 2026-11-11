<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Func;

use LangChain\Runnables\RunnableConfig;
use LangChain\Utils\Await;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Cache\InMemoryCache;
use LangGraph\Pregel\Command;
use LangGraph\Pregel\Utils\Config;
use LangGraph\Func\Func;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangGraph\Pregel\interrupt;

/** Source-derived writer/PUSH behavior with the established PHP pair/triple envelope adaptation. */
#[CoversClass(Func::class)]
final class FunctionalCustomStreamTest extends TestCase
{
    public function testEntrypointWriterStreamsOrderedCustomPayloads(): void
    {
        $graph = Func::entrypoint(['name' => 'graph', 'checkpointer' => new MemorySaver()], static function (string $input, RunnableConfig $config): string {
            ($config->options['writer'])('hello ' . $input);
            ($config->options['writer'])('hello again, ' . $input);

            return 'goodbye, ' . $input;
        });
        $config = new RunnableConfig(configurable: ['thread_id' => 'writer']);
        self::assertSame('goodbye, world', $graph->invoke('world', $config));
        $run = $graph->stream('world', $config->with(['options' => ['streamMode' => 'custom']]));
        $chunks = iterator_to_array($run);
        self::assertSame([['custom', 'hello world'], ['custom', 'hello again, world']], $chunks);
        // Upstream scalar custom mode yields these bare values. PHP preserves its mode envelope.
        self::assertSame(['hello world', 'hello again, world'], array_column($chunks, 1));
        self::assertSame('goodbye, world', $run->getReturn());
    }

    public function testCalledTaskUsesAmbientWriterAndProducesTaskEvents(): void
    {
        $task = Func::task('child', static function (string $input): string {
            Config::getWriter()('from task: ' . $input);

            return strtoupper($input);
        });
        $graph = Func::entrypoint('graph', static fn (string $input): string => Await::sync($task($input)));
        $run = $graph->stream('world', new RunnableConfig(options: ['streamMode' => ['tasks', 'custom', 'updates']]));
        $chunks = iterator_to_array($run);
        self::assertContains(['custom', 'from task: world'], $chunks);
        $tasks = array_values(array_map(static fn (array $c): array => $c[1], array_filter($chunks, static fn (array $c): bool => $c[0] === 'tasks' && $c[1]['name'] === 'child')));
        self::assertCount(2, $tasks);
        self::assertSame(['world'], $tasks[0]['input']);
        self::assertSame($tasks[0]['id'], $tasks[1]['id']);
        self::assertSame([], $tasks[1]['result']); // RETURN is not the entrypoint stream channel.
        self::assertSame('WORLD', $run->getReturn());
    }
    public function testFunctionalInterruptOmitsPushResultAndResumeStartsRecordedTasks(): void
    {
        $doneCalls = 0;
        $done = Func::task('done', static function (string $input) use (&$doneCalls): string { $doneCalls++; return 'done:' . $input; });
        $ask = Func::task('ask', static fn (string $input): string => interrupt('q:' . $input));
        $graph = Func::entrypoint(['name' => 'g', 'checkpointer' => new MemorySaver()], static function (string $input) use ($done, $ask): string {
            $first = Await::sync($done($input));

            return $first . '|' . Await::sync($ask($first));
        });
        $config = new RunnableConfig(configurable: ['thread_id' => 'push-pause'], options: ['streamMode' => ['tasks', 'updates']]);
        $paused = iterator_to_array($graph->stream('x', $config));
        $tasks = array_values(array_map(static fn (array $c): array => $c[1], array_filter($paused, static fn (array $c): bool => $c[0] === 'tasks')));
        self::assertSame(['g', 'done', 'done', 'ask', 'g'], array_column($tasks, 'name'));
        self::assertArrayHasKey('input', $tasks[3]);
        self::assertSame('q:done:x', $tasks[4]['interrupts'][0]['value']);
        self::assertSame([], array_filter($tasks, static fn (array $t): bool => $t['name'] === 'ask' && array_key_exists('result', $t)));
        $run = $graph->stream(new Command(resume: 'ans'), $config);
        $resumed = iterator_to_array($run);
        $events = array_values(array_map(static fn (array $c): array => $c[1], array_filter($resumed, static fn (array $c): bool => $c[0] === 'tasks')));
        self::assertSame(['g', 'done', 'ask', 'ask', 'g'], array_column($events, 'name'));
        self::assertArrayHasKey('input', $events[1]);
        self::assertSame($tasks[1]['id'], $events[1]['id']);
        self::assertSame(1, $doneCalls);
        self::assertSame('done:x|ans', $run->getReturn());
    }

    public function testCachedPushTaskStillStartsBeforeItsCachedUpdateWithoutResult(): void
    {
        $calls = 0;
        $child = Func::task(['name' => 'cached', 'cachePolicy' => true], static function (string $input) use (&$calls): string { $calls++; return strtoupper($input); });
        $graph = Func::entrypoint(['name' => 'g', 'cache' => new InMemoryCache()], static fn (string $input): string => Await::sync($child($input)));
        $config = new RunnableConfig(options: ['streamMode' => ['tasks', 'updates']]);
        iterator_to_array($graph->stream('x', $config));
        $run = $graph->stream('x', $config);
        $chunks = iterator_to_array($run);
        $tasks = array_values(array_map(static fn (array $c): array => $c[1], array_filter($chunks, static fn (array $c): bool => $c[0] === 'tasks')));
        self::assertSame(['g', 'cached', 'g'], array_column($tasks, 'name'));
        self::assertArrayHasKey('input', $tasks[1]);
        self::assertArrayNotHasKey('result', $tasks[1]);
        $update = array_values(array_filter($chunks, static fn (array $c): bool => $c[0] === 'updates' && isset($c[1]['cached'])))[0];
        self::assertSame(['cached' => true], $update[1]['__metadata__']);
        self::assertSame(1, $calls);
        self::assertSame('X', $run->getReturn());
    }

}
