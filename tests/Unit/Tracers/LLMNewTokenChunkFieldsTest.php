<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Tracers;

use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\DeepSeek\ChatDeepSeek;
use LangChain\LanguageModels\Chat\Ollama\ChatOllama;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAICompletions;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAIResponses;
use LangChain\LanguageModels\Chat\XAI\ChatXAI;
use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\BaseCallbackHandler;
use LangChain\Utils\Testing\FakeHttpClient;
use LangChain\Utils\Testing\FakeStreamingChatModel;
use LangGraph\Pregel\Messages\StreamMessagesHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `handleLLMNewToken(string $token, ?array $idx, array $fields)` carries the
 * streamed chunk in `$fields['chunk']`; `$idx` is the prompt/completion map.
 * Passing the fields array in the `$idx` slot left every handler with an empty
 * `$fields` and a made-up index.
 */
#[CoversClass(ChatDeepSeek::class)]
#[CoversClass(ChatXAI::class)]
#[CoversClass(ChatAnthropic::class)]
#[CoversClass(ChatOllama::class)]
#[CoversClass(ChatOpenAICompletions::class)]
#[CoversClass(ChatOpenAIResponses::class)]
#[CoversClass(FakeStreamingChatModel::class)]
final class LLMNewTokenChunkFieldsTest extends TestCase
{
    private static function recorder(): BaseCallbackHandler
    {
        return new class extends BaseCallbackHandler {
            public string $name = 'recorder';

            /** @var list<array{token: string, idx: array<string, int>, fields: array<string, mixed>}> */
            public array $calls = [];

            public function handleLLMNewToken(string $token, array $idx, string $runId, ?string $parentRunId = null, array $tags = [], array $fields = []): void
            {
                $this->calls[] = ['token' => $token, 'idx' => $idx, 'fields' => $fields];
            }
        };
    }

    /**
     * @param iterable<mixed> $stream
     */
    private static function drain(iterable $stream): void
    {
        foreach ($stream as $_) {
        }
    }

    private static function assertChunkFields(BaseCallbackHandler $recorder): void
    {
        self::assertNotSame([], $recorder->calls);
        foreach ($recorder->calls as $call) {
            self::assertInstanceOf(ChatGenerationChunk::class, $call['fields']['chunk'] ?? null);
            self::assertSame(['prompt' => 0, 'completion' => 0], $call['idx']);
        }
    }

    /**
     * @param list<array<string, mixed>> $events
     *
     * @return list<string>
     */
    private static function sse(array $events, bool $named = false): array
    {
        return array_map(
            static fn (array $e): string => ($named ? 'event: ' . $e['type'] . "\n" : '') . 'data: ' . json_encode($e) . "\n\n",
            $events,
        );
    }

    /**
     * @return list<string>
     */
    private static function chatCompletionsSse(): array
    {
        return [
            ...self::sse([
                ['id' => '1', 'model' => 'm', 'choices' => [['index' => 0, 'delta' => ['content' => 'Hel']]]],
                ['id' => '1', 'model' => 'm', 'choices' => [['index' => 0, 'delta' => ['content' => 'lo']]]],
                ['id' => '1', 'model' => 'm', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]],
            ]),
            "data: [DONE]\n\n",
        ];
    }

    /**
     * @return iterable<string, array{0: \Closure(FakeHttpClient): object}>
     */
    public static function providers(): iterable
    {
        yield 'ChatDeepSeek' => [static fn (FakeHttpClient $h): object => new ChatDeepSeek(['apiKey' => 'k', 'maxRetries' => 0, 'httpClient' => $h]), self::chatCompletionsSse()];
        yield 'ChatXAI' => [static fn (FakeHttpClient $h): object => new ChatXAI(['apiKey' => 'k', 'maxRetries' => 0, 'httpClient' => $h]), self::chatCompletionsSse()];
        yield 'ChatOpenAICompletions' => [static fn (FakeHttpClient $h): object => new ChatOpenAICompletions(['model' => 'gpt-4o', 'apiKey' => 'k', 'maxRetries' => 0, 'httpClient' => $h]), self::chatCompletionsSse()];
        yield 'ChatOpenAIResponses' => [
            static fn (FakeHttpClient $h): object => new ChatOpenAIResponses(['model' => 'gpt-4o', 'apiKey' => 'k', 'maxRetries' => 0, 'httpClient' => $h]),
            self::sse([
                ['type' => 'response.created', 'response' => ['id' => 'resp_test', 'model' => 'gpt-4o-mini']],
                ['type' => 'response.output_text.delta', 'delta' => 'Foo', 'content_index' => 0, 'output_index' => 0],
                ['type' => 'response.output_text.delta', 'delta' => ' bar', 'content_index' => 0, 'output_index' => 0],
                ['type' => 'response.completed', 'response' => [
                    'id' => 'resp_test', 'object' => 'response', 'created_at' => 0, 'status' => 'completed', 'model' => 'gpt-4o-mini',
                    'output' => [['type' => 'message', 'id' => 'm', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Foo bar', 'annotations' => []]]]],
                    'usage' => ['input_tokens' => 1, 'output_tokens' => 2, 'total_tokens' => 3],
                ]],
            ], true),
        ];
        yield 'ChatAnthropic' => [
            static fn (FakeHttpClient $h): object => new ChatAnthropic(['apiKey' => 'k', 'model' => 'claude-sonnet-4-20250514', 'httpClient' => $h]),
            self::sse([
                ['type' => 'message_start', 'message' => ['id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude', 'content' => [], 'usage' => ['input_tokens' => 5, 'output_tokens' => 0]]],
                ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Hello']],
                ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => ' world']],
                ['type' => 'content_block_stop', 'index' => 0],
                ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 2]],
                ['type' => 'message_stop'],
            ], true),
        ];
        yield 'ChatOllama' => [
            static fn (FakeHttpClient $h): object => new ChatOllama(['model' => 'llama3', 'httpClient' => $h]),
            [
                json_encode(['model' => 'llama3', 'message' => ['role' => 'assistant', 'content' => 'Bonjour'], 'done' => false]) . "\n"
                . json_encode(['model' => 'llama3', 'created_at' => '2026-10-08T00:00:00Z', 'message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'done_reason' => 'stop', 'prompt_eval_count' => 1, 'eval_count' => 2]) . "\n",
            ],
        ];
    }

    /**
     * @param \Closure(FakeHttpClient): object $make
     * @param list<string>                     $wire
     */
    #[DataProvider('providers')]
    public function testProviderStreamHandsTheChunkToTheHandlerInFields(\Closure $make, array $wire): void
    {
        $recorder = self::recorder();
        $model = $make(new FakeHttpClient([], $wire));

        self::drain($model->stream('hi', new RunnableConfig(callbacks: [$recorder])));

        self::assertChunkFields($recorder);
    }

    public function testFakeStreamingChatModelCharacterFallbackPassesTheChunk(): void
    {
        $recorder = self::recorder();
        $model = new FakeStreamingChatModel(['responses' => [new AIMessage('hi')]]);

        self::drain($model->stream('x', new RunnableConfig(callbacks: [$recorder])));

        self::assertSame(['h', 'i'], array_column($recorder->calls, 'token'));
        self::assertChunkFields($recorder);
    }

    public function testFakeStreamingChatModelScriptedChunksPassTheChunk(): void
    {
        $recorder = self::recorder();
        $model = new FakeStreamingChatModel(['chunks' => [new AIMessageChunk('a'), new AIMessageChunk('b')]]);

        self::drain($model->stream('x', new RunnableConfig(callbacks: [$recorder])));

        self::assertSame(['a', 'b'], array_column($recorder->calls, 'token'));
        self::assertChunkFields($recorder);
    }

    public function testStreamMessagesHandlerReceivesTheRealChunkSoToolCallChunksSurvive(): void
    {
        $streamed = [];
        $handler = new StreamMessagesHandler(static function (array $chunk) use (&$streamed): void {
            $streamed[] = $chunk;
        });
        $toolChunk = new AIMessageChunk([
            'content' => '',
            'tool_call_chunks' => [['name' => 'search', 'args' => '{"q":"x"}', 'id' => 'call_1', 'index' => 0]],
        ]);
        $model = new FakeStreamingChatModel(['chunks' => [$toolChunk]]);

        self::drain($model->stream('x', new RunnableConfig(
            callbacks: [$handler],
            metadata: ['langgraph_checkpoint_ns' => 'ns'],
        )));

        $messages = array_map(static fn (array $c): mixed => $c[2][0] ?? null, $streamed);
        $withToolChunks = array_values(array_filter(
            $messages,
            static fn (mixed $m): bool => $m instanceof AIMessageChunk && $m->toolCallChunks !== [],
        ));
        self::assertCount(1, $withToolChunks);
        self::assertSame('search', $withToolChunks[0]->toolCallChunks[0]['name']);
    }
}
