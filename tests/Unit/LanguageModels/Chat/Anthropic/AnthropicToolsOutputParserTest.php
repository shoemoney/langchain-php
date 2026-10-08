<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Anthropic;

use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\Anthropic\OutputParsers\AnthropicToolsOutputParser;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\Messages\AIMessage;
use LangChain\OutputParsers\OutputParserException;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Upstream has no dedicated parser test file; these exercise the behaviour of
 * `output_parsers.ts` directly, then run it behind a real ChatAnthropic.
 */
#[CoversClass(AnthropicToolsOutputParser::class)]
final class AnthropicToolsOutputParserTest extends TestCase
{
    private static function toolUseMessage(array $input = ['city' => 'Austin']): AIMessage
    {
        return new AIMessage([
            'content' => [
                ['type' => 'text', 'text' => 'Checking.'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_weather', 'input' => $input],
            ],
        ]);
    }

    /** @return list<array{text: string, message: AIMessage}> */
    private static function gen(AIMessage $message): array
    {
        return [['text' => '', 'message' => $message]];
    }

    public function testExtractToolCallsReadsOnlyToolUseBlocks(): void
    {
        $calls = AnthropicToolsOutputParser::extractToolCalls([
            ['type' => 'text', 'text' => 'x'],
            ['type' => 'tool_use', 'id' => 'a', 'name' => 'one', 'input' => ['k' => 1]],
            ['type' => 'tool_use', 'id' => 'b', 'name' => 'two', 'input' => []],
        ]);

        self::assertSame([
            ['name' => 'one', 'args' => ['k' => 1], 'id' => 'a', 'type' => 'tool_call'],
            ['name' => 'two', 'args' => [], 'id' => 'b', 'type' => 'tool_call'],
        ], $calls);
    }

    public function testReturnsTheFirstToolUseInput(): void
    {
        $parser = new AnthropicToolsOutputParser(['keyName' => 'get_weather']);

        self::assertSame(['city' => 'Austin'], $parser->parseResult(self::gen(self::toolUseMessage())));
    }

    public function testAcceptsChatGenerationObjects(): void
    {
        $parser = new AnthropicToolsOutputParser(['keyName' => 'get_weather']);

        self::assertSame(['city' => 'Austin'], $parser->parseResult([new ChatGeneration(self::toolUseMessage())]));
    }

    public function testSkipsGenerationsWithoutToolCalls(): void
    {
        $parser = new AnthropicToolsOutputParser(['keyName' => 'get_weather']);
        $generations = [
            ['text' => 'a', 'message' => new AIMessage('just text')],
            ['text' => '', 'message' => self::toolUseMessage(['city' => 'Paris'])],
        ];

        self::assertSame(['city' => 'Paris'], $parser->parseResult($generations));
    }

    public function testFallsBackToStructuredToolCallsWhenContentHoldsNoRawBlock(): void
    {
        $parser = new AnthropicToolsOutputParser(['keyName' => 'get_weather']);
        $message = new AIMessage([
            'content' => 'calling',
            'tool_calls' => [['id' => 'c', 'name' => 'get_weather', 'args' => ['city' => 'Rome'], 'type' => 'tool_call']],
        ]);

        self::assertSame(['city' => 'Rome'], $parser->parseResult(self::gen($message)));
    }

    public function testThrowsWhenNothingIsParseable(): void
    {
        $parser = new AnthropicToolsOutputParser(['keyName' => 'get_weather']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('No parseable tool calls provided to AnthropicToolsOutputParser.');

        $parser->parseResult(self::gen(new AIMessage('no tools here')));
    }

    public function testJsonSchemaFailureRaisesOutputParserException(): void
    {
        $parser = new AnthropicToolsOutputParser([
            'keyName' => 'get_weather',
            'jsonSchema' => ['type' => 'object', 'properties' => ['city' => ['type' => 'string']], 'required' => ['city']],
        ]);

        try {
            $parser->parseResult(self::gen(self::toolUseMessage(['zip' => 78701])));
            self::fail('expected OutputParserException');
        } catch (OutputParserException $e) {
            self::assertStringStartsWith('Failed to parse. Text: "', $e->getMessage());
            self::assertNotNull($e->llmOutput);
        }
    }

    public function testJsonSchemaSuccessReturnsTheInput(): void
    {
        $parser = new AnthropicToolsOutputParser([
            'keyName' => 'get_weather',
            'jsonSchema' => ['type' => 'object', 'required' => ['city']],
        ]);

        self::assertSame(['city' => 'Austin'], $parser->parseResult(self::gen(self::toolUseMessage())));
    }

    public function testStringInputIsJsonDecodedAndBadJsonIsAParserException(): void
    {
        $parser = new AnthropicToolsOutputParser(['keyName' => 'k']);

        self::assertSame(['a' => 1], $parser->parseResult(self::gen(self::toolUseMessage(['a' => 1]))));

        $message = new AIMessage(['content' => [['type' => 'tool_use', 'id' => 'x', 'name' => 'k', 'input' => '{not json']]]);
        $this->expectException(OutputParserException::class);
        $parser->parseResult(self::gen($message));
    }

    public function testReturnSingleAndKeyNameAreStored(): void
    {
        $parser = new AnthropicToolsOutputParser(['keyName' => 'k', 'returnSingle' => true]);

        self::assertSame('k', $parser->keyName);
        self::assertTrue($parser->returnSingle);
        self::assertFalse((new AnthropicToolsOutputParser(['keyName' => 'k']))->returnSingle);
    }

    /** A real chain: ChatAnthropic over the fake transport, piped into the parser. */
    public function testParsesAChatAnthropicResponseInsideAChain(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, [
            'id' => 'msg_1', 'model' => 'claude-sonnet-4-5', 'stop_reason' => 'tool_use',
            'content' => [
                ['type' => 'text', 'text' => 'Checking.'],
                ['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_weather', 'input' => ['city' => 'Austin']],
            ],
            'usage' => ['input_tokens' => 3, 'output_tokens' => 4],
        ])]);
        $model = new ChatAnthropic(['apiKey' => 'sk-ant-test', 'httpClient' => $http]);

        $chain = $model->pipe(new AnthropicToolsOutputParser([
            'keyName' => 'get_weather',
            'jsonSchema' => ['type' => 'object', 'required' => ['city']],
        ]));

        self::assertSame(['city' => 'Austin'], $chain->invoke('weather in Austin?'));
    }
}
