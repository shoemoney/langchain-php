<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat;

use LangChain\LanguageModels\Chat\Anthropic\AnthropicException;
use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\Anthropic\Utils\MessageInputs;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\Messages\AIMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\RunCollectorCallbackHandler;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Round three of the review loop. Every test here is mutation-verified: the fix
 * was reverted and the suite confirmed RED before being restored.
 */
#[CoversClass(ChatOpenAI::class)]
#[CoversClass(ChatAnthropic::class)]
#[CoversClass(MessageInputs::class)]
final class StreamErrorAndContentTest extends TestCase
{
    // ---- a provider error pushed DOWN the stream -------------------------

    /**
     * OpenAI can deliver `{"error": {...}}` as a stream event rather than
     * closing the connection.
     *
     * Nothing downstream looks for it — the consumer sees no `choices` and no
     * `usage`, skips the event, and the stream simply ends. The caller is
     * handed a truncated answer with no indication that anything failed.
     */
    public function testOpenAiRaisesAnErrorEventArrivingMidStream(): void
    {
        $http = new FakeHttpClient([], [
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"Hi\"}}]}\n\n",
            "data: {\"error\":{\"message\":\"The model produced invalid content\",\"code\":\"server_error\"}}\n\n",
            "data: [DONE]\n\n",
        ]);

        $model = new ChatOpenAI(['apiKey' => 'k', 'httpClient' => $http]);

        $seen = '';
        try {
            foreach ($model->stream('hi') as [, $chunk]) {
                $seen .= is_string($chunk->content) ? $chunk->content : '';
            }
            self::fail('the mid-stream error must not be swallowed');
        } catch (OpenAIException $e) {
            self::assertStringContainsString('invalid content', $e->getMessage());
            self::assertStringContainsString('server_error', $e->getMessage());
            self::assertSame('Hi', $seen, 'text delivered before the error stays visible');
        }
    }

    public function testAnthropicRaisesAnErrorEventArrivingMidStream(): void
    {
        $http = new FakeHttpClient([], [
            "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"Hi\"}}\n\n",
            "data: {\"type\":\"error\",\"error\":{\"type\":\"overloaded_error\",\"message\":\"Overloaded\"}}\n\n",
        ]);

        $model = new ChatAnthropic(['apiKey' => 'k', 'httpClient' => $http]);

        $this->expectException(AnthropicException::class);
        $this->expectExceptionMessage('Overloaded');

        iterator_to_array($model->stream('hi'), false);
    }

    /**
     * A lifecycle event with no `error` key still passes through untouched —
     * the new check must not swallow ordinary events.
     */
    public function testOrdinaryEventsAreNotMistakenForErrors(): void
    {
        $http = new FakeHttpClient([], [
            "data: {\"type\":\"message_start\",\"message\":{\"usage\":{\"input_tokens\":1}}}\n\n",
            "data: {\"type\":\"content_block_delta\",\"index\":0,\"delta\":{\"type\":\"text_delta\",\"text\":\"ok\"}}\n\n",
            "data: {\"type\":\"message_stop\"}\n\n",
        ]);

        $model = new ChatAnthropic(['apiKey' => 'k', 'httpClient' => $http]);

        $text = '';
        foreach ($model->stream('hi') as [, $chunk]) {
            $text .= is_string($chunk->content) ? $chunk->content : '';
        }

        self::assertSame('ok', $text);
    }

    // ---- assistant prose survives alongside a tool call -------------------

    /**
     * An assistant turn with BLOCK content and a tool call must keep both.
     *
     * The block-to-text conversion only recognised a string, so every word the
     * model wrote next to a tool call was deleted: the provider received a bare
     * `tool_use` block with no reasoning attached, and the conversation lost the
     * context that made the call legible.
     */
    public function testAssistantBlockContentSurvivesAlongsideAToolCall(): void
    {
        $message = new AIMessage([
            'content' => [['type' => 'text', 'text' => 'Let me check that for you.']],
            'tool_calls' => [['name' => 'get_weather', 'args' => ['city' => 'Austin'], 'id' => 't1', 'type' => 'tool_call']],
        ]);

        $content = MessageInputs::convertMessage($message)['content'];

        self::assertCount(2, $content);
        self::assertSame('text', $content[0]['type']);
        self::assertSame('Let me check that for you.', $content[0]['text']);
        self::assertSame('tool_use', $content[1]['type']);
        self::assertSame('get_weather', $content[1]['name']);
    }

    /**
     * The string form was already correct and must stay that way.
     */
    public function testAssistantStringContentStillPrecedesTheToolCall(): void
    {
        $message = new AIMessage([
            'content' => 'plain text',
            'tool_calls' => [['name' => 'g', 'args' => [], 'id' => 't1', 'type' => 'tool_call']],
        ]);

        $content = MessageInputs::convertMessage($message)['content'];

        self::assertSame('text', $content[0]['type']);
        self::assertSame('plain text', $content[0]['text']);
        self::assertSame('tool_use', $content[1]['type']);
    }

    /**
     * The whole turn reaches the wire, prose included.
     */
    public function testTheProseReachesTheAnthropicRequest(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, [
            'id' => 'x',
            'model' => 'm',
            'content' => [['type' => 'text', 'text' => 'ok']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        $model = new ChatAnthropic(['apiKey' => 'k', 'httpClient' => $http]);

        $model->invoke([
            new \LangChain\Messages\HumanMessage('weather?'),
            new AIMessage([
                'content' => [['type' => 'text', 'text' => 'one moment']],
                'tool_calls' => [['name' => 'g', 'args' => [], 'id' => 't1', 'type' => 'tool_call']],
            ]),
        ]);

        $sent = $http->lastRequestBody()['messages'][1]['content'];

        self::assertSame('one moment', $sent[0]['text'] ?? null);
        self::assertSame('tool_use', $sent[1]['type'] ?? null);
    }

    /**
     * A trailing usage event must not appear as an empty message.
     *
     * Upstream captures that event in a local and `continue`s, never yielding
     * it (`completions.ts:450-454`). Yielding it put a phantom EMPTY message on
     * the default channel at the end of every streamed call — invisible to a
     * consumer that concatenates text, and a spurious turn to one that counts
     * or renders each chunk. It is still FOLDED, so the usage reaches the run.
     */
    public function testAUsageEventIsNotSurfacedAsAMessage(): void
    {
        $http = new FakeHttpClient([], [
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"Hi\"}}]}\n\n",
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"usage\":{\"prompt_tokens\":5,\"completion_tokens\":1,\"total_tokens\":6}}\n\n",
            "data: [DONE]\n\n",
        ]);

        $model = new ChatOpenAI(['apiKey' => 'k', 'httpClient' => $http]);
        $collector = new RunCollectorCallbackHandler();

        $messages = [];
        foreach ($model->stream('hi', new RunnableConfig(callbacks: [$collector])) as [, $chunk]) {
            $messages[] = $chunk;
        }

        self::assertCount(1, $messages, 'the usage event must not be a message of its own');
        self::assertSame('Hi', $messages[0]->content);

        // ...and the usage is still delivered, on the run.
        $usage = null;
        foreach ($collector->tracedRuns as $run) {
            if ($run->runType === 'llm') {
                $usage = $run->outputs['llmOutput']['tokenUsage'] ?? null;
            }
        }

        self::assertSame(6, $usage['totalTokens'] ?? null, 'the usage must still reach the run');
    }

    /**
     * A chunk that carries real content is never suppressed.
     *
     * The filter is "metadata only", not "empty" — an empty content delta is
     * still an event the caller may be waiting on.
     */
    public function testContentBearingChunksAreAlwaysSurfaced(): void
    {
        $http = new FakeHttpClient([], [
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"a\"}}]}\n\n",
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"b\"}}]}\n\n",
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"c\"}}]}\n\n",
            "data: [DONE]\n\n",
        ]);

        $model = new ChatOpenAI(['apiKey' => 'k', 'httpClient' => $http]);

        $text = '';
        $count = 0;
        foreach ($model->stream('hi') as [, $chunk]) {
            $count++;
            $text .= is_string($chunk->content) ? $chunk->content : '';
        }

        self::assertSame(3, $count);
        self::assertSame('abc', $text);
    }

    /**
     * A refusal is content, not metadata.
     *
     * A provider reports a refusal in `additional_kwargs` with empty content,
     * so the "metadata-only" filter — added to stop a trailing usage event
     * surfacing as a phantom message — swallowed it. A refusal-only response
     * surfaced **zero chunks**: the only thing the model said, invisible to the
     * caller. `additional_kwargs` now counts as content.
     */
    public function testARefusalIsSurfacedNotTreatedAsMetadata(): void
    {
        $http = new FakeHttpClient([], [
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"refusal\":\"I cannot help with that\"}}]}\n\n",
            "data: [DONE]\n\n",
        ]);

        $model = new ChatOpenAI(['apiKey' => 'k', 'httpClient' => $http]);

        $messages = [];
        foreach ($model->stream('harmful request') as [, $chunk]) {
            $messages[] = $chunk;
        }

        self::assertCount(1, $messages, 'a refusal-only response must still produce a message');
        self::assertSame('I cannot help with that', $messages[0]->additional_kwargs['refusal'] ?? null);
    }

    /**
     * ...and it survives the fold when it arrives alongside real content.
     */
    public function testARefusalAlongsideContentSurvivesTheFold(): void
    {
        $http = new FakeHttpClient([], [
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"Hello\"}}]}\n\n",
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"refusal\":\"I cannot\"}}]}\n\n",
            "data: [DONE]\n\n",
        ]);

        $model = new ChatOpenAI(['apiKey' => 'k', 'httpClient' => $http]);
        $folded = null;
        foreach ($model->stream('hi') as [, $chunk]) {
            $folded = $folded === null ? $chunk : $folded->concat($chunk);
        }

        self::assertSame('Hello', $folded->content);
        self::assertSame('I cannot', $folded->additional_kwargs['refusal'] ?? null);
    }

    // ---- an abandoned stream still closes its run -------------------------

    /**
     * Breaking out of a stream early closes its run, as an END, not an error.
     *
     * `stream()` is a generator, so a consumer that stops iterating abandons it
     * and the code after the loop never runs — the run would hang open forever.
     * But a deliberate early exit is not a failure: recording it as one put
     * "Stream abandoned by the consumer", stack trace attached, into every
     * error dashboard. It ends with whatever was accumulated instead, which is
     * both honest and quiet.
     */
    public function testAnAbandonedStreamEndsCleanlyWithItsPartialResult(): void
    {
        $http = new FakeHttpClient([], [
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"one\"}}]}\n\n",
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"two\"}}]}\n\n",
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"three\"}}]}\n\n",
            "data: [DONE]\n\n",
        ]);

        $model = new ChatOpenAI(['apiKey' => 'k', 'httpClient' => $http]);
        $collector = new RunCollectorCallbackHandler();

        foreach ($model->stream('hi', new RunnableConfig(callbacks: [$collector])) as [, $chunk]) {
            self::assertSame('one', $chunk->content);
            break; // consumer gives up after the first token
        }

        $llm = array_values(array_filter(
            $collector->tracedRuns,
            static fn (\LangChain\Tracers\Run $r): bool => $r->runType === 'llm',
        ));

        self::assertCount(1, $llm);
        self::assertNotNull($llm[0]->endTime, 'the run must be closed, not left hanging');
        self::assertNull($llm[0]->error, 'a deliberate early exit is not a failure');
        self::assertSame(
            'one',
            $llm[0]->outputs['generations'][0][0]['text'] ?? null,
            'the text received before the consumer stopped is still reported',
        );
        // ...and it must be distinguishable from a stream that ran to the end.
        self::assertTrue(
            $llm[0]->extra['abandoned'] ?? false,
            'an abandoned run must not read as a completed one',
        );
    }

    /**
     * A stream consumed to completion still ends normally — the `finally` must
     * not double-report.
     */
    public function testAFullyConsumedStreamIsNotReportedAsAbandoned(): void
    {
        $http = new FakeHttpClient([], [
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{\"content\":\"one\"}}]}\n\n",
            "data: {\"id\":\"1\",\"model\":\"gpt-4o\",\"choices\":[{\"index\":0,\"delta\":{},\"finish_reason\":\"stop\"}]}\n\n",
            "data: [DONE]\n\n",
        ]);

        $model = new ChatOpenAI(['apiKey' => 'k', 'httpClient' => $http]);
        $collector = new RunCollectorCallbackHandler();

        iterator_to_array($model->stream('hi', new RunnableConfig(callbacks: [$collector])), false);

        $llm = array_values(array_filter(
            $collector->tracedRuns,
            static fn (\LangChain\Tracers\Run $r): bool => $r->runType === 'llm',
        ));

        self::assertNull($llm[0]->error, 'a completed stream is not an error');
        self::assertNotNull($llm[0]->endTime);
        self::assertArrayNotHasKey(
            'abandoned',
            $llm[0]->extra,
            'a stream that ran to completion must not be flagged as abandoned',
        );
    }
}
