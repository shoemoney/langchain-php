<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Converters;

use LangChain\LanguageModels\Chat\OpenAI\Converters\ResponsesInput;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `assistant reasoning conversion`, `v1 content-block replay`,
 * `configuration_update block support`, `tool_search support` (input half) and
 * `phase parameter support` (input half) from upstream
 * `converters/tests/responses.test.ts`.
 *
 * Upstream builds some of these messages with `convertResponsesMessageToAIMessage`,
 * which is the output side and not part of this unit. Where it did, the
 * message is built by hand with the same `response_metadata.output`.
 */
#[CoversClass(ResponsesInput::class)]
final class ResponsesInputAssistantTest extends TestCase
{
    use AssertsWireJson;

    /**
     * @param list<\LangChain\Messages\BaseMessage> $messages
     *
     * @return list<array<string, mixed>>
     */
    private static function convert(array $messages, string $model, bool $zdr = false): array
    {
        return ResponsesInput::convertMessagesToResponsesInput($messages, $zdr, $model);
    }

    // ---- assistant reasoning conversion -----------------------------------

    public function testIncludesReasoningItemsInZdrModeWhenEncryptedContentIsPresent(): void
    {
        $message = new AIMessage([
            'content' => [],
            'additional_kwargs' => ['reasoning' => [
                'id' => 'reasoning_123',
                'type' => 'reasoning',
                'summary' => [['type' => 'summary_text', 'text' => 'Encrypted summary']],
                'encrypted_content' => 'encrypted_payload',
            ]],
        ]);

        self::assertWire([[
            'id' => 'reasoning_123',
            'type' => 'reasoning',
            'summary' => [['type' => 'summary_text', 'text' => 'Encrypted summary']],
            'encrypted_content' => 'encrypted_payload',
        ]], self::convert([$message], 'gpt-4o', true));
    }

    public function testSkipsReasoningWithoutEncryptedContentInZdrModeButSendsItOtherwise(): void
    {
        $message = new AIMessage([
            'content' => [],
            'additional_kwargs' => ['reasoning' => [
                'id' => 'rs_1', 'type' => 'reasoning', 'summary' => [['type' => 'summary_text', 'text' => 'x']],
            ]],
        ]);

        self::assertSame([], self::convert([$message], 'gpt-4o', true));
        self::assertCount(1, self::convert([$message], 'gpt-4o', false));
    }

    public function testFoldsStreamedSummaryPartsThatShareAnIndexAndDropsTheIndex(): void
    {
        $message = new AIMessage([
            'content' => [],
            'additional_kwargs' => ['reasoning' => [
                'id' => 'rs_1',
                'type' => 'reasoning',
                'summary' => [
                    ['type' => 'summary_text', 'text' => 'a', 'index' => 0],
                    ['type' => 'summary_text', 'text' => 'b', 'index' => 0],
                    ['type' => 'summary_text', 'text' => 'c', 'index' => 1],
                ],
            ]],
        ]);

        self::assertWire([[
            'id' => 'rs_1',
            'type' => 'reasoning',
            'summary' => [
                ['type' => 'summary_text', 'text' => 'ab'],
                ['type' => 'summary_text', 'text' => 'c'],
            ],
        ]], self::convert([$message], 'o3-mini'));
    }

    public function testUsesFastPathWhenResponseMetadataOutputIsAvailable(): void
    {
        $output = [
            ['type' => 'reasoning', 'id' => 'rs_abc123', 'summary' => [['type' => 'summary_text', 'text' => 'Thinking...']]],
            ['type' => 'function_call', 'id' => 'fc_xyz789', 'call_id' => 'call_123', 'name' => 'get_weather', 'arguments' => '{"city":"NYC"}'],
        ];
        $message = new AIMessage([
            'content' => [],
            'tool_calls' => [['name' => 'get_weather', 'args' => ['city' => 'NYC'], 'id' => 'call_123']],
            'response_metadata' => ['output' => $output],
        ]);

        // The stored output is returned verbatim.
        self::assertWire($output, self::convert([$message], 'o3-mini'));
    }

    public function testPreservesMultipleOrderedReasoningItemsFromResponseMetadataOutputInZdrMode(): void
    {
        $output = [
            ['type' => 'reasoning', 'id' => 'rs_first', 'summary' => [['type' => 'summary_text', 'text' => 'First']], 'encrypted_content' => 'encrypted_first', 'created_by' => 'provider'],
            ['type' => 'function_call', 'id' => 'fc_first', 'call_id' => 'call_first', 'name' => 'add', 'arguments' => '{"a":1,"b":2}', 'created_by' => 'provider'],
            ['type' => 'reasoning', 'id' => 'rs_second', 'summary' => [['type' => 'summary_text', 'text' => 'Second']], 'encrypted_content' => 'encrypted_second', 'created_by' => 'provider'],
            ['type' => 'function_call', 'id' => 'fc_second', 'call_id' => 'call_second', 'name' => 'multiply', 'arguments' => '{"a":3,"b":4}', 'created_by' => 'provider'],
        ];
        $message = new AIMessage([
            'content' => [],
            'tool_calls' => [
                ['name' => 'add', 'args' => ['a' => 1, 'b' => 2], 'id' => 'call_first'],
                ['name' => 'multiply', 'args' => ['a' => 3, 'b' => 4], 'id' => 'call_second'],
            ],
            // The legacy field can retain only one reasoning item; the original output must win.
            'additional_kwargs' => ['reasoning' => $output[2]],
            'response_metadata' => ['output' => $output],
        ]);

        self::assertWire(
            array_map(static function (array $item): array {
                unset($item['created_by']);

                return $item;
            }, $output),
            self::convert([$message], 'o3-mini', true),
        );
    }

    public function testZdrReplayStripsOutputOnlyFieldsButNonZdrReplayDoesNot(): void
    {
        $output = [[
            'type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'c1', 'name' => 'f',
            'arguments' => '{}', 'parsed_arguments' => ['x' => 1], 'created_by' => 'p',
        ]];
        $message = new AIMessage(['content' => [], 'response_metadata' => ['output' => $output]]);

        self::assertSame($output, self::convert([$message], 'gpt-5.6', false));
        self::assertArrayNotHasKey('parsed_arguments', self::convert([$message], 'gpt-5.6', true)[0]);
    }

    public function testReconstructsReasoningToolCallsAndTextWhenThereIsNoStoredOutput(): void
    {
        $message = new AIMessage([
            'id' => 'msg_77',
            'content' => 'Checking.',
            'tool_calls' => [['id' => 'call_1', 'name' => 'lookup', 'args' => ['q' => 'x']]],
            'additional_kwargs' => [
                'reasoning' => ['id' => 'rs_1', 'type' => 'reasoning', 'summary' => [['type' => 'summary_text', 'text' => 't']]],
                ResponsesInput::FUNCTION_CALL_IDS_MAP_KEY => ['call_1' => 'fc_1'],
            ],
        ]);

        self::assertWire([
            ['id' => 'rs_1', 'type' => 'reasoning', 'summary' => [['type' => 'summary_text', 'text' => 't']]],
            ['type' => 'message', 'role' => 'assistant', 'id' => 'msg_77', 'content' => 'Checking.'],
            ['type' => 'function_call', 'name' => 'lookup', 'arguments' => '{"q":"x"}', 'call_id' => 'call_1', 'id' => 'fc_1'],
        ], self::convert([$message], 'o3-mini'));

        // ZDR: no stored ids, and the unencrypted reasoning item is withheld.
        self::assertWire([
            ['type' => 'message', 'role' => 'assistant', 'content' => 'Checking.'],
            ['type' => 'function_call', 'name' => 'lookup', 'arguments' => '{"q":"x"}', 'call_id' => 'call_1'],
        ], self::convert([$message], 'o3-mini', true));
    }

    public function testANoArgumentToolCallIsSentWithObjectArguments(): void
    {
        $message = new AIMessage(['content' => '', 'tool_calls' => [['id' => 'call_9', 'name' => 'ping', 'args' => []]]]);

        $result = self::convert([$message], 'gpt-4o');

        // `[]` would present a no-argument tool as taking a positional list.
        self::assertSame('{}', $result[1]['arguments']);
    }

    public function testRefusalIsAppendedAsARefusalPart(): void
    {
        $message = new AIMessage(['content' => 'No.', 'additional_kwargs' => ['refusal' => 'cannot help']]);

        self::assertWire([[
            'type' => 'message',
            'role' => 'assistant',
            'content' => [
                ['type' => 'output_text', 'text' => 'No.', 'annotations' => []],
                ['type' => 'refusal', 'refusal' => 'cannot help'],
            ],
        ]], self::convert([$message], 'gpt-4o'));
    }

    public function testConvertsLangChainCitationsBackToOpenAIAnnotations(): void
    {
        $message = new AIMessage(['content' => [[
            'type' => 'text',
            'text' => 'see',
            'annotations' => [
                ['type' => 'citation', 'source' => 'url_citation', 'url' => 'https://a.test', 'title' => 'A', 'startIndex' => 1, 'endIndex' => 3],
                ['type' => 'citation', 'source' => 'file_citation', 'title' => 'f.txt', 'startIndex' => 4, 'file_id' => 'file_1'],
                ['type' => 'file_path', 'file_id' => 'file_2', 'index' => 9],
                ['type' => 'non_standard', 'value' => ['type' => 'future_citation', 'x' => 1]],
            ],
        ]]]);

        $result = self::convert([$message], 'gpt-4o');

        self::assertWire([
            ['type' => 'url_citation', 'url' => 'https://a.test', 'title' => 'A', 'start_index' => 1, 'end_index' => 3],
            ['type' => 'file_citation', 'file_id' => 'file_1', 'filename' => 'f.txt', 'index' => 4],
            ['type' => 'file_path', 'file_id' => 'file_2', 'index' => 9],
            ['type' => 'future_citation', 'x' => 1],
        ], $result[0]['content'][0]['annotations']);
    }

    public function testBuiltInToolCallsAreCarriedForwardFromToolOutputs(): void
    {
        $message = new AIMessage([
            'content' => 'done',
            'additional_kwargs' => ['tool_outputs' => [
                ['type' => 'web_search_call', 'id' => 'ws_1'],
                ['type' => 'code_interpreter_call', 'id' => 'ci_1', 'code' => 'print(1)'],
            ]],
        ]);

        $result = self::convert([$message], 'gpt-4o');

        self::assertCount(2, $result);
        self::assertSame('code_interpreter_call', $result[1]['type']);
    }

    public function testCustomAndComputerToolCallsKeepTheirItemTypes(): void
    {
        $message = new AIMessage([
            'content' => [],
            'tool_calls' => [
                ['type' => 'tool_call', 'id' => 'call_c', 'call_id' => 'ctc_1', 'name' => 'apply', 'args' => ['input' => 'diff'], 'isCustomTool' => true],
                ['type' => 'tool_call', 'id' => 'call_k', 'call_id' => 'cu_1', 'name' => 'computer_use', 'args' => ['action' => ['type' => 'click']], 'isComputerTool' => true],
            ],
        ]);

        $result = self::convert([$message], 'gpt-4o');

        self::assertWire([
            ['type' => 'custom_tool_call', 'id' => 'ctc_1', 'call_id' => 'call_c', 'input' => 'diff', 'name' => 'apply'],
            ['type' => 'computer_call', 'id' => 'cu_1', 'call_id' => 'call_k', 'action' => ['type' => 'click']],
        ], $result);
    }

    // ---- v1 content-block replay (multi-reasoning-item ordering, ZDR) ------

    public function testBothTheDefaultAndTheV1PathReplayMultipleReasoningItemsInOrderUnderZdr(): void
    {
        $output = [
            ['type' => 'reasoning', 'id' => 'rs_first', 'summary' => [['type' => 'summary_text', 'text' => 'First']], 'encrypted_content' => 'enc_1'],
            ['type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_1', 'name' => 'add', 'arguments' => '{"a":1,"b":2}'],
            ['type' => 'reasoning', 'id' => 'rs_second', 'summary' => [['type' => 'summary_text', 'text' => 'Second']], 'encrypted_content' => 'enc_2'],
            ['type' => 'function_call', 'id' => 'fc_2', 'call_id' => 'call_2', 'name' => 'multiply', 'arguments' => '{"a":3,"b":4}'],
        ];
        $summarise = static fn (array $items): array => array_map(
            static fn (array $item): string => $item['type'] === 'reasoning'
                ? "reasoning:{$item['id']}:{$item['encrypted_content']}"
                : ($item['type'] === 'function_call' ? "function_call:{$item['call_id']}" : $item['type']),
            $items,
        );
        $expected = ['reasoning:rs_first:enc_1', 'function_call:call_1', 'reasoning:rs_second:enc_2', 'function_call:call_2'];

        // Default (v0): the legacy field holds one reasoning item, the stored output holds all.
        $v0 = new AIMessage([
            'content' => [],
            'tool_calls' => [
                ['id' => 'call_1', 'name' => 'add', 'args' => ['a' => 1, 'b' => 2]],
                ['id' => 'call_2', 'name' => 'multiply', 'args' => ['a' => 3, 'b' => 4]],
            ],
            'additional_kwargs' => ['reasoning' => $output[2]],
            'response_metadata' => ['output' => $output, 'model_provider' => 'openai'],
        ]);
        self::assertSame($expected, $summarise(self::convert([$v0], 'gpt-5.6', true)));

        // v1: the same turn as standard content blocks.
        $v1 = new AIMessage([
            'content' => [
                ['type' => 'reasoning', 'id' => 'rs_first', 'reasoning' => 'First', 'encrypted_content' => 'enc_1'],
                ['type' => 'tool_call', 'id' => 'call_1', 'name' => 'add', 'args' => ['a' => 1, 'b' => 2]],
                ['type' => 'reasoning', 'id' => 'rs_second', 'reasoning' => 'Second', 'encrypted_content' => 'enc_2'],
                ['type' => 'tool_call', 'id' => 'call_2', 'name' => 'multiply', 'args' => ['a' => 3, 'b' => 4]],
            ],
            'response_metadata' => ['model_provider' => 'openai', 'output_version' => 'v1'],
        ]);
        self::assertSame($expected, $summarise(self::convert([$v1], 'gpt-5.6', true)));
    }

    // ---- configuration_update block support -------------------------------

    public function testHoistsAConfigurationUpdateBlockIntoAPrecedingTopLevelItem(): void
    {
        $messages = [new HumanMessage(['content' => [
            ['type' => 'configuration_update', 'reasoning' => ['effort' => 'high']],
            ['type' => 'text', 'text' => 'Hello'],
        ]])];

        self::assertWire([
            ['type' => 'configuration_update', 'reasoning' => ['effort' => 'high']],
            ['type' => 'message', 'role' => 'user', 'content' => [['type' => 'input_text', 'text' => 'Hello']]],
        ], self::convert($messages, 'gpt-6-astra'));
    }

    public function testKeepsTheConfigurationUpdateItemPositionStableAsTheConversationGrows(): void
    {
        $messages = [
            new HumanMessage('First question'),
            new AIMessage(['content' => 'First answer', 'response_metadata' => ['id' => 'resp_123']]),
            new HumanMessage(['content' => [
                ['type' => 'configuration_update', 'reasoning' => ['effort' => 'high']],
                ['type' => 'text', 'text' => 'Second question'],
            ]]),
        ];

        $first = self::convert($messages, 'gpt-6-astra');

        self::assertSame(
            ['user', 'assistant', 'configuration_update', 'user'],
            array_map(static fn (array $item): string => $item['role'] ?? $item['type'], $first),
        );

        $grown = [
            ...$messages,
            new AIMessage(['content' => 'Second answer', 'response_metadata' => ['id' => 'resp_456']]),
            new HumanMessage('Third question'),
        ];
        $second = self::convert($grown, 'gpt-6-astra');

        self::assertWire($first, array_slice($second, 0, count($first)));
    }

    public function testStillYieldsTheInputItemWhenThereIsNoAccompanyingText(): void
    {
        $messages = [
            new HumanMessage('Earlier question'),
            new AIMessage(['content' => 'Earlier answer', 'response_metadata' => ['id' => 'resp_123']]),
            new HumanMessage(['content' => [['type' => 'configuration_update', 'reasoning' => ['effort' => 'low']]]]),
        ];

        $result = self::convert($messages, 'gpt-6-astra');

        self::assertWire(['type' => 'configuration_update', 'reasoning' => ['effort' => 'low']], $result[array_key_last($result)]);
    }

    // ---- tool_search support (input half) ---------------------------------

    public function testRoundTripsToolSearchItemsViaResponseMetadataOutput(): void
    {
        $toolSearchCall = ['type' => 'tool_search_call', 'id' => 'ts_001', 'call_id' => 'call_abc', 'execution' => 'server', 'status' => 'completed'];
        $toolSearchOutput = [
            'type' => 'tool_search_output',
            'id' => 'tso_001',
            'call_id' => 'call_abc',
            'execution' => 'server',
            'status' => 'completed',
            'tools' => [[
                'type' => 'function',
                'name' => 'get_weather',
                'description' => 'Get weather',
                // An empty `properties` MAP: it must stay an object on the wire.
                'parameters' => ['type' => 'object', 'properties' => new \stdClass()],
                'strict' => null,
            ]],
        ];
        $functionCall = ['type' => 'function_call', 'id' => 'fc_001', 'call_id' => 'call_xyz', 'name' => 'get_weather', 'arguments' => '{"location":"SF"}'];

        $aiMessage = new AIMessage([
            'content' => '',
            'tool_calls' => [['id' => 'call_xyz', 'name' => 'get_weather', 'args' => ['location' => 'SF']]],
            'response_metadata' => ['model_provider' => 'openai', 'output' => [$toolSearchCall, $toolSearchOutput, $functionCall]],
        ]);

        $result = self::convert([$aiMessage], 'gpt-5.3');

        self::assertWire([$toolSearchCall, $toolSearchOutput, $functionCall], $result);
        self::assertStringContainsString('"properties":{}', json_encode($result));
    }
}
