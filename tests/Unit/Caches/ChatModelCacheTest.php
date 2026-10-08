<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Caches;

use LangChain\Caches\BaseCache;
use LangChain\Caches\InMemoryCache;
use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\MessageUtils;
use LangChain\OutputParsers\StringOutputParser;
use LangChain\Prompts\ChatPromptTemplate;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangChain\Tracers\RunCollectorCallbackHandler;
use LangChain\Tracers\StreamEvent;
use LangChain\Utils\Testing\FakeChatModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Cache support in {@see BaseChatModel}. The first four tests are converted from
 * `language_models/tests/chat_models.test.ts`; the rest are this port's own.
 */
#[CoversClass(BaseChatModel::class)]
#[CoversClass(InMemoryCache::class)]
final class ChatModelCacheTest extends TestCase
{
    /** A fresh cache per test, so the process-wide one cannot leak hits between tests. */
    private function cachedFake(): FakeChatModel
    {
        return new FakeChatModel(['cache' => new InMemoryCache()]);
    }

    /**
     * A chat model that counts its real invocations and answers `reply-N`.
     */
    private function countingModel(?BaseCache $cache, string $type = 'counting', array $params = []): BaseChatModel
    {
        return new class (['cache' => $cache], $type, $params) extends BaseChatModel {
            public int $calls = 0;

            /** @param array<string, mixed> $fields */
            public function __construct(array $fields, private string $type, private array $params)
            {
                parent::__construct($fields);
            }

            public function llmType(): string
            {
                return $this->type;
            }

            public function identifyingParams(): array
            {
                return $this->params;
            }

            protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
            {
                $this->calls++;
                $text = 'reply-' . $this->calls;
                $runManager?->handleLLMNewToken($text);

                return new ChatResult([new ChatGeneration(new AIMessage($text), $text)], [
                    'tokenUsage' => ['promptTokens' => 7, 'completionTokens' => 3, 'totalTokens' => 10],
                ]);
            }
        };
    }

    public function testUsesCallbacksWithACache(): void
    {
        $model = $this->cachedFake();
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

        self::assertSame($response->content, $response2->content);
        self::assertSame($response2->content, $acc, 'a cache hit still fires the token callback');
    }

    public function testCanCacheComplexMessages(): void
    {
        $model = $this->cachedFake();
        self::assertNotNull($model->cache);

        $humanMessage = new HumanMessage(['content' => [['type' => 'text', 'text' => 'Hello there!']]]);
        $prompt = MessageUtils::getBufferString([$humanMessage]);
        self::assertSame('Human: Hello there!', $prompt);
        $llmKey = $model->serializedCacheKeyParametersForCall([]);

        $model->invoke([$humanMessage]);

        // Upstream's FakeChatModel echoes `m.text` for block content; this port's
        // FakeChatModel (outside WP-13b) still JSON-encodes the blocks, so the echoed
        // reply is the encoded block list. What is pinned here is that the entry exists
        // and that text and message agree.
        $echo = (string) json_encode([['type' => 'text', 'text' => 'Hello there!']], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        $value = $model->cache->lookup($prompt, $llmKey);
        self::assertIsArray($value);
        self::assertSame($echo, $value[0]->text);
        self::assertInstanceOf(ChatGeneration::class, $value[0]);
        self::assertInstanceOf(AIMessage::class, $value[0]->message);
        self::assertSame($echo, $value[0]->message->content);
    }

    public function testWithCacheDoesNotStartMultipleChatModelRuns(): void
    {
        $model = $this->cachedFake();
        self::assertNotNull($model->cache);

        $humanMessage = new HumanMessage(['content' => [['type' => 'text', 'text' => 'Hello there again!']]]);
        $prompt = MessageUtils::getBufferString([$humanMessage]);
        $llmKey = $model->serializedCacheKeyParametersForCall([]);
        self::assertNull($model->cache->lookup($prompt, $llmKey));

        $runCollector = new RunCollectorCallbackHandler();
        $config = new RunnableConfig(callbacks: [$runCollector]);

        $events = iterator_to_array($model->streamEvents([$humanMessage], $config, 'v2'), false);
        self::assertNotNull($model->cache->lookup($prompt, $llmKey));
        self::assertCount(2, $events);
        self::assertContainsOnlyInstancesOf(StreamEvent::class, $events);
        self::assertSame('on_chat_model_start', $events[0]->event);
        self::assertSame('on_chat_model_end', $events[1]->event);
        self::assertNotTrue($runCollector->tracedRuns[0]->extra['cached'] ?? null);

        $events2 = iterator_to_array($model->streamEvents([$humanMessage], $config, 'v2'), false);
        self::assertCount(2, $events2);
        self::assertSame('on_chat_model_start', $events2[0]->event);
        self::assertSame('on_chat_model_end', $events2[1]->event);
        self::assertTrue($runCollector->tracedRuns[1]->extra['cached']);
    }

    public function testCacheKeyIncludesTheLlmStringSoModelsDoNotShareHits(): void
    {
        $cache = new InMemoryCache();
        $a = $this->countingModel($cache, 'model-a', ['model' => 'a']);
        $b = $this->countingModel($cache, 'model-b', ['model' => 'b']);

        self::assertSame('reply-1', $a->invoke('same prompt')->content);
        self::assertSame('reply-1', $b->invoke('same prompt')->content);
        self::assertSame(1, $b->calls, 'the second model must run, not read the first one\'s entry');
        self::assertSame('reply-1', $a->invoke('same prompt')->content);
        self::assertSame(1, $a->calls);
    }

    public function testDifferentIdentifyingParamsOfOneClassDoNotShareHits(): void
    {
        $cache = new InMemoryCache();
        $cold = $this->countingModel($cache, 'same', ['temperature' => 0]);
        $hot = $this->countingModel($cache, 'same', ['temperature' => 1]);

        $cold->invoke('p');
        $hot->invoke('p');

        self::assertSame(1, $hot->calls);
    }

    public function testCallOptionsArePartOfTheKey(): void
    {
        $model = $this->countingModel(new InMemoryCache());

        $model->invoke('p', new RunnableConfig(options: ['stop' => ['x']]));
        $model->invoke('p', new RunnableConfig(options: ['stop' => ['y']]));
        $model->invoke('p', new RunnableConfig(options: ['stop' => ['x']]));

        self::assertSame(2, $model->calls);
    }

    public function testHitSkipsTheModelRun(): void
    {
        $model = $this->countingModel(new InMemoryCache());

        $first = $model->invoke('hello');
        $second = $model->invoke('hello');

        self::assertSame(1, $model->calls);
        self::assertSame('reply-1', $second->content);
        self::assertSame($first->content, $second->content);
    }

    public function testNoCacheMeansEveryCallRunsTheModel(): void
    {
        $model = $this->countingModel(null);

        $model->invoke('hello');
        $model->invoke('hello');

        self::assertNull($model->cache);
        self::assertSame(2, $model->calls);
    }

    public function testCacheTrueSelectsTheGlobalInMemoryCache(): void
    {
        $model = new FakeChatModel(['cache' => true]);

        self::assertInstanceOf(InMemoryCache::class, $model->cache);
        $model->invoke('global-cache-probe-' . bin2hex(random_bytes(4)));
        self::assertNull((new FakeChatModel([]))->cache);
    }

    public function testBatchServesHitsAndRunsOnlyTheMisses(): void
    {
        $model = $this->countingModel(new InMemoryCache());
        $model->invoke('one');

        $result = $model->generateMessages([
            [new HumanMessage('one')],
            [new HumanMessage('two')],
        ]);

        self::assertSame(2, $model->calls, 'only "two" reached the model');
        self::assertSame('reply-1', $result->generations[0][0]->text);
        self::assertSame('reply-2', $result->generations[1][0]->text);
    }

    public function testCachedGenerationsAreCopiesSoCallerMutationDoesNotPoisonTheCache(): void
    {
        $model = $this->countingModel(new InMemoryCache());
        $model->invoke('hello');

        $hit = $model->generateMessages([[new HumanMessage('hello')]]);
        $hit->generations[0][0]->message->content = 'tampered';
        $again = $model->generateMessages([[new HumanMessage('hello')]]);

        self::assertSame('reply-1', $again->generations[0][0]->message->content);
    }

    public function testHitEmptiesTokenUsageAndContributesNoLlmOutput(): void
    {
        $model = $this->countingModel(new InMemoryCache());
        $fresh = $model->generateMessages([[new HumanMessage('hello')]]);
        $hit = $model->generateMessages([[new HumanMessage('hello')]]);

        self::assertSame(10, $fresh->llmOutput['tokenUsage']['totalTokens']);
        self::assertSame([], $hit->llmOutput);
        self::assertSame([], $hit->generations[0][0]->generationInfo['tokenUsage']);
    }

    public function testHitEndsItsRunWithTheCachedFlagAndFiresTheTokenCallback(): void
    {
        $model = $this->countingModel(new InMemoryCache());
        $collector = new RunCollectorCallbackHandler();
        $config = new RunnableConfig(callbacks: [$collector]);

        $model->invoke('hello', $config);
        $model->invoke('hello', $config);

        self::assertCount(2, $collector->tracedRuns);
        self::assertArrayNotHasKey('cached', $collector->tracedRuns[0]->extra);
        self::assertTrue($collector->tracedRuns[1]->extra['cached']);
        self::assertNotNull($collector->tracedRuns[1]->endTime);
        self::assertNull($collector->tracedRuns[1]->error);
    }

    public function testAFailingModelCachesNothing(): void
    {
        $cache = new InMemoryCache();
        $model = new class (['cache' => $cache]) extends BaseChatModel {
            public int $calls = 0;

            public function llmType(): string
            {
                return 'flaky';
            }

            protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
            {
                if (++$this->calls === 1) {
                    throw new \RuntimeException('boom');
                }

                return new ChatResult([new ChatGeneration(new AIMessage('ok'), 'ok')]);
            }
        };

        try {
            $model->invoke('p');
            self::fail('first call should fail');
        } catch (\RuntimeException $e) {
            self::assertSame('boom', $e->getMessage());
        }

        self::assertSame('ok', $model->invoke('p')->content);
        self::assertSame(2, $model->calls);
    }

    public function testEndToEndChainThroughACachedModelRunsTheModelOncePerDistinctInput(): void
    {
        $model = $this->countingModel(new InMemoryCache());
        $chain = ChatPromptTemplate::fromMessages([['human', 'Tell me about {topic}']])
            ->pipe($model)
            ->pipe(new StringOutputParser());

        $first = $chain->invoke(['topic' => 'cats']);
        $second = $chain->invoke(['topic' => 'cats']);
        $third = $chain->invoke(['topic' => 'dogs']);

        self::assertSame('reply-1', $first);
        self::assertSame('reply-1', $second);
        self::assertSame('reply-2', $third);
        self::assertSame(2, $model->calls);
    }
}
