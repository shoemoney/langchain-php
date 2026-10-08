<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables;

use LangChain\LanguageModels\Outputs\GenerationChunk;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Prompts\ChatPromptTemplate;
use LangChain\Prompts\PromptTemplate;
use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use LangChain\Runnables\RunnableParallel;
use LangChain\Runnables\RunnablePassthrough;
use LangChain\Runnables\RunnablePick;
use LangChain\Tools\DynamicStructuredTool;
use LangChain\Tools\DynamicTool;
use LangChain\Tools\Schema;
use LangChain\Tracers\CallbackHandler;
use LangChain\Tracers\CallbackManagerForToolRun;
use LangChain\Tracers\EventStreamCallbackHandler;
use LangChain\Tracers\StreamEvent;
use LangChain\Utils\Testing\FakeChatModel;
use LangChain\Utils\Testing\FakeListChatModel;
use LangChain\Utils\Testing\FakeLLM;
use LangChain\Utils\Testing\FakeStreamingLLM;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `runnables/tests/runnable_stream_events_v2.test.ts`.
 *
 * Event ORDER across different runs is not asserted where upstream's async interleaving cannot be
 * reproduced on one thread; each such test says so and asserts the per-run order and the totals instead.
 * Not converted (no PHP counterpart): the `dispatchCustomEvent`-in-a-lambda test (no run-context
 * dispatcher), the retriever test (the port has no `FakeRetriever`), and the `AbortSignal` / `timeout`
 * tests (a synchronous step cannot be interrupted from outside).
 */
#[CoversClass(Runnable::class)]
#[CoversClass(EventStreamCallbackHandler::class)]
#[CoversClass(StreamEvent::class)]
final class RunnableStreamEventsV2Test extends TestCase
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

        $events = self::events($chain->streamEvents('hello', null, 'v2'));

        self::assertSame([
            ['on_chain_start', 'reverse', [], ['input' => 'hello']],
            ['on_chain_stream', 'reverse', [], ['chunk' => 'olleh']],
            ['on_chain_end', 'reverse', [], ['output' => 'olleh']],
        ], StreamEventsAssertions::summarise($events));
    }

    public function testStreamEventsDoesNotIncludeTracerOnlyConfigurableMetadata(): void
    {
        $chain = RunnableLambda::from(static fn (string $s): string => $s)->withConfig(['runName' => 'echo']);

        $events = self::events($chain->streamEvents('hello', new RunnableConfig(configurable: [
            'thread_id' => 'th-123',
            'checkpoint_id' => 'ckpt-1',
            'temperature' => 0.5,
            'streaming' => true,
            'api_key' => 'should-not-propagate',
            '__secret_key' => 'should-not-propagate',
        ]), 'v2'));

        self::assertNotEmpty($events);
        foreach ($events as $event) {
            foreach (['thread_id', 'checkpoint_id', 'temperature', 'streaming', 'api_key', '__secret_key'] as $key) {
                self::assertArrayNotHasKey($key, $event->metadata);
            }
        }
    }

    public function testStreamEventsOnAChatModel(): void
    {
        $model = new FakeListChatModel(['responses' => ['abc']]);

        $events = self::events($model->streamEvents('hello', null, 'v2'));

        self::assertSame(
            ['on_chat_model_start', 'on_chat_model_stream', 'on_chat_model_stream', 'on_chat_model_stream', 'on_chat_model_end'],
            array_map(static fn (StreamEvent $e): string => $e->event, $events),
        );
        foreach ($events as $event) {
            self::assertSame('FakeListChatModel', $event->name);
            self::assertSame([], $event->tags);
        }
        self::assertSame('hello', $events[0]->data['input']);
        self::assertSame(
            ['a', 'b', 'c'],
            array_map(static fn (StreamEvent $e): string => $e->data['chunk']->content, \array_slice($events, 1, 3)),
        );
        self::assertInstanceOf(AIMessageChunk::class, $events[4]->data['output']);
        self::assertSame('abc', $events[4]->data['output']->content);
    }

    public function testStreamEventsPreservesResponseMetadataFromGenerationInfo(): void
    {
        $model = new FakeListChatModel([
            'responses' => ['abc'],
            'generationInfo' => [
                'finish_reason' => 'stop',
                'model_name' => 'test-model',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 5],
            ],
        ]);

        $events = self::events($model->streamEvents('hello', null, 'v2'));

        $output = StreamEventsAssertions::first($events, 'on_chat_model_end')->data['output'];
        self::assertSame('stop', $output->response_metadata['finish_reason']);
        self::assertSame('test-model', $output->response_metadata['model_name']);
        self::assertSame(['prompt_tokens' => 10, 'completion_tokens' => 5], $output->response_metadata['usage']);
    }

    public function testStreamEventsNestedInAnotherRunnableWithPassedCallbacksStillWorks(): void
    {
        $model = new FakeListChatModel(['responses' => ['abc']]);
        $seen = [];
        $container = RunnableLambda::from(static function (mixed $input) use ($model, &$seen): array {
            foreach ($model->streamEvents('hello', null, 'v2') as $event) {
                $seen[] = $event;
            }

            return $seen;
        });

        $container->invoke([], new RunnableConfig(callbacks: [
            CallbackHandler::fromMethods(['handleLLMStart' => static function (): void {
            }]),
        ]));

        self::assertSame(
            ['on_chat_model_start', 'on_chat_model_stream', 'on_chat_model_stream', 'on_chat_model_stream', 'on_chat_model_end'],
            array_map(static fn (StreamEvent $e): string => $e->event, $seen),
        );
    }

    /**
     * Upstream's expected order interleaves step N+1's start with step N's end because each step is a
     * concurrently scheduled async generator. On one thread a step's end follows the pull that exhausts
     * it, so the asserted order here is the one this port produces: per-run start < stream < end holds
     * for every run, and the root encloses everything.
     */
    public function testStreamEventsWithThreeRunnables(): void
    {
        $r = self::reverse();
        $chain = $r->withConfig(['runName' => '1'])
            ->pipe($r->withConfig(['runName' => '2']))
            ->pipe($r->withConfig(['runName' => '3']));

        $events = self::events($chain->streamEvents('hello', null, 'v2'));

        self::assertSame(
            ['RunnableSequence', '1', '1', '1', '2', '2', '2', '3', '3', 'RunnableSequence', '3', 'RunnableSequence'],
            array_map(static fn (StreamEvent $e): string => $e->name, $events),
        );
        self::assertSame([
            ['on_chain_start', 'RunnableSequence', [], ['input' => 'hello']],
            ['on_chain_start', '1', ['seq:step:1'], []],
            ['on_chain_stream', '1', ['seq:step:1'], ['chunk' => 'olleh']],
            ['on_chain_end', '1', ['seq:step:1'], ['output' => 'olleh', 'input' => 'hello']],
            ['on_chain_start', '2', ['seq:step:2'], []],
            ['on_chain_stream', '2', ['seq:step:2'], ['chunk' => 'hello']],
            ['on_chain_end', '2', ['seq:step:2'], ['output' => 'hello', 'input' => 'olleh']],
            ['on_chain_start', '3', ['seq:step:3'], []],
            ['on_chain_stream', '3', ['seq:step:3'], ['chunk' => 'olleh']],
            ['on_chain_stream', 'RunnableSequence', [], ['chunk' => 'olleh']],
            ['on_chain_end', '3', ['seq:step:3'], ['output' => 'olleh', 'input' => 'hello']],
            ['on_chain_end', 'RunnableSequence', [], ['output' => 'olleh']],
        ], StreamEventsAssertions::summarise($events));
    }

    /**
     * `LANGCHAIN_CALLBACKS_BACKGROUND` selects fire-and-forget dispatch upstream. Handlers here are
     * synchronous, so the variable has nothing to select and the events must be identical.
     */
    public function testStreamEventsWithThreeRunnablesWithBackgroundedCallbacksSetToTrue(): void
    {
        $original = getenv('LANGCHAIN_CALLBACKS_BACKGROUND');
        putenv('LANGCHAIN_CALLBACKS_BACKGROUND=true');

        try {
            $r = self::reverse();
            $chain = $r->withConfig(['runName' => '1'])
                ->pipe($r->withConfig(['runName' => '2']))
                ->pipe($r->withConfig(['runName' => '3']));
            $events = self::events($chain->streamEvents('hello', null, 'v2'));
        } finally {
            $original === false ? putenv('LANGCHAIN_CALLBACKS_BACKGROUND') : putenv('LANGCHAIN_CALLBACKS_BACKGROUND=' . $original);
        }

        self::assertCount(12, $events);
        self::assertSame('olleh', $events[11]->data['output']);
    }

    public function testStreamEventsWithThreeRunnablesWithFiltering(): void
    {
        $r = self::reverse();
        $chain = $r->withConfig(['runName' => '1'])
            ->pipe($r->withConfig(['runName' => '2', 'tags' => ['my_tag']]))
            ->pipe($r->withConfig(['runName' => '3', 'tags' => ['my_tag']]));

        $events = self::events($chain->streamEvents('hello', null, 'v2', ['includeNames' => ['1']]));

        self::assertSame([
            ['on_chain_start', '1', ['seq:step:1'], ['input' => 'hello']],
            ['on_chain_stream', '1', ['seq:step:1'], ['chunk' => 'olleh']],
            ['on_chain_end', '1', ['seq:step:1'], ['output' => 'olleh']],
        ], StreamEventsAssertions::summarise($events));

        $events2 = self::events($chain->streamEvents('hello', null, 'v2', [
            'excludeNames' => ['2'],
            'includeTags' => ['my_tag'],
        ]));

        self::assertCount(3, $events2);
        foreach ($events2 as $event) {
            self::assertSame('3', $event->name);
            self::assertContains('seq:step:3', $event->tags);
            self::assertContains('my_tag', $event->tags);
        }
        self::assertSame(['on_chain_start', 'on_chain_stream', 'on_chain_end'], array_map(static fn (StreamEvent $e): string => $e->event, $events2));
        self::assertSame(['input' => 'hello'], $events2[0]->data);
        self::assertSame(['chunk' => 'olleh'], $events2[1]->data);
        self::assertSame(['output' => 'olleh'], $events2[2]->data);
    }

    /**
     * The children of a `RunnableParallel` run inside its `invoke()`, which this port does not trace, so
     * only the sequence, the map and their chunks are reported — not the per-key lambda and passthrough
     * runs upstream lists. The root's dict chunks and final output are what upstream reports.
     */
    public function testStreamEventsWithARunnableMap(): void
    {
        $chain = RunnableParallel::from([
            'reversed' => self::reverse(),
            'original' => new RunnablePassthrough(),
        ])->pipe(new RunnablePick('reversed'));

        $events = self::events($chain->streamEvents('hello', null, 'v2'));

        self::assertSame('on_chain_start', $events[0]->event);
        self::assertSame('RunnableSequence', $events[0]->name);
        self::assertSame(['input' => 'hello'], $events[0]->data);

        $last = $events[array_key_last($events)];
        self::assertSame('on_chain_end', $last->event);
        self::assertSame('RunnableSequence', $last->name);
        self::assertSame(['output' => 'olleh'], $last->data);

        $rootStreams = array_values(array_filter(
            $events,
            static fn (StreamEvent $e): bool => $e->event === 'on_chain_stream' && $e->name === 'RunnableSequence',
        ));
        self::assertCount(1, $rootStreams);
        self::assertSame(['chunk' => 'olleh'], $rootStreams[0]->data);
    }

    public function testStreamEventsWithLlm(): void
    {
        $model = (new FakeStreamingLLM(['responses' => ['hey!']]))->withConfig([
            'metadata' => ['a' => 'b'],
            'tags' => ['my_model'],
            'runName' => 'my_model',
        ]);

        $events = self::events($model->streamEvents('hello', null, 'v2'));

        self::assertSame(
            ['on_llm_start', 'on_llm_stream', 'on_llm_stream', 'on_llm_stream', 'on_llm_stream', 'on_llm_end'],
            array_map(static fn (StreamEvent $e): string => $e->event, $events),
        );
        foreach ($events as $event) {
            self::assertSame('my_model', $event->name);
            self::assertSame(['my_model'], $event->tags);
            self::assertSame('b', $event->metadata['a']);
        }
        self::assertSame('hello', $events[0]->data['input']);
        self::assertSame(
            ['h', 'e', 'y', '!'],
            array_map(static function (StreamEvent $e): string {
                self::assertInstanceOf(GenerationChunk::class, $e->data['chunk']);

                return $e->data['chunk']->text;
            }, \array_slice($events, 1, 4)),
        );
        self::assertSame('hey!', $events[5]->data['output']['generations'][0][0]['text']);
        self::assertSame([], $events[5]->data['output']['llmOutput']);
    }

    /**
     * End to end: a real prompt, model and chain, with the tags, names and metadata each layer binds.
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

        $events = self::events($chain->streamEvents(['question' => 'hello'], null, 'v2'));

        $names = array_map(static fn (StreamEvent $e): string => $e->event . ' ' . $e->name, $events);
        self::assertSame('on_chain_start my_chain', $names[0]);
        self::assertSame('on_chain_end my_chain', $names[array_key_last($names)]);
        self::assertSame(['on_prompt_start my_template', 'on_prompt_end my_template'], \array_slice($names, 1, 2));
        self::assertSame('on_chat_model_start my_model', $names[3]);
        self::assertSame('on_chat_model_end my_model', $names[array_key_last($names) - 1]);

        // Root start event reports the input passed in; the prompt reports it too.
        self::assertSame(['input' => ['question' => 'hello']], $events[0]->data);
        self::assertSame(['input' => ['question' => 'hello']], $events[1]->data);
        self::assertSame(['my_chain'], $events[0]->tags);
        self::assertSame('bar', $events[0]->metadata['foo']);

        // Layer tags are inherited by the layers beneath, plus the sequence step.
        self::assertEqualsCanonicalizing(['my_template', 'my_chain', 'seq:step:1'], $events[1]->tags);
        $modelStart = StreamEventsAssertions::first($events, 'on_chat_model_start');
        self::assertEqualsCanonicalizing(['my_model', 'my_chain', 'seq:step:2'], $modelStart->tags);
        self::assertSame('bar', $modelStart->metadata['foo']);
        self::assertSame('b', $modelStart->metadata['a']);
        $messages = $modelStart->data['input']['messages'][0];
        self::assertInstanceOf(SystemMessage::class, $messages[0]);
        self::assertSame('You are Godzilla', $messages[0]->content);
        self::assertInstanceOf(HumanMessage::class, $messages[1]);
        self::assertSame('hello', $messages[1]->content);

        // One chat-model stream event and one chain stream event per streamed character.
        $modelChunks = array_map(
            static fn (StreamEvent $e): string => $e->data['chunk']->content,
            array_values(array_filter($events, static fn (StreamEvent $e): bool => $e->event === 'on_chat_model_stream')),
        );
        $chainChunks = array_map(
            static fn (StreamEvent $e): string => $e->data['chunk']->content,
            array_values(array_filter($events, static fn (StreamEvent $e): bool => $e->event === 'on_chain_stream' && $e->name === 'my_chain')),
        );
        self::assertSame(['R', 'O', 'A', 'R'], $modelChunks);
        self::assertSame(['R', 'O', 'A', 'R'], $chainChunks);

        $end = $events[array_key_last($events)];
        self::assertSame('ROAR', $end->data['output']->content);
        self::assertSame('ROAR', StreamEventsAssertions::first($events, 'on_chat_model_end')->data['output']->content);
    }

    /**
     * A model INVOKED (not streamed) from inside a step still reports `on_chat_model_stream` events,
     * because the event handler prefers streaming. `FakeListChatModel` streams; `FakeChatModel` does not
     * and reports a single chunk.
     */
    public function testChatModelThatSupportsStreamingButIsInvokedStillEmitsStreamEvents(): void
    {
        $events = self::events(self::chainInvokingChatModel(new FakeListChatModel(['responses' => ['ROAR']]))
            ->streamEvents(['question' => 'hello'], null, 'v2'));

        $chunks = array_values(array_filter($events, static fn (StreamEvent $e): bool => $e->event === 'on_chat_model_stream'));
        self::assertSame(['R', 'O', 'A', 'R'], array_map(static fn (StreamEvent $e): string => $e->data['chunk']->content, $chunks));
        self::assertSame('ROAR', StreamEventsAssertions::first($events, 'on_chat_model_end')->data['output']->content);
        self::assertSame('on_chain_end my_chain', StreamEventsAssertions::names($events)[array_key_last($events)]);
    }

    public function testChatModelThatDoesNotSupportStreamingButIsInvokedEmitsOneStreamEvent(): void
    {
        $events = self::events(self::chainInvokingChatModel(new FakeChatModel([]))
            ->streamEvents(['question' => 'hello'], null, 'v2'));

        $chunks = array_values(array_filter($events, static fn (StreamEvent $e): bool => $e->event === 'on_chat_model_stream'));
        self::assertCount(1, $chunks);
        self::assertSame("You are Godzilla\nhello", $chunks[0]->data['chunk']->content);
        self::assertSame("You are Godzilla\nhello", StreamEventsAssertions::first($events, 'on_chat_model_end')->data['output']->content);
    }

    public function testLlmThatSupportsStreamingButIsInvokedStillEmitsStreamEvents(): void
    {
        $events = self::events(self::chainInvokingLlm(new FakeStreamingLLM(['responses' => ['ROAR']]))
            ->streamEvents(['question' => 'hello'], null, 'v2'));

        $chunks = array_values(array_filter($events, static fn (StreamEvent $e): bool => $e->event === 'on_llm_stream'));
        self::assertSame(['R', 'O', 'A', 'R'], array_map(static fn (StreamEvent $e): string => $e->data['chunk']->text, $chunks));
        self::assertSame('ROAR', StreamEventsAssertions::first($events, 'on_llm_end')->data['output']['generations'][0][0]['text']);
        self::assertSame('ROAR', $events[array_key_last($events)]->data['output']);
    }

    public function testLlmThatDoesNotSupportStreamingButIsInvokedEmitsOneStreamEvent(): void
    {
        $events = self::events(self::chainInvokingLlm(new FakeLLM(['response' => 'ROAR']))
            ->streamEvents(['question' => 'hello'], null, 'v2'));

        $chunks = array_values(array_filter($events, static fn (StreamEvent $e): bool => $e->event === 'on_llm_stream'));
        self::assertCount(1, $chunks);
        self::assertSame('ROAR', $chunks[0]->data['chunk']->text);
        self::assertSame('ROAR', StreamEventsAssertions::first($events, 'on_llm_end')->data['output']['generations'][0][0]['text']);
    }

    private static function chainInvokingChatModel(Runnable $model): Runnable
    {
        $template = ChatPromptTemplate::fromMessages([
            ['system', 'You are Godzilla'],
            ['human', '{question}'],
        ])->withConfig(['runName' => 'my_template', 'tags' => ['my_template']]);

        return $template
            ->pipe(RunnableLambda::from(static fn (mixed $val, RunnableConfig $config): mixed => $model->invoke($val, $config)))
            ->withConfig(['metadata' => ['foo' => 'bar'], 'tags' => ['my_chain'], 'runName' => 'my_chain']);
    }

    private static function chainInvokingLlm(Runnable $model): Runnable
    {
        $template = PromptTemplate::fromTemplate("You are Godzilla\n{question}")
            ->withConfig(['runName' => 'my_template', 'tags' => ['my_template']]);

        return $template
            ->pipe(RunnableLambda::from(static fn (mixed $val, RunnableConfig $config): mixed => $model->invoke($val, $config)))
            ->withConfig(['metadata' => ['foo' => 'bar'], 'tags' => ['my_chain'], 'runName' => 'my_chain']);
    }

    public function testStreamEventsWithSimpleTools(): void
    {
        $tool = new DynamicTool(
            ['name' => 'parameterless', 'description' => 'A tool that does nothing'],
            static fn (): string => 'hello',
        );

        $events = self::events($tool->streamEvents([], null, 'v2'));

        self::assertSame([
            ['on_tool_start', 'parameterless', [], ['input' => []]],
            ['on_tool_end', 'parameterless', [], ['output' => 'hello']],
        ], StreamEventsAssertions::summarise($events));

        $withParams = new DynamicStructuredTool(
            [
                'name' => 'with_parameters',
                'description' => 'A tool that does nothing',
                'schema' => Schema::object(['x' => ['type' => 'number'], 'y' => ['type' => 'string']], ['x', 'y']),
            ],
            static fn (array $params): string => json_encode(['x' => $params['x'], 'y' => $params['y']], JSON_THROW_ON_ERROR),
        );

        $events2 = self::events($withParams->streamEvents(['x' => 1, 'y' => '2'], null, 'v2'));

        self::assertSame([
            ['on_tool_start', 'with_parameters', [], ['input' => ['x' => 1, 'y' => '2']]],
            ['on_tool_end', 'with_parameters', [], ['output' => '{"x":1,"y":"2"}']],
        ], StreamEventsAssertions::summarise($events2));
    }

    public function testCustomEventInsideACustomTool(): void
    {
        $customTool = new DynamicStructuredTool(
            [
                'name' => 'testtool',
                'description' => 'dispatches two custom events',
                'schema' => Schema::object(['x' => ['type' => 'number'], 'y' => ['type' => 'string']], ['x', 'y']),
            ],
            static function (array $params, ?CallbackManagerForToolRun $runManager): string {
                $runManager?->handleCustomEvent('testEvent', ['someval' => 'test']);
                $runManager?->handleCustomEvent('testEvent', ['someval' => 'test2']);

                return json_encode(['x' => $params['x'], 'y' => $params['y']], JSON_THROW_ON_ERROR);
            },
        );

        $events = self::events($customTool->streamEvents(['x' => 1, 'y' => '2'], null, 'v2'));

        self::assertSame([
            ['on_tool_start', 'testtool', [], ['input' => ['x' => 1, 'y' => '2']]],
            ['on_custom_event', 'testEvent', [], ['someval' => 'test']],
            ['on_custom_event', 'testEvent', [], ['someval' => 'test2']],
            ['on_tool_end', 'testtool', [], ['output' => '{"x":1,"y":"2"}']],
        ], StreamEventsAssertions::summarise($events));
    }

    public function testStreamEventsWithToolsThatReturnObjects(): void
    {
        $adder = static fn (): string => json_encode(['sum' => 3], JSON_THROW_ON_ERROR);
        $parameterless = new DynamicTool(['name' => 'parameterless', 'description' => 'no params'], $adder);

        $events = self::events($parameterless->streamEvents([], null, 'v2'));

        self::assertSame([
            ['on_tool_start', 'parameterless', [], ['input' => []]],
            ['on_tool_end', 'parameterless', [], ['output' => '{"sum":3}']],
        ], StreamEventsAssertions::summarise($events));

        $adderTool = new DynamicStructuredTool(
            [
                'name' => 'with_parameters',
                'description' => 'A tool that does nothing',
                'schema' => Schema::object(['x' => ['type' => 'number'], 'y' => ['type' => 'number']], ['x', 'y']),
            ],
            $adder,
        );

        $events2 = self::events($adderTool->streamEvents(['x' => 1, 'y' => 2], null, 'v2'));

        self::assertSame([
            ['on_tool_start', 'with_parameters', [], ['input' => ['x' => 1, 'y' => 2]]],
            ['on_tool_end', 'with_parameters', [], ['output' => '{"sum":3}']],
        ], StreamEventsAssertions::summarise($events2));
    }

    public function testStreamEventsWithTextEventStreamEncoding(): void
    {
        $chain = self::reverse()->withConfig(['runName' => 'reverse']);

        $events = iterator_to_array(
            $chain->streamEvents('hello', new RunnableConfig(runId: ['1234']), 'v2', [], 'text/event-stream'),
            false,
        );

        self::assertCount(4, $events);
        $expected = [
            ['data' => ['input' => 'hello'], 'event' => 'on_chain_start', 'name' => 'reverse', 'run_id' => '1234', 'tags' => []],
            ['data' => ['chunk' => 'olleh'], 'event' => 'on_chain_stream', 'name' => 'reverse', 'run_id' => '1234', 'tags' => []],
            ['data' => ['output' => 'olleh'], 'event' => 'on_chain_end', 'name' => 'reverse', 'run_id' => '1234', 'tags' => []],
        ];
        foreach ($expected as $i => $payload) {
            self::assertStringStartsWith("event: data\ndata: ", $events[$i]);
            self::assertStringEndsWith("\n\n", $events[$i]);
            $decoded = json_decode(substr($events[$i], \strlen("event: data\ndata: "), -2), true, flags: JSON_THROW_ON_ERROR);
            self::assertIsArray($decoded['metadata']);
            unset($decoded['metadata']);
            ksort($decoded);
            self::assertSame($payload, $decoded);
        }
        self::assertSame("event: end\n\n", $events[3]);
    }

    public function testStreamEventsRejectsAnUnsupportedVersionAtTheCall(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Only versions "v1" and "v2"');

        self::reverse()->streamEvents('hello', null, 'v3');
    }

    public function testStreamEventsMethodHandlesErrors(): void
    {
        $model = new FakeListChatModel(['responses' => ['abc']]);
        $caught = null;

        try {
            foreach ($model->streamEvents('Hello! Tell me about yourself.', null, 'v2') as $_) {
                throw new \RuntimeException('should catch this error');
            }
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertSame('should catch this error', $caught?->getMessage());
    }

    public function testStreamEventsEmitsOnToolErrorForFailingTools(): void
    {
        $errorMessage = 'Tool execution failed!';
        $failingTool = new DynamicTool(
            ['name' => 'failing_tool', 'description' => 'A tool that always fails'],
            static function () use ($errorMessage): never {
                throw new \RuntimeException($errorMessage);
            },
        );

        $events = [];
        $caught = null;
        try {
            foreach ($failingTool->streamEvents([], null, 'v2') as $event) {
                $events[] = $event;
            }
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertSame($errorMessage, $caught?->getMessage());
        self::assertSame(
            ['on_tool_start failing_tool', 'on_tool_error failing_tool'],
            StreamEventsAssertions::names($events),
        );
        self::assertSame(['input' => []], $events[0]->data);
        // The port's tool run records a non-string argument under `input`, JSON-encoded.
        self::assertSame(['input' => '[]'], $events[1]->data['input']);
        self::assertStringContainsString($errorMessage, $events[1]->data['error']);
    }

    /**
     * A failing step still yields the events emitted before it failed, then raises.
     */
    public function testAFailingStepEmitsItsStartedRunsBeforeTheErrorSurfaces(): void
    {
        $boom = RunnableLambda::from(static function (string $s): string {
            throw new \RuntimeException('boom');
        })->withConfig(['runName' => 'boom']);

        $events = [];
        $caught = null;
        try {
            foreach ($boom->streamEvents('x', null, 'v2') as $event) {
                $events[] = $event;
            }
        } catch (\RuntimeException $e) {
            $caught = $e;
        }

        self::assertSame('boom', $caught?->getMessage());
        self::assertSame(['on_chain_start boom'], StreamEventsAssertions::names($events));
    }
}
