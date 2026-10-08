<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Converters;

use LangChain\LanguageModels\Chat\OpenAI\Converters\Misc;
use LangChain\LanguageModels\Chat\OpenAI\Converters\ResponsesInput;
use LangChain\Messages\AIMessage;
use LangChain\Messages\ChatMessage;
use LangChain\Messages\FunctionMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Utils\Js;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `describe("convertMessagesToResponsesInput")` from upstream
 * `converters/tests/responses.test.ts`, minus the nested blocks that live in
 * their own files, plus the `Anthropic cross-provider compatibility` case.
 */
#[CoversClass(ResponsesInput::class)]
#[CoversClass(Misc::class)]
final class ResponsesInputMessagesTest extends TestCase
{
    use AssertsWireJson;

    /**
     * @param list<\LangChain\Messages\BaseMessage> $messages
     *
     * @return list<array<string, mixed>>
     */
    private static function convert(array $messages, string $model = 'gpt-4o', bool $zdr = false): array
    {
        return ResponsesInput::convertMessagesToResponsesInput($messages, $zdr, $model);
    }

    public function testPreservesPromptCacheBreakpointsOnConvertedContentBlocks(): void
    {
        $message = new HumanMessage(['content' => [
            ['type' => 'text', 'text' => 'Stable prefix', 'extras' => ['prompt_cache_breakpoint' => ['mode' => 'explicit']]],
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/image.png'], 'prompt_cache_breakpoint' => null],
            ['type' => 'file', 'source_type' => 'id', 'id' => 'file_123', 'extras' => ['prompt_cache_breakpoint' => ['mode' => 'explicit']]],
        ]]);

        $result = self::convert([$message], 'gpt-5.6');

        self::assertWire([
            ['type' => 'input_text', 'text' => 'Stable prefix', 'prompt_cache_breakpoint' => ['mode' => 'explicit']],
            // `detail` is undefined upstream, so it is absent; an explicit null breakpoint is a value and is kept.
            ['type' => 'input_image', 'image_url' => 'https://example.com/image.png', 'prompt_cache_breakpoint' => null],
            ['type' => 'input_file', 'file_id' => 'file_123', 'prompt_cache_breakpoint' => ['mode' => 'explicit']],
        ], $result[0]['content']);
        self::assertArrayNotHasKey('detail', $result[0]['content'][1]);
    }

    public function testAppliesPromptCacheBreakpointsToV1StandardInputBlocksOnly(): void
    {
        $breakpoint = ['prompt_cache_breakpoint' => ['mode' => 'explicit']];
        $messages = [
            new HumanMessage([
                'content' => [
                    ['type' => 'text', 'text' => 'Stable prefix', 'extras' => $breakpoint],
                    ['type' => 'image', 'url' => 'https://example.com/image.png', 'extras' => $breakpoint],
                    ['type' => 'file', 'fileId' => 'file_123', 'extras' => $breakpoint],
                ],
                'response_metadata' => ['output_version' => 'v1'],
            ]),
            new AIMessage([
                'content' => [['type' => 'text', 'text' => 'Earlier answer', 'extras' => $breakpoint]],
                'response_metadata' => ['output_version' => 'v1'],
            ]),
        ];

        $result = self::convert($messages, 'gpt-5.6');

        self::assertWire([
            [
                ['type' => 'input_text', 'text' => 'Stable prefix', 'prompt_cache_breakpoint' => ['mode' => 'explicit']],
                ['type' => 'input_image', 'detail' => 'auto', 'image_url' => 'https://example.com/image.png', 'prompt_cache_breakpoint' => ['mode' => 'explicit']],
                ['type' => 'input_file', 'file_id' => 'file_123', 'prompt_cache_breakpoint' => ['mode' => 'explicit']],
            ],
            [['type' => 'output_text', 'text' => 'Earlier answer', 'annotations' => []]],
        ], array_map(static fn (array $item): mixed => $item['content'], $result));
    }

    // ---- Regression Tests -------------------------------------------------

    public function testAllowsFileUrlWithoutFilenameMetadataAndExcludesFilenameFromPayload(): void
    {
        $url = 'https://www.appropedia.org/w/images/c/ca/Writing_Sample.pdf';
        $messages = [
            new SystemMessage(['content' => 'You are a helpful assistant that answers questions about the world.']),
            new HumanMessage(['contentBlocks' => [
                ['type' => 'text', 'text' => 'summary of this document'],
                ['type' => 'file', 'url' => $url, 'mimeType' => 'application/pdf'],
                ['type' => 'text', 'text' => 'The user cannot see this text only you can, they have uploaded a file.'],
            ]]),
        ];

        $result = self::convert($messages, 'gpt-5.2', true);

        self::assertWire([
            'type' => 'message',
            'role' => 'developer',
            'content' => 'You are a helpful assistant that answers questions about the world.',
        ], $result[0]);
        self::assertWire([
            'type' => 'message',
            'role' => 'user',
            'content' => [
                ['type' => 'input_text', 'text' => 'summary of this document'],
                ['type' => 'input_file', 'file_url' => $url],
                ['type' => 'input_text', 'text' => 'The user cannot see this text only you can, they have uploaded a file.'],
            ],
        ], $result[1]);
        self::assertArrayNotHasKey('filename', $result[1]['content'][1]);
    }

    public function testRoutesStandardUrlFileBlocksToNativeInputFileInsteadOfTheCompletionsConverter(): void
    {
        $message = new HumanMessage(['content' => [
            ['type' => 'text', 'text' => 'What is in this document?'],
            [
                'type' => 'file',
                'source_type' => 'url',
                'url' => 'https://example.com/document.pdf',
                'mime_type' => 'application/pdf',
                'metadata' => ['filename' => 'document.pdf'],
            ],
        ]]);

        self::assertWire([[
            'type' => 'message',
            'role' => 'user',
            'content' => [
                ['type' => 'input_text', 'text' => 'What is in this document?'],
                ['type' => 'input_file', 'file_url' => 'https://example.com/document.pdf', 'filename' => 'document.pdf'],
            ],
        ]], self::convert([$message]));
    }

    public function testImageAndAudioDataBlocksKeepTheCompletionsPartShape(): void
    {
        $message = new HumanMessage(['content' => [
            ['type' => 'image', 'source_type' => 'base64', 'mime_type' => 'image/png', 'data' => 'AAA', 'metadata' => ['detail' => 'low']],
            ['type' => 'audio', 'source_type' => 'base64', 'mime_type' => 'audio/wav', 'data' => 'BBB'],
        ]]);

        self::assertWire([[
            'type' => 'message',
            'role' => 'user',
            'content' => [
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,AAA', 'detail' => 'low']],
                ['type' => 'input_audio', 'input_audio' => ['format' => 'wav', 'data' => 'BBB']],
            ],
        ]], self::convert([$message]));
    }

    // ---- ToolMessage conversion -------------------------------------------

    /**
     * @param string|list<array<string, mixed>> $content
     *
     * @return array<string, mixed>
     */
    private static function convertTool(string $callId, string|array $content, string $model = 'gpt-4o'): array
    {
        return self::convert([new ToolMessage(['tool_call_id' => $callId, 'content' => $content])], $model)[0];
    }

    public function testPassesThroughProviderNativeInputFileContentWithoutStringification(): void
    {
        $content = [['type' => 'input_file', 'file_data' => 'data:application/pdf;base64,JVBERi0xLjQKJeLjz9M=', 'filename' => 'test.pdf']];

        self::assertWire(
            ['type' => 'function_call_output', 'call_id' => 'call_123', 'output' => $content],
            self::convertTool('call_123', $content),
        );
    }

    public function testPassesThroughProviderNativeInputImageContentWithoutStringification(): void
    {
        $content = [['type' => 'input_image', 'image_url' => 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUg==']];

        self::assertWire(
            ['type' => 'function_call_output', 'call_id' => 'call_456', 'output' => $content],
            self::convertTool('call_456', $content),
        );
    }

    public function testPassesThroughProviderNativeInputTextContentWithoutStringification(): void
    {
        $content = [['type' => 'input_text', 'text' => 'Some text result']];

        self::assertWire(
            ['type' => 'function_call_output', 'call_id' => 'call_789', 'output' => $content],
            self::convertTool('call_789', $content),
        );
    }

    public function testPassesThroughMixedProviderNativeContentTypesWithoutStringification(): void
    {
        $content = [
            ['type' => 'input_file', 'file_data' => 'data:application/pdf;base64,JVBERi0xLjQ=', 'filename' => 'doc.pdf'],
            ['type' => 'input_text', 'text' => 'File description'],
        ];

        self::assertWire(
            ['type' => 'function_call_output', 'call_id' => 'call_mixed', 'output' => $content],
            self::convertTool('call_mixed', $content),
        );
    }

    public function testStringifiesNonNativeArrayContent(): void
    {
        self::assertWire(
            [
                'type' => 'function_call_output',
                'call_id' => 'call_obj',
                'output' => '[{"type":"text","text":"Result from tool"}]',
            ],
            self::convertTool('call_obj', [['type' => 'text', 'text' => 'Result from tool']]),
        );
    }

    public function testKeepsStringContentAsIs(): void
    {
        self::assertWire(
            ['type' => 'function_call_output', 'call_id' => 'call_str', 'output' => 'Simple string result'],
            self::convertTool('call_str', 'Simple string result'),
        );
    }

    public function testToolMessageIdIsForwardedOnlyWhenItIsAFunctionCallItemId(): void
    {
        $withFc = new ToolMessage(['tool_call_id' => 'c', 'content' => 'x', 'id' => 'fc_9']);
        $withOther = new ToolMessage(['tool_call_id' => 'c', 'content' => 'x', 'id' => 'run-9']);

        $result = self::convert([$withFc, $withOther]);

        self::assertSame('fc_9', $result[0]['id']);
        self::assertArrayNotHasKey('id', $result[1]);
    }

    public function testConvertsAV1ImageIntoNativeInputImageOutput(): void
    {
        self::assertWire(
            [
                'type' => 'function_call_output',
                'call_id' => 'call_img',
                'output' => [['type' => 'input_image', 'detail' => 'auto', 'image_url' => 'data:image/png;base64,AAA']],
            ],
            self::convertTool('call_img', [['type' => 'image', 'mimeType' => 'image/png', 'data' => 'AAA']], 'gpt-5.5'),
        );
    }

    public function testConvertsASourceTypeImageWithText(): void
    {
        $result = self::convertTool('call_img', [
            ['type' => 'text', 'text' => 'Read /a.png'],
            ['type' => 'image', 'source_type' => 'base64', 'mime_type' => 'image/png', 'data' => 'AAA'],
        ], 'gpt-5.5');

        self::assertWire([
            ['type' => 'input_text', 'text' => 'Read /a.png'],
            ['type' => 'input_image', 'detail' => 'auto', 'image_url' => 'data:image/png;base64,AAA'],
        ], $result['output']);
    }

    public function testKeepsFileOnlyToolContentUnchanged(): void
    {
        $content = [['type' => 'file', 'mimeType' => 'application/zip', 'data' => 'AAA']];

        $result = self::convertTool('call_file', $content, 'gpt-5.5');

        self::assertSame('function_call_output', $result['type']);
        self::assertSame(Js::encode($content), $result['output']);
    }

    public function testKeepsAnImageWithoutASourceAsJsonText(): void
    {
        $empty = ['type' => 'image', 'mimeType' => 'image/png'];

        $result = self::convertTool('call_img', [
            ['type' => 'image', 'mimeType' => 'image/png', 'data' => 'AAA'],
            $empty,
        ], 'gpt-5.5');

        self::assertWire([
            ['type' => 'input_image', 'detail' => 'auto', 'image_url' => 'data:image/png;base64,AAA'],
            ['type' => 'input_text', 'text' => Js::encode($empty)],
        ], $result['output']);
    }

    public function testKeepsNonImageBlocksAsJsonTextNextToImages(): void
    {
        $file = ['type' => 'file', 'mimeType' => 'application/zip', 'data' => 'BBB'];

        $result = self::convertTool('call_img', [
            ['type' => 'image', 'mimeType' => 'image/png', 'data' => 'AAA'],
            $file,
        ], 'gpt-5.5');

        self::assertWire([
            ['type' => 'input_image', 'detail' => 'auto', 'image_url' => 'data:image/png;base64,AAA'],
            ['type' => 'input_text', 'text' => Js::encode($file)],
        ], $result['output']);
    }

    public function testConvertsAComputerCallOutputFromAnImageUrlBlock(): void
    {
        $message = new ToolMessage([
            'tool_call_id' => 'call_c',
            'content' => [['type' => 'image_url', 'image_url' => ['url' => 'data:image/png;base64,ZZ']]],
            'additional_kwargs' => ['type' => 'computer_call_output'],
        ]);

        self::assertWire(
            [[
                'type' => 'computer_call_output',
                'output' => ['type' => 'input_image', 'image_url' => 'data:image/png;base64,ZZ'],
                'call_id' => 'call_c',
            ]],
            self::convert([$message]),
        );
    }

    public function testRejectsAComputerCallOutputWithNoImage(): void
    {
        $message = new ToolMessage([
            'tool_call_id' => 'call_c',
            'content' => [['type' => 'text', 'text' => 'nope']],
            'additional_kwargs' => ['type' => 'computer_call_output'],
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid computer call output');

        self::convert([$message]);
    }

    public function testConvertsACustomToolOutput(): void
    {
        $message = new ToolMessage([
            'tool_call_id' => 'call_x',
            'content' => 'patched',
            'additional_kwargs' => ['customTool' => true],
        ]);

        self::assertWire(
            [['type' => 'custom_tool_call_output', 'call_id' => 'call_x', 'output' => 'patched']],
            self::convert([$message]),
        );
    }

    // ---- roles ------------------------------------------------------------

    public function testSystemBecomesDeveloperOnlyForReasoningModels(): void
    {
        $system = new SystemMessage('be brief');

        self::assertSame('developer', self::convert([$system], 'o3-mini')[0]['role']);
        self::assertSame('developer', self::convert([$system], 'gpt-5.2')[0]['role']);
        self::assertSame('system', self::convert([$system], 'gpt-4o')[0]['role']);
        self::assertSame('system', self::convert([$system], 'gpt-5-chat-latest')[0]['role']);
    }

    public function testFunctionMessagesAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Function messages are not supported in Responses API');

        self::convert([new FunctionMessage(['name' => 'f', 'content' => 'x'])]);
    }

    public function testAnUnsupportedRoleIsWarnedAboutAndSkipped(): void
    {
        $result = [];
        $warnings = self::captureWarnings(static function () use (&$result): void {
            $result = ResponsesInput::convertMessagesToResponsesInput(
                [new ChatMessage(['role' => 'narrator', 'content' => 'once upon a time'])],
                false,
                'gpt-4o',
            );
        });

        self::assertSame([], $result);
        self::assertCount(2, $warnings, 'one for the unknown role, one for the unsupported conversion');
    }

    public function testMcpApprovalResponsesAreHoistedBeforeTheMessage(): void
    {
        $message = new HumanMessage(['content' => [
            ['type' => 'mcp_approval_response', 'approval_request_id' => 'mcpr_1', 'approve' => true],
            ['type' => 'text', 'text' => 'go'],
        ]]);

        self::assertWire([
            ['type' => 'mcp_approval_response', 'approval_request_id' => 'mcpr_1', 'approve' => true],
            ['type' => 'message', 'role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'go']]],
        ], self::convert([$message]));
    }

    // ---- Anthropic cross-provider compatibility ---------------------------

    public function testDropsToolUseBlocksFromAssistantContent(): void
    {
        $message = new AIMessage([
            'content' => [
                ['type' => 'text', 'text' => 'I will search for that.'],
                ['type' => 'tool_use', 'id' => 'toolu_abc123', 'name' => 'get_weather', 'input' => ['location' => 'SF']],
            ],
            'tool_calls' => [['id' => 'toolu_abc123', 'name' => 'get_weather', 'args' => ['location' => 'SF']]],
        ]);

        $result = self::convert([$message]);

        self::assertWire([
            'type' => 'message',
            'role' => 'assistant',
            'content' => [['type' => 'output_text', 'text' => 'I will search for that.', 'annotations' => []]],
        ], $result[0]);
        self::assertWire([
            'type' => 'function_call',
            'name' => 'get_weather',
            'arguments' => '{"location":"SF"}',
            'call_id' => 'toolu_abc123',
        ], $result[1]);
    }
}
