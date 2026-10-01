<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\RunCollectorCallbackHandler;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(BaseChatModel::class)]
#[CoversClass(ChatOpenAI::class)]
final class ModelTracingAttributionTest extends TestCase
{
    /**
     * Each prompt in a batch must be attributed to its OWN run.
     *
     * The generate call used run manager 0 for every prompt while the end event
     * used the right index, so tokens and callbacks for prompt 3 were recorded
     * against prompt 1's run. Upstream passes `runManagers?.[i]` into
     * `_generate`; this pins that.
     */
    public function testEachPromptIsTracedToItsOwnRun(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::json(200, ['id' => 'a', 'model' => 'm', 'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'one'],
            ]], 'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1]]),
            FakeHttpClient::json(200, ['id' => 'b', 'model' => 'm', 'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'two'],
            ]], 'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 2]]),
        ]);

        $model = new ChatOpenAI(['apiKey' => 'k', 'httpClient' => $http]);
        $collector = new RunCollectorCallbackHandler();

        $model->generateMessages(
            [['a' => 'first'], ['b' => 'second']],
            [],
            null,
            new RunnableConfig(callbacks: [$collector]),
        );

        $llmRuns = array_values(array_filter(
            $collector->tracedRuns,
            static fn (\LangChain\Tracers\Run $r): bool => $r->runType === 'llm',
        ));

        self::assertCount(2, $llmRuns, 'one run per prompt');
        self::assertNotSame($llmRuns[0]->id, $llmRuns[1]->id, 'runs must be distinct');

        // Each run records its OWN usage, not the batch total. The second
        // prompt spent 5+2=7; reporting the batch total (10) on both would mean
        // each run claims tokens it did not spend.
        $tokens = static fn (\LangChain\Tracers\Run $r): array => $r->outputs['llmOutput']['tokenUsage'] ?? [];
        self::assertSame(2, $tokens($llmRuns[0])['totalTokens'] ?? null);
        self::assertSame(7, $tokens($llmRuns[1])['totalTokens'] ?? null);
    }

    /**
     * A failure in the eager path must reach `handleLLMError`.
     *
     * Without it the trace shows a run that started and never ended: no error
     * event, no token counts, and a span that hangs in any UI reading it. The
     * streaming path already did this; the eager path did not.
     */
    public function testAnEagerFailureReachesHandleLLMError(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::json(200, ['id' => 'a', 'model' => 'm', 'choices' => [[
                'index' => 0,
                'message' => ['role' => 'assistant', 'content' => 'ok'],
            ]]]),
            FakeHttpClient::json(500, ['error' => ['message' => 'boom']]),
        ]);

        $model = new ChatOpenAI(['apiKey' => 'k', 'httpClient' => $http, 'maxRetries' => 0]);
        $collector = new RunCollectorCallbackHandler();

        try {
            $model->generateMessages(
                [['a' => 'first'], ['b' => 'second']],
                [],
                null,
                new RunnableConfig(callbacks: [$collector]),
            );
            self::fail('expected the second prompt to fail');
        } catch (\Throwable) {
            // expected
        }

        $errored = array_filter(
            $collector->tracedRuns,
            static fn (\LangChain\Tracers\Run $r): bool => $r->error !== null,
        );

        self::assertNotEmpty($errored, 'the failed run must record an error');
        self::assertStringContainsString('boom', (string) reset($errored)->error);
    }

    /**
     * A batch's token usage is summed, not discarded.
     *
     * `combineLLMOutput()` returned `[]`, so a caller invoking several prompts
     * at once got no totals at all.
     */
    public function testBatchUsageIsCombined(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::json(200, ['id' => 'a', 'model' => 'm', 'choices' => [[
                'index' => 0, 'message' => ['role' => 'assistant', 'content' => 'one'],
            ]], 'usage' => ['prompt_tokens' => 3, 'completion_tokens' => 1, 'total_tokens' => 4]]),
            FakeHttpClient::json(200, ['id' => 'b', 'model' => 'm', 'choices' => [[
                'index' => 0, 'message' => ['role' => 'assistant', 'content' => 'two'],
            ]], 'usage' => ['prompt_tokens' => 4, 'completion_tokens' => 2, 'total_tokens' => 6]]),
        ]);

        $model = new ChatOpenAI(['apiKey' => 'k', 'httpClient' => $http]);
        $result = $model->generateMessages([['a' => '1'], ['b' => '2']]);

        $usage = $result->llmOutput['tokenUsage'] ?? null;

        self::assertNotNull($usage, 'a batch must report a combined usage');
        self::assertSame(7, $usage['promptTokens']);
        self::assertSame(3, $usage['completionTokens']);
        self::assertSame(10, $usage['totalTokens']);
    }

    /**
     * `stop` reaches the request under every spelling a caller may use.
     *
     * The wire name is `stop`, the JS field is `stopSequences`, and
     * `stop_sequences` is Anthropic's. Accepting only one meant the other two
     * were recorded in `kwargs` and then never sent.
     */
    #[DataProvider('stopSpellings')]
    public function testEveryStopSpellingReachesTheRequest(string $key): void
    {
        $bound = (new ChatOpenAI(['apiKey' => 'k']))->bindTools([], [$key => ['END']]);
        self::assertSame(['END'], $bound->invocationParams()['stop'] ?? null, "bound via $key");

        $constructed = new ChatOpenAI(['apiKey' => 'k', $key => ['END']]);
        self::assertSame(['END'], $constructed->invocationParams()['stop'] ?? null, "ctor via $key");

        $perCall = (new ChatOpenAI(['apiKey' => 'k']))->invocationParams([$key => ['END']]);
        self::assertSame(['END'], $perCall['stop'] ?? null, "per-call via $key");
    }

    /** @return iterable<string, array{string}> */
    public static function stopSpellings(): iterable
    {
        yield 'stop' => ['stop'];
        yield 'stopSequences' => ['stopSequences'];
        yield 'stop_sequences' => ['stop_sequences'];
    }
}
