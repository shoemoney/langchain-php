<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Runnables\RunnableSequence;
use LangChain\Tests\Unit\Agents\Support\MockChatModel;
use LangChain\Utils\Testing\FakeChatModel;
use LangGraph\Agents\Utils;
use LangGraph\Agents\WithAgentName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `langchain/src/agents/tests/withAgentName.test.ts`.
 */
#[CoversClass(WithAgentName::class)]
final class WithAgentNameTest extends TestCase
{
    public function testShouldThrowIfAgentModeIsNotInline(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid agent name mode: not-inline. Needs to be one of: "inline"');

        WithAgentName::withAgentName(new MockChatModel(), 'not-inline');
    }

    public function testShouldReturnARunnableSequenceForInlineMode(): void
    {
        $wrapped = WithAgentName::withAgentName(new MockChatModel(), 'inline');

        self::assertInstanceOf(RunnableSequence::class, $wrapped);
        self::assertTrue(method_exists($wrapped, 'invoke'));
        self::assertTrue(method_exists($wrapped, 'stream'));
    }

    public function testShouldProcessMessagesEndToEnd(): void
    {
        $wrapped = WithAgentName::withAgentName(new MockChatModel(), 'inline');

        $result = $wrapped->invoke([
            new HumanMessage(['content' => 'Hello']),
            new AIMessage(['content' => 'Hi there!', 'name' => 'assistant']),
        ]);

        self::assertInstanceOf(AIMessage::class, $result);
        // The model echoed the tagged text; the tag pattern is unanchored, so the output pass finds the
        // echoed tags, takes the name from them and strips them (upstream asserts name and content alike).
        self::assertSame('assistant', $result->name);
        self::assertStringContainsString('Hi there!', $result->content);
        self::assertStringNotContainsString('<name>', $result->content);
    }

    public function testShouldPreserveMessageOrderAndTypes(): void
    {
        $wrapped = WithAgentName::withAgentName(new MockChatModel(), 'inline');

        $result = $wrapped->invoke([
            new HumanMessage(['content' => 'Human message']),
            new AIMessage(['content' => 'AI response', 'name' => 'assistant']),
            new HumanMessage(['content' => 'Follow-up']),
        ]);

        self::assertInstanceOf(AIMessage::class, $result);
        self::assertSame('test-agent', $result->name);
        self::assertStringContainsString('Follow-up', $result->content);
    }

    public function testShouldHandleMessagesWithAgentNames(): void
    {
        $result = WithAgentName::withAgentName(new MockChatModel(), 'inline')->invoke([new HumanMessage(['content' => 'test'])]);

        self::assertInstanceOf(AIMessage::class, $result);
        self::assertSame('test-agent', $result->name);
    }

    public function testTheModelReceivesTheTaggedHistoryAndTheCallerGetsTheNameBack(): void
    {
        // FakeChatModel echoes its whole input: the reply is the tagged history, whose tags the output
        // pass strips again, recovering the speaker's name.
        $wrapped = WithAgentName::withAgentName(new FakeChatModel(), 'inline');

        $result = $wrapped->invoke([
            new HumanMessage('hi'),
            new AIMessage(['content' => 'hello', 'name' => 'bob']),
        ]);

        self::assertInstanceOf(AIMessage::class, $result);
        self::assertSame('hello', $result->content);
        self::assertSame('bob', $result->name);
    }

    public function testTheInlineFormatIsByteForByteTheDocumentedOne(): void
    {
        $tagged = Utils::addInlineAgentName(new AIMessage(['content' => 'How can I help you?', 'name' => 'agent_name']));

        self::assertSame('<name>agent_name</name><content>How can I help you?</content>', $tagged->content);
    }

    // ---- the helpers as exercised by upstream's withAgentName.test.ts ---------------------------

    public function testAddInlineWithStringContent(): void
    {
        $result = Utils::addInlineAgentName(new AIMessage(['content' => 'Hello world', 'name' => 'test-agent']));

        self::assertInstanceOf(AIMessage::class, $result);
        self::assertStringContainsString('test-agent', $result->content);
        self::assertStringContainsString('Hello world', $result->content);
        self::assertNull($result->name);
    }

    public function testAddInlineWithArrayContent(): void
    {
        $result = Utils::addInlineAgentName(new AIMessage([
            'content' => [['type' => 'text', 'text' => 'Hello'], ['type' => 'text', 'text' => 'world']],
            'name' => 'test-agent',
        ]));

        self::assertIsArray($result->content);
        $json = (string) json_encode($result->content);
        self::assertStringContainsString('test-agent', $json);
        self::assertStringContainsString('Hello', $json);
        self::assertStringContainsString('world', $json);
        self::assertNull($result->name);
    }

    public function testAddInlineWithMixedContentTypes(): void
    {
        $result = Utils::addInlineAgentName(new AIMessage([
            'content' => [['type' => 'text', 'text' => 'Hello'], ['type' => 'image_url', 'image_url' => ['url' => 'http://example.com/image.jpg']]],
            'name' => 'test-agent',
        ]));

        $json = (string) json_encode($result->content);
        self::assertStringContainsString('test-agent', $json);
        self::assertStringContainsString('Hello', $json);
        self::assertStringContainsString('image.jpg', $json);
    }

    public function testAddInlineAddsAnEmptyContentBlockWhenNoTextBlockExists(): void
    {
        $result = Utils::addInlineAgentName(new AIMessage([
            'content' => [['type' => 'image_url', 'image_url' => ['url' => 'http://example.com/image.jpg']]],
            'name' => 'test-agent',
        ]));

        $json = (string) json_encode($result->content);
        self::assertStringContainsString('test-agent', $json);
        self::assertStringContainsString('image.jpg', $json);
    }

    public function testAddInlinePassesMessagesWithoutNameAndNonAiMessagesThrough(): void
    {
        $noName = new AIMessage(['content' => 'Hello world']);
        $human = new HumanMessage(['content' => 'Hello world', 'name' => 'user']);

        self::assertSame($noName, Utils::addInlineAgentName($noName));
        self::assertSame($human, Utils::addInlineAgentName($human));
    }

    public function testRemoveInlineExtractsNameAndContentFromArrayContent(): void
    {
        $result = Utils::removeInlineAgentName(new AIMessage([
            'content' => [
                ['type' => 'text', 'text' => '<name>test-agent</name><content>Hello</content>'],
                ['type' => 'image_url', 'image_url' => ['url' => 'http://example.com/image.jpg']],
            ],
        ]));

        self::assertCount(2, $result->content);
        self::assertSame('Hello', $result->content[0]['text']);
        self::assertSame('test-agent', $result->name);
    }

    public function testRemoveInlineDropsEmptyContentBlocks(): void
    {
        $result = Utils::removeInlineAgentName(new AIMessage([
            'content' => [
                ['type' => 'text', 'text' => '<name>test-agent</name><content></content>'],
                ['type' => 'image_url', 'image_url' => ['url' => 'http://example.com/image.jpg']],
            ],
        ]));

        self::assertCount(1, $result->content);
        self::assertSame('image_url', $result->content[0]['type']);
        self::assertSame('test-agent', $result->name);
    }
}
