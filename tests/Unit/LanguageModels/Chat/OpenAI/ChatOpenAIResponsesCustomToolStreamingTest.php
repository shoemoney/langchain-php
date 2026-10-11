<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAIResponses;
use LangChain\LanguageModels\Chat\OpenAI\Converters\ResponsesOutput;
use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatOpenAIResponses::class)]
#[CoversClass(AIMessageChunk::class)]
final class ChatOpenAIResponsesCustomToolStreamingTest extends TestCase
{
    public static function streamingModes(): iterable
    {
        foreach (['stream', 'invoke'] as $mode) {
            yield "$mode raw code" => [$mode, "console.log('custom tool streaming repro')"];
            yield "$mode whitespace" => [$mode, "  console.log('x')\n  "];
            yield "$mode JSON-like input" => [$mode, '{"query":"test query"}'];
        }
    }

    #[DataProvider('streamingModes')]
    public function testCustomToolCallsSurviveTheResponsesHttpStream(string $mode, string $rawInput): void
    {
        $item = ['type' => 'custom_tool_call', 'id' => 'ctc_123', 'call_id' => 'call_123', 'name' => 'execute_code', 'input' => ''];
        $events = [
            ['type' => 'response.created', 'response' => ['id' => 'resp_custom', 'model' => 'gpt-5']],
            ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => $item],
            ['type' => 'response.custom_tool_call_input.delta', 'output_index' => 0, 'delta' => substr($rawInput, 0, 7)],
            ['type' => 'response.custom_tool_call_input.delta', 'output_index' => 0, 'delta' => substr($rawInput, 7)],
            ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => array_replace($item, ['input' => $rawInput])],
            ['type' => 'response.completed', 'response' => [
                'id' => 'resp_custom', 'model' => 'gpt-5', 'status' => 'completed',
                'output' => [array_replace($item, ['input' => $rawInput])],
                'usage' => ['input_tokens' => 1, 'output_tokens' => 2, 'total_tokens' => 3],
            ]],
        ];
        $sse = array_map(static fn (array $event): string => 'event: ' . $event['type'] . "\ndata: " . json_encode($event) . "\n\n", $events);
        $http = new FakeHttpClient([], $sse);
        $definition = ['type' => 'custom', 'name' => 'execute_code', 'description' => 'Execute code', 'format' => ['type' => 'text']];
        $model = (new ChatOpenAIResponses([
            'apiKey' => 'sk-test', 'httpClient' => $http, 'model' => 'gpt-5',
            'maxRetries' => 0, 'streaming' => $mode === 'invoke',
        ]))->bindTools([$definition]);

        if ($mode === 'invoke') {
            $message = $model->invoke('Execute code');
        } else {
            $folded = new AIMessageChunk('');
            foreach ($model->stream('Execute code') as [$channel, $chunk]) {
                self::assertInstanceOf(AIMessageChunk::class, $chunk);
                $folded = $folded->concat($chunk);
            }
            self::assertSame($rawInput, $folded->toolCallChunks[0]['args']);
            $message = $folded->toMessage();
        }

        self::assertInstanceOf(AIMessage::class, $message);
        self::assertSame([], $message->invalidToolCalls);
        self::assertCount(1, $message->toolCalls);
        self::assertSame('execute_code', $message->toolCalls[0]['name']);
        self::assertSame('call_123', $message->toolCalls[0]['id']);
        self::assertSame(['input' => $rawInput], $message->toolCalls[0]['args']);
        self::assertSame(['call_123' => 'ctc_123'], $message->additional_kwargs[ResponsesOutput::CUSTOM_TOOL_CALL_IDS_MAP_KEY]);
        if ($mode === 'invoke') {
            self::assertSame('resp_custom', $message->id);
        }
        self::assertSame([], $http->requests);
        self::assertCount(1, $http->streamRequests);
        self::assertSame('https://api.openai.com/v1/responses', $http->streamRequests[0]['url']);
        self::assertTrue($http->lastRequestBody()['stream']);
        self::assertSame([$definition], $http->lastRequestBody()['tools']);
    }
}
