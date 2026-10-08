<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables;

use LangChain\Prompts\ChatPromptTemplate;
use LangChain\Prompts\PromptTemplate;
use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableLambda;
use LangChain\Runnables\RunnableSequence;
use LangChain\Tracers\LogStreamCallbackHandler;
use LangChain\Tracers\RunLog;
use LangChain\Tracers\RunLogPatch;
use LangChain\Tracers\RunLogPatchApplier;
use LangChain\Utils\Testing\FakeChatModel;
use LangChain\Utils\Testing\FakeLLM;
use LangChain\Utils\Testing\FakeStreamingLLM;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `runnables/tests/runnable_stream_log.test.ts`, plus the patch algebra the tests rest on.
 *
 * The second upstream test builds its inputs with a `RunnableMap` whose children are a retriever sequence
 * and an LLM. The port has no `FakeRetriever`, and a map's children run inside `invoke()`, which this port
 * does not trace — so the test is converted onto a `RunnableSequence`, whose steps ARE traced, keeping its
 * subject: `includeNames` / `includeTags` select which sub-runs appear in `state.logs`. The tagged LLM is
 * a `FakeStreamingLLM`: the non-streaming `FakeLLM` drops the config's tags and run name on its eager
 * `invoke()` path, so a tag filter could not select it.
 */
#[CoversClass(Runnable::class)]
#[CoversClass(LogStreamCallbackHandler::class)]
#[CoversClass(RunLog::class)]
#[CoversClass(RunLogPatch::class)]
#[CoversClass(RunLogPatchApplier::class)]
final class RunnableStreamLogTest extends TestCase
{
    /**
     * @param iterable<RunLogPatch> $patches
     */
    private static function finalState(iterable $patches): RunLog
    {
        $state = null;
        foreach ($patches as $patch) {
            $state = $state === null ? RunLog::fromRunLogPatch($patch) : $state->concat($patch);
        }
        self::assertInstanceOf(RunLog::class, $state);

        return $state;
    }

    public function testStreamLogMethod(): void
    {
        $runnable = PromptTemplate::fromTemplate('{input}')->pipe(new FakeLLM([]));

        $final = self::finalState($runnable->streamLog(['input' => 'Hello world!']));

        self::assertSame(['output' => 'Hello world!'], $final->state['final_output']);
    }

    public function testStreamLogMethodWithAMoreComplicatedSequence(): void
    {
        $promptTemplate = ChatPromptTemplate::fromMessages([
            ['system', 'You are a nice assistant.'],
            ['human', "Context:\n{documents}\n\nQuestion:\n{question}"],
        ]);
        $documents = RunnableLambda::from(static fn (string $question): array => [
            'documents' => json_encode([['pageContent' => 'foo'], ['pageContent' => 'bar']], JSON_THROW_ON_ERROR),
            'question' => $question,
        ])->withConfig(['runName' => 'CUSTOM_NAME']);
        $extra = (new FakeStreamingLLM(['responses' => ['testing']]))->withConfig(['tags' => ['only_one']]);

        $runnable = RunnableSequence::from([
            $documents,
            $promptTemplate,
            new FakeChatModel([]),
            static fn (mixed $message): string => 'unused:' . $message->content,
            $extra,
        ]);

        $stream = $runnable->streamLog(
            'Do you know the Muffin Man?',
            null,
            ['includeTags' => ['only_one'], 'includeNames' => ['CUSTOM_NAME']],
        );
        $final = self::finalState($stream);

        self::assertArrayHasKey('FakeStreamingLLM', $final->state['logs']);
        self::assertSame('testing', $final->state['logs']['FakeStreamingLLM']['final_output']['generations'][0][0]['text']);
        self::assertArrayHasKey('CUSTOM_NAME', $final->state['logs']);
        self::assertSame(
            json_encode([['pageContent' => 'foo'], ['pageContent' => 'bar']], JSON_THROW_ON_ERROR),
            $final->state['logs']['CUSTOM_NAME']['final_output']['documents'],
        );
        // Filtered out: the prompt, the chat model and the plain callable never appear.
        self::assertSame(['CUSTOM_NAME', 'FakeStreamingLLM'], array_keys($final->state['logs']));
    }

    public function testTheLogRecordsTheRootRunAndEverySubRunInOrder(): void
    {
        $r = RunnableLambda::from(static fn (string $s): string => strrev($s));
        $chain = $r->withConfig(['runName' => 'step'])->pipe($r->withConfig(['runName' => 'step']));

        $final = self::finalState($chain->streamLog('abc'));

        self::assertSame('RunnableSequence', $final->state['name']);
        self::assertSame('chain', $final->state['type']);
        self::assertSame(['output' => 'abc'], $final->state['final_output']);
        self::assertSame(['abc'], $final->state['streamed_output']);
        // Two sub-runs with the same name get a `:2` suffix.
        self::assertSame(['step', 'step:2'], array_keys($final->state['logs']));
        self::assertSame(['seq:step:1'], $final->state['logs']['step']['tags']);
        self::assertSame(['seq:step:2'], $final->state['logs']['step:2']['tags']);
        self::assertSame(['cba'], $final->state['logs']['step']['streamed_output']);
        self::assertSame(['output' => 'cba'], $final->state['logs']['step']['final_output']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $final->state['logs']['step']['start_time']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{3}Z$/', $final->state['logs']['step']['end_time']);
    }

    public function testRunLogPatchConcatBuildsTheStateFromAnEmptyDocument(): void
    {
        $a = new RunLogPatch(['ops' => [['op' => 'replace', 'path' => '', 'value' => ['streamed_output' => [], 'logs' => []]]]]);
        $b = new RunLogPatch(['ops' => [
            ['op' => 'add', 'path' => '/streamed_output/-', 'value' => 'x'],
            ['op' => 'add', 'path' => '/logs/step', 'value' => ['id' => '1']],
        ]]);
        $c = new RunLogPatch(['ops' => [['op' => 'add', 'path' => '/streamed_output/-', 'value' => 'y']]]);

        $log = $a->concat($b);
        self::assertInstanceOf(RunLog::class, $log);
        self::assertSame(['streamed_output' => ['x'], 'logs' => ['step' => ['id' => '1']]], $log->state);
        self::assertCount(3, $log->ops);

        $next = $log->concat($c);
        self::assertSame(['x', 'y'], $next->state['streamed_output']);
        // Values, not references: the earlier log is untouched.
        self::assertSame(['x'], $log->state['streamed_output']);
    }

    public function testThePatchApplierSupportsAddReplaceAndRemove(): void
    {
        $doc = ['a' => ['b' => [1, 2]], 'c' => 'old'];

        $out = RunLogPatchApplier::apply($doc, [
            ['op' => 'add', 'path' => '/a/b/1', 'value' => 9],
            ['op' => 'add', 'path' => '/a/b/-', 'value' => 3],
            ['op' => 'replace', 'path' => '/c', 'value' => 'new'],
            ['op' => 'remove', 'path' => '/a/b/0'],
        ]);

        self::assertSame(['a' => ['b' => [9, 2, 3]], 'c' => 'new'], $out);
        self::assertSame(['a' => ['b' => [1, 2]], 'c' => 'old'], $doc);
    }

    public function testThePatchApplierRejectsAnUnresolvablePath(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        RunLogPatchApplier::apply(['a' => 1], [['op' => 'add', 'path' => '/missing/child', 'value' => 1]]);
    }
}
