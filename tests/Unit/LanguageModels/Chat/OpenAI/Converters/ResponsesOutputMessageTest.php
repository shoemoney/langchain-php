<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Converters;

use LangChain\LanguageModels\Chat\OpenAI\Converters\ResponsesInput;
use LangChain\LanguageModels\Chat\OpenAI\Converters\ResponsesOutput;
use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\Messages\AIMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `convertResponsesMessageToAIMessage` describes of upstream
 * `converters/tests/responses.test.ts`: reasoning elevation, response metadata,
 * image generation, tool_search, annotation round trip and phase.
 */
#[CoversClass(ResponsesOutput::class)]
final class ResponsesOutputMessageTest extends TestCase
{
    use AssertsWireJson;

    private const USAGE = ['input_tokens' => 10, 'output_tokens' => 20, 'total_tokens' => 30];

    /**
     * @param list<array<string, mixed>> $output
     * @param array<string, mixed>       $extra
     *
     * @return array<string, mixed>
     */
    private static function response(array $output, array $extra = []): array
    {
        return $extra + [
            'id' => 'resp_123',
            'model' => 'gpt-4o',
            'created_at' => 1234567890,
            'object' => 'response',
            'status' => 'completed',
            'output' => $output,
            'usage' => self::USAGE,
        ];
    }

    /** @return array<string, mixed> */
    private static function textMessage(string $text, array $annotations = [], array $extra = []): array
    {
        return $extra + [
            'type' => 'message',
            'id' => 'msg_123',
            'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => $annotations]],
        ];
    }

    /**
     * @param list<mixed> $blocks
     *
     * @return list<array<string, mixed>>
     */
    private static function blocksOfType(array $blocks, string $type): array
    {
        return array_values(array_filter($blocks, static fn (mixed $b): bool => is_array($b) && ($b['type'] ?? null) === $type));
    }

    // ---- reasoning --------------------------------------------------------

    public function testElevatesReasoningToTheContentArray(): void
    {
        $reasoning = [
            'type' => 'reasoning',
            'id' => 'rs_abc123',
            'summary' => [
                ['type' => 'summary_text', 'text' => 'First reasoning step'],
                ['type' => 'summary_text', 'text' => 'Second reasoning step'],
            ],
        ];

        $result = ResponsesOutput::convertResponsesMessageToAIMessage(
            self::response([$reasoning, self::textMessage('Hello!')], ['model' => 'o3-mini']),
        );

        self::assertSame($reasoning, $result->additional_kwargs['reasoning']);
        $reasoningBlocks = self::blocksOfType($result->content, 'reasoning');
        self::assertSame([['type' => 'reasoning', 'reasoning' => 'First reasoning stepSecond reasoning step']], $reasoningBlocks);
        $textBlocks = self::blocksOfType($result->content, 'text');
        self::assertCount(1, $textBlocks);
        self::assertSame('Hello!', $textBlocks[0]['text']);
    }

    public function testReasoningWithAnEmptySummaryAddsNoContentBlock(): void
    {
        $result = ResponsesOutput::convertResponsesMessageToAIMessage(self::response([
            ['type' => 'reasoning', 'id' => 'rs_abc123', 'summary' => []],
            self::textMessage('Hello!'),
        ]));

        self::assertArrayHasKey('reasoning', $result->additional_kwargs);
        self::assertSame([], self::blocksOfType($result->content, 'reasoning'));
    }

    public function testAResponseWithoutReasoningHasNone(): void
    {
        $result = ResponsesOutput::convertResponsesMessageToAIMessage(self::response([self::textMessage('Hello!')]));

        self::assertArrayNotHasKey('reasoning', $result->additional_kwargs);
        self::assertSame([], self::blocksOfType($result->content, 'reasoning'));
    }

    // ---- ids and metadata -------------------------------------------------

    public function testUsesTheTopLevelResponseIdForTheMessageId(): void
    {
        $result = ResponsesOutput::convertResponsesMessageToAIMessage(
            self::response([self::textMessage('Hello!', [], ['id' => 'msg_nested'])], ['id' => 'resp_top_level']),
        );

        self::assertSame('resp_top_level', $result->id);
        self::assertSame('resp_top_level', $result->response_metadata['id']);
    }

    public function testStoresTheOutputInResponseMetadata(): void
    {
        $output = [
            ['type' => 'reasoning', 'id' => 'rs_abc123', 'summary' => [['type' => 'summary_text', 'text' => 'Thinking...']]],
            ['type' => 'function_call', 'id' => 'fc_xyz789', 'call_id' => 'call_123', 'name' => 'get_weather', 'arguments' => '{"city":"NYC"}'],
        ];

        $result = ResponsesOutput::convertResponsesMessageToAIMessage(self::response($output, ['model' => 'o3-mini']));

        self::assertSame($output, $result->response_metadata['output']);
    }

    public function testStripsParsedArgumentsFromStoredFunctionCalls(): void
    {
        $result = ResponsesOutput::convertResponsesMessageToAIMessage(self::response([[
            'type' => 'function_call',
            'id' => 'fc_xyz789',
            'call_id' => 'call_123',
            'name' => 'get_weather',
            'arguments' => '{"city":"NYC"}',
            'parsed_arguments' => ['city' => 'NYC'],
        ]]));

        $stored = $result->response_metadata['output'];
        self::assertCount(1, $stored);
        self::assertSame('get_weather', $stored[0]['name']);
        self::assertArrayNotHasKey('parsed_arguments', $stored[0]);
    }

    public function testResponseMetadataIdentifiesTheProvider(): void
    {
        $result = ResponsesOutput::convertResponsesMessageToAIMessage(self::response([self::textMessage('hi')], ['service_tier' => 'auto']));

        self::assertSame('openai', $result->response_metadata['model_provider']);
        self::assertSame('gpt-4o', $result->response_metadata['model']);
        self::assertSame('gpt-4o', $result->response_metadata['model_name']);
        self::assertSame('auto', $result->response_metadata['service_tier']);
        self::assertSame(['input_tokens' => 10, 'output_tokens' => 20, 'total_tokens' => 30, 'input_token_details' => [], 'output_token_details' => []], $result->response_metadata['usage_metadata']);
    }

    public function testAnErrorObjectRaises(): void
    {
        try {
            ResponsesOutput::convertResponsesMessageToAIMessage(self::response([], ['error' => ['code' => 'server_error', 'message' => 'boom']]));
            self::fail('expected an exception');
        } catch (OpenAIException $e) {
            self::assertStringContainsString('boom', $e->getMessage());
            self::assertStringContainsString('server_error', $e->getMessage());
            self::assertSame('server_error', $e->providerError['code']);
        }
    }

    // ---- tool calls -------------------------------------------------------

    public function testFunctionCallsBecomeToolCallsAndRememberTheItemId(): void
    {
        $result = ResponsesOutput::convertResponsesMessageToAIMessage(self::response([[
            'type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'get_weather', 'arguments' => '{"city":"NYC"}',
        ]]));

        self::assertSame([['name' => 'get_weather', 'args' => ['city' => 'NYC'], 'id' => 'call_1', 'type' => 'tool_call']], $result->toolCalls);
        self::assertSame(['call_1' => 'fc_1'], $result->additional_kwargs[ResponsesOutput::FUNCTION_CALL_IDS_MAP_KEY]);
    }

    public function testUnparseableArgumentsLandInInvalidToolCalls(): void
    {
        $result = ResponsesOutput::convertResponsesMessageToAIMessage(self::response([[
            'type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'get_weather', 'arguments' => '{"city":',
        ]]));

        self::assertSame([], $result->toolCalls);
        self::assertCount(1, $result->invalidToolCalls);
        self::assertSame('get_weather', $result->invalidToolCalls[0]['name']);
        self::assertSame('{"city":', $result->invalidToolCalls[0]['args']);
        self::assertSame('call_1', $result->invalidToolCalls[0]['id']);
        self::assertStringContainsString('not valid JSON', $result->invalidToolCalls[0]['error']);
    }

    public function testCustomToolCallsKeepBothIds(): void
    {
        $result = ResponsesOutput::convertResponsesMessageToAIMessage(self::response([[
            'type' => 'custom_tool_call', 'id' => 'ctc_1', 'call_id' => 'call_9', 'name' => 'execute_code', 'input' => 'print(1)',
        ]]));

        self::assertCount(1, $result->toolCalls);
        self::assertSame('execute_code', $result->toolCalls[0]['name']);
        self::assertSame(['input' => 'print(1)'], $result->toolCalls[0]['args']);
        self::assertSame('call_9', $result->toolCalls[0]['id']);
        self::assertTrue($result->toolCalls[0]['isCustomTool']);
        self::assertSame(['call_9' => 'ctc_1'], $result->additional_kwargs[ResponsesOutput::CUSTOM_TOOL_CALL_IDS_MAP_KEY]);
    }

    public function testComputerCallsBecomeComputerUseToolCalls(): void
    {
        $result = ResponsesOutput::convertResponsesMessageToAIMessage(self::response([[
            'type' => 'computer_call', 'id' => 'cu_1', 'call_id' => 'call_c', 'action' => ['type' => 'click', 'x' => 1, 'y' => 2],
        ]]));

        self::assertSame('computer_use', $result->toolCalls[0]['name']);
        self::assertSame(['action' => ['type' => 'click', 'x' => 1, 'y' => 2]], $result->toolCalls[0]['args']);
        self::assertSame('call_c', $result->toolCalls[0]['id']);
    }

    public function testBuiltInToolItemsAreCollectedAsToolOutputs(): void
    {
        $search = ['type' => 'web_search_call', 'id' => 'ws_1', 'status' => 'completed'];
        $result = ResponsesOutput::convertResponsesMessageToAIMessage(self::response([$search, self::textMessage('found')]));

        self::assertSame([$search], $result->additional_kwargs['tool_outputs']);
    }

    // ---- message parts ----------------------------------------------------

    public function testARefusalIsRecordedAndNotRenderedAsContent(): void
    {
        $result = ResponsesOutput::convertResponsesMessageToAIMessage(self::response([[
            'type' => 'message', 'id' => 'm', 'role' => 'assistant', 'content' => [['type' => 'refusal', 'refusal' => 'No.']],
        ]]));

        self::assertSame('No.', $result->additional_kwargs['refusal']);
        self::assertSame([], $result->content);
    }

    public function testAParsedOutputTextPartIsLiftedIntoAdditionalKwargs(): void
    {
        $result = ResponsesOutput::convertResponsesMessageToAIMessage(self::response([[
            'type' => 'message', 'id' => 'm', 'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => '{"a":1}', 'annotations' => [], 'parsed' => ['a' => 1]]],
        ]]));

        self::assertSame(['a' => 1], $result->additional_kwargs['parsed']);
    }

    // ---- image generation -------------------------------------------------

    public function testImageGenerationCallBecomesAnImageContentBlock(): void
    {
        $png = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
        $result = ResponsesOutput::convertResponsesMessageToAIMessage(self::response([
            ['type' => 'image_generation_call', 'id' => 'ig_abc123', 'status' => 'completed', 'result' => $png],
        ], ['model' => 'gpt-4']));

        self::assertCount(1, $result->content);
        self::assertSame([
            'type' => 'image',
            'mimeType' => 'image/png',
            'data' => $png,
            'id' => 'ig_abc123',
            'metadata' => ['status' => 'completed'],
        ], $result->content[0]);
        self::assertCount(1, $result->additional_kwargs['tool_outputs']);
        self::assertSame('image_generation_call', $result->additional_kwargs['tool_outputs'][0]['type']);
    }

    public function testAnImageGenerationCallWithoutAResultAddsNoImageBlock(): void
    {
        $result = ResponsesOutput::convertResponsesMessageToAIMessage(self::response([
            ['type' => 'image_generation_call', 'id' => 'ig_abc123', 'status' => 'in_progress', 'result' => null],
        ], ['status' => 'in_progress']));

        self::assertSame([], $result->content);
        self::assertCount(1, $result->additional_kwargs['tool_outputs']);
    }

    public function testTextAndImageOutputItemsKeepTheirOrder(): void
    {
        $result = ResponsesOutput::convertResponsesMessageToAIMessage(self::response([
            self::textMessage('Here is the image you requested:'),
            ['type' => 'image_generation_call', 'id' => 'ig_abc123', 'status' => 'completed', 'result' => 'base64ImageData'],
        ]));

        self::assertCount(2, $result->content);
        self::assertSame('text', $result->content[0]['type']);
        self::assertSame('Here is the image you requested:', $result->content[0]['text']);
        self::assertSame('image', $result->content[1]['type']);
        self::assertSame('base64ImageData', $result->content[1]['data']);
    }

    // ---- tool_search ------------------------------------------------------

    public function testToolSearchItemsAreCollectedInToolOutputs(): void
    {
        $call = ['type' => 'tool_search_call', 'id' => 'ts_001', 'call_id' => 'call_abc', 'execution' => 'server', 'status' => 'completed'];
        $found = [
            'type' => 'tool_search_output', 'id' => 'tso_001', 'call_id' => 'call_abc', 'execution' => 'server', 'status' => 'completed',
            'tools' => [['type' => 'function', 'name' => 'get_weather', 'description' => 'Get weather', 'parameters' => ['type' => 'object', 'properties' => []], 'strict' => null]],
        ];

        $result = ResponsesOutput::convertResponsesMessageToAIMessage(self::response([
            $call,
            $found,
            ['type' => 'function_call', 'id' => 'fc_001', 'call_id' => 'call_xyz', 'name' => 'get_weather', 'arguments' => '{"location":"SF"}'],
        ], ['model' => 'gpt-5.3']));

        self::assertCount(1, $result->toolCalls);
        self::assertSame('get_weather', $result->toolCalls[0]['name']);
        self::assertCount(2, $result->additional_kwargs['tool_outputs']);
        self::assertSame('tool_search_call', $result->additional_kwargs['tool_outputs'][0]['type']);
        self::assertSame('tool_search_output', $result->additional_kwargs['tool_outputs'][1]['type']);
    }

    public function testToolSearchItemsRoundTripThroughResponseMetadataOutput(): void
    {
        $items = [
            ['type' => 'tool_search_call', 'id' => 'ts_001', 'call_id' => 'call_abc', 'execution' => 'server', 'status' => 'completed'],
            ['type' => 'function_call', 'id' => 'fc_001', 'call_id' => 'call_xyz', 'name' => 'get_weather', 'arguments' => '{"location":"SF"}'],
        ];
        $message = ResponsesOutput::convertResponsesMessageToAIMessage(self::response($items, ['model' => 'gpt-5.3']));

        $input = ResponsesInput::convertMessagesToResponsesInput([$message], false, 'gpt-5.3');

        self::assertWire($items, $input);
    }

    // ---- annotations ------------------------------------------------------

    /** @return iterable<string, array{0: array<string, mixed>, 1: array<string, mixed>}> */
    public static function annotations(): iterable
    {
        yield 'url_citation' => [
            ['type' => 'url_citation', 'url' => 'https://example.com/article', 'title' => 'Example Article', 'start_index' => 0, 'end_index' => 38],
            ['type' => 'citation', 'source' => 'url_citation', 'url' => 'https://example.com/article', 'title' => 'Example Article', 'startIndex' => 0, 'endIndex' => 38],
        ];
        yield 'file_citation' => [
            ['type' => 'file_citation', 'file_id' => 'file-abc123', 'filename' => 'report.pdf', 'index' => 5],
            ['type' => 'citation', 'source' => 'file_citation', 'title' => 'report.pdf', 'startIndex' => 5, 'file_id' => 'file-abc123'],
        ];
        yield 'container_file_citation' => [
            ['type' => 'container_file_citation', 'file_id' => 'file-def456', 'filename' => 'data.csv', 'container_id' => 'container-xyz', 'start_index' => 0, 'end_index' => 24],
            ['type' => 'citation', 'source' => 'container_file_citation', 'title' => 'data.csv', 'startIndex' => 0, 'endIndex' => 24, 'file_id' => 'file-def456', 'container_id' => 'container-xyz'],
        ];
        yield 'file_path' => [
            ['type' => 'file_path', 'file_id' => 'file-ghi789', 'index' => 10],
            ['type' => 'citation', 'source' => 'file_path', 'startIndex' => 10, 'file_id' => 'file-ghi789'],
        ];
    }

    /**
     * @param array<string, mixed> $openai
     * @param array<string, mixed> $langchain
     */
    #[DataProvider('annotations')]
    public function testAnnotationsRoundTripThroughAnAiMessage(array $openai, array $langchain): void
    {
        $message = ResponsesOutput::convertResponsesMessageToAIMessage(
            self::response([self::textMessage('Cited text.', [$openai])]),
        );

        self::assertSame([$langchain], $message->content[0]['annotations']);

        $input = ResponsesInput::convertMessagesToResponsesInput([$message], false, 'gpt-4o');
        $item = array_values(array_filter($input, static fn (array $i): bool => $i['type'] === 'message'))[0];
        $part = array_values(array_filter($item['content'], static fn (array $c): bool => $c['type'] === 'output_text'))[0];

        self::assertSame([$openai], $part['annotations']);
    }

    public function testAnnotationsRoundTripEvenWithoutTheVerbatimOutputFastPath(): void
    {
        // Without `output` in the metadata the input converter has to rebuild
        // the OpenAI annotation from the LangChain citation.
        $message = ResponsesOutput::convertResponsesMessageToAIMessage(self::response([
            self::textMessage('Multi.', [
                ['type' => 'url_citation', 'url' => 'https://example.com/page1', 'title' => 'Page 1', 'start_index' => 0, 'end_index' => 10],
                ['type' => 'url_citation', 'url' => 'https://example.com/page2', 'title' => 'Page 2', 'start_index' => 11, 'end_index' => 27],
            ]),
        ]));
        unset($message->response_metadata['output']);

        $input = ResponsesInput::convertMessagesToResponsesInput([$message], false, 'gpt-4o');
        $part = array_values(array_filter($input[0]['content'], static fn (array $c): bool => $c['type'] === 'output_text'))[0];

        self::assertCount(2, $part['annotations']);
        self::assertSame('https://example.com/page1', $part['annotations'][0]['url']);
        self::assertSame(11, $part['annotations'][1]['start_index']);
    }

    public function testAnUnknownAnnotationIsKeptAsANonStandardBlock(): void
    {
        $unknown = ['type' => 'something_new', 'x' => 1];

        self::assertSame(
            ['type' => 'non_standard', 'value' => $unknown],
            ResponsesOutput::convertOpenAIAnnotationToLangChain($unknown),
        );
        self::assertSame($unknown, ResponsesInput::convertLangChainAnnotationToOpenAI(['type' => 'non_standard', 'value' => $unknown]));
    }

    // ---- phase ------------------------------------------------------------

    public function testPhaseLandsOnTheTextBlockWhenPresent(): void
    {
        $result = ResponsesOutput::convertResponsesMessageToAIMessage(
            self::response([self::textMessage('Let me check that for you.', [], ['phase' => 'commentary'])], ['model' => 'gpt-5.4']),
        );

        self::assertCount(1, $result->content);
        self::assertSame('text', $result->content[0]['type']);
        self::assertSame('commentary', $result->content[0]['phase']);
    }

    public function testFinalAnswerPhaseLandsOnTheTextBlock(): void
    {
        $result = ResponsesOutput::convertResponsesMessageToAIMessage(
            self::response([self::textMessage('The weather is sunny.', [], ['phase' => 'final_answer'])]),
        );

        self::assertSame('final_answer', $result->content[0]['phase']);
    }

    public function testNoPhaseKeyWhenTheMessageHasNone(): void
    {
        $result = ResponsesOutput::convertResponsesMessageToAIMessage(self::response([self::textMessage('Hello!')]));

        self::assertArrayNotHasKey('phase', $result->content[0]);
    }

    public function testPhaseRoundTripsThroughResponseToMessageToInput(): void
    {
        $message = ResponsesOutput::convertResponsesMessageToAIMessage(
            self::response([self::textMessage('Let me check the weather.', [], ['phase' => 'commentary'])], ['model' => 'gpt-5.4']),
        );
        self::assertSame('commentary', $message->content[0]['phase']);

        $input = ResponsesInput::convertMessagesToResponsesInput([$message], false, 'gpt-5.4');
        $item = array_values(array_filter($input, static fn (array $i): bool => $i['type'] === 'message'))[0];

        self::assertSame('commentary', $item['phase']);
    }

    public function testTheResultIsAnAiMessage(): void
    {
        self::assertInstanceOf(AIMessage::class, ResponsesOutput::convertResponsesMessageToAIMessage(self::response([])));
    }

    public function testTextOfJoinsTextBlocksOnly(): void
    {
        self::assertSame('ab', ResponsesOutput::textOf([['type' => 'text', 'text' => 'a'], ['type' => 'image', 'data' => 'x'], 'b']));
        self::assertSame('plain', ResponsesOutput::textOf('plain'));
    }
}
