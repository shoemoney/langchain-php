<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Anthropic;

use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\Anthropic\Utils\MessageInputs;
use LangChain\LanguageModels\Chat\Anthropic\Utils\Standard;
use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `tests/standard_content.test.ts` (`_formatStandardContent`) plus the
 * opt-in `outputVersion: 'v1'` wiring.
 */
#[CoversClass(Standard::class)]
#[CoversClass(MessageInputs::class)]
#[CoversClass(ChatAnthropic::class)]
final class AnthropicStandardContentTest extends TestCase
{
    /**
     * @param list<array<string, mixed>> $blocks
     */
    private static function message(array $blocks, string $provider = 'anthropic'): BaseMessage
    {
        return new AIMessage([
            'content' => $blocks,
            'response_metadata' => ['model_provider' => $provider],
        ]);
    }

    /**
     * @param array<string, mixed> $block
     *
     * @return array<string, mixed>
     */
    private static function one(array $block, string $provider = 'anthropic'): array
    {
        $formatted = Standard::formatStandardContent(self::message([$block], $provider));
        self::assertCount(1, $formatted);

        return $formatted[0];
    }

    // ---- ported: standard_content.test.ts --------------------------------

    public function testConvertsFileBlocksBackedByFileIdsIntoAnthropicDocuments(): void
    {
        $content = self::one([
            'type' => 'file',
            'fileId' => 'file-123',
            'metadata' => [
                'cache_control' => ['type' => 'ephemeral', 'ttl' => '5m'],
                'citations' => ['enabled' => true],
                'context' => 'source context',
                'title' => 'My Document',
            ],
        ]);

        self::assertSame([
            'type' => 'document',
            'source' => ['type' => 'file', 'file_id' => 'file-123'],
            'cache_control' => ['type' => 'ephemeral', 'ttl' => '5m'],
            'citations' => ['enabled' => true],
            'context' => 'source context',
            'title' => 'My Document',
        ], $content);
    }

    public function testConvertsInlinedTextFilesIntoTextDocumentSources(): void
    {
        $content = self::one(['type' => 'file', 'data' => 'Plain text body', 'mimeType' => 'text/plain']);

        self::assertSame([
            'type' => 'document',
            'source' => ['type' => 'text', 'data' => 'Plain text body', 'media_type' => 'text/plain'],
        ], $content);
    }

    public function testWrapsBase64ImageFilePayloadsInDocumentContentBlocks(): void
    {
        $content = self::one(['type' => 'file', 'data' => [1, 2, 3], 'mimeType' => 'image/png']);

        self::assertSame([
            'type' => 'document',
            'source' => [
                'type' => 'content',
                'content' => [[
                    'type' => 'image',
                    'source' => ['type' => 'base64', 'data' => base64_encode("\x01\x02\x03"), 'media_type' => 'image/png'],
                ]],
            ],
        ], $content);
    }

    public function testConvertsStandardImageBlocksWithMetadata(): void
    {
        $content = self::one([
            'type' => 'image',
            'url' => 'https://example.com/image.png',
            'metadata' => ['cache_control' => ['type' => 'ephemeral', 'ttl' => '1h']],
        ]);

        self::assertSame([
            'type' => 'image',
            'source' => ['type' => 'url', 'url' => 'https://example.com/image.png'],
            'cache_control' => ['type' => 'ephemeral', 'ttl' => '1h'],
        ], $content);
    }

    public function testPromotesPlainTextBlocksToAnthropicTextDocuments(): void
    {
        $message = self::message([[
            'type' => 'text-plain',
            'text' => 'Inline document',
            'data' => 'Inline document',
            'mimeType' => 'text/plain',
        ]]);
        self::assertSame('text-plain', $message->contentBlocks()[0]['type']);

        $formatted = Standard::formatStandardContent($message);

        self::assertCount(1, $formatted);
        self::assertSame([
            'type' => 'document',
            'source' => ['type' => 'text', 'data' => 'Inline document', 'media_type' => 'text/plain'],
        ], $formatted[0]);
    }

    public function testThrowsForUnsupportedAudioBlocks(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/does not support audio/i');

        Standard::formatStandardContent(self::message([['type' => 'audio', 'fileId' => 'audio-1']]));
    }

    // ---- the rest of formatStandardContent --------------------------------

    public function testTextBlockWithoutAnnotationsHasNoCitationsKey(): void
    {
        self::assertSame(['type' => 'text', 'text' => 'hi'], self::one(['type' => 'text', 'text' => 'hi']));
    }

    public function testAnnotationsBecomeAnthropicCitationsOfEachKind(): void
    {
        $content = self::one([
            'type' => 'text',
            'text' => 'cited',
            'annotations' => [
                ['type' => 'citation', 'source' => 'char', 'url' => 'f1', 'startIndex' => 1, 'endIndex' => 5, 'title' => 'T', 'citedText' => 'abc'],
                ['type' => 'citation', 'source' => 'page', 'startIndex' => 2, 'endIndex' => 3],
                ['type' => 'citation', 'source' => 'block', 'startIndex' => 4, 'endIndex' => 6],
                ['type' => 'citation', 'source' => 'url', 'url' => 'https://x.test', 'startIndex' => 9, 'title' => 'W'],
                ['type' => 'citation', 'source' => 'search', 'startIndex' => 7, 'endIndex' => 8],
                ['type' => 'not-a-citation'],
                ['type' => 'citation', 'source' => 'mystery'],
            ],
        ]);

        $citations = $content['citations'];
        self::assertSame(
            ['char_location', 'page_location', 'content_block_location', 'web_search_result_location', 'search_result_location'],
            array_column($citations, 'type'),
        );
        self::assertSame(['file_id' => 'f1', 'start_char_index' => 1, 'end_char_index' => 5, 'document_title' => 'T', 'cited_text' => 'abc'], array_intersect_key($citations[0], array_flip(['file_id', 'start_char_index', 'end_char_index', 'document_title', 'cited_text'])));
        self::assertSame(2, $citations[1]['start_page_number']);
        self::assertSame(6, $citations[2]['end_block_index']);
        self::assertSame('9', $citations[3]['encrypted_index']);
        self::assertSame('https://x.test', $citations[3]['url']);
        self::assertSame('search', $citations[4]['source']);
    }

    public function testToolCallBecomesToolUse(): void
    {
        self::assertEquals(
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'lookup', 'input' => ['q' => 'x']],
            self::one(['type' => 'tool_call', 'id' => 'toolu_1', 'name' => 'lookup', 'args' => ['q' => 'x']]),
        );
    }

    public function testEmptyToolCallArgsEncodeAsAnObjectNotAList(): void
    {
        $content = self::one(['type' => 'tool_call', 'id' => 't', 'name' => 'ping', 'args' => []]);

        self::assertSame('{"type":"tool_use","id":"t","name":"ping","input":{}}', json_encode($content));
    }

    public function testToolCallChunkParsesStreamedJsonAndToleratesGarbage(): void
    {
        $ok = self::one(['type' => 'tool_call_chunk', 'id' => 't', 'name' => 'n', 'args' => '{"a":1}']);
        $bad = self::one(['type' => 'tool_call_chunk', 'id' => 't', 'name' => 'n', 'args' => '{"a":']);

        self::assertSame(['a' => 1], $ok['input']);
        self::assertSame('{}', json_encode($bad['input']));
    }

    public function testReasoningIsOnlySentBackToAnthropicThatProducedIt(): void
    {
        $block = ['type' => 'reasoning', 'reasoning' => 'hmm', 'signature' => 'sig'];

        self::assertSame(['type' => 'thinking', 'thinking' => 'hmm', 'signature' => 'sig'], self::one($block));
        self::assertSame([], Standard::formatStandardContent(self::message([$block], 'openai')));
    }

    public function testServerToolCallsAndResultsRoundTripToTheirWireShapes(): void
    {
        $formatted = Standard::formatStandardContent(self::message([
            ['type' => 'server_tool_call', 'id' => 's1', 'name' => 'web_search', 'args' => ['query' => 'q']],
            ['type' => 'server_tool_call', 'id' => 's2', 'name' => 'unknown_tool', 'args' => []],
            ['type' => 'server_tool_call_result', 'toolCallId' => 's1', 'name' => 'web_search', 'output' => ['urls' => ['https://a', 'https://b']]],
            ['type' => 'server_tool_call_result', 'toolCallId' => 's3', 'name' => 'code_execution', 'output' => ['stdout' => 'ok']],
            ['type' => 'server_tool_call_result', 'toolCallId' => 's4', 'name' => 'mcp_tool_result', 'output' => [['type' => 'text', 'text' => 'r']]],
        ]));

        self::assertSame(['server_tool_use', 'web_search_tool_result', 'code_execution_tool_result', 'mcp_tool_result'], array_column($formatted, 'type'));
        self::assertSame('web_search', $formatted[0]['name']);
        self::assertSame('s1', $formatted[1]['tool_use_id']);
        self::assertSame('https://b', $formatted[1]['content'][1]['url']);
        self::assertSame('', $formatted[1]['content'][0]['encrypted_content']);
        self::assertSame(['stdout' => 'ok'], $formatted[2]['content']);
    }

    public function testNonStandardValueIsPassedThroughForAnthropicOnly(): void
    {
        $value = ['type' => 'redacted_thinking', 'data' => 'xyz'];

        self::assertSame($value, self::one(['type' => 'non_standard', 'value' => $value]));
        self::assertSame([], Standard::formatStandardContent(self::message([['type' => 'non_standard', 'value' => $value]], 'openai')));
    }

    public function testFileUrlAndPdfDataSources(): void
    {
        self::assertSame(
            ['type' => 'document', 'source' => ['type' => 'url', 'url' => 'https://x.test/a.pdf']],
            self::one(['type' => 'file', 'url' => 'https://x.test/a.pdf', 'mimeType' => 'application/pdf; charset=binary']),
        );
        self::assertSame(
            ['type' => 'document', 'source' => ['type' => 'base64', 'data' => 'QUJD', 'media_type' => 'application/pdf']],
            self::one(['type' => 'file', 'data' => 'QUJD']),
        );
    }

    public function testUnsupportedBase64FileMimeTypeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported file mime type for Anthropic base64 source: application/zip');

        Standard::formatStandardContent(self::message([['type' => 'file', 'data' => 'QUJD', 'mimeType' => 'application/zip']]));
    }

    public function testFileWithoutAnySourceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('File content block must include a fileId, url, or data property.');

        Standard::formatStandardContent(self::message([['type' => 'file']]));
    }

    public function testImageSources(): void
    {
        self::assertSame(
            ['type' => 'image', 'source' => ['type' => 'file', 'file_id' => 'img-1']],
            self::one(['type' => 'image', 'fileId' => 'img-1']),
        );
        // No mime type defaults to PNG.
        self::assertSame(
            ['type' => 'image', 'source' => ['type' => 'base64', 'data' => 'QUJD', 'media_type' => 'image/png']],
            self::one(['type' => 'image', 'data' => 'QUJD']),
        );
        // An unsupported image mime type is dropped silently, as upstream.
        self::assertSame([], Standard::formatStandardContent(self::message([['type' => 'image', 'data' => 'QUJD', 'mimeType' => 'image/bmp']])));
    }

    public function testImageWithoutAnySourceThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Image content block must include a fileId, url, or data property.');

        Standard::formatStandardContent(self::message([['type' => 'image']]));
    }

    public function testVideoBlocksAreDropped(): void
    {
        self::assertSame([], Standard::formatStandardContent(self::message([['type' => 'video', 'url' => 'https://x.test/v.mp4']])));
    }

    // ---- the inverse, used for outputVersion v1 ---------------------------

    public function testToStandardContentTranslatesAnthropicBlocks(): void
    {
        $blocks = Standard::toStandardContent([
            ['type' => 'thinking', 'thinking' => 'hmm', 'signature' => 'sig'],
            ['type' => 'text', 'text' => 'answer', 'citations' => [
                ['type' => 'char_location', 'file_id' => 'f', 'start_char_index' => 1, 'end_char_index' => 2, 'document_title' => 'D', 'cited_text' => 'c'],
            ]],
            ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'lookup', 'input' => ['q' => 1]],
            ['type' => 'web_search_tool_result', 'tool_use_id' => 's1', 'content' => [['url' => 'https://a']]],
            ['type' => 'weird'],
        ]);

        self::assertSame(['reasoning', 'text', 'tool_call', 'server_tool_call_result', 'non_standard'], array_column($blocks, 'type'));
        self::assertSame('sig', $blocks[0]['signature']);
        self::assertSame(
            ['type' => 'citation', 'source' => 'char', 'url' => 'f', 'title' => 'D', 'startIndex' => 1, 'endIndex' => 2, 'citedText' => 'c'],
            $blocks[1]['annotations'][0],
        );
        self::assertSame(['urls' => ['https://a']], $blocks[3]['output']);
    }

    public function testToStandardContentTurnsAStringAndAppendsStructuredToolCalls(): void
    {
        $blocks = Standard::toStandardContent('hello', [['id' => 'c1', 'name' => 'n', 'args' => ['a' => 1]]]);

        self::assertSame(
            [['type' => 'text', 'text' => 'hello'], ['type' => 'tool_call', 'id' => 'c1', 'name' => 'n', 'args' => ['a' => 1]]],
            $blocks,
        );
        self::assertSame([], Standard::toStandardContent(''));
    }

    // ---- opt-in wiring ----------------------------------------------------

    public function testV1MessageTakesTheStandardPathInMessageInputs(): void
    {
        $message = new AIMessage([
            'content' => [['type' => 'tool_call', 'id' => 'toolu_1', 'name' => 'lookup', 'args' => ['q' => 'x']]],
            'tool_calls' => [['id' => 'toolu_1', 'name' => 'lookup', 'args' => ['q' => 'x'], 'type' => 'tool_call']],
            'response_metadata' => ['output_version' => 'v1', 'model_provider' => 'anthropic'],
        ]);

        $wire = MessageInputs::convertMessage($message);

        self::assertSame('assistant', $wire['role']);
        self::assertEquals([['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'lookup', 'input' => ['q' => 'x']]], $wire['content']);
    }

    public function testMessageWithoutTheV1MarkerKeepsTheDefaultWireShape(): void
    {
        $message = new AIMessage([
            'content' => 'calling',
            'tool_calls' => [['id' => 'toolu_1', 'name' => 'lookup', 'args' => ['q' => 'x'], 'type' => 'tool_call']],
        ]);

        $wire = MessageInputs::convertMessage($message);

        self::assertSame('text', $wire['content'][0]['type']);
        self::assertSame('tool_use', $wire['content'][1]['type']);
    }

    // ---- end to end -------------------------------------------------------

    private static function modelWith(array $responses, array $fields = []): ChatAnthropic
    {
        return new ChatAnthropic($fields + [
            'apiKey' => 'sk-ant-test',
            'maxRetries' => 0,
            'httpClient' => new FakeHttpClient($responses),
        ]);
    }

    public function testDefaultOutputIsUnchangedWithoutOutputVersion(): void
    {
        $model = self::modelWith([FakeHttpClient::json(200, [
            'id' => 'msg_1', 'model' => 'claude-sonnet-4-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'plain']],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);

        $message = $model->invoke('hi');

        self::assertSame('plain', $message->content);
        self::assertArrayNotHasKey('output_version', $message->response_metadata);
    }

    public function testOutputVersionV1ProducesStandardBlocksThatRoundTripOnTheWire(): void
    {
        $thinkingAndTool = [
            'id' => 'msg_1', 'model' => 'claude-sonnet-4-5', 'stop_reason' => 'tool_use',
            'content' => [
                ['type' => 'thinking', 'thinking' => 'need a lookup', 'signature' => 'sig_1'],
                ['type' => 'text', 'text' => 'Looking up.'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'lookup', 'input' => ['q' => 'x']],
            ],
            'usage' => ['input_tokens' => 5, 'output_tokens' => 7],
        ];
        $final = [
            'id' => 'msg_2', 'model' => 'claude-sonnet-4-5', 'stop_reason' => 'end_turn',
            'content' => [['type' => 'text', 'text' => 'Done.']],
            'usage' => ['input_tokens' => 9, 'output_tokens' => 2],
        ];
        $model = self::modelWith(
            [FakeHttpClient::json(200, $thinkingAndTool), FakeHttpClient::json(200, $final)],
            ['outputVersion' => 'v1'],
        );

        $first = $model->invoke('go');

        self::assertSame('v1', $first->response_metadata['output_version']);
        self::assertSame('anthropic', $first->response_metadata['model_provider']);
        self::assertSame(['reasoning', 'text', 'tool_call'], array_column($first->content, 'type'));
        self::assertCount(1, $first->toolCalls);

        $second = $model->invoke([new HumanMessage('go'), $first]);
        self::assertSame('Done.', $second->content[0]['text']);

        $assistant = $model->httpClient->lastRequestBody()['messages'][1];
        self::assertSame('assistant', $assistant['role']);
        self::assertSame(['thinking', 'text', 'tool_use'], array_column($assistant['content'], 'type'));
        self::assertSame('sig_1', $assistant['content'][0]['signature']);
        self::assertSame(['q' => 'x'], $assistant['content'][2]['input']);
    }
}
