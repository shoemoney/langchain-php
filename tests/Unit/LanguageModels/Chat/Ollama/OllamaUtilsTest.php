<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Ollama;

use LangChain\LanguageModels\Chat\Ollama\OllamaUtils;
use LangChain\Messages\AIMessage;
use LangChain\Messages\ChatMessage;
use LangChain\Messages\FunctionMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\ToolMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `utils.test.ts`, plus the conversion branches upstream leaves untested.
 */
#[CoversClass(OllamaUtils::class)]
final class OllamaUtilsTest extends TestCase
{
    public function testConvertOllamaMessagesToLangChainSeparatesThinkingIntoReasoningContent(): void
    {
        $chunk = OllamaUtils::convertOllamaMessagesToLangChain([
            'role' => 'assistant',
            'content' => 'Hello! How can I help?',
            'thinking' => 'We should respond politely.',
        ]);

        self::assertSame('Hello! How can I help?', $chunk->content);
        self::assertSame('We should respond politely.', $chunk->additional_kwargs['reasoning_content']);
    }

    public function testEmptyThinkingAddsNoReasoningContent(): void
    {
        $chunk = OllamaUtils::convertOllamaMessagesToLangChain(['content' => 'x', 'thinking' => '']);

        self::assertSame([], $chunk->additional_kwargs);
        self::assertSame('ollama', $chunk->response_metadata['model_provider']);
    }

    public function testResponseToolCallsBecomeToolCallChunksWithGeneratedIds(): void
    {
        $chunk = OllamaUtils::convertOllamaMessagesToLangChain([
            'content' => '',
            'tool_calls' => [
                ['function' => ['name' => 'get_weather', 'arguments' => ['city' => 'Austin']]],
                ['function' => ['name' => 'noargs', 'arguments' => []]],
            ],
        ]);

        self::assertCount(2, $chunk->toolCallChunks);
        self::assertSame('get_weather', $chunk->toolCallChunks[0]['name']);
        self::assertSame('{"city":"Austin"}', $chunk->toolCallChunks[0]['args']);
        self::assertSame('tool_call_chunk', $chunk->toolCallChunks[0]['type']);
        self::assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $chunk->toolCallChunks[0]['id']);
        self::assertNotSame($chunk->toolCallChunks[0]['id'], $chunk->toolCallChunks[1]['id']);

        // An empty argument map is an OBJECT, not `[]`.
        self::assertSame('{}', $chunk->toolCallChunks[1]['args']);
    }

    public function testConvertToOllamaMessagesPreservesToolCallsWhenAIMessageContentIsAString(): void
    {
        $ai = new AIMessage([
            'content' => "I'll look that up for you.",
            'tool_calls' => [['id' => 'call_123', 'name' => 'get_weather', 'args' => ['location' => 'San Francisco']]],
        ]);

        $result = OllamaUtils::convertToOllamaMessages([$ai]);

        $withCalls = array_values(array_filter($result, static fn (array $m): bool => !empty($m['tool_calls'])));
        self::assertCount(1, $withCalls);
        self::assertSame("I'll look that up for you.", $withCalls[0]['content']);
        self::assertSame('call_123', $withCalls[0]['tool_calls'][0]['id']);
        self::assertSame('function', $withCalls[0]['tool_calls'][0]['type']);
        self::assertSame('get_weather', $withCalls[0]['tool_calls'][0]['function']['name']);
        self::assertSame(['location' => 'San Francisco'], $withCalls[0]['tool_calls'][0]['function']['arguments']);
    }

    public function testConvertToOllamaMessagesPreservesToolCallsWhenAIMessageContentIsEmptyString(): void
    {
        $ai = new AIMessage([
            'content' => '',
            'tool_calls' => [['id' => 'call_456', 'name' => 'search', 'args' => ['query' => 'test']]],
        ]);

        $result = OllamaUtils::convertToOllamaMessages([$ai]);

        self::assertCount(1, $result);
        self::assertSame('', $result[0]['content']);
        self::assertSame('search', $result[0]['tool_calls'][0]['function']['name']);
    }

    public function testConvertToOllamaMessagesReturnsStringContentForAIMessageWithoutToolCalls(): void
    {
        $result = OllamaUtils::convertToOllamaMessages([new AIMessage(['content' => 'Hello!'])]);

        self::assertCount(1, $result);
        self::assertSame('Hello!', $result[0]['content']);
        self::assertArrayNotHasKey('tool_calls', $result[0]);
    }

    /**
     * PHP's `BaseMessage` defaults absent content to `[]`, where the TypeScript
     * default is `""`. Read as block content that dropped the tool calls with no
     * error, so an empty list is treated as empty text.
     */
    public function testAnAIMessageWithToolCallsAndNoContentKeepsItsToolCalls(): void
    {
        $result = OllamaUtils::convertToOllamaMessages([
            new AIMessage(['content' => [], 'tool_calls' => [['id' => 'c', 'name' => 'f', 'args' => ['a' => 1]]]]),
        ]);

        self::assertCount(1, $result);
        self::assertSame('f', $result[0]['tool_calls'][0]['function']['name']);
    }

    public function testAnEmptyArgumentMapSerialisesAsAnObjectNotAList(): void
    {
        $result = OllamaUtils::convertToOllamaMessages([
            new AIMessage(['content' => '', 'tool_calls' => [['id' => 'c', 'name' => 'f', 'args' => []]]]),
        ]);

        self::assertStringContainsString('"arguments":{}', json_encode($result, JSON_THROW_ON_ERROR));
    }

    public function testAToolCallWithoutAnIdOmitsTheKey(): void
    {
        $result = OllamaUtils::convertToOllamaMessages([
            new AIMessage(['content' => '', 'tool_calls' => [['name' => 'f', 'args' => ['a' => 1]]]]),
        ]);

        self::assertArrayNotHasKey('id', $result[0]['tool_calls'][0]);
    }

    public function testBlockContentEmitsOneAssistantMessagePerTextBlockThenTheToolCalls(): void
    {
        $ai = new AIMessage([
            'content' => [
                ['type' => 'text', 'text' => 'one'],
                ['type' => 'text', 'text' => 'two'],
                ['type' => 'tool_use', 'id' => 'c', 'name' => 'f', 'input' => []],
            ],
            'tool_calls' => [['id' => 'c', 'name' => 'f', 'args' => ['a' => 1]]],
        ]);

        $result = OllamaUtils::convertToOllamaMessages([$ai]);

        self::assertCount(3, $result);
        self::assertSame('one', $result[0]['content']);
        self::assertSame('two', $result[1]['content']);
        self::assertSame('', $result[2]['content']);
        self::assertSame('f', $result[2]['tool_calls'][0]['function']['name']);
    }

    public function testToolUseBlockWithoutToolCallsIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("'tool_use' content type is not supported without tool calls.");

        OllamaUtils::convertToOllamaMessages([
            new AIMessage(['content' => [['type' => 'tool_use', 'id' => 'c', 'name' => 'f', 'input' => []]]]),
        ]);
    }

    public function testHumanAndSystemAndToolMessagesMapToTheirRoles(): void
    {
        $result = OllamaUtils::convertToOllamaMessages([
            new SystemMessage('be brief'),
            new HumanMessage('hi'),
            new ToolMessage(['content' => '72F', 'tool_call_id' => 'c']),
        ]);

        self::assertSame([
            ['role' => 'system', 'content' => 'be brief'],
            ['role' => 'user', 'content' => 'hi'],
            ['role' => 'tool', 'content' => '72F'],
        ], $result);
    }

    public function testGenericChatMessagesAreSentAsUserMessages(): void
    {
        $result = OllamaUtils::convertToOllamaMessages([new ChatMessage(['content' => 'hello', 'role' => 'narrator'])]);

        self::assertSame([['role' => 'user', 'content' => 'hello']], $result);
    }

    public function testHumanImageBlocksCarryBareBase64(): void
    {
        $result = OllamaUtils::convertToOllamaMessages([
            new HumanMessage([
                ['type' => 'text', 'text' => 'what is this'],
                ['type' => 'image_url', 'image_url' => 'data:image/png;base64,AAAA'],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpeg;base64,BBBB']],
            ]),
        ]);

        self::assertSame(['role' => 'user', 'content' => 'what is this'], $result[0]);
        self::assertSame(['role' => 'user', 'content' => '', 'images' => ['AAAA']], $result[1]);
        self::assertSame(['role' => 'user', 'content' => '', 'images' => ['BBBB']], $result[2]);
    }

    public function testUnsupportedHumanContentIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported content type: audio');

        OllamaUtils::convertToOllamaMessages([new HumanMessage([['type' => 'audio', 'data' => 'x']])]);
    }

    public function testSystemBlockContentMustBeAllText(): void
    {
        $ok = OllamaUtils::convertToOllamaMessages([
            new SystemMessage([['type' => 'text', 'text' => 'a'], ['type' => 'text', 'text' => 'b']]),
        ]);
        self::assertSame([['role' => 'system', 'content' => 'a'], ['role' => 'system', 'content' => 'b']], $ok);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported content type(s): text, image_url');

        OllamaUtils::convertToOllamaMessages([
            new SystemMessage([['type' => 'text', 'text' => 'a'], ['type' => 'image_url', 'image_url' => 'x']]),
        ]);
    }

    public function testNonStringToolContentIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Non string tool message content is not supported');

        OllamaUtils::convertToOllamaMessages([
            new ToolMessage(['content' => [['type' => 'text', 'text' => 'x']], 'tool_call_id' => 'c']),
        ]);
    }

    public function testUnsupportedMessageTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported message type: function');

        OllamaUtils::convertToOllamaMessages([new FunctionMessage(['content' => 'x', 'name' => 'f'])]);
    }
}
