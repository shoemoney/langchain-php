<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prebuilt;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Utils\Testing\FakeListChatModel;
use LangGraph\Prebuilt\AgentName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `langgraph-core/src/tests/prebuilt/agentName.test.ts` (all 12 cases), plus `withAgentName`.
 */
#[CoversClass(AgentName::class)]
final class AgentNameTest extends TestCase
{
    // ---- _addInlineAgentName ------------------------------------------------

    public function testAddReturnsNonAiMessagesUnchanged(): void
    {
        $human = new HumanMessage('Hello');

        self::assertSame($human, AgentName::addInlineAgentName($human));
    }

    public function testAddReturnsAiMessagesWithNoNameUnchanged(): void
    {
        $ai = new AIMessage('Hello world');

        self::assertSame($ai, AgentName::addInlineAgentName($ai));
    }

    public function testAddFormatsAiMessagesWithNameAndContentTags(): void
    {
        $result = AgentName::addInlineAgentName(new AIMessage(['content' => 'Hello world', 'name' => 'assistant']));

        self::assertSame('<name>assistant</name><content>Hello world</content>', $result->content);
        self::assertNull($result->name);
    }

    public function testAddHandlesContentBlocksCorrectly(): void
    {
        $image = ['type' => 'image', 'image_url' => 'http://example.com/image.jpg'];
        $result = AgentName::addInlineAgentName(new AIMessage([
            'content' => [['type' => 'text', 'text' => 'Hello world'], $image],
            'name' => 'assistant',
        ]));

        self::assertSame([
            ['type' => 'text', 'text' => '<name>assistant</name><content>Hello world</content>'],
            $image,
        ], $result->content);
    }

    public function testAddHandlesContentBlocksWithoutTextBlocks(): void
    {
        $blocks = [
            ['type' => 'image', 'image_url' => 'http://example.com/image.jpg'],
            ['type' => 'file', 'file_url' => 'http://example.com/document.pdf'],
        ];
        $result = AgentName::addInlineAgentName(new AIMessage(['content' => $blocks, 'name' => 'assistant']));

        self::assertSame(
            [['type' => 'text', 'text' => '<name>assistant</name><content></content>'], ...$blocks],
            $result->content,
        );
    }

    public function testAddKeepsToolCallsAndIdentityOfTheRewrittenMessage(): void
    {
        $call = ['id' => 'c1', 'name' => 'search', 'args' => ['q' => 'x'], 'type' => 'tool_call'];
        $result = AgentName::addInlineAgentName(new AIMessage([
            'content' => 'hi',
            'name' => 'bob',
            'id' => 'm1',
            'tool_calls' => [$call],
        ]));

        self::assertSame([$call], $result->toolCalls);
        self::assertSame('m1', $result->id);
    }

    // ---- _removeInlineAgentName ---------------------------------------------

    public function testRemoveReturnsNonAiMessagesUnchanged(): void
    {
        $human = new HumanMessage('Hello');

        self::assertSame($human, AgentName::removeInlineAgentName($human));
    }

    public function testRemoveReturnsMessagesWithEmptyContentUnchanged(): void
    {
        $ai = new AIMessage(['content' => '', 'name' => 'assistant']);

        self::assertSame($ai, AgentName::removeInlineAgentName($ai));
    }

    public function testRemoveReturnsMessagesWithoutNameContentTagsUnchanged(): void
    {
        $ai = new AIMessage(['content' => 'Hello world', 'name' => 'assistant']);

        self::assertSame($ai, AgentName::removeInlineAgentName($ai));
    }

    public function testRemoveExtractsContentFromTags(): void
    {
        $result = AgentName::removeInlineAgentName(new AIMessage([
            'content' => '<name>assistant</name><content>Hello world</content>',
            'name' => 'assistant',
        ]));

        self::assertSame('Hello world', $result->content);
        self::assertSame('assistant', $result->name);
    }

    public function testRemoveHandlesContentBlocksCorrectly(): void
    {
        $image = ['type' => 'image', 'image_url' => 'http://example.com/image.jpg'];
        $result = AgentName::removeInlineAgentName(new AIMessage([
            'content' => [['type' => 'text', 'text' => '<name>assistant</name><content>Hello world</content>'], $image],
            'name' => 'assistant',
        ]));

        self::assertSame([['type' => 'text', 'text' => 'Hello world'], $image], $result->content);
        self::assertSame('assistant', $result->name);
    }

    public function testRemoveHandlesContentBlocksWithEmptyTextContent(): void
    {
        $blocks = [
            ['type' => 'text', 'text' => '<name>assistant</name><content></content>'],
            ['type' => 'image', 'image_url' => 'http://example.com/image.jpg'],
            ['type' => 'file', 'file_url' => 'http://example.com/document.pdf'],
        ];
        $result = AgentName::removeInlineAgentName(new AIMessage(['content' => $blocks, 'name' => 'assistant']));

        self::assertSame(array_slice($blocks, 1), $result->content);
    }

    public function testRemoveHandlesMultilineContent(): void
    {
        $result = AgentName::removeInlineAgentName(new AIMessage([
            'content' => "<name>assistant</name><content>This is\na multiline\nmessage</content>",
            'name' => 'assistant',
        ]));

        self::assertSame("This is\na multiline\nmessage", $result->content);
    }

    // ---- withAgentName ------------------------------------------------------

    public function testWithAgentNameTagsTheHistoryGoingInAndStripsTheReplyComingOut(): void
    {
        $model = new FakeListChatModel(['responses' => [
            new AIMessage('<name>bob</name><content>pong</content>'),
        ]]);
        $wrapped = AgentName::withAgentName($model, 'inline');

        $out = $wrapped->invoke([new HumanMessage('ping'), new AIMessage(['content' => 'earlier', 'name' => 'alice'])]);

        self::assertSame('pong', $out->content);
        self::assertSame('bob', $out->name);
    }

    public function testWithAgentNameRejectsUnknownModes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid agent name mode: bogus');

        AgentName::withAgentName(new FakeListChatModel(['responses' => ['x']]), 'bogus');
    }
}
