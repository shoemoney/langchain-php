<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\XAI;

use LangChain\LanguageModels\Chat\XAI\ChatXAI;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\HumanMessage;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Ports `chat_models/tests/chat_models_stream_events.test.ts` (3 tests).
 *
 * Upstream asserts through vitest matchers over `streamEvents()`'s protocol events
 * (`toHaveStreamText`, `toHaveStreamReasoning`, `toHaveStreamToolCalls`). The OpenAI
 * Chat Completions protocol-event converter is not ported (only Responses, Anthropic and
 * Ollama have one), so the same three scenarios are asserted on the chunks `stream()` yields
 * and on the message they fold into. The mock overrides `completionWithRetry()` exactly as
 * upstream's `MockStreamChatXAI` does.
 */
#[CoversClass(ChatXAI::class)]
final class ChatXAIStreamEventsTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $chunks
     */
    private static function mock(array $chunks): ChatXAI
    {
        return new class ($chunks) extends ChatXAI {
            /** @param list<array<string, mixed>> $chunks */
            public function __construct(private readonly array $chunks)
            {
                parent::__construct(['apiKey' => 'fake-key', 'model' => 'grok-3', 'streaming' => true]);
            }

            public function completionWithRetry(array $request): array|\Generator
            {
                return (function (): \Generator {
                    yield from $this->chunks;
                })();
            }
        };
    }

    /**
     * @return array{0: AIMessageChunk, 1: list<AIMessageChunk>}
     */
    private static function fold(ChatXAI $model): array
    {
        $full = null;
        $chunks = [];
        foreach ($model->stream('Hello') as [, $chunk]) {
            $chunks[] = $chunk;
            $full = $full === null ? $chunk : $full->concat($chunk);
        }
        self::assertInstanceOf(AIMessageChunk::class, $full);

        return [$full, $chunks];
    }

    /** @return list<array<string, mixed>> */
    private static function textChunks(): array
    {
        return [
            ['id' => 'chatcmpl-text', 'model' => 'test-model', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Hello'], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-text', 'model' => 'test-model', 'choices' => [['index' => 0, 'delta' => ['content' => ' world'], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-text', 'model' => 'test-model', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]],
        ];
    }

    public function testStreamsText(): void
    {
        [$full] = self::fold(self::mock(self::textChunks()));

        self::assertSame('Hello world', $full->content);
    }

    public function testStreamsReasoning(): void
    {
        [$full] = self::fold(self::mock([
            ['id' => 'chatcmpl-reason', 'model' => 'test-model', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'reasoning_content' => 'Let me reason...'], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-reason', 'model' => 'test-model', 'choices' => [['index' => 0, 'delta' => ['content' => 'Answer.'], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-reason', 'model' => 'test-model', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]],
        ]));

        self::assertSame('Let me reason...', $full->additional_kwargs['reasoning_content']);
        self::assertSame('Answer.', $full->content);
    }

    public function testStreamsToolCalls(): void
    {
        [$full] = self::fold(self::mock([
            ['id' => 'chatcmpl-tools', 'model' => 'test-model', 'choices' => [['index' => 0, 'delta' => ['role' => 'assistant', 'content' => 'Let me search.'], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-tools', 'model' => 'test-model', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [
                ['index' => 0, 'id' => 'call_abc', 'type' => 'function', 'function' => ['name' => 'web_search', 'arguments' => '{"query"']],
            ]], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-tools', 'model' => 'test-model', 'choices' => [['index' => 0, 'delta' => ['tool_calls' => [
                ['index' => 0, 'function' => ['arguments' => ':"weather"}']],
            ]], 'finish_reason' => null]]],
            ['id' => 'chatcmpl-tools', 'model' => 'test-model', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'tool_calls']]],
        ]));

        [$toolCalls, $invalid] = $full->parseToolCalls();

        self::assertSame([], $invalid);
        self::assertCount(1, $toolCalls);
        self::assertSame('web_search', $toolCalls[0]['name']);
        self::assertSame(['query' => 'weather'], $toolCalls[0]['args']);
        self::assertSame('call_abc', $toolCalls[0]['id']);
    }

    // ---- the xAI override of the delta converter ----------------------------

    public function testUsageIsKeptOnlyOnTheFinalChunk(): void
    {
        $usage = ['prompt_tokens' => 10, 'completion_tokens' => 2, 'total_tokens' => 12];
        $chunks = self::textChunks();
        foreach ($chunks as &$chunk) {
            $chunk['usage'] = $usage;
        }
        unset($chunk);

        [, $emitted] = self::fold(self::mock($chunks));

        self::assertArrayNotHasKey('usage', $emitted[0]->response_metadata);
        self::assertArrayNotHasKey('usage_metadata', $emitted[0]->response_metadata);
        self::assertArrayNotHasKey('usage', $emitted[1]->response_metadata);
        self::assertSame($usage, $emitted[2]->response_metadata['usage']);
        self::assertSame(
            ['input_tokens' => 10, 'output_tokens' => 2, 'total_tokens' => 12],
            $emitted[2]->response_metadata['usage_metadata'],
        );
    }

    public function testAUsageOnlyTrailingChunkIsStillEmittedWithTheTokenCount(): void
    {
        $chunks = self::textChunks();
        $chunks[] = ['id' => 'chatcmpl-text', 'model' => 'test-model', 'choices' => [], 'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 2, 'total_tokens' => 6]];

        $model = self::mock($chunks);
        $generations = iterator_to_array(
            (new \ReflectionMethod($model, 'streamResponseChunks'))->invoke($model, [new HumanMessage('Hello')]),
            false,
        );

        self::assertCount(4, $generations);
        self::assertSame('', $generations[3]->message->content);
        self::assertSame(6, $generations[3]->message->response_metadata['usage_metadata']['total_tokens']);
    }

    public function testTheSameScenarioOverARealServerSentEventBody(): void
    {
        $body = array_map(static fn (array $c): string => 'data: ' . json_encode($c) . "\n\n", self::textChunks());
        $body[] = "data: [DONE]\n\n";
        $model = new ChatXAI(['apiKey' => 'k', 'maxRetries' => 0, 'httpClient' => new FakeHttpClient([], $body)]);

        [$full] = self::fold($model);

        self::assertSame('Hello world', $full->content);
        $request = $model->httpClient->lastRequestBody();
        self::assertTrue($request['stream']);
        self::assertSame('grok-3-fast', $request['model']);
    }
}
