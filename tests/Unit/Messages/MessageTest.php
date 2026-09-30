<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Messages;

use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\ContentBlock;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\HumanMessageChunk;
use LangChain\Messages\MessageMerge;
use LangChain\Messages\MessageUtils;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\SystemMessageChunk;
use LangChain\Messages\ToolMessage;
use LangChain\Messages\ToolMessageChunk;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(MessageMerge::class)]
#[CoversClass(MessageUtils::class)]
final class MessageTest extends TestCase
{
    // ---- construction ----------------------------------------------------

    public function testHumanMessageFromString(): void
    {
        $m = new HumanMessage('hello');
        $this->assertSame('hello', $m->content);
        $this->assertSame('human', $m->getType());
        $this->assertSame('hello', $m->text());
    }

    public function testHumanMessageFromFieldMap(): void
    {
        $m = new HumanMessage([
            'content' => 'hi',
            'name' => 'alice',
            'id' => 'msg-1',
        ]);
        $this->assertSame('hi', $m->content);
        $this->assertSame('alice', $m->name);
        $this->assertSame('msg-1', $m->id);
    }

    public function testEmptyContentBecomesEmptyBlockList(): void
    {
        $m = new HumanMessage([]);
        $this->assertSame([], $m->content);
        $this->assertSame('', $m->text());
    }

    public function testMessageMetadataDefaultsToEmptyArrays(): void
    {
        $m = new HumanMessage('x');
        $this->assertSame([], $m->additional_kwargs);
        $this->assertSame([], $m->response_metadata);
    }

    public function testToolMessageCarriesCallId(): void
    {
        $m = new ToolMessage(['content' => '72F', 'tool_call_id' => 'call_9']);
        $this->assertSame('call_9', $m->toolCallId);
        $this->assertSame('tool', $m->getType());
    }

    public function testAIMessageCarriesToolCalls(): void
    {
        $m = new AIMessage([
            'content' => '',
            'tool_calls' => [['name' => 'get_weather', 'args' => ['city' => 'Austin'], 'id' => 'c1']],
        ]);
        $this->assertCount(1, $m->toolCalls);
        $this->assertSame('get_weather', $m->toolCalls[0]['name']);
    }

    public function testTextConcatenatesOnlyTextBlocks(): void
    {
        $m = new HumanMessage([
            ContentBlock::text('a'),
            ContentBlock::image('https://example.com/x.png'),
            ContentBlock::text('b'),
        ]);
        $this->assertSame('ab', $m->text());
    }

    // ---- serialization ---------------------------------------------------

    public function testToDictProducesStoredShape(): void
    {
        $m = new HumanMessage('hi');
        $d = $m->toDict();
        $this->assertSame('human', $d['type']);
        $this->assertSame('hi', $d['data']['content']);
    }

    public function testSerializedConstructorCarriesNamespaceId(): void
    {
        $m = new SystemMessage('sys');
        $json = $m->toJson();
        $this->assertSame(1, $json['lc']);
        $this->assertSame('constructor', $json['type']);
        $this->assertSame(['langchain_core', 'messages', 'SystemMessage'], $json['id']);
    }

    public function testStoredMessageRoundTrip(): void
    {
        $original = new ToolMessage(['content' => 'result', 'tool_call_id' => 'c7']);
        $restored = MessageUtils::mapStoredMessageToChatMessage($original->toDict());

        $this->assertInstanceOf(ToolMessage::class, $restored);
        $this->assertSame('result', $restored->content);
        $this->assertSame('c7', $restored->toolCallId);
    }

    public function testV1StoredMessageIsUpgraded(): void
    {
        $v1 = ['type' => 'human', 'role' => 'human', 'text' => 'legacy'];
        $m = MessageUtils::mapStoredMessageToChatMessage($v1);
        $this->assertInstanceOf(HumanMessage::class, $m);
        $this->assertSame('legacy', $m->content);
    }

    // ---- coercion --------------------------------------------------------

    #[DataProvider('messageLikeProvider')]
    public function testCoerceMessageLike(mixed $input, string $expectedClass, string $expectedText): void
    {
        $m = MessageUtils::coerceMessageLikeToMessage($input);
        $this->assertInstanceOf($expectedClass, $m);
        $this->assertSame($expectedText, $m->text());
    }

    /** @return iterable<string, array{0: mixed, 1: string, 2: string}> */
    public static function messageLikeProvider(): iterable
    {
        yield 'plain string' => ['hi', HumanMessage::class, 'hi'];
        yield 'role field map' => [['role' => 'system', 'content' => 'sys'], SystemMessage::class, 'sys'];
        yield 'assistant alias' => [['role' => 'assistant', 'content' => 'out'], AIMessage::class, 'out'];
        yield 'user alias' => [['role' => 'user', 'content' => 'q'], HumanMessage::class, 'q'];
    }

    public function testCoercePassesThroughMessageInstance(): void
    {
        $m = new HumanMessage('x');
        $this->assertSame($m, MessageUtils::coerceMessageLikeToMessage($m));
    }

    public function testCoerceRejectsUnusableValue(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MessageUtils::coerceMessageLikeToMessage(42);
    }

    // ---- buffer string ---------------------------------------------------

    public function testBufferStringRendersRoles(): void
    {
        $s = MessageUtils::getBufferString([
            new HumanMessage("What's the weather?"),
            new AIMessage('Let me check.'),
        ]);
        $this->assertSame("Human: What's the weather?\nAI: Let me check.", $s);
    }

    public function testBufferStringUsesCustomPrefixes(): void
    {
        $s = MessageUtils::getBufferString([new HumanMessage('q'), new AIMessage('a')], 'User', 'Bot');
        $this->assertSame("User: q\nBot: a", $s);
    }

    public function testBufferStringIncludesToolCallsForAiMessages(): void
    {
        $s = MessageUtils::getBufferString([
            new AIMessage(['content' => '', 'tool_calls' => [['name' => 'f', 'args' => ['a' => 1]]]]),
        ]);
        $this->assertStringContainsString('"name":"f"', $s);
    }

    public function testBufferStringTruncatesLongToolCallIds(): void
    {
        $longId = str_repeat('x', 100);
        $s = MessageUtils::getBufferString([
            new AIMessage(['content' => '', 'tool_calls' => [['name' => 'f', 'args' => [], 'id' => $longId]]]),
        ]);
        $this->assertStringNotContainsString($longId, $s);
        $this->assertStringContainsString('...', $s);
    }

    public function testBufferStringRendersMultimodalAsPlaceholders(): void
    {
        $s = MessageUtils::getBufferString([
            new HumanMessage([ContentBlock::text('see '), ContentBlock::image('https://x/y.png')]),
        ]);
        $this->assertSame('Human: see [image]', $s);
    }

    public function testBufferStringIncludesName(): void
    {
        $s = MessageUtils::getBufferString([new HumanMessage(['content' => 'q', 'name' => 'bob'])]);
        $this->assertSame('Human: bob, q', $s);
    }

    // ---- content merging -------------------------------------------------

    public function testMergeContentConcatenatesStrings(): void
    {
        $this->assertSame('ab', MessageMerge::mergeContent('a', 'b'));
    }

    public function testMergeContentEmptyFirstYieldsSecond(): void
    {
        $this->assertSame('b', MessageMerge::mergeContent('', 'b'));
    }

    public function testMergeContentPromotesStringToBlockList(): void
    {
        $merged = MessageMerge::mergeContent('a', [ContentBlock::text('b')]);
        $this->assertIsArray($merged);
        $this->assertCount(2, $merged);
        $this->assertSame('a', $merged[0]['text']);
    }

    public function testMergeContentKeepsDataBlockSourceType(): void
    {
        $merged = MessageMerge::mergeContent('a', [ContentBlock::data('AAAA', 'image/png')]);
        $this->assertIsArray($merged);
        $this->assertSame('text', $merged[0]['source_type']);
    }

    public function testMergeStatus(): void
    {
        $this->assertSame('error', MessageMerge::mergeStatus('error', 'success'));
        $this->assertSame('error', MessageMerge::mergeStatus('success', 'error'));
        $this->assertSame('success', MessageMerge::mergeStatus('success', 'success'));
        $this->assertSame('success', MessageMerge::mergeStatus(null, null));
    }

    // ---- dict/list merging -----------------------------------------------

    public function testMergeDictsConcatenatesStringsAndSumsNumbers(): void
    {
        $merged = MessageMerge::mergeDicts(['text' => 'a', 'n' => 1], ['text' => 'b', 'n' => 2]);
        $this->assertSame('ab', $merged['text']);
        $this->assertSame(3, $merged['n']);
    }

    public function testMergeDictsNeverMergesTypeField(): void
    {
        $merged = MessageMerge::mergeDicts(['type' => 'ai'], ['type' => 'ai_delta']);
        $this->assertSame('ai', $merged['type']);
    }

    public function testMergeDictsAdoptsIncomingIdentityFields(): void
    {
        $merged = MessageMerge::mergeDicts(
            ['id' => 'a', 'name' => 'x'],
            ['id' => 'b', 'name' => 'y']
        );
        $this->assertSame('b', $merged['id']);
        $this->assertSame('y', $merged['name']);
    }

    public function testMergeDictsPreservesIgnoredKeys(): void
    {
        $merged = MessageMerge::mergeDicts(['index' => 0], ['index' => 5]);
        $this->assertSame(0, $merged['index']);
    }

    public function testMergeDictsRejectsTypeChange(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('different type');
        MessageMerge::mergeDicts(['x' => 'a'], ['x' => 1]);
    }

    public function testMergeDictsHandlesNulls(): void
    {
        $this->assertNull(MessageMerge::mergeDicts(null, null));
        $this->assertSame(['a' => 1], MessageMerge::mergeDicts(['a' => 1], null));
        $this->assertSame(['a' => 1], MessageMerge::mergeDicts(null, ['a' => 1]));
    }

    public function testMergeListsAppendsDistinctItems(): void
    {
        $merged = MessageMerge::mergeLists(
            [['type' => 'text', 'text' => 'a']],
            [['type' => 'text', 'text' => 'b']]
        );
        $this->assertCount(2, $merged);
    }

    public function testMergeListsDropsEmptyTextDeltas(): void
    {
        $merged = MessageMerge::mergeLists(
            [['type' => 'text', 'text' => 'a']],
            [['type' => 'text', 'text' => '']]
        );
        $this->assertCount(1, $merged);
    }

    public function testMergeListsFoldsByIndex(): void
    {
        $merged = MessageMerge::mergeLists(
            [['index' => 0, 'name' => 'f', 'args' => '{"a"']],
            [['index' => 0, 'args' => ':1}']]
        );
        $this->assertCount(1, $merged);
        $this->assertSame('{"a":1}', $merged[0]['args']);
    }

    public function testMergeListsFoldsByIdWhenNoIndex(): void
    {
        $merged = MessageMerge::mergeLists(
            [['id' => 'c1', 'name' => 'f', 'args' => '{"a"']],
            [['id' => 'c1', 'args' => ':1}']]
        );
        $this->assertCount(1, $merged);
        $this->assertSame('{"a":1}', $merged[0]['args']);
    }

    public function testMergeListsRefusesToFoldMismatchedTypes(): void
    {
        $merged = MessageMerge::mergeLists(
            [['index' => 0, 'type' => 'tool_call', 'name' => 'f']],
            [['index' => 0, 'type' => 'reasoning', 'text' => 'why']]
        );
        $this->assertCount(2, $merged);
    }

    public function testMergeObjRoutesByType(): void
    {
        $this->assertSame('ab', MessageMerge::mergeObj('a', 'b'));
        $this->assertSame(['a' => 1], MessageMerge::mergeObj(null, ['a' => 1]));
    }

    public function testMergeObjRejectsMismatchedTypes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MessageMerge::mergeObj('a', 1);
    }

    // ---- chunk folding ----------------------------------------------------

    public function testAIChunkConcatFoldsIntoOneMessage(): void
    {
        $a = new AIMessageChunk(['content' => 'Hello']);
        $b = new AIMessageChunk(['content' => ' world']);
        $c = $a->concat($b);
        $this->assertSame('Hello world', $c->text());
    }

    public function testAIChunkAccumulatesToolCallArgs(): void
    {
        // Chunks arrive folded, exactly as a stream produces them.
        $chunk = (new AIMessageChunk([
            'content' => '',
            'tool_call_chunks' => [['index' => 0, 'id' => 'c1', 'name' => 'get_weather', 'args' => '{"city":']],
        ]))->concat(new AIMessageChunk([
            'content' => '',
            'tool_call_chunks' => [['index' => 0, 'args' => '"Austin"}']],
        ]));

        [$calls, $invalid] = $chunk->parseToolCalls();
        $this->assertCount(1, $calls);
        $this->assertCount(0, $invalid);
        $this->assertSame('get_weather', $calls[0]['name']);
        $this->assertSame(['city' => 'Austin'], $calls[0]['args']);
    }

    public function testAIChunkReportsUnparseableToolArgsAsInvalid(): void
    {
        $chunk = new AIMessageChunk([
            'content' => '',
            'tool_call_chunks' => [['index' => 0, 'name' => 'f', 'args' => '{not json']],
        ]);

        [$calls, $invalid] = $chunk->parseToolCalls();
        $this->assertCount(0, $calls);
        $this->assertCount(1, $invalid);
        $this->assertStringContainsString('Could not parse', $invalid[0]['error']);
    }

    public function testFoldedStreamReconstructsToolCall(): void
    {
        $chunks = [
            new AIMessageChunk(['content' => 'Let me check. ', 'tool_call_chunks' => [
                ['index' => 0, 'id' => 'c1', 'name' => 'get_weather', 'args' => '{"city":'],
            ]]),
            new AIMessageChunk(['content' => '', 'tool_call_chunks' => [
                ['index' => 0, 'args' => '"Austin"}'],
            ]]),
        ];
        $merged = array_reduce(
            array_slice($chunks, 1),
            static fn (AIMessageChunk $acc, AIMessageChunk $c): AIMessageChunk => $acc->concat($c),
            $chunks[0]
        );

        $this->assertSame('Let me check. ', $merged->text());
        [$calls] = $merged->parseToolCalls();
        $this->assertSame('get_weather', $calls[0]['name']);
    }

    public function testConvertToChunkRoundTripsToolCalls(): void
    {
        $msg = new AIMessage([
            'content' => 'x',
            'tool_calls' => [['name' => 'f', 'args' => ['a' => 1], 'id' => 'c1']],
        ]);
        $chunk = MessageUtils::convertToChunk($msg);
        $this->assertInstanceOf(AIMessageChunk::class, $chunk);
        [$calls] = $chunk->parseToolCalls();
        $this->assertSame('f', $calls[0]['name']);
    }

    public function testContentBlockGuards(): void
    {
        $this->assertTrue(ContentBlock::isText(ContentBlock::text('a')));
        $this->assertFalse(ContentBlock::isText(ContentBlock::image('u')));
        $this->assertTrue(ContentBlock::isData(ContentBlock::data('AA', 'image/png')));
        $this->assertTrue(ContentBlock::isUrl(ContentBlock::url('https://x')));
        $this->assertFalse(ContentBlock::isBlock('not a block'));
    }

    public function testChunkFoldRejectsAMismatchedChunkKind(): void
    {
        // PHP parameter types are contravariant, so concat() takes the shared
        // base and validates at runtime rather than silently folding.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('chunks of different message kinds do not merge');

        (new HumanMessageChunk(['content' => 'a']))->concat(new SystemMessageChunk(['content' => 'b']));
    }

    public function testToMessagePromotesFoldedChunk(): void
    {
        $chunk = (new AIMessageChunk(['content' => 'hi ', 'response_metadata' => ['finish_reason' => 'stop']]))
            ->concat(new AIMessageChunk(['content' => 'there']));

        $msg = $chunk->toMessage();
        $this->assertInstanceOf(AIMessage::class, $msg);
        $this->assertSame('hi there', $msg->text());
        $this->assertSame('stop', $msg->response_metadata['finish_reason']);
    }

    public function testFoldedChunkBecomesToolMessage(): void
    {
        $chunk = (new ToolMessageChunk(['content' => 're', 'tool_call_id' => 'c1']))
            ->concat(new ToolMessageChunk(['content' => 'sult']));
        $msg = $chunk->toMessage();
        $this->assertInstanceOf(ToolMessage::class, $msg);
        $this->assertSame('result', $msg->text());
        $this->assertSame('c1', $msg->toolCallId);
    }

    /**
     * `getBufferString()` must render a ChatMessage by its ROLE, not throw.
     *
     * Upstream gives ChatMessage the type "generic" and reads the role off the
     * instance (`role = (m as ChatMessage).role`, messages/utils.ts:390-391). This
     * port sets `type` to the ROLE, so matching on `type` against ROLE_CHAT never
     * fired and every chat message threw "Got unsupported message type: user".
     *
     * A FunctionMessage still throws, and that is FAITHFUL — upstream has no
     * "function" arm either — so the second case pins that we did NOT over-correct
     * by making every type render.
     */
    public function testBufferStringRendersChatMessagesByRoleAndStillRefusesFunction(): void
    {
        $chat = new \LangChain\Messages\ChatMessage(['role' => 'user', 'content' => 'c']);
        self::assertSame('user: c', \LangChain\Messages\MessageUtils::getBufferString([$chat]));

        $assistant = new \LangChain\Messages\ChatMessage(['role' => 'assistant', 'content' => 'a']);
        self::assertSame('assistant: a', \LangChain\Messages\MessageUtils::getBufferString([$assistant]));

        // A NAMED chat message renders the role and the name SEPARATELY. An earlier
        // version of the fix used `$m->name` for the role and produced
        // "alice: alice, c"; this assertion is the one that would have caught it,
        // and it is here because the unnamed case could not: with no name, role and
        // name agree and the two forms are indistinguishable.
        $named = new \LangChain\Messages\ChatMessage(['role' => 'user', 'content' => 'c', 'name' => 'alice']);
        self::assertSame('user: alice, c', \LangChain\Messages\MessageUtils::getBufferString([$named]));

        $this->expectException(\InvalidArgumentException::class);
        \LangChain\Messages\MessageUtils::getBufferString([
            new \LangChain\Messages\FunctionMessage('fn', 'r'),
        ]);
    }
}
