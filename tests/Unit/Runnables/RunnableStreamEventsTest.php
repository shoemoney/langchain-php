<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables;

use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\Messages\AIMessageChunk;
use LangChain\Prompts\ChatPromptTemplate;
use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use LangChain\Runnables\RunnableParallel;
use LangChain\Runnables\RunnablePassthrough;
use LangChain\Runnables\RunnablePick;
use LangChain\Tools\DynamicTool;
use LangChain\Tracers\LogStreamCallbackHandler;
use LangChain\Tracers\RootEventFilter;
use LangChain\Tracers\StreamEvent;
use LangChain\Utils\Testing\FakeListChatModel;
use LangChain\Utils\Testing\FakeStreamingLLM;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `runnables/tests/runnable_stream_events.test.ts` — the "v1" schema, built on `streamLog()`.
 *
 * Not converted: the retriever test (the port has no `FakeRetriever`). Event ORDER across different runs
 * differs from upstream's async interleaving where noted; per-run order and content are asserted.
 */
#[CoversClass(Runnable::class)]
#[CoversClass(LogStreamCallbackHandler::class)]
#[CoversClass(RootEventFilter::class)]
final class RunnableStreamEventsTest extends TestCase
{
    private static function reverse(): RunnableLambda
    {
        return RunnableLambda::from(static fn (string $s): string => strrev($s));
    }

    /** @return list<StreamEvent> */
    private static function events(\Generator $stream): array
    {
        return StreamEventsAssertions::collect($stream);
    }

    public function testStreamEventsMethod(): void
    {
        $chain = self::reverse()->withConfig(['runName' => 'reverse']);

        $events = self::events($chain->streamEvents('hello', null, 'v1'));

        self::assertSame([
            ['on_chain_start', 'reverse', [], ['input' => 'hello']],
            ['on_chain_stream', 'reverse', [], ['chunk' => 'olleh']],
            ['on_chain_end', 'reverse', [], ['output' => 'olleh']],
        ], StreamEventsAssertions::summarise($events));
    }

    public function testStreamEventsWithThreeRunnables(): void
    {
        $r = self::reverse();
        $chain = $r->withConfig(['runName' => '1'])
            ->pipe($r->withConfig(['runName' => '2']))
            ->pipe($r->withConfig(['runName' => '3']));

        $events = self::events($chain->streamEvents('hello', null, 'v1'));

        self::assertSame([
            ['on_chain_start', 'RunnableSequence', [], ['input' => 'hello']],
            ['on_chain_start', '1', ['seq:step:1'], []],
            ['on_chain_stream', '1', ['seq:step:1'], ['chunk' => 'olleh']],
            ['on_chain_end', '1', ['seq:step:1'], ['input' => 'hello', 'output' => 'olleh']],
            ['on_chain_start', '2', ['seq:step:2'], []],
            ['on_chain_stream', '2', ['seq:step:2'], ['chunk' => 'hello']],
            ['on_chain_end', '2', ['seq:step:2'], ['input' => 'olleh', 'output' => 'hello']],
            ['on_chain_start', '3', ['seq:step:3'], []],
            ['on_chain_stream', '3', ['seq:step:3'], ['chunk' => 'olleh']],
            ['on_chain_stream', 'RunnableSequence', [], ['chunk' => 'olleh']],
            ['on_chain_end', '3', ['seq:step:3'], ['input' => 'hello', 'output' => 'olleh']],
            ['on_chain_end', 'RunnableSequence', [], ['output' => 'olleh']],
        ], StreamEventsAssertions::summarise($events));
    }

    public function testStreamEventsWithThreeRunnablesWithBackgroundedCallbacksSetToTrue(): void
    {
        $original = getenv('LANGCHAIN_CALLBACKS_BACKGROUND');
        putenv('LANGCHAIN_CALLBACKS_BACKGROUND=true');

        try {
            $r = self::reverse();
            $chain = $r->withConfig(['runName' => '1'])
                ->pipe($r->withConfig(['runName' => '2']))
                ->pipe($r->withConfig(['runName' => '3']));
            $events = self::events($chain->streamEvents('hello', null, 'v1'));
        } finally {
            $original === false ? putenv('LANGCHAIN_CALLBACKS_BACKGROUND') : putenv('LANGCHAIN_CALLBACKS_BACKGROUND=' . $original);
        }

        self::assertCount(12, $events);
        self::assertSame(['output' => 'olleh'], $events[11]->data);
    }

    public function testStreamEventsWithThreeRunnablesWithFiltering(): void
    {
        $r = self::reverse();
        $chain = $r->withConfig(['runName' => '1'])
            ->pipe($r->withConfig(['runName' => '2', 'tags' => ['my_tag']]))
            ->pipe($r->withConfig(['runName' => '3', 'tags' => ['my_tag']]));

        $events = self::events($chain->streamEvents('hello', null, 'v1', ['includeNames' => ['1']]));

        self::assertSame([
            ['on_chain_start', '1', ['seq:step:1'], []],
            ['on_chain_stream', '1', ['seq:step:1'], ['chunk' => 'olleh']],
            ['on_chain_end', '1', ['seq:step:1'], ['input' => 'hello', 'output' => 'olleh']],
        ], StreamEventsAssertions::summarise($events));

        $events2 = self::events($chain->streamEvents('hello', null, 'v1', [
            'excludeNames' => ['2'],
            'includeTags' => ['my_tag'],
        ]));

        self::assertSame(
            ['on_chain_start', 'on_chain_stream', 'on_chain_end'],
            array_map(static fn (StreamEvent $e): string => $e->event, $events2),
        );
        foreach ($events2 as $event) {
            self::assertSame('3', $event->name);
            self::assertContains('seq:step:3', $event->tags);
            self::assertContains('my_tag', $event->tags);
        }
        self::assertSame([], $events2[0]->data);
        self::assertSame(['chunk' => 'olleh'], $events2[1]->data);
        self::assertSame(['input' => 'hello', 'output' => 'olleh'], $events2[2]->data);
    }

    /**
     * As in the v2 port, the map's per-key children are not traced; the root sequence is.
     */
    public function testStreamEventsWithARunnableMap(): void
    {
        $chain = RunnableParallel::from([
            'reversed' => self::reverse(),
            'original' => new RunnablePassthrough(),
        ])->pipe(new RunnablePick('reversed'));

        $events = self::events($chain->streamEvents('hello', null, 'v1'));

        self::assertSame(
            ['on_chain_start RunnableSequence', 'on_chain_stream RunnableSequence', 'on_chain_end RunnableSequence'],
            StreamEventsAssertions::names($events),
        );
        self::assertSame(['input' => 'hello'], $events[0]->data);
        self::assertSame(['chunk' => 'olleh'], $events[1]->data);
        self::assertSame(['output' => 'olleh'], $events[2]->data);
    }

    public function testStreamEventsWithLlm(): void
    {
        $model = (new FakeStreamingLLM(['responses' => ['hey!']]))->withConfig([
            'metadata' => ['a' => 'b'],
            'tags' => ['my_model'],
            'runName' => 'my_model',
        ]);

        $events = self::events($model->streamEvents('hello', null, 'v1'));

        self::assertSame(
            ['on_llm_start', 'on_llm_stream', 'on_llm_stream', 'on_llm_stream', 'on_llm_stream', 'on_llm_end'],
            array_map(static fn (StreamEvent $e): string => $e->event, $events),
        );
        foreach ($events as $event) {
            self::assertSame('my_model', $event->name);
            self::assertContains('my_model', $event->tags);
            self::assertSame('b', $event->metadata['a']);
        }
        self::assertSame(['input' => 'hello'], $events[0]->data);
        self::assertSame(['h', 'e', 'y', '!'], array_map(static fn (StreamEvent $e): string => $e->data['chunk'], \array_slice($events, 1, 4)));
        self::assertSame('hey!', $events[5]->data['output']['generations'][0][0]['text']);
    }

    /**
     * End to end through a real prompt and chat model. A chat model's v1 type is `llm`, because the log
     * entry type is the tracer run type.
     */
    public function testStreamEventsWithChatModelChain(): void
    {
        $template = ChatPromptTemplate::fromMessages([
            ['system', 'You are Godzilla'],
            ['human', '{question}'],
        ])->withConfig(['runName' => 'my_template', 'tags' => ['my_template']]);
        $model = (new FakeListChatModel(['responses' => ['ROAR']]))->withConfig([
            'metadata' => ['a' => 'b'],
            'tags' => ['my_model'],
            'runName' => 'my_model',
        ]);
        $chain = $template->pipe($model)->withConfig([
            'metadata' => ['foo' => 'bar'],
            'tags' => ['my_chain'],
            'runName' => 'my_chain',
        ]);

        $events = self::events($chain->streamEvents(['question' => 'hello'], null, 'v1'));

        $names = StreamEventsAssertions::names($events);
        self::assertSame('on_chain_start my_chain', $names[0]);
        self::assertSame('on_chain_end my_chain', $names[array_key_last($names)]);
        self::assertSame(['input' => ['question' => 'hello']], $events[0]->data);
        self::assertSame(['my_chain'], $events[0]->tags);
        self::assertSame('bar', $events[0]->metadata['foo']);

        self::assertSame(['on_prompt_start my_template', 'on_prompt_end my_template'], \array_slice($names, 1, 2));
        self::assertSame(['input' => ['question' => 'hello']], $events[1]->data);

        $modelStart = StreamEventsAssertions::first($events, 'on_llm_start');
        self::assertSame('my_model', $modelStart->name);
        self::assertEqualsCanonicalizing(['my_model', 'my_chain', 'seq:step:2'], $modelStart->tags);
        self::assertSame('b', $modelStart->metadata['a']);

        $modelChunks = array_map(
            static fn (StreamEvent $e): string => $e->data['chunk'] instanceof ChatGenerationChunk
                ? $e->data['chunk']->message->content
                : $e->data['chunk']->content,
            array_values(array_filter($events, static fn (StreamEvent $e): bool => $e->event === 'on_llm_stream' && $e->name === 'my_model')),
        );
        self::assertSame(['R', 'O', 'A', 'R'], $modelChunks);
        $chainChunks = array_map(
            static fn (StreamEvent $e): string => $e->data['chunk']->content,
            array_values(array_filter($events, static fn (StreamEvent $e): bool => $e->event === 'on_chain_stream' && $e->name === 'my_chain')),
        );
        self::assertSame(['R', 'O', 'A', 'R'], $chainChunks);

        $end = $events[array_key_last($events)];
        self::assertInstanceOf(AIMessageChunk::class, $end->data['output']);
        self::assertSame('ROAR', $end->data['output']->content);
    }

    public function testStreamEventsWithSimpleTools(): void
    {
        $tool = new DynamicTool(
            ['name' => 'parameterless', 'description' => 'A tool that does nothing'],
            static fn (): string => 'hello',
        );

        $events = self::events($tool->streamEvents([], null, 'v1'));

        self::assertSame([
            ['on_tool_start', 'parameterless', [], ['input' => []]],
            ['on_tool_stream', 'parameterless', [], ['chunk' => 'hello']],
            ['on_tool_end', 'parameterless', [], ['output' => 'hello']],
        ], StreamEventsAssertions::summarise($events));
    }

    public function testStreamEventsWithTextEventStreamEncoding(): void
    {
        $chain = self::reverse()->withConfig(['runName' => 'reverse']);

        $events = iterator_to_array(
            $chain->streamEvents('hello', new RunnableConfig(runId: ['1234']), 'v1', [], 'text/event-stream'),
            false,
        );

        self::assertCount(4, $events);
        $expectedEvents = ['on_chain_start', 'on_chain_stream', 'on_chain_end'];
        foreach ($expectedEvents as $i => $name) {
            self::assertStringEndsWith("\n\n", $events[$i]);
            $decoded = json_decode(substr($events[$i], \strlen("event: data\ndata: "), -2), true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($name, $decoded['event']);
            self::assertSame('reverse', $decoded['name']);
        }
        self::assertSame("event: end\n\n", $events[3]);
    }

    public function testTheRootEventFilterAppliesToTheRootOnly(): void
    {
        $filter = new RootEventFilter(['includeNames' => ['keep'], 'excludeTags' => ['drop']]);

        self::assertTrue($filter->includeEvent(new StreamEvent('on_chain_start', 'keep', 'id'), 'chain'));
        self::assertFalse($filter->includeEvent(new StreamEvent('on_chain_start', 'other', 'id'), 'chain'));
        self::assertFalse($filter->includeEvent(new StreamEvent('on_chain_start', 'keep', 'id', ['drop']), 'chain'));
    }
}
