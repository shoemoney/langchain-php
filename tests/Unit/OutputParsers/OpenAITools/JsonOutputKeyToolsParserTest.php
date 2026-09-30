<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\OutputParsers\OpenAITools;

use LangChain\Messages\AIMessage;
use LangChain\OutputParsers\OpenAITools\JsonOutputKeyToolsParser;
use LangChain\OutputParsers\OpenAITools\JsonOutputToolsParser;
use LangChain\OutputParsers\OutputParserException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(JsonOutputToolsParser::class)]
#[CoversClass(JsonOutputKeyToolsParser::class)]
final class JsonOutputKeyToolsParserTest extends TestCase
{
    /**
     * A generation carrying a message, which is the shape every parser in this
     * family receives from a chat model.
     *
     * @return list<array{text: string, message: AIMessage}>
     */
    private static function gen(AIMessage $message): array
    {
        return [['text' => $message->text(), 'message' => $message]];
    }

    // ---- JsonOutputToolsParser -----------------------------------------

    public function testReadsParsedToolCallsOffTheMessage(): void
    {
        $message = new AIMessage([
            'content' => '',
            'tool_calls' => [
                ['name' => 'get_weather', 'args' => ['city' => 'Austin'], 'id' => 'call_1', 'type' => 'tool_call'],
            ],
        ]);

        $result = (new JsonOutputToolsParser())->parseResult(self::gen($message));

        self::assertSame([
            ['type' => 'get_weather', 'args' => ['city' => 'Austin'], 'id' => null],
        ], $result);
    }

    public function testReturnIdKeepsTheCallId(): void
    {
        $message = new AIMessage([
            'content' => '',
            'tool_calls' => [
                ['name' => 'get_weather', 'args' => ['city' => 'Austin'], 'id' => 'call_1', 'type' => 'tool_call'],
            ],
        ]);

        $result = (new JsonOutputToolsParser(['returnId' => true]))->parseResult(self::gen($message));

        self::assertSame('call_1', $result[0]['id']);
    }

    /**
     * The provider-wire fallback. An unported client leaves the raw shape in
     * `additional_kwargs`, and the parser must still read the arguments out of
     * the JSON *string* it carries.
     */
    public function testFallsBackToProviderWireShape(): void
    {
        $message = new AIMessage([
            'content' => '',
            'additional_kwargs' => [
                'tool_calls' => [
                    [
                        'id' => 'call_9',
                        'type' => 'function',
                        'function' => ['name' => 'lookup', 'arguments' => '{"q":"php"}'],
                    ],
                ],
            ],
        ]);

        $result = (new JsonOutputToolsParser(['returnId' => true]))->parseResult(self::gen($message));

        self::assertSame([['type' => 'lookup', 'args' => ['q' => 'php'], 'id' => 'call_9']], $result);
    }

    /**
     * A message with no tool calls at all is an empty result, not an error.
     */
    public function testNoToolCallsYieldsEmptyList(): void
    {
        $result = (new JsonOutputToolsParser())->parseResult(self::gen(new AIMessage('just prose')));

        self::assertSame([], $result);
    }

    /**
     * Strict mode: arguments that are not valid JSON are a failure.
     *
     * The message names the function and echoes the offending text, because
     * "invalid JSON" without the payload is unactionable for whoever has to
     * decide whether it is a model bug or a transport bug.
     */
    public function testInvalidJsonArgumentsThrowWithContext(): void
    {
        $message = new AIMessage([
            'content' => '',
            'additional_kwargs' => [
                'tool_calls' => [
                    ['id' => 'c', 'function' => ['name' => 'broken', 'arguments' => '{not json']],
                ],
            ],
        ]);

        try {
            (new JsonOutputToolsParser())->parseResult(self::gen($message));
            self::fail('expected an OutputParserException');
        } catch (OutputParserException $e) {
            self::assertStringContainsString('broken', $e->getMessage());
            self::assertStringContainsString('{not json', $e->getMessage());
            self::assertStringContainsString('not valid JSON', $e->getMessage());
        }
    }

    /**
     * Partial mode: arguments that are not JSON *at all* are "not yet", not broken.
     *
     * This is the streaming case. Most of a streamed tool call's arguments are
     * a fragment, and treating that as an error would make every streamed tool
     * call fail.
     */
    public function testPartialModeTolerUnparseableArguments(): void
    {
        $parser = new JsonOutputToolsParser();

        $message = new AIMessage([
            'content' => '',
            'additional_kwargs' => [
                'tool_calls' => [
                    ['id' => 'c', 'function' => ['name' => 'slow', 'arguments' => 'nonsense so far']],
                ],
            ],
        ]);

        self::assertSame([], $parser->parsePartialResult(self::gen($message), true));
    }

    /**
     * Partial mode recovers the value that has arrived so far.
     *
     * An unterminated string is the single most common mid-stream state, and
     * the parser closing it is what makes a token-by-token tool call useful
     * rather than merely non-crashing.
     */
    public function testPartialModeRecoversAnUnterminatedString(): void
    {
        $message = new AIMessage([
            'content' => '',
            'additional_kwargs' => [
                'tool_calls' => [
                    ['id' => 'c', 'function' => ['name' => 'slow', 'arguments' => '{"q":"unfinis']],
                ],
            ],
        ]);

        $result = (new JsonOutputToolsParser())->parsePartialResult(self::gen($message), true);

        self::assertSame([['type' => 'slow', 'args' => ['q' => 'unfinis'], 'id' => null]], $result);
    }

    public function testDiffModeIsUnsupported(): void
    {
        $parser = new JsonOutputToolsParser(['diff' => true]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not supported.');

        // Diffing is only reached through the streaming transform, which is
        // where a caller would ask for it.
        iterator_to_array($parser->transform([new AIMessage('{"a":1}')]));
    }

    public function testParseFromTextIsNotSupported(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not implemented.');

        (new JsonOutputToolsParser())->parse('{"a":1}');
    }

    // ---- JsonOutputKeyToolsParser ---------------------------------------

    public function testKeyedParserReturnsOnlyTheNamedCalls(): void
    {
        $message = new AIMessage([
            'content' => '',
            'tool_calls' => [
                ['name' => 'other', 'args' => ['x' => 1], 'id' => 'a', 'type' => 'tool_call'],
                ['name' => 'extract', 'args' => ['name' => 'Ada'], 'id' => 'b', 'type' => 'tool_call'],
            ],
        ]);

        $parser = new JsonOutputKeyToolsParser(['keyName' => 'extract', 'returnSingle' => true]);

        self::assertSame(['name' => 'Ada'], $parser->parseResult(self::gen($message)));
    }

    /**
     * The model narrating instead of calling is a *null*, not an exception.
     *
     * This is the case `withStructuredOutput` depends on: a model that declines
     * the schema produces no call, and the caller is told "nothing" rather than
     * being handed a fabricated default.
     */
    public function testMissingNamedCallYieldsNull(): void
    {
        $message = new AIMessage([
            'content' => '',
            'tool_calls' => [
                ['name' => 'something_else', 'args' => [], 'id' => 'a', 'type' => 'tool_call'],
            ],
        ]);

        $parser = new JsonOutputKeyToolsParser(['keyName' => 'extract', 'returnSingle' => true]);

        self::assertNull($parser->parseResult(self::gen($message)));
    }

    public function testReturnSingleFalseYieldsListOfArgs(): void
    {
        $message = new AIMessage([
            'content' => '',
            'tool_calls' => [
                ['name' => 'extract', 'args' => ['n' => 1], 'id' => 'a', 'type' => 'tool_call'],
                ['name' => 'extract', 'args' => ['n' => 2], 'id' => 'b', 'type' => 'tool_call'],
            ],
        ]);

        $parser = new JsonOutputKeyToolsParser(['keyName' => 'extract']);

        self::assertSame([['n' => 1], ['n' => 2]], $parser->parseResult(self::gen($message)));
    }

    /**
     * Schema validation runs only on the *final* parse.
     *
     * Validating a partial result would reject every in-flight stream, so
     * `parsePartialResult` deliberately skips it while `parseResult` enforces it.
     */
    public function testSchemaValidatesTheFinalResultOnly(): void
    {
        $parser = new JsonOutputKeyToolsParser([
            'keyName' => 'extract',
            'returnSingle' => true,
            'jsonSchema' => [
                'type' => 'object',
                'properties' => ['name' => ['type' => 'string']],
                'required' => ['name'],
            ],
        ]);

        $good = new AIMessage([
            'content' => '',
            'tool_calls' => [['name' => 'extract', 'args' => ['name' => 'Ada'], 'id' => 'a', 'type' => 'tool_call']],
        ]);
        self::assertSame(['name' => 'Ada'], $parser->parseResult(self::gen($good)));

        $bad = new AIMessage([
            'content' => '',
            'tool_calls' => [['name' => 'extract', 'args' => ['name' => 42], 'id' => 'a', 'type' => 'tool_call']],
        ]);

        $this->expectException(OutputParserException::class);
        $this->expectExceptionMessage('Failed to parse.');
        $parser->parseResult(self::gen($bad));
    }

    public function testPartialParseSkipsSchemaValidation(): void
    {
        $parser = new JsonOutputKeyToolsParser([
            'keyName' => 'extract',
            'returnSingle' => true,
            'jsonSchema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
        ]);

        $incomplete = new AIMessage([
            'content' => '',
            'tool_calls' => [['name' => 'extract', 'args' => ['partial' => true], 'id' => 'a', 'type' => 'tool_call']],
        ]);

        // Would throw if the schema were applied.
        self::assertSame(['partial' => true], $parser->parsePartialResult(self::gen($incomplete), true));
    }

    /**
     * `createFunctionCallingParser` is the factory `withStructuredOutput` uses.
     * The key name has to arrive intact or the whole chain silently finds
     * nothing.
     */
    public function testFactoryProducesKeyedParser(): void
    {
        $parser = \LangChain\LanguageModels\StructuredOutput::createFunctionCallingParser('extract');

        self::assertInstanceOf(JsonOutputKeyToolsParser::class, $parser);
        self::assertSame('extract', $parser->keyName);
        self::assertTrue($parser->returnSingle);
    }
}
