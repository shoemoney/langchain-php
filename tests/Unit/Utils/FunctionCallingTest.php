<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Utils;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\Messages\AIMessage;
use LangChain\Tools\Schema;
use LangChain\Tools\ToolUtils;
use LangChain\Utils\FunctionCalling;
use LangChain\Utils\Testing\FakeHttpClient;
use LangChain\Utils\Testing\FakeTool;
use LangChain\Utils\Testing\StructuredToolSpec;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `utils/tests/function_calling.test.ts`.
 *
 * Upstream builds the schema with `zod/v3`; here the same schema is spelled as
 * JSON Schema, and the expected output is what that schema serialises to (no
 * `$schema` draft key, since nothing converts it).
 */
#[CoversClass(FunctionCalling::class)]
#[CoversClass(ToolUtils::class)]
final class FunctionCallingTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'prop1' => ['type' => 'string'],
                'prop2' => ['type' => 'number', 'description' => 'Some desc'],
                'optionalProp' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'nestedRequired' => ['type' => 'string'],
                            'nestedOptional' => ['type' => 'string'],
                        ],
                        'required' => ['nestedRequired'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['prop1', 'prop2'],
            'additionalProperties' => false,
        ];
    }

    private static function tool(): FakeTool
    {
        return new FakeTool([
            'name' => 'faketesttool',
            'description' => 'A fake test tool',
            'schema' => new Schema(self::parameters()),
        ]);
    }

    public function testCanConvertToolToOpenAIFunctionsFormat(): void
    {
        self::assertSame([
            'name' => 'faketesttool',
            'description' => 'A fake test tool',
            'parameters' => self::parameters(),
        ], FunctionCalling::convertToOpenAIFunction(self::tool()));
    }

    public function testCanConvertToolToOpenAIToolFormat(): void
    {
        self::assertSame([
            'type' => 'function',
            'function' => [
                'name' => 'faketesttool',
                'description' => 'A fake test tool',
                'parameters' => self::parameters(),
            ],
        ], FunctionCalling::convertToOpenAITool(self::tool()));
    }

    public function testStrictIsEmittedOnlyWhenSet(): void
    {
        self::assertArrayNotHasKey('strict', FunctionCalling::convertToOpenAIFunction(self::tool()));
        self::assertArrayNotHasKey('strict', FunctionCalling::convertToOpenAIFunction(self::tool(), []));
        self::assertTrue(FunctionCalling::convertToOpenAIFunction(self::tool(), ['strict' => true])['strict']);
        self::assertFalse(FunctionCalling::convertToOpenAIFunction(self::tool(), ['strict' => false])['strict']);
    }

    public function testStrictLandsOnTheFunctionOfAToolDefinition(): void
    {
        $tool = FunctionCalling::convertToOpenAITool(self::tool(), ['strict' => true]);

        self::assertTrue($tool['function']['strict']);
        self::assertArrayNotHasKey('strict', $tool);
    }

    public function testAProviderShapedToolPassesThroughUntouched(): void
    {
        $definition = ['type' => 'function', 'function' => ['name' => 'x', 'parameters' => ['type' => 'object']]];

        self::assertSame($definition, FunctionCalling::convertToOpenAITool($definition));
        self::assertTrue(FunctionCalling::convertToOpenAITool($definition, ['strict' => true])['function']['strict']);
    }

    public function testAnArrayWithNameAndSchemaIsConverted(): void
    {
        $result = FunctionCalling::convertToOpenAITool([
            'name' => 'lookup',
            'description' => 'Look it up',
            'schema' => ['type' => 'object', 'properties' => ['q' => ['type' => 'string']]],
        ]);

        self::assertSame('function', $result['type']);
        self::assertSame('lookup', $result['function']['name']);
        self::assertSame(['q' => ['type' => 'string']], $result['function']['parameters']['properties']);
    }

    public function testASpecWithoutADescriptionOmitsIt(): void
    {
        $spec = new StructuredToolSpec('s', new Schema(['type' => 'object']));

        self::assertSame(
            ['name' => 's', 'parameters' => ['type' => 'object']],
            FunctionCalling::convertToOpenAIFunction($spec),
        );
    }

    public function testAnUnconvertibleValueIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        FunctionCalling::convertToOpenAIFunction('not a tool');
    }

    public function testPredicates(): void
    {
        $tool = self::tool();
        $spec = new StructuredToolSpec('s', new Schema(['type' => 'object']));

        self::assertTrue(ToolUtils::isStructuredTool($tool));
        self::assertFalse(ToolUtils::isStructuredTool(['name' => 'x']));
        self::assertTrue(ToolUtils::isStructuredToolParams($spec));
        self::assertTrue(ToolUtils::isStructuredToolParams(['name' => 'x', 'schema' => ['type' => 'object']]));
        self::assertTrue(ToolUtils::isStructuredToolParams(['name' => 'x', 'schema' => new Schema([])]));
        self::assertFalse(ToolUtils::isStructuredToolParams(['name' => 'x', 'schema' => ['type' => 'bogus']]));
        self::assertFalse(ToolUtils::isStructuredToolParams(['name' => 'x']));
        self::assertFalse(ToolUtils::isStructuredToolParams('x'));
        self::assertFalse(ToolUtils::isRunnableToolLike($tool));
        self::assertFalse(ToolUtils::isRunnableToolLike(null));
        self::assertTrue(ToolUtils::isLangChainTool($tool));
        self::assertTrue(ToolUtils::isLangChainTool($spec));
        self::assertFalse(ToolUtils::isLangChainTool(['type' => 'function', 'function' => ['name' => 'x']]));
        self::assertFalse(ToolUtils::isLangChainTool(null));
    }

    /**
     * End to end: a converted tool is bound to a chat model, goes out on the wire,
     * the model answers with a call to it, and the call runs the real tool.
     */
    public function testAConvertedToolRoundTripsThroughAChatModelAndRuns(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::json(200, [
                'id' => 'c1', 'object' => 'chat.completion', 'created' => 1, 'model' => 'gpt-4o',
                'choices' => [[
                    'index' => 0,
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => ['name' => 'faketesttool', 'arguments' => '{"prop1":"hello","prop2":3}'],
                        ]],
                    ],
                    'finish_reason' => 'tool_calls',
                ]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ]),
        ]);
        $tool = self::tool();
        $model = (new ChatOpenAI(['apiKey' => 'k', 'httpClient' => $http]))
            ->bindTools([FunctionCalling::convertToOpenAITool($tool)]);

        $reply = $model->invoke([['role' => 'user', 'content' => 'call it']]);

        $sent = $http->lastRequestBody()['tools'][0];
        self::assertSame('function', $sent['type']);
        self::assertSame('faketesttool', $sent['function']['name']);
        self::assertSame(self::parameters(), $sent['function']['parameters']);

        self::assertInstanceOf(AIMessage::class, $reply);
        self::assertCount(1, $reply->toolCalls);
        $call = $reply->toolCalls[0];
        self::assertSame('faketesttool', $call['name']);
        self::assertSame('{"prop1":"hello","prop2":3}', $tool->invoke($call['args']));
    }
}
