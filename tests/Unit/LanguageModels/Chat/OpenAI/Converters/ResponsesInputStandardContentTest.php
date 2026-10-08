<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Converters;

use LangChain\LanguageModels\Chat\OpenAI\Converters\ResponsesInput;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `describe("convertStandardContentMessageToResponsesInput")` and
 * `describe("... (role-aware text parts)")` from upstream
 * `converters/tests/responses.test.ts`.
 */
#[CoversClass(ResponsesInput::class)]
final class ResponsesInputStandardContentTest extends TestCase
{
    use AssertsWireJson;

    public function testConvertsTextBlocksIntoASingleMessageWithInferredRole(): void
    {
        $message = new HumanMessage(['contentBlocks' => [
            ['type' => 'text', 'text' => 'Hello'],
            ['type' => 'text', 'text' => 'World'],
        ]]);

        self::assertWire([[
            'type' => 'message',
            'role' => 'user',
            'content' => [
                ['type' => 'input_text', 'text' => 'Hello'],
                ['type' => 'input_text', 'text' => 'World'],
            ],
        ]], ResponsesInput::convertStandardContentMessageToResponsesInput($message));
    }

    public function testEmitsReasoningBlocksAsDedicatedReasoningItems(): void
    {
        $message = new AIMessage([
            'contentBlocks' => [['type' => 'reasoning', 'id' => 'reason-1', 'reasoning' => 'Thoughts...']],
            'response_metadata' => ['model_provider' => 'openai'],
        ]);

        $result = ResponsesInput::convertStandardContentMessageToResponsesInput($message);

        self::assertWire([[
            'type' => 'reasoning',
            'id' => 'reason-1',
            'summary' => [['type' => 'summary_text', 'text' => 'Thoughts...']],
        ]], $result);
        // The Responses API rejects a populated `content` on reasoning input items.
        self::assertArrayNotHasKey('content', $result[0]);
    }

    public function testOmitsIdOnReasoningBlocksReassembledFromStreaming(): void
    {
        // `id: ""` makes the Responses API reject the follow-up turn with a 400,
        // so the field must be absent, not defaulted.
        $message = new AIMessage([
            'contentBlocks' => [['type' => 'reasoning', 'reasoning' => 'Thoughts...']],
            'response_metadata' => ['model_provider' => 'openai'],
        ]);

        $result = ResponsesInput::convertStandardContentMessageToResponsesInput($message);

        self::assertWire([[
            'type' => 'reasoning',
            'summary' => [['type' => 'summary_text', 'text' => 'Thoughts...']],
        ]], $result);
        self::assertArrayNotHasKey('id', $result[0]);
        self::assertArrayNotHasKey('content', $result[0]);
    }

    public function testConvertsToolCallBlocksAndAggregatesChunkFallbacks(): void
    {
        $message = new AIMessage(['contentBlocks' => [
            ['type' => 'tool_call_chunk', 'id' => 'call-1', 'name' => 'calculator', 'args' => '{"value":'],
            ['type' => 'tool_call_chunk', 'id' => 'call-1', 'args' => '42}'],
        ]]);

        self::assertWire([[
            'type' => 'function_call',
            'call_id' => 'call-1',
            'name' => 'calculator',
            'arguments' => '{"value":42}',
        ]], ResponsesInput::convertStandardContentMessageToResponsesInput($message));
    }

    public function testConvertsServerToolCallResultsToFunctionOutputs(): void
    {
        $message = new AIMessage(['contentBlocks' => [[
            'type' => 'server_tool_call_result',
            'toolCallId' => 'call-2',
            'status' => 'success',
            'output' => ['foo' => 'bar'],
        ]]]);

        self::assertWire([[
            'type' => 'function_call_output',
            'call_id' => 'call-2',
            'output' => '{"foo":"bar"}',
            'status' => 'completed',
        ]], ResponsesInput::convertStandardContentMessageToResponsesInput($message));
    }

    public function testConvertsCompletedToolCallsUsingDirectBlocks(): void
    {
        $message = new AIMessage(['contentBlocks' => [[
            'type' => 'tool_call', 'id' => 'call-3', 'name' => 'summarize', 'args' => ['topic' => 'news'],
        ]]]);

        self::assertWire([[
            'type' => 'function_call',
            'call_id' => 'call-3',
            'name' => 'summarize',
            'arguments' => '{"topic":"news"}',
        ]], ResponsesInput::convertStandardContentMessageToResponsesInput($message));
    }

    public function testANoArgumentToolCallEncodesItsArgumentsAsAnObject(): void
    {
        $message = new AIMessage(['contentBlocks' => [[
            'type' => 'tool_call', 'id' => 'call-4', 'name' => 'ping', 'args' => [],
        ]]]);

        $result = ResponsesInput::convertStandardContentMessageToResponsesInput($message);

        // `[]` here would present a no-argument tool as one taking a positional list.
        self::assertSame('{}', $result[0]['arguments']);
    }

    public function testEmbedsMultimodalBlocksAlongsideText(): void
    {
        $message = new HumanMessage(['contentBlocks' => [
            ['type' => 'text', 'text' => 'Look at this'],
            ['type' => 'image', 'url' => 'https://example.com/image.png', 'metadata' => ['detail' => 'high']],
            ['type' => 'file', 'fileId' => 'file-123', 'metadata' => ['filename' => 'notes.txt']],
        ]]);

        self::assertWire([[
            'type' => 'message',
            'role' => 'user',
            'content' => [
                ['type' => 'input_text', 'text' => 'Look at this'],
                ['type' => 'input_image', 'detail' => 'high', 'image_url' => 'https://example.com/image.png'],
                ['type' => 'input_file', 'file_id' => 'file-123', 'filename' => 'notes.txt'],
            ],
        ]], ResponsesInput::convertStandardContentMessageToResponsesInput($message));
    }

    public function testPreservesOpenAIOnlyNonStandardContentWhenPresent(): void
    {
        $message = new AIMessage([
            'contentBlocks' => [['type' => 'non_standard', 'value' => ['type' => 'custom', 'payload' => 'data']]],
            'response_metadata' => ['model_provider' => 'openai'],
        ]);

        self::assertWire(
            [['type' => 'custom', 'payload' => 'data']],
            ResponsesInput::convertStandardContentMessageToResponsesInput($message),
        );
    }

    public function testNonStandardContentFromAnotherProviderIsDropped(): void
    {
        $message = new AIMessage([
            'contentBlocks' => [['type' => 'non_standard', 'value' => ['type' => 'custom']]],
            'response_metadata' => ['model_provider' => 'anthropic'],
        ]);

        self::assertSame([], ResponsesInput::convertStandardContentMessageToResponsesInput($message));
    }

    public function testConvertsFilePayloadsWhenFilenameIsProvided(): void
    {
        $message = new HumanMessage(['contentBlocks' => [[
            'type' => 'file',
            'mimeType' => 'application/pdf',
            'data' => 'iVBORw0KGgoAAAANSUhEUgAAAAE',
            'metadata' => ['filename' => 'sample.pdf'],
        ]]]);

        self::assertWire([[
            'role' => 'user',
            'type' => 'message',
            'content' => [[
                'type' => 'input_file',
                'file_data' => 'data:application/pdf;base64,iVBORw0KGgoAAAANSUhEUgAAAAE',
                'filename' => 'sample.pdf',
            ]],
        ]], ResponsesInput::convertStandardContentMessageToResponsesInput($message));
    }

    public function testUsesPlaceholderFilenameWhenFilePayloadDoesNotContainFilename(): void
    {
        $message = new HumanMessage(['contentBlocks' => [[
            'type' => 'file',
            'mimeType' => 'application/pdf',
            'data' => 'iVBORw0KGgoAAAANSUhEUgAAAAE',
        ]]]);

        $result = [];
        $warnings = self::captureWarnings(static function () use ($message, &$result): void {
            $result = ResponsesInput::convertStandardContentMessageToResponsesInput($message);
        });

        self::assertWire([[
            'role' => 'user',
            'type' => 'message',
            'content' => [[
                'type' => 'input_file',
                'file_data' => 'data:application/pdf;base64,iVBORw0KGgoAAAANSUhEUgAAAAE',
                'filename' => 'LC_AUTOGENERATED',
            ]],
        ]], $result);
        self::assertCount(1, $warnings);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function officeFiles(): iterable
    {
        yield 'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'report.docx'];
        yield 'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'slides.pptx'];
        yield 'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'data.xlsx'];
        yield 'csv' => ['text/csv', 'data.csv'];
    }

    #[DataProvider('officeFiles')]
    public function testConvertsFileBlocksWithBase64DataToInputFile(string $mimeType, string $filename): void
    {
        $message = new HumanMessage(['contentBlocks' => [[
            'type' => 'file', 'mimeType' => $mimeType, 'data' => 'dGVzdGRhdGE=', 'metadata' => ['filename' => $filename],
        ]]]);

        self::assertWire([[
            'role' => 'user',
            'type' => 'message',
            'content' => [[
                'type' => 'input_file',
                'file_data' => "data:{$mimeType};base64,dGVzdGRhdGE=",
                'filename' => $filename,
            ]],
        ]], ResponsesInput::convertStandardContentMessageToResponsesInput($message));
    }

    #[DataProvider('officeFiles')]
    public function testConvertsFileBlocksWithAUrlToInputFile(string $mimeType, string $filename): void
    {
        $url = "https://example.com/{$filename}";
        $message = new HumanMessage(['contentBlocks' => [[
            'type' => 'file', 'url' => $url, 'metadata' => ['filename' => $filename],
        ]]]);

        self::assertWire([[
            'role' => 'user',
            'type' => 'message',
            'content' => [['type' => 'input_file', 'file_url' => $url, 'filename' => $filename]],
        ]], ResponsesInput::convertStandardContentMessageToResponsesInput($message));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fileIds(): iterable
    {
        yield 'docx' => ['file-docx-123'];
        yield 'pptx' => ['file-pptx-456'];
        yield 'xlsx' => ['file-xlsx-789'];
        yield 'csv' => ['file-csv-012'];
    }

    #[DataProvider('fileIds')]
    public function testConvertsFileBlocksWithAFileIdToInputFile(string $fileId): void
    {
        $message = new HumanMessage(['contentBlocks' => [['type' => 'file', 'fileId' => $fileId]]]);

        self::assertWire([[
            'role' => 'user',
            'type' => 'message',
            'content' => [['type' => 'input_file', 'file_id' => $fileId]],
        ]], ResponsesInput::convertStandardContentMessageToResponsesInput($message));
    }

    public function testEmitsOutputTextForAssistantMessagesNotInputText(): void
    {
        $items = ResponsesInput::convertStandardContentMessageToResponsesInput(
            new AIMessage(['contentBlocks' => [['type' => 'text', 'text' => 'hi']]]),
        );

        self::assertWire([[
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => 'hi', 'annotations' => []]],
        ]], $items);
    }

    public function testEmitsInputTextForUserMessages(): void
    {
        $items = ResponsesInput::convertStandardContentMessageToResponsesInput(
            new HumanMessage(['contentBlocks' => [['type' => 'text', 'text' => 'hi']]]),
        );

        self::assertWire([[
            'type' => 'message',
            'role' => 'user',
            'content' => [['type' => 'input_text', 'text' => 'hi']],
        ]], $items);
    }

    public function testReasoningSplitsTheMessageIntoOrderedItems(): void
    {
        $message = new AIMessage([
            'contentBlocks' => [
                ['type' => 'text', 'text' => 'before'],
                ['type' => 'reasoning', 'id' => 'rs_1', 'reasoning' => 'think', 'encrypted_content' => 'enc'],
                ['type' => 'text', 'text' => 'after'],
            ],
            'response_metadata' => ['model_provider' => 'openai'],
        ]);

        self::assertWire([
            ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'before', 'annotations' => []]]],
            ['type' => 'reasoning', 'id' => 'rs_1', 'summary' => [['type' => 'summary_text', 'text' => 'think']], 'encrypted_content' => 'enc'],
            ['type' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'after', 'annotations' => []]]],
        ], ResponsesInput::convertStandardContentMessageToResponsesInput($message));
    }
}
