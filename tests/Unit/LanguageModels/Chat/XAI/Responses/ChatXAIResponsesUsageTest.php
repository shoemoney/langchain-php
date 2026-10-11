<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\XAI\Responses;

use LangChain\LanguageModels\Chat\XAI\ChatXAIResponses;
use LangChain\LanguageModels\Outputs\LLMResult;
use LangChain\Messages\HumanMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\CallbackHandler;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Provider usage contracts through the actual HTTP/SSE decoder and public generation paths. */
#[CoversClass(ChatXAIResponses::class)]
final class ChatXAIResponsesUsageTest extends TestCase
{
    private const USAGE = [
        'input_tokens' => 9,
        'output_tokens' => 3,
        'total_tokens' => 12,
        'input_tokens_details' => ['cached_tokens' => 4],
        'output_tokens_details' => ['reasoning_tokens' => 2],
    ];

    private const METADATA = [
        'input_tokens' => 9,
        'output_tokens' => 3,
        'total_tokens' => 12,
        'input_token_details' => ['cache_read' => 4],
        'output_token_details' => ['reasoning' => 2],
    ];

    private static function response(array $usage = self::USAGE): array
    {
        return [
            'id' => 'resp_usage', 'object' => 'response', 'created_at' => 1, 'model' => 'grok-3', 'status' => 'completed',
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Bonjour.']]]],
            'usage' => $usage,
        ];
    }

    private static function stream(array $usage = self::USAGE, bool $completed = true): FakeHttpClient
    {
        $events = [
            ['type' => 'response.created', 'response' => ['id' => 'resp_usage', 'model' => 'grok-3']],
            ['type' => 'response.output_text.delta', 'delta' => 'Bon', 'content_index' => 0, 'output_index' => 0],
            ['type' => 'response.output_text.delta', 'delta' => 'jour.', 'content_index' => 0, 'output_index' => 0],
        ];
        if ($completed) {
            $events[] = ['type' => 'response.completed', 'response' => self::response($usage)];
        }
        $sse = implode('', array_map(static fn (array $event): string => 'event: ' . $event['type'] . "\ndata: " . json_encode($event) . "\n\n", $events));

        // Byte fragments force the real SSE buffer to span event names, JSON values and separators.
        return new FakeHttpClient(streamChunks: str_split($sse, 17));
    }

    private static function model(FakeHttpClient $client, array $fields = []): ChatXAIResponses
    {
        return new ChatXAIResponses($fields + ['apiKey' => 'xai-test', 'maxRetries' => 0, 'httpClient' => $client]);
    }

    public static function generationRoutes(): array
    {
        return [
            'constructor streaming' => [['streaming' => true], [], false],
            'call streaming' => [[], ['streaming' => true], false],
            'callback requests streaming' => [[], [], true],
        ];
    }

    #[DataProvider('generationRoutes')]
    public function testStreamedGenerationReportsTheUsageShapeOwnedByItsGenerationRoute(array $fields, array $options, bool $preferStreaming): void
    {
        $client = self::stream();
        $model = self::model($client, $fields);
        $handler = CallbackHandler::fromMethods(['handleLLMNewToken' => static function (): void {
        }]);
        $handler->preferStreaming = $preferStreaming;

        $result = $model->generateMessages([[new HumanMessage('hi')]], $options, config: new RunnableConfig(callbacks: [$handler]));

        self::assertSame($preferStreaming
            ? ['tokenUsage' => ['promptTokens' => 9, 'completionTokens' => 3, 'totalTokens' => 12]]
            : ['estimatedTokenUsage' => self::METADATA], $result->llmOutput);
        self::assertSame(self::METADATA, $result->firstGeneration()->message->response_metadata['usage_metadata']);
        self::assertSame('Bonjour.', $result->firstText());
        self::assertSame([], $client->requests);
        self::assertCount(1, $client->streamRequests);
        self::assertSame('https://api.x.ai/v1/responses', $client->streamRequests[0]['url']);
        self::assertTrue($client->lastRequestBody()['stream']);
    }

    public function testPublicStreamRetainsCoreTokenUsageInTheEndCallback(): void
    {
        $output = null;
        $handler = CallbackHandler::fromMethods([
            'handleLLMEnd' => static function (LLMResult $result) use (&$output): void {
                $output = $result;
            },
        ]);
        $model = self::model(self::stream());
        $text = '';
        foreach ($model->stream('hi', new RunnableConfig(callbacks: [$handler])) as [, $chunk]) {
            $text .= $chunk->content;
        }

        self::assertSame('Bonjour.', $text);
        self::assertSame(['tokenUsage' => ['promptTokens' => 9, 'completionTokens' => 3, 'totalTokens' => 12]], $output?->llmOutput);
    }

    public function testEagerGenerationRetainsIdAndCamelCaseEstimatedUsage(): void
    {
        $client = new FakeHttpClient([FakeHttpClient::json(200, self::response())]);
        $result = self::model($client)->generateMessages([[new HumanMessage('hi')]]);

        self::assertSame([
            'id' => 'resp_usage',
            'estimatedTokenUsage' => ['promptTokens' => 9, 'completionTokens' => 3, 'totalTokens' => 12],
        ], $result->llmOutput);
        self::assertSame(self::METADATA, $result->firstGeneration()->message->response_metadata['usage_metadata']);
        self::assertFalse($client->lastRequestBody()['stream']);
        self::assertSame([], $client->streamRequests);
    }

    public function testAStreamWithoutUsageOmitsEstimatedUsageRatherThanInventingCounters(): void
    {
        $result = self::model(self::stream(completed: false), ['streaming' => true])->generateMessages([[new HumanMessage('hi')]]);

        self::assertSame([], $result->llmOutput);
        self::assertSame('Bonjour.', $result->firstText());
        self::assertArrayNotHasKey('usage_metadata', $result->firstGeneration()->message->response_metadata);
    }

    public function testStreamedZeroCountersAndEmptyDetailsRemainPresent(): void
    {
        $usage = ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0];
        $result = self::model(self::stream($usage), ['streaming' => true])->generateMessages([[new HumanMessage('hi')]]);

        self::assertSame(['estimatedTokenUsage' => [
            'input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0,
            'input_token_details' => [], 'output_token_details' => [],
        ]], $result->llmOutput);
    }
}
