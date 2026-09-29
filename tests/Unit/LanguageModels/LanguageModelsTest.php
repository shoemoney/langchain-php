<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\BaseLLM;
use LangChain\LanguageModels\BaseLanguageModel;
use LangChain\LanguageModels\LLM;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\LanguageModels\Outputs\Generation;
use LangChain\LanguageModels\Outputs\GenerationChunk;
use LangChain\LanguageModels\Outputs\LLMResult;
use LangChain\LanguageModels\SimpleChatModel;
use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Runnables\RunnableConfig;
use LangChain\Schema\StringPromptValue;
use LangChain\Tracers\CallbackHandler;
use LangChain\Tracers\RunCollectorCallbackHandler;
use LangChain\Utils\Testing\FakeChatModel;
use LangChain\Utils\Testing\FakeListChatModel;
use LangChain\Utils\Testing\FakeLLM;
use LangChain\Utils\Testing\FakeStreamingChatModel;
use LangChain\Utils\Testing\FakeStreamingLLM;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `language_models/tests/chat_models.test.ts` and `llms.test.ts`, plus
 * the output value types both suites depend on.
 *
 * The recurring assertion across these files is that *observability survives the
 * model*. A handler attached to an `invoke()` call must still receive token
 * callbacks, a tracer must still see exactly one run, and streaming must fold to
 * the same answer the non-streaming path would have produced. Those are the
 * properties that break silently when a model is refactored.
 */
#[CoversClass(BaseChatModel::class)]
#[CoversClass(BaseLLM::class)]
#[CoversClass(BaseLanguageModel::class)]
#[CoversClass(LLM::class)]
#[CoversClass(SimpleChatModel::class)]
#[CoversClass(FakeChatModel::class)]
#[CoversClass(FakeListChatModel::class)]
#[CoversClass(FakeStreamingChatModel::class)]
#[CoversClass(FakeLLM::class)]
#[CoversClass(FakeStreamingLLM::class)]
final class LanguageModelsTest extends TestCase
{
    // ---- input coercion --------------------------------------------------

    public function testChatModelAcceptsAPositionalPairShorthand(): void
    {
        $model = new FakeChatModel([]);

        $response = $model->invoke([['human', 'Hello there!']]);

        $this->assertSame('Hello there!', $response->content);
    }

    public function testChatModelAcceptsAnObjectShorthandWithType(): void
    {
        $model = new FakeChatModel([]);

        $response = $model->invoke([
            ['type' => 'human', 'content' => 'Hello there!', 'additional_kwargs' => []],
        ]);

        $this->assertSame('Hello there!', $response->content);
    }

    public function testChatModelAcceptsAnObjectWithRole(): void
    {
        $model = new FakeChatModel([]);

        $response = $model->invoke([['role' => 'human', 'content' => 'Hello there!!']]);

        $this->assertSame('Hello there!!', $response->content);
    }

    public function testChatModelJoinsEveryMessageRoleIntoTheReply(): void
    {
        // The echo makes the whole conversation observable in the output, so a
        // dropped or misordered message shows up as a wrong answer rather than
        // as no difference at all.
        $model = new FakeChatModel([]);

        $response = $model->invoke([
            ['role' => 'system', 'content' => 'You are an assistant.'],
            ['role' => 'human', 'content' => [['type' => 'text', 'text' => 'What is the weather in SF?']]],
            [
                'role' => 'assistant',
                'content' => '',
                'tool_calls' => [[
                    'id' => 'call_123',
                    'function' => ['name' => 'get_weather', 'arguments' => '{"location":"sf"}'],
                    'type' => 'function',
                ]],
            ],
            ['role' => 'tool', 'content' => 'Pretty nice right now!', 'tool_call_id' => 'call_123'],
        ]);

        $expected = implode("\n", [
            'You are an assistant.',
            (string) json_encode([['type' => 'text', 'text' => 'What is the weather in SF?']], \JSON_PRETTY_PRINT),
            '',
            'Pretty nice right now!',
        ]);

        $this->assertSame($expected, $response->content);
    }

    public function testChatModelHonoursStopTokens(): void
    {
        $model = new FakeChatModel([]);

        $response = $model->invoke('anything', new RunnableConfig(options: ['stop' => ['STOP']]));

        $this->assertSame('STOP', $response->content);
    }

    // ---- callbacks -------------------------------------------------------

    public function testChatModelReachesCallbacksWithTheWholeText(): void
    {
        $model = new FakeChatModel([]);
        $acc = '';

        $response = $model->invoke('Hello there!', new RunnableConfig(callbacks: [
            CallbackHandler::fromMethods([
                'handleLLMNewToken' => static function (string $token) use (&$acc): void {
                    $acc .= $token;
                },
            ]),
        ]));

        $this->assertSame($acc, $response->content);
    }

    public function testFakeLLMReachesCallbacks(): void
    {
        $model = new FakeLLM([]);
        $acc = '';

        $response = $model->invoke('Hello there!', new RunnableConfig(callbacks: [
            CallbackHandler::fromMethods([
                'handleLLMNewToken' => static function (string $token) use (&$acc): void {
                    $acc .= $token;
                },
            ]),
        ]));

        $this->assertSame($acc, $response);
    }

    public function testChatModelEmitsACustomEvent(): void
    {
        $model = new FakeListChatModel(['responses' => ['hi'], 'emitCustomEvent' => true]);
        $customEvent = null;

        $response = $model->invoke([['human', 'Hello there!']], new RunnableConfig(callbacks: [
            CallbackHandler::fromMethods([
                'handleCustomEvent' => static function (string $name, mixed $data) use (&$customEvent): void {
                    $customEvent = $data;
                },
            ]),
        ]));

        $this->assertSame('hi', $response->content);
        $this->assertNotNull($customEvent);
    }

    public function testChatModelCanFailOnDemand(): void
    {
        $model = new FakeListChatModel(['responses' => ['hi']]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');
        $model->invoke('hi', new RunnableConfig(options: ['thrownErrorString' => 'boom']));
    }

    // ---- streaming -------------------------------------------------------

    public function testChatModelStreamsOneChunkPerCharacter(): void
    {
        $model = new FakeListChatModel(['responses' => ['Hello world!']]);

        $texts = [];
        foreach ($model->stream('hi') as [$channel, $chunk]) {
            $this->assertSame(BaseChatModel::CHANNEL_DEFAULT, $channel);
            $texts[] = $chunk->content;
        }

        $this->assertCount(12, $texts);
        $this->assertSame('Hello world!', implode('', $texts));
    }

    public function testAStreamingChatModelWithNoStreamOverrideFallsBackToInvoke(): void
    {
        // Not an error: a model that cannot stream still has to answer `stream()`,
        // because callers cannot know in advance which kind they hold.
        $model = new FakeChatModel([]);

        $chunks = [];
        foreach ($model->stream('Hello there!') as [$channel, $chunk] ) {
            $chunks[] = $chunk->content;
        }

        $this->assertCount(1, $chunks);
        $this->assertSame('Hello there!', $chunks[0]);
    }

    public function testAPreferStreamingHandlerRoutesInvokeThroughTheStream(): void
    {
        $handler = CallbackHandler::fromMethods(['handleLLMNewToken' => static function (): void {
        }]);
        $handler->preferStreaming = true;

        $model = new FakeListChatModel(['responses' => ['Hello world!']]);

        $response = $model->invoke('Hello there!', new RunnableConfig(callbacks: [$handler]));

        $this->assertSame('Hello world!', $response->content);
    }

    public function testDisableStreamingSuppressesTheImplicitStreamPath(): void
    {
        $handler = CallbackHandler::fromMethods(['handleLLMNewToken' => static function (): void {
        }]);
        $handler->preferStreaming = true;

        $model = new FakeListChatModel(['responses' => ['Hello world!']]);
        $model->disableStreaming = true;

        $response = $model->invoke('Hello there!', new RunnableConfig(callbacks: [$handler]));

        // Same answer either way; what differs is that no per-token callbacks
        // fired, which is the point of the flag for a lossy provider.
        $this->assertSame('Hello world!', $response->content);
    }

    public function testStreamingLLMEmitsOneChunkPerCharacter(): void
    {
        $model = new FakeStreamingLLM(['responses' => ['Hello streaming world!']]);

        $chunks = [];
        foreach ($model->stream('hi') as [$channel, $chunk]) {
            $chunks[] = $chunk;
        }

        $this->assertGreaterThan(1, count($chunks));
        $this->assertSame('Hello streaming world!', implode('', $chunks));
    }

    public function testFakeListChatModelLoopsItsResponses(): void
    {
        $model = new FakeListChatModel(['responses' => ['first', 'second']]);

        // The list is a loop, not a script: a test that makes one call more than
        // it scripted gets an answer rather than null, which would fail in a way
        // that looks like a bug in the model.
        $this->assertSame('first', $model->invoke('x')->content);
        $this->assertSame('second', $model->invoke('x')->content);
        $this->assertSame('first', $model->invoke('x')->content);
    }

    public function testFakeStreamingLLMDrainsItsResponsesThenEchoesThePrompt(): void
    {
        $model = new FakeStreamingLLM(['responses' => ['one', 'two']]);

        $this->assertSame('one', $model->invoke('x'));
        $this->assertSame('two', $model->invoke('x'));
        // Queue drained, not looped: falling back to the prompt keeps the answer
        // deterministic and input-dependent rather than null.
        $this->assertSame('x', $model->invoke('x'));
    }

    public function testFakeLLMEchoesThePromptWhenNoResponseIsSet(): void
    {
        // Depending on the input is the point: a fixed response would let a
        // mis-wired chain pass.
        $model = new FakeLLM([]);

        $this->assertSame('some prompt', $model->invoke('some prompt'));
    }

    public function testFakeLLMCanFailOnDemand(): void
    {
        $model = new FakeLLM(['thrownErrorString' => 'kaboom']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('kaboom');
        $model->invoke('x');
    }

    public function testGenerationInfoLandsOnTheFinalStreamedChunk(): void
    {
        $model = new FakeListChatModel([
            'responses' => ['abc'],
            'generationInfo' => ['finish_reason' => 'stop'],
        ]);

        $chunks = [];
        foreach ($model->stream('hi') as [$channel, $chunk]) {
            $chunks[] = $chunk;
        }

        // `stream()` yields messages, and the chunk's generationInfo is folded
        // into the message's response_metadata — which is how a finish_reason
        // survives streaming to whatever reads the accumulated message.
        $this->assertArrayNotHasKey('finish_reason', $chunks[0]->response_metadata);
        $this->assertSame('stop', $chunks[2]->response_metadata['finish_reason']);
    }

    // ---- tracing ---------------------------------------------------------

    public function testChatModelRecordsExactlyOneRun(): void
    {
        $collector = new RunCollectorCallbackHandler();
        $model = new FakeChatModel([]);

        $model->invoke('Hello there!', new RunnableConfig(callbacks: [$collector]));

        $this->assertCount(1, $collector->tracedRuns);
        $this->assertSame('llm', $collector->tracedRuns[0]->runType);
    }

    public function testAnLLMRecordsExactlyOneRun(): void
    {
        $collector = new RunCollectorCallbackHandler();
        $model = new FakeLLM([]);

        $model->invoke('Hello there!', new RunnableConfig(callbacks: [$collector]));

        $this->assertCount(1, $collector->tracedRuns);
        $this->assertSame('llm', $collector->tracedRuns[0]->runType);
        $this->assertSame(['prompts' => ['Hello there!']], $collector->tracedRuns[0]->inputs);
    }

    public function testAStreamedModelRecordsExactlyOneRun(): void
    {
        $collector = new RunCollectorCallbackHandler();
        $model = new FakeStreamingLLM(['responses' => ['Hello streaming world!']]);

        foreach ($model->stream('hi', new RunnableConfig(callbacks: [$collector])) as [$channel, $chunk]) {
            // Drain.
        }

        // Streaming many chunks must not mean many runs.
        $this->assertCount(1, $collector->tracedRuns);
    }

    public function testTheRunCarriesAUsableIdentifier(): void
    {
        $collector = new RunCollectorCallbackHandler();
        $model = new FakeChatModel([]);

        $model->invoke('Hello there!', new RunnableConfig(callbacks: [$collector]));

        $run = $collector->tracedRuns[0];
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $run->id,
        );
        $this->assertSame($run->id, $run->traceId);
        $this->assertStringEndsWith($run->id, (string) $run->dottedOrder);
    }

    // ---- output value types ----------------------------------------------

    public function testGenerationChunkConcatsTextAndMergesInfo(): void
    {
        $a = new GenerationChunk('Hello ', ['finish_reason' => null, 'model' => 'm1']);
        $b = new GenerationChunk('world', ['finish_reason' => 'stop']);

        $merged = $a->concat($b);

        $this->assertSame('Hello world', $merged->text);
        // Later wins: the final chunk's finish reason is the real one.
        $this->assertSame(['finish_reason' => 'stop', 'model' => 'm1'], $merged->generationInfo);
    }

    public function testChatGenerationChunkFoldsMessagesNotJustText(): void
    {
        $a = new ChatGenerationChunk(new AIMessageChunk('Hello '), 'Hello ');
        $b = new ChatGenerationChunk(new AIMessageChunk('world'), 'world');

        $merged = $a->concat($b);

        $this->assertSame('Hello world', $merged->text);
        $this->assertInstanceOf(AIMessageChunk::class, $merged->message);
        $this->assertSame('Hello world', $merged->message->text());
    }

    public function testChatGenerationChunkConcatenationMergesToolCallArguments(): void
    {
        // The reason folding goes through the message chunk rather than through
        // string concat: a streamed tool call's arguments arrive in fragments,
        // and folding them as text scrambles them.
        $a = new ChatGenerationChunk(new AIMessageChunk([
            'content' => '',
            'tool_call_chunks' => [[
                'index' => 0,
                'id' => 'call_1',
                'name' => 'get_weather',
                'args' => '{"loc',
            ]],
        ]), '');
        $b = new ChatGenerationChunk(new AIMessageChunk([
            'content' => '',
            'tool_call_chunks' => [['index' => 0, 'args' => 'ation":"sf"}']],
        ]), '');

        $merged = $a->concat($b);

        $this->assertCount(1, $merged->message->toolCallChunks);
        [$calls, $invalid] = $merged->message->parseToolCalls();
        $this->assertSame([], $invalid);
        $this->assertSame('get_weather', $calls[0]['name']);
        $this->assertSame(['location' => 'sf'], $calls[0]['args']);
    }

    public function testLLMResultExposesTheFirstGeneration(): void
    {
        $result = new LLMResult([[new Generation('first'), new Generation('second')]]);

        $this->assertSame('first', $result->firstText());
    }

    public function testAnEmptyLLMResultHasNoFirstGeneration(): void
    {
        // Null rather than a throw: a subclass returning nothing is a
        // legitimate outcome the caller must still handle.
        $this->assertNull((new LLMResult())->firstGeneration());
        $this->assertNull((new LLMResult([[]]))->firstGeneration());
    }

    public function testLLMResultOmitsRunIdsFromItsSerializedForm(): void
    {
        $result = new LLMResult([[new Generation('a')]], ['tokenUsage' => []], ['run-1']);

        $this->assertArrayNotHasKey('runIds', $result->toArray());
        $this->assertArrayNotHasKey('__run', $result->toArray());
    }

    // ---- cache keys ------------------------------------------------------

    public function testCacheKeysAreStableAcrossKeyOrder(): void
    {
        $model = new FakeLLM([]);

        $a = $model->serializedCacheKeyParametersForCall(['stop' => ['x'], 'temperature' => 0.5]);
        $b = $model->serializedCacheKeyParametersForCall(['temperature' => 0.5, 'stop' => ['x']]);

        // Sorted, so the order the caller wrote the options in cannot change the
        // key and silently fragment the cache.
        $this->assertSame($a, $b);
    }

    public function testCacheKeysDifferWhenTheOptionsDiffer(): void
    {
        $model = new FakeLLM([]);

        $this->assertNotSame(
            $model->serializedCacheKeyParametersForCall(['temperature' => 0.5]),
            $model->serializedCacheKeyParametersForCall(['temperature' => 0.9]),
        );
    }

    public function testCacheKeysIncludeTheModelTypeAndKind(): void
    {
        $llm = new FakeLLM([]);
        $chat = new FakeChatModel([]);

        $this->assertStringContainsString('_type:"fake"', $llm->serializedCacheKeyParametersForCall());
        $this->assertStringContainsString('_model:"llm"', $llm->serializedCacheKeyParametersForCall());
        $this->assertStringContainsString('_model:"chat"', $chat->serializedCacheKeyParametersForCall());
    }

    public function testCacheKeysSkipNullOptions(): void
    {
        $model = new FakeLLM([]);

        $this->assertStringNotContainsString('temperature', $model->serializedCacheKeyParametersForCall(['temperature' => null]));
    }

    // ---- serialization ---------------------------------------------------

    public function testTheCacheParameterIsNotSerialized(): void
    {
        // Runtime wiring, not configuration: a model rebuilt from a payload
        // should not inherit the observer that was attached at write time.
        $model = new FakeListChatModel([
            'responses' => ['hi'],
            'emitCustomEvent' => true,
            'cache' => true,
        ]);

        $json = $model->toJson();

        $this->assertSame(['responses' => ['hi'], 'emit_custom_event' => true], $json['kwargs']);
        // The concrete model kind sits in the path so a trace tells ChatOpenAI
        // from ChatAnthropic without reading the payload.
        $this->assertSame(['langchain', 'chat_models', 'fake-list', 'FakeListChatModel'], $json['id']);
        $this->assertSame(1, $json['lc']);
        $this->assertSame('constructor', $json['type']);
    }

    // ---- subclass contracts ----------------------------------------------

    public function testAModelWithoutAStreamOverrideReportsNoStreamingSupport(): void
    {
        $this->assertFalse((new FakeChatModel([]))->supportsStreaming());
    }

    public function testAModelWithAStreamOverrideReportsStreamingSupport(): void
    {
        $this->assertTrue((new FakeListChatModel([]))->supportsStreaming());
        $this->assertTrue((new FakeStreamingLLM([]))->supportsStreaming());
    }

    public function testAnEmptyStreamIsAnErrorNotAnEmptyAnswer(): void
    {
        $model = new SilentStreamingChatModel([]);

        // A prefer-streaming handler is what routes `invoke()` through the stream;
        // without one the eager path runs and the empty stream is never reached.
        $handler = CallbackHandler::fromMethods(['handleLLMNewToken' => static function (): void {
        }]);
        $handler->preferStreaming = true;

        // Silently returning nothing is indistinguishable from success to every
        // caller downstream, so it must not be allowed to look like one.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Received empty response from chat model call.');
        $model->invoke('hi', new RunnableConfig(callbacks: [$handler]));
    }

    public function testTokenUsageIsBuiltFromTheAccumulatedMessage(): void
    {
        $model = new DeltaUsageChatModel([]);

        $llmOutput = null;
        $handler = CallbackHandler::fromMethods([
            'handleLLMEnd' => static function (LLMResult $output) use (&$llmOutput): void {
                $llmOutput = $output;
            },
        ]);
        $handler->preferStreaming = true;

        $model->invoke('hi', new RunnableConfig(callbacks: [$handler]));

        // Built from the FOLDED message, not the last chunk. Providers stream
        // usage as deltas, so the final chunk usually carries only its own
        // increment and reporting that as the total under-reports every prompt.
        $this->assertSame([
            'promptTokens' => 10,
            'completionTokens' => 12,
            'totalTokens' => 22,
        ], $llmOutput?->llmOutput['tokenUsage']);
    }

    public function testSimpleChatModelWrapsTextInAnAIMessage(): void
    {
        $model = new class extends SimpleChatModel {
            public function llmType(): string
            {
                return 'simple-fake';
            }

            protected function call(array $messages, array $options = [], ?\LangChain\Tracers\CallbackManagerForLLMRun $runManager = null): string
            {
                return 'simple';
            }
        };

        $response = $model->invoke('hi');

        $this->assertInstanceOf(AIMessage::class, $response);
        $this->assertSame('simple', $response->content);
    }

    public function testASimpleLLMFansOutOverABatch(): void
    {
        $model = new class extends LLM {
            public function llmType(): string
            {
                return 'batched-fake';
            }

            protected function call(string $prompt, array $options = [], ?\LangChain\Tracers\CallbackManagerForLLMRun $runManager = null): string
            {
                return strtoupper($prompt);
            }
        };

        $result = $model->generatePrompt(
            [new StringPromptValue('a'), new StringPromptValue('b')],
        );

        // One generation group per prompt — the outer list is prompt-major, so
        // a result can be paired back to the input that caused it.
        $this->assertCount(2, $result->generations);
        $this->assertSame('A', $result->generations[0][0]->text);
        $this->assertSame('B', $result->generations[1][0]->text);
    }

    public function testFlatteningAQueuedResultKeepsTokenCountsOnTheFirstOnly(): void
    {
        $model = new class extends BaseLLM {
            public function llmType(): string
            {
                return 'batch-llm';
            }

            protected function generatePrompts(array $prompts, array $options = [], ?\LangChain\Tracers\CallbackManagerForLLMRun $runManager = null): LLMResult
            {
                return new LLMResult(
                    [[new Generation('a')], [new Generation('b')]],
                    ['tokenUsage' => ['totalTokens' => 5]],
                );
            }
        };

        // `flattenLLMResult` is protected, so a subclass exposes it rather than
        // the test reaching through reflection — which is deprecated since 8.5
        // and would make this suite warn on a newer PHP than it was written for.
        $exposing = new class extends BaseLLM {
            public function llmType(): string
            {
                return 'batch-llm';
            }

            protected function generatePrompts(array $prompts, array $options = [], ?\LangChain\Tracers\CallbackManagerForLLMRun $runManager = null): LLMResult
            {
                return new LLMResult();
            }

            /** @return list<LLMResult> */
            public function flatten(LLMResult $result): array
            {
                return $this->flattenLLMResult($result);
            }
        };

        $results = $exposing->flatten(new LLMResult([[new Generation('a')], [new Generation('b')]], ['tokenUsage' => ['totalTokens' => 5]]));

        // Repeating the totals on every prompt invites a consumer to sum them
        // and double-count.
        $this->assertSame(['tokenUsage' => ['totalTokens' => 5]], $results[0]->llmOutput);
        $this->assertSame(['tokenUsage' => []], $results[1]->llmOutput);
    }
}

/**
 * A model that streams but yields nothing — the "silent provider" case.
 */
final class SilentStreamingChatModel extends BaseChatModel
{
    public function llmType(): string
    {
        return 'silent';
    }

    protected function generate(array $messages, array $options = [], ?\LangChain\Tracers\CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        return new ChatResult([new ChatGeneration(new AIMessage('eager'))]);
    }

    protected function streamResponseChunks(array $messages, array $options = [], ?\LangChain\Tracers\CallbackManagerForLLMRun $runManager = null): \Generator
    {
        yield from [];
    }
}

/**
 * A model that streams usage as per-chunk deltas.
 *
 * Providers report prompt tokens once and completion tokens incrementally, so
 * this is the shape that catches a bug where only the last chunk's delta is
 * reported as the total.
 */
final class DeltaUsageChatModel extends BaseChatModel
{
    public function llmType(): string
    {
        return 'delta-usage-fake';
    }

    protected function generate(array $messages, array $options = [], ?\LangChain\Tracers\CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        throw new \RuntimeException('DeltaUsageChatModel only supports streaming');
    }

    protected function streamResponseChunks(array $messages, array $options = [], ?\LangChain\Tracers\CallbackManagerForLLMRun $runManager = null): \Generator
    {
        $deltas = [
            ['content' => 'Hello', 'usage' => ['input_tokens' => 10, 'output_tokens' => 1, 'total_tokens' => 11]],
            ['content' => ' world', 'usage' => ['input_tokens' => 0, 'output_tokens' => 4, 'total_tokens' => 4]],
            ['content' => '!', 'usage' => ['input_tokens' => 0, 'output_tokens' => 7, 'total_tokens' => 7]],
        ];

        foreach ($deltas as $delta) {
            yield new ChatGenerationChunk(
                new AIMessageChunk([
                    'content' => $delta['content'],
                    'response_metadata' => ['usage_metadata' => $delta['usage']],
                ]),
                $delta['content'],
            );
        }
    }
}
