<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Converters;

use LangChain\LanguageModels\Chat\OpenAI\Converters\ResponsesInput;
use LangChain\LanguageModels\Chat\OpenAI\Converters\ResponsesOutput;
use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\Messages\AIMessageChunk;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `convertResponsesDeltaToChatGenerationChunk` describes of upstream
 * `converters/tests/responses.test.ts`: ids, custom tools, built-in tool
 * progress, reasoning elevation, image generation, tool_search, json_schema and
 * phase.
 */
#[CoversClass(ResponsesOutput::class)]
final class ResponsesDeltaTest extends TestCase
{
    use AssertsWireJson;

    /**
     * @param array<string, mixed> $event
     */
    private static function chunk(array $event): ChatGenerationChunk
    {
        $chunk = ResponsesOutput::convertResponsesDeltaToChatGenerationChunk($event);
        self::assertNotNull($chunk, 'event ' . ($event['type'] ?? '?') . ' should produce a chunk');

        return $chunk;
    }

    /**
     * @param array<string, mixed> $event
     */
    private static function message(array $event): AIMessageChunk
    {
        $message = self::chunk($event)->message;
        self::assertInstanceOf(AIMessageChunk::class, $message);

        return $message;
    }

    /**
     * @param list<mixed> $content
     *
     * @return list<array<string, mixed>>
     */
    private static function ofType(array $content, string $type): array
    {
        return array_values(array_filter($content, static fn (mixed $b): bool => is_array($b) && ($b['type'] ?? null) === $type));
    }

    // ---- ids --------------------------------------------------------------

    public function testUsesTheTopLevelResponseIdWhenStreaming(): void
    {
        $created = self::message([
            'type' => 'response.created',
            'response' => ['id' => 'resp_top_level', 'model' => 'gpt-4o', 'object' => 'response', 'status' => 'in_progress', 'output' => []],
        ]);
        $added = self::message([
            'type' => 'response.output_item.added', 'output_index' => 0,
            'item' => ['type' => 'message', 'id' => 'msg_nested', 'role' => 'assistant', 'content' => [], 'status' => 'in_progress'],
        ]);
        $delta = self::message(['type' => 'response.output_text.delta', 'output_index' => 0, 'content_index' => 0, 'delta' => 'Hello!']);
        $completed = self::message([
            'type' => 'response.completed',
            'response' => [
                'id' => 'resp_top_level', 'model' => 'gpt-4o', 'created_at' => 1234567890, 'object' => 'response', 'status' => 'completed',
                'output' => [['type' => 'message', 'id' => 'msg_nested', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Hello!', 'annotations' => []]]]],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 20, 'total_tokens' => 30],
            ],
        ]);

        self::assertSame('resp_top_level', $created->id);
        self::assertNull($added->id);
        self::assertNull($delta->id);

        $aggregated = $created;
        foreach ([$added, $delta, $completed] as $chunk) {
            $aggregated = $aggregated->concat($chunk);
        }

        self::assertSame('resp_top_level', $aggregated->id);
        self::assertSame('resp_top_level', $aggregated->response_metadata['id']);
    }

    // ---- text -------------------------------------------------------------

    public function testTextDeltasCarryTheContentIndexAndLegacyText(): void
    {
        $chunk = self::chunk(['type' => 'response.output_text.delta', 'output_index' => 0, 'content_index' => 2, 'delta' => 'Hi']);

        self::assertSame('Hi', $chunk->text);
        self::assertSame([['type' => 'text', 'text' => 'Hi', 'index' => 2]], $chunk->message->content);
    }

    public function testTextDeltasFoldByContentIndex(): void
    {
        $a = self::message(['type' => 'response.output_text.delta', 'output_index' => 0, 'content_index' => 0, 'delta' => 'Hel']);
        $b = self::message(['type' => 'response.output_text.delta', 'output_index' => 0, 'content_index' => 0, 'delta' => 'lo']);
        $c = self::message(['type' => 'response.output_text.delta', 'output_index' => 0, 'content_index' => 1, 'delta' => 'World']);

        $folded = $a->concat($b)->concat($c);

        self::assertCount(2, $folded->content);
        self::assertSame('Hello', $folded->content[0]['text']);
        self::assertSame('World', $folded->content[1]['text']);
    }

    public function testAnAddedAnnotationBecomesACitationOnAnEmptyTextBlock(): void
    {
        $message = self::message([
            'type' => 'response.output_text.annotation.added', 'content_index' => 1,
            'annotation' => ['type' => 'url_citation', 'url' => 'https://e.com', 'title' => 'E', 'start_index' => 0, 'end_index' => 3],
        ]);

        self::assertEquals([[
            'type' => 'text', 'text' => '', 'index' => 1,
            'annotations' => [['type' => 'citation', 'source' => 'url_citation', 'url' => 'https://e.com', 'title' => 'E', 'startIndex' => 0, 'endIndex' => 3]],
        ]], $message->content);
    }

    public function testARefusalIsRecorded(): void
    {
        $message = self::message(['type' => 'response.refusal.done', 'refusal' => 'No.']);

        self::assertSame('No.', $message->additional_kwargs['refusal']);
    }

    // ---- function and custom tool calls ----------------------------------

    public function testAFunctionCallItemStartsAToolCallChunk(): void
    {
        $message = self::message([
            'type' => 'response.output_item.added', 'output_index' => 3,
            'item' => ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_abc', 'name' => 'web_search', 'arguments' => ''],
        ]);

        self::assertSame([['type' => 'tool_call_chunk', 'name' => 'web_search', 'args' => '', 'id' => 'call_abc', 'index' => 3]], $message->toolCallChunks);
        self::assertSame(['call_abc' => 'fc_1'], $message->additional_kwargs[ResponsesOutput::FUNCTION_CALL_IDS_MAP_KEY]);
    }

    public function testFunctionArgumentDeltasFoldIntoTheOpeningChunkByOutputIndex(): void
    {
        $start = self::message([
            'type' => 'response.output_item.added', 'output_index' => 0,
            'item' => ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_abc', 'name' => 'web_search', 'arguments' => ''],
        ]);
        $one = self::message(['type' => 'response.function_call_arguments.delta', 'output_index' => 0, 'delta' => '{"query"']);
        $two = self::message(['type' => 'response.function_call_arguments.delta', 'output_index' => 0, 'delta' => ':"weather"}']);

        $folded = $start->concat($one)->concat($two);

        self::assertCount(1, $folded->toolCallChunks);
        [$calls, $invalid] = $folded->parseToolCalls();
        self::assertSame([], $invalid);
        self::assertSame('web_search', $calls[0]['name']);
        self::assertSame(['query' => 'weather'], $calls[0]['args']);
        self::assertSame('call_abc', $calls[0]['id']);
    }

    public function testParallelFunctionCallsStayApartByOutputIndex(): void
    {
        $a = self::message([
            'type' => 'response.output_item.added', 'output_index' => 1,
            'item' => ['type' => 'function_call', 'id' => 'fc_a', 'call_id' => 'call_a', 'name' => 'one', 'arguments' => ''],
        ]);
        $b = self::message([
            'type' => 'response.output_item.added', 'output_index' => 2,
            'item' => ['type' => 'function_call', 'id' => 'fc_b', 'call_id' => 'call_b', 'name' => 'two', 'arguments' => ''],
        ]);
        $da = self::message(['type' => 'response.function_call_arguments.delta', 'output_index' => 1, 'delta' => '{"x":1}']);
        $db = self::message(['type' => 'response.function_call_arguments.delta', 'output_index' => 2, 'delta' => '{"y":2}']);

        [$calls] = $a->concat($b)->concat($da)->concat($db)->parseToolCalls();

        self::assertSame([['one', ['x' => 1]], ['two', ['y' => 2]]], array_map(static fn (array $c): array => [$c['name'], $c['args']], $calls));
    }

    public function testPreservesCustomToolMetadataFromOutputItemAdded(): void
    {
        $message = self::message([
            'type' => 'response.output_item.added', 'output_index' => 0,
            'item' => ['type' => 'custom_tool_call', 'id' => 'ctc_123', 'call_id' => 'call_123', 'name' => 'execute_code', 'input' => ''],
        ]);

        self::assertSame([[
            'type' => 'tool_call_chunk', 'isCustomTool' => true, 'name' => 'execute_code', 'args' => '', 'id' => 'call_123', 'index' => 0,
        ]], $message->toolCallChunks);
        self::assertSame(['call_123' => 'ctc_123'], $message->additional_kwargs[ResponsesOutput::CUSTOM_TOOL_CALL_IDS_MAP_KEY]);
    }

    public function testCustomToolStreamingChunksFoldIntoOneRawInputChunk(): void
    {
        $start = self::message([
            'type' => 'response.output_item.added', 'output_index' => 0,
            'item' => ['type' => 'custom_tool_call', 'id' => 'ctc_123', 'call_id' => 'call_123', 'name' => 'execute_code', 'input' => ''],
        ]);
        $delta = self::message([
            'type' => 'response.custom_tool_call_input.delta', 'output_index' => 0, 'delta' => "console.log('custom tool streaming repro')",
        ]);

        $combined = $start->concat($delta);

        // `isCustomTool` is a bool, which the merge keeps rather than folding.
        self::assertCount(1, $combined->toolCallChunks);
        self::assertSame("console.log('custom tool streaming repro')", $combined->toolCallChunks[0]['args']);
        self::assertSame('execute_code', $combined->toolCallChunks[0]['name']);
        self::assertSame('call_123', $combined->toolCallChunks[0]['id']);
        self::assertTrue($combined->toolCallChunks[0]['isCustomTool']);
        self::assertSame(['call_123' => 'ctc_123'], $combined->additional_kwargs[ResponsesOutput::CUSTOM_TOOL_CALL_IDS_MAP_KEY]);
    }

    public function testCustomToolInputDeltaEvents(): void
    {
        $message = self::message(['type' => 'response.custom_tool_call_input.delta', 'delta' => '{"query": "test query"}', 'output_index' => 0]);

        self::assertSame([['type' => 'tool_call_chunk', 'args' => '{"query": "test query"}', 'index' => 0, 'isCustomTool' => true]], $message->toolCallChunks);
    }

    public function testFunctionAndCustomToolDeltasDifferOnlyByTheCustomMarker(): void
    {
        $function = self::message(['type' => 'response.function_call_arguments.delta', 'delta' => '{"location": "NYC"}', 'output_index' => 0]);
        $custom = self::message(['type' => 'response.custom_tool_call_input.delta', 'delta' => '{"location": "NYC"}', 'output_index' => 0]);

        self::assertSame([['type' => 'tool_call_chunk', 'args' => '{"location": "NYC"}', 'index' => 0]], $function->toolCallChunks);
        self::assertSame([['type' => 'tool_call_chunk', 'args' => '{"location": "NYC"}', 'index' => 0, 'isCustomTool' => true]], $custom->toolCallChunks);
    }

    public function testAComputerCallIsEmittedWhenItIsDone(): void
    {
        $item = ['type' => 'computer_call', 'id' => 'cu_1', 'call_id' => 'call_c', 'action' => ['type' => 'click'], 'pending_safety_checks' => []];
        $message = self::message(['type' => 'response.output_item.done', 'output_index' => 4, 'item' => $item]);

        self::assertSame([[
            'type' => 'tool_call_chunk', 'name' => 'computer_use', 'args' => '{"action":{"type":"click"}}', 'id' => 'call_c', 'index' => 4,
        ]], $message->toolCallChunks);
        self::assertSame([$item], $message->additional_kwargs['tool_outputs']);
    }

    // ---- built-in tool progress ------------------------------------------

    /** @return iterable<string, array{0: string, 1: string, 2: string}> */
    public static function searchProgress(): iterable
    {
        foreach (['web_search_call', 'file_search_call'] as $kind) {
            foreach (['in_progress', 'searching', 'completed'] as $status) {
                yield "$kind $status" => [$kind, $status, 'ws_123'];
            }
        }
    }

    #[DataProvider('searchProgress')]
    public function testSearchProgressEventsSurfaceInGenerationInfo(string $kind, string $status, string $itemId): void
    {
        $chunk = self::chunk(['type' => "response.$kind.$status", 'item_id' => $itemId, 'status' => $status]);

        self::assertSame(['tool_outputs' => ['id' => $itemId, 'type' => $kind, 'status' => $status]], $chunk->generationInfo);
    }

    /** @return iterable<string, array{0: string}> */
    public static function imageStatuses(): iterable
    {
        yield 'in_progress' => ['in_progress'];
        yield 'generating' => ['generating'];
        yield 'completed' => ['completed'];
    }

    #[DataProvider('imageStatuses')]
    public function testImageGenerationLifecycleEventsSurfaceInGenerationInfo(string $status): void
    {
        $chunk = self::chunk(['type' => "response.image_generation_call.$status", 'item_id' => 'ig_123', 'status' => $status]);

        self::assertSame(['tool_outputs' => ['id' => 'ig_123', 'type' => 'image_generation_call', 'status' => $status]], $chunk->generationInfo);
    }

    /** @return iterable<string, array{0: string}> */
    public static function toolOutputItemTypes(): iterable
    {
        foreach ([
            'web_search_call', 'file_search_call', 'code_interpreter_call', 'shell_call', 'local_shell_call', 'mcp_call',
            'mcp_list_tools', 'mcp_approval_request', 'custom_tool_call', 'tool_search_call', 'tool_search_output',
        ] as $type) {
            yield $type => [$type];
        }
    }

    #[DataProvider('toolOutputItemTypes')]
    public function testFinishedBuiltInToolItemsBecomeToolOutputs(string $type): void
    {
        $item = ['type' => $type, 'id' => 'x_001', 'call_id' => 'call_abc', 'status' => 'completed'];
        $message = self::message(['type' => 'response.output_item.done', 'output_index' => 1, 'item' => $item]);

        self::assertSame([$item], $message->additional_kwargs['tool_outputs']);
    }

    // ---- reasoning --------------------------------------------------------

    public function testReplaysEncryptedReasoningFromStreamingResponsesInZdrMode(): void
    {
        $added = self::message([
            'type' => 'response.output_item.added', 'output_index' => 0,
            'item' => ['type' => 'reasoning', 'id' => 'rs_abc123', 'summary' => [], 'encrypted_content' => 'incomplete_payload'],
        ]);
        $done = self::message([
            'type' => 'response.output_item.done', 'output_index' => 0,
            'item' => ['type' => 'reasoning', 'id' => 'rs_abc123', 'summary' => [], 'encrypted_content' => 'canonical_payload'],
        ]);

        $streamed = $added->concat($done);
        $replay = ResponsesInput::convertMessagesToResponsesInput([$streamed], true, 'o3');

        self::assertWire([['id' => 'rs_abc123', 'type' => 'reasoning', 'summary' => [], 'encrypted_content' => 'canonical_payload']], $replay);
    }

    public function testV0ReasoningStaysASingleLastWriteWinsObjectWithTwoItems(): void
    {
        // The accepted v0 limitation: wrong id, concatenated ciphertext. Multi-item
        // support is the v1 content path's job.
        $events = [
            ['type' => 'response.output_item.added', 'output_index' => 0, 'item' => ['type' => 'reasoning', 'id' => 'rs_first', 'summary' => []]],
            ['type' => 'response.output_item.done', 'output_index' => 0, 'item' => ['type' => 'reasoning', 'id' => 'rs_first', 'summary' => [], 'encrypted_content' => 'canonical_payload_1']],
            ['type' => 'response.output_item.added', 'output_index' => 1, 'item' => ['type' => 'reasoning', 'id' => 'rs_second', 'summary' => []]],
            ['type' => 'response.output_item.done', 'output_index' => 1, 'item' => ['type' => 'reasoning', 'id' => 'rs_second', 'summary' => [], 'encrypted_content' => 'canonical_payload_2']],
        ];
        $chunks = array_map(static fn (array $e): AIMessageChunk => self::message($e), $events);

        $streamed = array_shift($chunks);
        foreach ($chunks as $chunk) {
            $streamed = $streamed->concat($chunk);
        }

        $replay = ResponsesInput::convertMessagesToResponsesInput([$streamed], true, 'o3');

        self::assertWire([['id' => 'rs_second', 'type' => 'reasoning', 'summary' => [], 'encrypted_content' => 'canonical_payload_1canonical_payload_2']], $replay);
    }

    public function testElevatesReasoningToContentOnOutputItemAdded(): void
    {
        $message = self::message([
            'type' => 'response.output_item.added', 'output_index' => 0,
            'item' => ['type' => 'reasoning', 'id' => 'rs_abc123', 'summary' => [
                ['type' => 'summary_text', 'text' => 'Thinking about this...'],
                ['type' => 'summary_text', 'text' => 'Let me reason through.'],
            ]],
        ]);

        self::assertSame('rs_abc123', $message->additional_kwargs['reasoning']['id']);
        self::assertSame('reasoning', $message->additional_kwargs['reasoning']['type']);
        self::assertSame(0, $message->additional_kwargs['reasoning']['summary'][0]['index']);
        self::assertSame(1, $message->additional_kwargs['reasoning']['summary'][1]['index']);
        self::assertSame(
            [['type' => 'reasoning', 'reasoning' => 'Thinking about this...Let me reason through.']],
            self::ofType($message->content, 'reasoning'),
        );
    }

    public function testElevatesReasoningToContentOnSummaryPartAdded(): void
    {
        $message = self::message([
            'type' => 'response.reasoning_summary_part.added', 'item_id' => 'rs_abc123', 'output_index' => 0, 'summary_index' => 0,
            'part' => ['type' => 'summary_text', 'text' => 'Initial reasoning step'],
        ]);

        self::assertSame(
            ['type' => 'reasoning', 'summary' => [['type' => 'summary_text', 'text' => 'Initial reasoning step', 'index' => 0]]],
            $message->additional_kwargs['reasoning'],
        );
        self::assertSame([['type' => 'reasoning', 'reasoning' => 'Initial reasoning step', 'index' => 0]], self::ofType($message->content, 'reasoning'));
    }

    public function testElevatesReasoningToContentOnSummaryTextDelta(): void
    {
        $message = self::message([
            'type' => 'response.reasoning_summary_text.delta', 'item_id' => 'rs_abc123', 'output_index' => 0, 'summary_index' => 0, 'delta' => 'more reasoning text',
        ]);

        self::assertSame(
            ['type' => 'reasoning', 'summary' => [['text' => 'more reasoning text', 'type' => 'summary_text', 'index' => 0]]],
            $message->additional_kwargs['reasoning'],
        );
        self::assertSame([['type' => 'reasoning', 'reasoning' => 'more reasoning text', 'index' => 0]], self::ofType($message->content, 'reasoning'));
    }

    public function testNoReasoningBlockWhenTheSummaryIsEmptyOnOutputItemAdded(): void
    {
        $message = self::message([
            'type' => 'response.output_item.added', 'output_index' => 0,
            'item' => ['type' => 'reasoning', 'id' => 'rs_abc123', 'summary' => []],
        ]);

        self::assertArrayHasKey('reasoning', $message->additional_kwargs);
        self::assertSame([], self::ofType($message->content, 'reasoning'));
    }

    public function testNoReasoningBlockWhenTheDeltaIsEmpty(): void
    {
        $message = self::message([
            'type' => 'response.reasoning_summary_text.delta', 'item_id' => 'rs_abc123', 'output_index' => 0, 'summary_index' => 0, 'delta' => '',
        ]);

        self::assertArrayHasKey('reasoning', $message->additional_kwargs);
        self::assertSame([], self::ofType($message->content, 'reasoning'));
    }

    public function testReasoningSummaryDeltasFoldPerSummaryIndex(): void
    {
        $a = self::message(['type' => 'response.reasoning_summary_text.delta', 'output_index' => 0, 'summary_index' => 0, 'delta' => 'Let me']);
        $b = self::message(['type' => 'response.reasoning_summary_text.delta', 'output_index' => 0, 'summary_index' => 0, 'delta' => ' think']);
        $c = self::message(['type' => 'response.reasoning_summary_text.delta', 'output_index' => 0, 'summary_index' => 1, 'delta' => 'Next']);

        $folded = $a->concat($b)->concat($c);

        self::assertSame(['Let me think', 'Next'], array_column(self::ofType($folded->content, 'reasoning'), 'reasoning'));
    }

    // ---- image generation -------------------------------------------------

    public function testAFinishedImageGenerationItemBecomesAnImageBlock(): void
    {
        $message = self::message([
            'type' => 'response.output_item.done', 'sequence_number' => 1, 'output_index' => 0,
            'item' => ['type' => 'image_generation_call', 'id' => 'ig_stream_123', 'status' => 'completed', 'result' => 'streamedBase64ImageData'],
        ]);

        self::assertSame([[
            'type' => 'image', 'mimeType' => 'image/png', 'data' => 'streamedBase64ImageData', 'id' => 'ig_stream_123', 'metadata' => ['status' => 'completed'],
        ]], $message->content);
        self::assertCount(1, $message->additional_kwargs['tool_outputs']);
    }

    public function testAFinishedImageGenerationItemWithoutAResultHasNoImageBlock(): void
    {
        $message = self::message([
            'type' => 'response.output_item.done', 'sequence_number' => 1, 'output_index' => 0,
            'item' => ['type' => 'image_generation_call', 'id' => 'ig_stream_123', 'status' => 'in_progress', 'result' => null],
        ]);

        self::assertSame([], $message->content);
        self::assertArrayHasKey('tool_outputs', $message->additional_kwargs);
    }

    public function testPartialImagesAreDropped(): void
    {
        self::assertNull(ResponsesOutput::convertResponsesDeltaToChatGenerationChunk([
            'type' => 'response.image_generation_call.partial_image', 'sequence_number' => 1, 'item_id' => 'ig_partial_123',
            'output_index' => 0, 'partial_image_index' => 0, 'partial_image_b64' => 'partialImageData',
        ]));
    }

    public function testUnrecognisedEventsProduceNothing(): void
    {
        self::assertNull(ResponsesOutput::convertResponsesDeltaToChatGenerationChunk(['type' => 'response.in_progress']));
        self::assertNull(ResponsesOutput::convertResponsesDeltaToChatGenerationChunk([]));
    }

    // ---- created / completed ---------------------------------------------

    public function testResponseCreatedCarriesTheIdAndModel(): void
    {
        $chunk = self::chunk(['type' => 'response.created', 'response' => ['id' => 'resp_1', 'model' => 'gpt-4o']]);

        self::assertSame('resp_1', $chunk->message->id);
        self::assertSame(['model_provider' => 'openai', 'id' => 'resp_1', 'model_name' => 'gpt-4o', 'model' => 'gpt-4o'], $chunk->message->response_metadata);
    }

    public function testResponseCompletedCarriesUsageAndTheCleanedOutput(): void
    {
        $message = self::message([
            'type' => 'response.completed',
            'response' => [
                'id' => 'resp_1', 'model' => 'gpt-4o', 'status' => 'completed',
                'output' => [['type' => 'function_call', 'id' => 'fc', 'call_id' => 'c', 'name' => 'n', 'arguments' => '{}', 'parsed_arguments' => []]],
                'usage' => ['input_tokens' => 3, 'output_tokens' => 4, 'total_tokens' => 7],
            ],
        ]);

        self::assertSame(3, $message->response_metadata['usage_metadata']['input_tokens']);
        self::assertArrayNotHasKey('parsed_arguments', $message->response_metadata['output'][0]);
        self::assertSame('completed', $message->response_metadata['status']);
        self::assertSame('resp_1', $message->id);
    }

    // ---- json_schema ------------------------------------------------------

    /**
     * @param list<array<string, mixed>> $output
     *
     * @return array<string, mixed>
     */
    private static function jsonSchemaCompletion(array $output, string $model = 'gpt-4o'): array
    {
        return [
            'type' => 'response.completed',
            'response' => [
                'id' => 'resp_test', 'model' => $model, 'object' => 'response', 'created_at' => 1700000000, 'status' => 'completed',
                'incomplete_details' => null, 'metadata' => [], 'user' => null, 'service_tier' => null,
                'output' => $output,
                'text' => ['format' => [
                    'type' => 'json_schema', 'name' => 'response', 'strict' => true,
                    'schema' => ['type' => 'object', 'additionalProperties' => false, 'required' => ['answer'], 'properties' => ['answer' => ['type' => 'string']]],
                ]],
                'usage' => ['input_tokens' => 50, 'output_tokens' => 20, 'total_tokens' => 70, 'input_tokens_details' => ['cached_tokens' => 0], 'output_tokens_details' => ['reasoning_tokens' => 0]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private static function textOutput(string $text): array
    {
        return ['type' => 'message', 'id' => 'msg_001', 'role' => 'assistant', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => $text, 'annotations' => []]]];
    }

    public function testJsonSchemaWithOnlyAToolCallDoesNotParseAnything(): void
    {
        $message = self::message(self::jsonSchemaCompletion([
            ['type' => 'function_call', 'id' => 'fc_001', 'call_id' => 'call_abc', 'name' => 'lookup', 'arguments' => '{"query":"What is LangChain?"}'],
        ]));

        self::assertArrayNotHasKey('parsed', $message->additional_kwargs);
        self::assertSame(50, $message->response_metadata['usage_metadata']['input_tokens']);
    }

    public function testJsonSchemaTextIsParsedIntoAdditionalKwargs(): void
    {
        $message = self::message(self::jsonSchemaCompletion([self::textOutput('{"answer":"LangChain is a framework"}')]));

        self::assertSame(['answer' => 'LangChain is a framework'], $message->additional_kwargs['parsed']);
    }

    public function testTrailingNonWhitespaceAfterValidJsonDoesNotKillTheStream(): void
    {
        $message = self::message(self::jsonSchemaCompletion([self::textOutput('{"status":"ok","plan":{"steps":[]}}x')], 'gpt-5-mini'));

        self::assertArrayNotHasKey('parsed', $message->additional_kwargs);
        self::assertSame(50, $message->response_metadata['usage_metadata']['input_tokens']);
    }

    public function testWellFormedJsonStillParses(): void
    {
        $message = self::message(self::jsonSchemaCompletion([self::textOutput('{"status":"ok","plan":{"steps":[]}}')], 'gpt-5-mini'));

        self::assertSame(['status' => 'ok', 'plan' => ['steps' => []]], $message->additional_kwargs['parsed']);
    }

    // ---- phase ------------------------------------------------------------

    public function testAMessageItemWithAPhaseOpensAnEmptyPhasedTextBlock(): void
    {
        $chunk = self::chunk([
            'type' => 'response.output_item.added', 'output_index' => 0,
            'item' => ['type' => 'message', 'id' => 'msg_001', 'role' => 'assistant', 'phase' => 'commentary', 'content' => [], 'status' => 'in_progress'],
        ]);

        self::assertSame([['type' => 'text', 'text' => '', 'phase' => 'commentary', 'index' => 0]], $chunk->message->content);
    }

    public function testAMessageItemWithoutAPhaseOpensNothing(): void
    {
        $chunk = self::chunk([
            'type' => 'response.output_item.added', 'output_index' => 0,
            'item' => ['type' => 'message', 'id' => 'msg_001', 'role' => 'assistant', 'content' => [], 'status' => 'in_progress'],
        ]);

        self::assertSame([], $chunk->message->content);
    }
}
