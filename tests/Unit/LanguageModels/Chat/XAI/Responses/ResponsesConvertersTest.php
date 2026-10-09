<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\XAI\Responses;

use LangChain\LanguageModels\Chat\XAI\Converters\Responses;
use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\ToolMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Ports `converters/tests/responses.test.ts` (32 tests).
 *
 * `usage_metadata` has no property on a port message; it is read from `response_metadata['usage_metadata']`.
 * The unknown-message-type fallback is exercised with a `ToolMessage` (a type the converter has no branch for)
 * instead of upstream's hand-mutated `HumanMessage`.
 */
#[CoversClass(Responses::class)]
final class ResponsesConvertersTest extends TestCase
{
    private const NO_DETAILS = ['input_token_details' => [], 'output_token_details' => []];

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private static function response(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'resp_123', 'object' => 'response', 'created_at' => 1234567890, 'model' => 'grok-3', 'status' => 'completed',
            'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Hello from xAI!']]]],
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private static function withoutUsage(array $metadata): array
    {
        unset($metadata['usage_metadata']);

        return $metadata;
    }

    // ---- convertUsageToUsageMetadata ---------------------------------------

    public function testShouldConvertXaiUsageToLangChainFormatWithCachedTokens(): void
    {
        $result = Responses::convertUsageToUsageMetadata([
            'input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 150,
            'input_tokens_details' => ['cached_tokens' => 75],
            'output_tokens_details' => ['reasoning_tokens' => 10],
        ]);

        self::assertSame([
            'input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 150,
            'input_token_details' => ['cache_read' => 75],
            'output_token_details' => ['reasoning' => 10],
        ], $result);
    }

    public function testShouldHandleMissingUsageDetailsGracefully(): void
    {
        $result = Responses::convertUsageToUsageMetadata(['input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 150]);

        self::assertSame(['input_tokens' => 100, 'output_tokens' => 50, 'total_tokens' => 150] + self::NO_DETAILS, $result);
    }

    public function testShouldHandleNullUsage(): void
    {
        self::assertSame(['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0] + self::NO_DETAILS, Responses::convertUsageToUsageMetadata(null));
    }

    public function testAZeroCachedTokenCountIsStillReported(): void
    {
        $result = Responses::convertUsageToUsageMetadata([
            'input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2, 'input_tokens_details' => ['cached_tokens' => 0],
        ]);

        self::assertSame(['cache_read' => 0], $result['input_token_details']);
    }

    // ---- convertMessageToResponsesInput ------------------------------------

    public function testShouldConvertHumanMessageWithStringContent(): void
    {
        self::assertSame(
            ['role' => 'user', 'content' => 'Hello, world!'],
            Responses::convertMessageToResponsesInput(new HumanMessage('Hello, world!')),
        );
    }

    public function testShouldConvertHumanMessageWithTextContentParts(): void
    {
        $message = new HumanMessage(['content' => [['type' => 'text', 'text' => 'Hello'], ['type' => 'text', 'text' => 'World']]]);

        self::assertSame([
            'role' => 'user',
            'content' => [['type' => 'input_text', 'text' => 'Hello'], ['type' => 'input_text', 'text' => 'World']],
        ], Responses::convertMessageToResponsesInput($message));
    }

    public function testShouldConvertHumanMessageWithImageUrlContentStringFormat(): void
    {
        $message = new HumanMessage(['content' => [
            ['type' => 'text', 'text' => "What's in this image?"],
            ['type' => 'image_url', 'image_url' => 'https://example.com/image.jpg'],
        ]]);

        self::assertSame([
            'role' => 'user',
            'content' => [
                ['type' => 'input_text', 'text' => "What's in this image?"],
                ['type' => 'input_image', 'image_url' => 'https://example.com/image.jpg', 'detail' => 'auto'],
            ],
        ], Responses::convertMessageToResponsesInput($message));
    }

    public function testShouldConvertHumanMessageWithImageUrlContentObjectFormat(): void
    {
        $message = new HumanMessage(['content' => [
            ['type' => 'text', 'text' => 'Describe this'],
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/photo.png']],
        ]]);

        self::assertSame([
            'role' => 'user',
            'content' => [
                ['type' => 'input_text', 'text' => 'Describe this'],
                ['type' => 'input_image', 'image_url' => 'https://example.com/photo.png', 'detail' => 'auto'],
            ],
        ], Responses::convertMessageToResponsesInput($message));
    }

    public function testShouldHandleUnknownContentTypesWithEmptyText(): void
    {
        $message = new HumanMessage(['content' => [['type' => 'unknown_type', 'data' => 'something']]]);

        self::assertSame(
            ['role' => 'user', 'content' => [['type' => 'input_text', 'text' => '']]],
            Responses::convertMessageToResponsesInput($message),
        );
    }

    public function testShouldConvertSystemMessageWithStringContent(): void
    {
        self::assertSame(
            ['role' => 'system', 'content' => 'You are a helpful assistant.'],
            Responses::convertMessageToResponsesInput(new SystemMessage('You are a helpful assistant.')),
        );
    }

    public function testShouldConvertSystemMessageWithArrayContentByJoining(): void
    {
        $message = new SystemMessage(['content' => [['type' => 'text', 'text' => 'You are helpful.'], ['type' => 'text', 'text' => ' Be concise.']]]);

        self::assertSame(
            ['role' => 'system', 'content' => 'You are helpful. Be concise.'],
            Responses::convertMessageToResponsesInput($message),
        );
    }

    public function testShouldConvertAiMessageWithStringContent(): void
    {
        self::assertSame(
            ['type' => 'message', 'role' => 'assistant', 'text' => "I'm an AI assistant."],
            Responses::convertMessageToResponsesInput(new AIMessage("I'm an AI assistant.")),
        );
    }

    public function testShouldConvertAiMessageWithNonStringContentToEmptyText(): void
    {
        $message = new AIMessage(['content' => [['type' => 'text', 'text' => 'Complex content']]]);

        self::assertSame(
            ['type' => 'message', 'role' => 'assistant', 'text' => ''],
            Responses::convertMessageToResponsesInput($message),
        );
    }

    public function testShouldFallbackToUserRoleWithStringContent(): void
    {
        $message = new ToolMessage(['content' => 'Test content', 'tool_call_id' => 'call_1']);

        self::assertSame(['role' => 'user', 'content' => 'Test content'], Responses::convertMessageToResponsesInput($message));
    }

    public function testTheFallbackStringifiesNonStringContent(): void
    {
        $message = new ToolMessage(['content' => [['type' => 'text', 'text' => 'x']], 'tool_call_id' => 'call_1']);

        self::assertSame(
            ['role' => 'user', 'content' => '[{"type":"text","text":"x"}]'],
            Responses::convertMessageToResponsesInput($message),
        );
    }

    // ---- convertMessagesToResponsesInput -----------------------------------

    public function testShouldConvertAnArrayOfMessages(): void
    {
        $result = Responses::convertMessagesToResponsesInput([
            new SystemMessage('Be helpful.'),
            new HumanMessage('Hello!'),
            new AIMessage('Hi there!'),
            new HumanMessage('How are you?'),
        ]);

        self::assertCount(4, $result);
        self::assertSame(['role' => 'system', 'content' => 'Be helpful.'], $result[0]);
        self::assertSame(['role' => 'user', 'content' => 'Hello!'], $result[1]);
        self::assertSame(['type' => 'message', 'role' => 'assistant', 'text' => 'Hi there!'], $result[2]);
        self::assertSame(['role' => 'user', 'content' => 'How are you?'], $result[3]);
    }

    public function testShouldHandleEmptyArray(): void
    {
        self::assertSame([], Responses::convertMessagesToResponsesInput([]));
    }

    // ---- extractTextFromOutput ---------------------------------------------

    public function testShouldExtractTextFromMessageOutputItems(): void
    {
        $output = [['type' => 'message', 'role' => 'assistant', 'content' => [
            ['type' => 'output_text', 'text' => 'Hello, '],
            ['type' => 'output_text', 'text' => 'world!'],
        ]]];

        self::assertSame('Hello, world!', Responses::extractTextFromOutput($output));
    }

    public function testShouldHandleRefusalContentByIgnoringIt(): void
    {
        $output = [['type' => 'message', 'role' => 'assistant', 'content' => [
            ['type' => 'output_text', 'text' => 'Some text'],
            ['type' => 'refusal', 'refusal' => 'I cannot help with that.'],
        ]]];

        self::assertSame('Some text', Responses::extractTextFromOutput($output));
    }

    public function testShouldHandleMultipleMessageItems(): void
    {
        $output = [
            ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'First ']]],
            ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Second']]],
        ];

        self::assertSame('First Second', Responses::extractTextFromOutput($output));
    }

    public function testShouldIgnoreNonMessageOutputItems(): void
    {
        $output = [
            ['type' => 'function_call', 'id' => 'call_123', 'name' => 'get_weather', 'arguments' => '{"location": "NYC"}'],
            ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'The weather is sunny.']]],
        ];

        self::assertSame('The weather is sunny.', Responses::extractTextFromOutput($output));
    }

    public function testShouldReturnEmptyStringForEmptyOutput(): void
    {
        self::assertSame('', Responses::extractTextFromOutput([]));
    }

    public function testShouldReturnEmptyStringWhenNoTextContent(): void
    {
        $output = [['type' => 'function_call', 'id' => 'call_123', 'name' => 'some_function', 'arguments' => '{}']];

        self::assertSame('', Responses::extractTextFromOutput($output));
    }

    // ---- convertResponseToAIMessage ----------------------------------------

    public function testShouldConvertABasicXaiResponseToAiMessage(): void
    {
        $result = Responses::convertResponseToAIMessage(self::response(['usage' => ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15]]));

        self::assertSame('Hello from xAI!', $result->content);
        self::assertSame([
            'model_provider' => 'xai', 'model' => 'grok-3', 'created_at' => 1234567890,
            'id' => 'resp_123', 'status' => 'completed', 'object' => 'response',
        ], self::withoutUsage($result->response_metadata));
        self::assertSame(
            ['input_tokens' => 10, 'output_tokens' => 5, 'total_tokens' => 15] + self::NO_DETAILS,
            $result->response_metadata['usage_metadata'],
        );
    }

    public function testShouldIncludeIncompleteDetailsInResponseMetadata(): void
    {
        $result = Responses::convertResponseToAIMessage(self::response([
            'status' => 'incomplete', 'incomplete_details' => ['reason' => 'max_output_tokens'],
        ]));

        self::assertSame(['reason' => 'max_output_tokens'], $result->response_metadata['incomplete_details']);
    }

    public function testShouldIncludeReasoningInAdditionalKwargs(): void
    {
        $result = Responses::convertResponseToAIMessage(self::response(['reasoning' => ['effort' => 'high', 'summary' => 'detailed']]));

        self::assertSame(['effort' => 'high', 'summary' => 'detailed'], $result->additional_kwargs['reasoning']);
    }

    public function testShouldHandleResponseWithNoUsage(): void
    {
        $result = Responses::convertResponseToAIMessage(self::response());

        self::assertSame(
            ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0] + self::NO_DETAILS,
            $result->response_metadata['usage_metadata'],
        );
        self::assertSame([], $result->additional_kwargs);
    }

    // ---- convertStreamEventToChunk -----------------------------------------

    public function testShouldConvertTextDeltaToChatGenerationChunk(): void
    {
        $result = Responses::convertStreamEventToChunk([
            'type' => 'response.output_text.delta', 'output_index' => 0, 'content_index' => 0, 'delta' => 'Hello',
        ]);

        self::assertNotNull($result);
        self::assertSame('Hello', $result->text);
        self::assertInstanceOf(AIMessageChunk::class, $result->message);
        self::assertSame('Hello', $result->message->content);
        self::assertSame(['model_provider' => 'xai'], $result->message->response_metadata);
    }

    public function testShouldConvertCreatedEventWithResponseMetadata(): void
    {
        $result = Responses::convertStreamEventToChunk([
            'type' => 'response.created',
            'response' => ['id' => 'resp_stream_123', 'object' => 'response', 'created_at' => 1234567890, 'model' => 'grok-3', 'status' => 'in_progress', 'output' => []],
        ]);

        self::assertNotNull($result);
        self::assertSame('', $result->text);
        self::assertSame(['model_provider' => 'xai', 'id' => 'resp_stream_123', 'model' => 'grok-3'], $result->message->response_metadata);
    }

    public function testShouldConvertCompletedEventWithFullResponseData(): void
    {
        $result = Responses::convertStreamEventToChunk([
            'type' => 'response.completed',
            'response' => self::response([
                'id' => 'resp_complete_123',
                'output' => [['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Final answer']]]],
                'usage' => ['input_tokens' => 20, 'output_tokens' => 10, 'total_tokens' => 30],
            ]),
        ]);

        self::assertNotNull($result);
        self::assertSame('', $result->text);
        self::assertSame(
            ['input_tokens' => 20, 'output_tokens' => 10, 'total_tokens' => 30] + self::NO_DETAILS,
            $result->message->response_metadata['usage_metadata'],
        );
        self::assertSame('resp_complete_123', $result->message->response_metadata['id']);
        self::assertSame('xai', $result->message->response_metadata['model_provider']);
    }

    public function testShouldReturnNullForResponseInProgressEvent(): void
    {
        self::assertNull(Responses::convertStreamEventToChunk([
            'type' => 'response.in_progress',
            'response' => ['id' => 'resp_123', 'object' => 'response', 'created_at' => 1234567890, 'model' => 'grok-3', 'status' => 'in_progress', 'output' => []],
        ]));
    }

    public function testShouldReturnNullForResponseOutputItemAddedEvent(): void
    {
        self::assertNull(Responses::convertStreamEventToChunk([
            'type' => 'response.output_item.added', 'output_index' => 0,
            'item' => ['type' => 'message', 'role' => 'assistant', 'content' => []],
        ]));
    }

    public function testShouldReturnNullForErrorEvent(): void
    {
        self::assertNull(Responses::convertStreamEventToChunk(['type' => 'error', 'code' => 'server_error', 'message' => 'Something went wrong']));
    }

    public function testFoldedChunksKeepTheTextAndTheUsage(): void
    {
        $events = [
            ['type' => 'response.created', 'response' => ['id' => 'resp_1', 'model' => 'grok-3']],
            ['type' => 'response.output_text.delta', 'delta' => 'Hel', 'content_index' => 0, 'output_index' => 0],
            ['type' => 'response.output_text.delta', 'delta' => 'lo', 'content_index' => 0, 'output_index' => 0],
            ['type' => 'response.completed', 'response' => self::response(['id' => 'resp_1', 'output' => [], 'usage' => ['input_tokens' => 3, 'output_tokens' => 2, 'total_tokens' => 5]])],
        ];

        $full = null;
        foreach ($events as $event) {
            $chunk = Responses::convertStreamEventToChunk($event);
            $full = $full === null ? $chunk : $full->concat($chunk);
        }

        self::assertSame('Hello', $full->text);
        self::assertSame('Hello', $full->message->content);
        self::assertSame(5, $full->message->response_metadata['usage_metadata']['total_tokens']);
    }
}
