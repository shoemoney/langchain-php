<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Universal;

use LangChain\LanguageModels\Chat\Universal\ConfigurableModel;
use LangChain\LanguageModels\Chat\Universal\InitChatModel;
use LangChain\Messages\AIMessage;
use LangChain\OutputParsers\StringOutputParser;
use LangChain\Prompts\ChatPromptTemplate;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\RunLogPatch;
use LangChain\Tracers\StreamEvent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `Can call base runnable methods` from `universal.int.test.ts` (`streamEvents`, `streamLog`,
 * `stream`, `batch`; `withConfig` with tools lives in {@see UniversalConfigurableTest}).
 *
 * Every method resolves the real client first and delegates to it, so the events, log patches and chunks
 * are the client's own.
 */
#[CoversClass(ConfigurableModel::class)]
final class UniversalRunnableMethodsTest extends TestCase
{
    private function gpt4(\LangChain\Utils\Testing\FakeHttpClient $http): ConfigurableModel
    {
        return InitChatModel::init(null, [
            'modelProvider' => 'openai',
            'temperature' => 0.25,
            'apiKey' => 'sk-test',
            'httpClient' => $http,
        ]);
    }

    public function testCanCallStreamEvents(): void
    {
        $gpt4 = $this->gpt4(UniversalFixtures::openAiHttp("I am a streaming model."));

        $prompt = ChatPromptTemplate::fromMessages([['human', '{input}']]);
        $stream = $prompt->pipe($gpt4)->streamEvents(
            ['input' => "what's your name"],
            new RunnableConfig(configurable: ['model' => 'gpt-4o-mini']),
            'v2',
        );

        $events = [];
        foreach ($stream as $event) {
            self::assertInstanceOf(StreamEvent::class, $event);
            $events[] = $event;
        }

        // The first event should be a start event.
        self::assertSame('on_chain_start', $events[0]->event);
        // Events in the middle should be stream events.
        self::assertStringEndsWith('_stream', $events[intdiv(\count($events), 2)]->event);
        // The last event should be an end event.
        self::assertSame('on_chain_end', $events[\count($events) - 1]->event);
        self::assertContains('on_chat_model_stream', array_map(static fn (StreamEvent $e): string => $e->event, $events));
    }

    public function testStreamEventsOnTheModelItselfDefersToTheClient(): void
    {
        $gpt4 = $this->gpt4(UniversalFixtures::openAiHttp('Hello there'));

        $names = [];
        foreach ($gpt4->streamEvents('hi', new RunnableConfig(), 'v2') as $event) {
            $names[] = $event->event;
        }

        self::assertSame('on_chat_model_start', $names[0]);
        self::assertSame('on_chat_model_end', $names[\count($names) - 1]);
    }

    public function testStreamEventsRejectsAnUnknownVersionWhenRead(): void
    {
        $gpt4 = $this->gpt4(UniversalFixtures::openAiHttp());

        $this->expectException(\InvalidArgumentException::class);
        iterator_to_array($gpt4->streamEvents('hi', null, 'v9'));
    }

    public function testCanCallStreamLog(): void
    {
        $gpt4 = $this->gpt4(UniversalFixtures::openAiHttp('I am a streaming model.'));

        $runLog = null;
        foreach ($gpt4->streamLog("what's your name") as $event) {
            self::assertInstanceOf(RunLogPatch::class, $event);
            $runLog = $runLog === null ? $event : $runLog->concat($event);
        }

        self::assertNotNull($runLog);
        self::assertGreaterThan(0, \count($runLog->ops));
    }

    public function testCanCallStream(): void
    {
        $gpt4 = $this->gpt4(UniversalFixtures::openAiHttp('I am a streaming model.'));

        $finalChunk = null;
        $pieces = 0;
        foreach ($gpt4->stream("what's your name") as [$channel, $chunk]) {
            self::assertSame('default', $channel);
            $finalChunk = $finalChunk === null ? $chunk : $finalChunk->concat($chunk);
            ++$pieces;
        }

        self::assertGreaterThan(1, $pieces, 'the reply arrived in more than one chunk');
        self::assertNotNull($finalChunk);
        self::assertSame('I am a streaming model.', $finalChunk->content);
    }

    public function testCanCallBatch(): void
    {
        $http = UniversalFixtures::openAiHttp('I am GPT.', 2);
        $gpt4 = $this->gpt4($http);

        $batchResult = $gpt4->batch(["what's your name", "what's your name"]);

        self::assertCount(2, $batchResult);
        self::assertSame('I am GPT.', $batchResult[0]->content);
        self::assertSame('I am GPT.', $batchResult[1]->content);
        self::assertCount(2, $http->requests);
    }

    public function testTransformDelegatesToTheClient(): void
    {
        $gpt4 = $this->gpt4(UniversalFixtures::openAiHttp('I am GPT.'));

        $outputs = iterator_to_array($gpt4->transform(['what is your name']), false);

        self::assertCount(1, $outputs);
    }

    public function testAChainRunsThroughTheWrapper(): void
    {
        $http = UniversalFixtures::openAiHttp('Paris');
        $chain = ChatPromptTemplate::fromMessages([['system', 'Answer briefly.'], ['human', '{question}']])
            ->pipe($this->gpt4($http))
            ->pipe(new StringOutputParser());

        self::assertSame('Paris', $chain->invoke(['question' => 'Capital of France?']));
        self::assertSame(
            [['role' => 'system', 'content' => 'Answer briefly.'], ['role' => 'user', 'content' => 'Capital of France?']],
            $http->lastRequestBody()['messages'],
        );
    }

    public function testGenerateGoesThroughTheClientToo(): void
    {
        $gpt4 = $this->gpt4(UniversalFixtures::openAiHttp('I am GPT.'));

        $result = $gpt4->generatePrompt([\LangChain\LanguageModels\BaseLangChain::convertInputToPromptValue('hi')]);

        self::assertInstanceOf(AIMessage::class, $result->generations[0][0]->message);
        self::assertSame('I am GPT.', $result->generations[0][0]->text);
    }
}
