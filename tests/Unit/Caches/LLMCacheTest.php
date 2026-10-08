<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Caches;

use LangChain\Caches\InMemoryCache;
use LangChain\LanguageModels\BaseLLM;
use LangChain\LanguageModels\LLM;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangChain\Tracers\RunCollectorCallbackHandler;
use LangChain\Tracers\StreamEvent;
use LangChain\Utils\Testing\FakeLLM;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Cache support in {@see BaseLLM}. The first two tests are converted from
 * `language_models/tests/llms.test.ts`; the rest are this port's own.
 */
#[CoversClass(BaseLLM::class)]
#[CoversClass(LLM::class)]
final class LLMCacheTest extends TestCase
{
    private function countingLlm(?InMemoryCache $cache, string $type = 'counting'): LLM
    {
        return new class (['cache' => $cache], $type) extends LLM {
            public int $calls = 0;

            /** @param array<string, mixed> $fields */
            public function __construct(array $fields, private string $type)
            {
                parent::__construct($fields);
            }

            public function llmType(): string
            {
                return $this->type;
            }

            protected function call(string $prompt, array $options = [], ?CallbackManagerForLLMRun $runManager = null): string
            {
                $this->calls++;

                return $prompt . '#' . $this->calls;
            }
        };
    }

    public function testFakeLlmUsesCallbacksWithACache(): void
    {
        $model = new FakeLLM(['cache' => new InMemoryCache()]);
        $acc = '';
        $handler = new class ($acc) extends \LangChain\Tracers\BaseCallbackHandler {
            public function __construct(public string &$acc)
            {
                parent::__construct();
            }

            public function handleLLMNewToken(string $token, array $idx = [], ?string $runId = null, ?string $parentRunId = null, array $tags = [], array $fields = []): void
            {
                $this->acc .= $token;
            }
        };

        $response = $model->invoke('Hello there!');
        $response2 = $model->invoke('Hello there!', new RunnableConfig(callbacks: [$handler]));

        self::assertSame($response, $response2);
        self::assertSame($response2, $acc);
    }

    public function testLlmWithCacheDoesNotStartMultipleLlmRuns(): void
    {
        $model = new FakeLLM(['cache' => new InMemoryCache()]);
        self::assertNotNull($model->cache);
        $runCollector = new RunCollectorCallbackHandler();
        $config = new RunnableConfig(callbacks: [$runCollector]);

        $events = iterator_to_array($model->streamEvents('Hello there!', $config, 'v2'), false);
        self::assertCount(2, $events);
        self::assertContainsOnlyInstancesOf(StreamEvent::class, $events);
        self::assertSame('on_llm_start', $events[0]->event);
        self::assertSame('on_llm_end', $events[1]->event);
        self::assertNotTrue($runCollector->tracedRuns[0]->extra['cached'] ?? null);

        $events2 = iterator_to_array($model->streamEvents('Hello there!', $config, 'v2'), false);
        self::assertCount(2, $events2);
        self::assertSame('on_llm_start', $events2[0]->event);
        self::assertSame('on_llm_end', $events2[1]->event);
        self::assertTrue($runCollector->tracedRuns[1]->extra['cached']);
    }

    public function testHitSkipsTheModel(): void
    {
        $llm = $this->countingLlm(new InMemoryCache());

        self::assertSame('hi#1', $llm->invoke('hi'));
        self::assertSame('hi#1', $llm->invoke('hi'));
        self::assertSame(1, $llm->calls);
    }

    public function testNoCacheRunsEveryTime(): void
    {
        $llm = $this->countingLlm(null);

        $llm->invoke('hi');
        $llm->invoke('hi');

        self::assertNull($llm->cache);
        self::assertSame(2, $llm->calls);
    }

    public function testCacheKeyIncludesTheModelIdentitySoModelsDoNotShareHits(): void
    {
        $cache = new InMemoryCache();
        $a = $this->countingLlm($cache, 'model-a');
        $b = $this->countingLlm($cache, 'model-b');

        $a->invoke('p');
        $b->invoke('p');

        self::assertSame(1, $b->calls);
    }

    public function testBatchRunsOnlyTheMissesAndKeepsOrder(): void
    {
        $llm = $this->countingLlm(new InMemoryCache());
        $llm->invoke('b');

        $result = $llm->generateStrings(['a', 'b', 'c']);

        self::assertSame(3, $llm->calls, 'a and c ran, b was served');
        self::assertSame(['a#2', 'b#1', 'c#3'], array_map(static fn (array $g): string => $g[0]->text, $result->generations));
    }

    public function testCachedGenerationsAreCopies(): void
    {
        $llm = $this->countingLlm(new InMemoryCache());
        $llm->invoke('p');

        $hit = $llm->generateStrings(['p']);
        $hit->generations[0][0]->text = 'tampered';

        self::assertSame('p#1', $llm->generateStrings(['p'])->generations[0][0]->text);
    }

    public function testHitEndsItsRunFlaggedCached(): void
    {
        $llm = $this->countingLlm(new InMemoryCache());
        $collector = new RunCollectorCallbackHandler();
        $config = new RunnableConfig(callbacks: [$collector]);

        $llm->invoke('p', $config);
        $llm->invoke('p', $config);

        self::assertArrayNotHasKey('cached', $collector->tracedRuns[0]->extra);
        self::assertTrue($collector->tracedRuns[1]->extra['cached']);
    }
}
