<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\RunCollectorCallbackHandler;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `BaseLanguageModel::invoke()` destructured the whole `RunnableConfig` down to two of its fields:
 *
 *     $this->generatePrompt([$promptValue], $config?->options ?? [], $config?->callbacks);
 *
 * Fourteen of sixteen fields — tags, metadata, runId, runName, recursionLimit, configurable, context and
 * the rest — were discarded at the boundary, silently. A caller who binds a `runName` or a tag gets
 * neither and nothing reports it.
 *
 * The fix adds a FOURTH parameter rather than routing config fields through `$options`, because
 * `$options` is the INVOCATION options (max_tokens, stop, tools, failOnDemand) and aliasing the two is
 * what broke four tests in the first attempt at this fix.
 *
 * TWO FIELDS ARE ASSERTED, NOT ONE, on purpose: the defect class is a hand-maintained subset, and a fix
 * restoring only `runName` would leave thirteen fields broken while this suite went green.
 */
#[CoversClass(ChatOpenAI::class)]
final class InvokeForwardsConfigTest extends TestCase
{
    private function collector(): RunCollectorCallbackHandler
    {
        return new RunCollectorCallbackHandler();
    }

    private function model(): ChatOpenAI
    {
        return new ChatOpenAI([
            'apiKey' => 'k',
            'httpClient' => new FakeHttpClient([
                FakeHttpClient::json(200, [
                    'id' => 'c1', 'object' => 'chat.completion', 'created' => 1, 'model' => 'gpt-4o',
                    'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok'], 'finish_reason' => 'stop']],
                    'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
                ]),
            ]),
        ]);
    }

    /** RED before the fix: the traced run is named after the component id. */
    public function testAPerCallRunNameReachesTheTracedRun(): void
    {
        $collector = $this->collector();

        $this->model()->invoke(
            [['role' => 'user', 'content' => 'hi']],
            new RunnableConfig(callbacks: [$collector], runName: 'pull_person'),
        );

        self::assertNotEmpty($collector->tracedRuns, 'nothing was traced');
        self::assertSame('pull_person', $collector->tracedRuns[0]->name());
    }

    /** This is the one that stops a `runName`-only fix from looking done. */
    public function testAPerCallTagReachesTheTracedRunToo(): void
    {
        $collector = $this->collector();

        $this->model()->invoke(
            [['role' => 'user', 'content' => 'hi']],
            new RunnableConfig(callbacks: [$collector], tags: ['pipeline', 'stage-2']),
        );

        self::assertNotEmpty($collector->tracedRuns, 'nothing was traced');
        self::assertContains('pipeline', $collector->tracedRuns[0]->tags);
    }

    /** CONTROL: per-call metadata reaches the run as well — a third field, same class of claim. */
    public function testAPerCallMetadataReachesTheTracedRun(): void
    {
        $collector = $this->collector();

        $this->model()->invoke(
            [['role' => 'user', 'content' => 'hi']],
            new RunnableConfig(callbacks: [$collector], metadata: ['trace' => 'yes']),
        );

        self::assertNotEmpty($collector->tracedRuns, 'nothing was traced');
        self::assertSame('yes', $collector->tracedRuns[0]->extra['metadata']['trace'] ?? null);
    }

    /** CONTROL: with no config the run still exists and still falls back cleanly. */
    public function testNoConfigStillTracesARun(): void
    {
        $collector = $this->collector();

        $this->model()->invoke([['role' => 'user', 'content' => 'hi']], new RunnableConfig(callbacks: [$collector]));

        self::assertNotEmpty($collector->tracedRuns);
        self::assertNotSame('', $collector->tracedRuns[0]->name());
    }

    /** CONTROL: the answer survives the boundary. */
    public function testTheAnswerStillComesBack(): void
    {
        self::assertNotNull($this->model()->invoke([['role' => 'user', 'content' => 'hi']]));
    }
}
