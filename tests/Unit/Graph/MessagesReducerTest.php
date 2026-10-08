<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Graph;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\RemoveMessage;
use LangChain\Messages\SystemMessage;
use LangGraph\Graph\MessagesReducer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `langgraph-core/src/graph/messages_reducer.test.ts`, plus the
 * `messagesDeltaReducer` cases (upstream covers it from `delta_channel.test.ts`,
 * which needs `DeltaChannel`; here it is tested as the pure function it is).
 */
#[CoversClass(MessagesReducer::class)]
#[CoversClass(RemoveMessage::class)]
final class MessagesReducerTest extends TestCase
{
    private static function human(string $id, string $content): HumanMessage
    {
        return new HumanMessage(['id' => $id, 'content' => $content]);
    }

    private static function ai(string $id, string $content): AIMessage
    {
        return new AIMessage(['id' => $id, 'content' => $content]);
    }

    private static function reduce(mixed $left, mixed $right): array
    {
        return MessagesReducer::messagesStateReducer($left, $right);
    }

    // ---- messagesStateReducer ----------------------------------------------

    public function testShouldAddASingleMessage(): void
    {
        $result = self::reduce([self::human('1', 'Hello')], self::ai('2', 'Hi there!'));

        self::assertEquals([self::human('1', 'Hello'), self::ai('2', 'Hi there!')], $result);
    }

    public function testShouldAddMultipleMessages(): void
    {
        $result = self::reduce(
            [self::human('1', 'Hello')],
            [self::ai('2', 'Hi there!'), new SystemMessage(['id' => '3', 'content' => 'System message'])],
        );

        self::assertEquals([
            self::human('1', 'Hello'),
            self::ai('2', 'Hi there!'),
            new SystemMessage(['id' => '3', 'content' => 'System message']),
        ], $result);
    }

    public function testShouldUpdateAnExistingMessage(): void
    {
        $result = self::reduce([self::human('1', 'Hello')], self::human('1', 'Hello again'));

        self::assertEquals([self::human('1', 'Hello again')], $result);
    }

    public function testShouldAssignMissingIds(): void
    {
        $result = self::reduce([new HumanMessage('Hello')], [new AIMessage('Hi there!')]);

        self::assertCount(2, $result);
        foreach ($result as $message) {
            self::assertIsString($message->id);
            self::assertNotSame('', $message->id);
        }
    }

    public function testAssignedIdsAreAlsoRecordedForSerialisation(): void
    {
        [$message] = self::reduce([], [new HumanMessage('Hello')]);

        self::assertSame($message->id, $message->kwargs()['id']);
    }

    public function testAssignedIdsAreDistinct(): void
    {
        $result = self::reduce([new HumanMessage('a')], [new HumanMessage('b'), new HumanMessage('c')]);

        self::assertCount(3, array_unique(array_map(static fn (BaseMessage $m): ?string => $m->id, $result)));
    }

    public function testShouldHandleDuplicatesInInput(): void
    {
        $result = self::reduce([], [self::ai('1', 'Hi there!'), self::ai('1', 'Hi there again!')]);

        self::assertCount(1, $result);
        self::assertSame('1', $result[0]->id);
        self::assertSame('Hi there again!', $result[0]->content);
    }

    public function testShouldHandleDuplicatesWithRemove(): void
    {
        $result = self::reduce(
            [self::ai('1', 'Hello!')],
            [new RemoveMessage(['id' => '1']), self::ai('1', 'Hi there!'), self::ai('1', 'Hi there again!')],
        );

        self::assertCount(1, $result);
        self::assertSame('1', $result[0]->id);
        self::assertSame('Hi there again!', $result[0]->content);
    }

    public function testShouldRemoveAMessage(): void
    {
        $result = self::reduce(
            [self::human('1', 'Hello'), self::ai('2', 'Hi there!')],
            new RemoveMessage(['id' => '2']),
        );

        self::assertEquals([self::human('1', 'Hello')], $result);
    }

    public function testShouldHandleDuplicateRemoveMessages(): void
    {
        $result = self::reduce(
            [self::human('1', 'Hello'), self::ai('2', 'Hi there!')],
            [new RemoveMessage(['id' => '2']), new RemoveMessage(['id' => '2'])],
        );

        self::assertEquals([self::human('1', 'Hello')], $result);
    }

    public function testShouldThrowOnRemovingANonexistentMessage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Attempting to delete a message with an ID that doesn't exist");

        self::reduce([self::human('1', 'Hello')], new RemoveMessage(['id' => '2']));
    }

    public function testShouldHandleMixedOperations(): void
    {
        $result = self::reduce(
            [self::human('1', 'Hello'), self::ai('2', 'Hi there!')],
            [
                self::human('1', 'Updated hello'),
                new RemoveMessage(['id' => '2']),
                new SystemMessage(['id' => '3', 'content' => 'New message']),
            ],
        );

        self::assertEquals([
            self::human('1', 'Updated hello'),
            new SystemMessage(['id' => '3', 'content' => 'New message']),
        ], $result);
    }

    public function testShouldHandleEmptyInputs(): void
    {
        self::assertSame([], self::reduce([], []));
        self::assertEquals([self::human('1', 'Hello')], self::reduce([], [self::human('1', 'Hello')]));
        self::assertEquals([self::human('1', 'Hello')], self::reduce([self::human('1', 'Hello')], []));
    }

    public function testShouldHandleNonArrayInputs(): void
    {
        $result = self::reduce(self::human('1', 'Hello'), self::ai('2', 'Hi there!'));

        self::assertEquals([self::human('1', 'Hello'), self::ai('2', 'Hi there!')], $result);
    }

    public function testShouldRemoveAllMessages(): void
    {
        // simple removal
        self::assertSame([], self::reduce(
            [new HumanMessage('Hello'), new AIMessage('Hi there!')],
            [new RemoveMessage(['id' => MessagesReducer::REMOVE_ALL_MESSAGES])],
        ));

        // removal and update (i.e. overwriting)
        self::assertEquals(
            [self::human('1', 'Updated hello')],
            self::reduce(
                [new HumanMessage('Hello'), new AIMessage('Hi there!')],
                [new RemoveMessage(['id' => MessagesReducer::REMOVE_ALL_MESSAGES]), self::human('1', 'Updated hello')],
            ),
        );

        // removing preceding messages in the right list
        self::assertEquals(
            [self::human('1', 'Updated hi there')],
            self::reduce(
                [new HumanMessage('Hello'), new AIMessage('Hi there!')],
                [
                    new HumanMessage('Updated hello'),
                    new RemoveMessage(['id' => MessagesReducer::REMOVE_ALL_MESSAGES]),
                    self::human('1', 'Updated hi there'),
                ],
            ),
        );
    }

    public function testTheLastRemoveAllMarkerWins(): void
    {
        $result = self::reduce([self::human('1', 'a')], [
            self::human('2', 'b'),
            new RemoveMessage(['id' => MessagesReducer::REMOVE_ALL_MESSAGES]),
            self::human('3', 'c'),
            new RemoveMessage(['id' => MessagesReducer::REMOVE_ALL_MESSAGES]),
            self::human('4', 'd'),
        ]);

        self::assertEquals([self::human('4', 'd')], $result);
    }

    public function testMessageLikesAreCoerced(): void
    {
        $result = self::reduce([], ['hello', ['role' => 'assistant', 'content' => 'hi', 'id' => 'a']]);

        self::assertInstanceOf(HumanMessage::class, $result[0]);
        self::assertSame('hello', $result[0]->content);
        self::assertInstanceOf(AIMessage::class, $result[1]);
    }

    public function testAFieldMapIsOneMessageNotAList(): void
    {
        $result = self::reduce([], ['role' => 'user', 'content' => 'hi', 'id' => 'u']);

        self::assertCount(1, $result);
        self::assertSame('u', $result[0]->id);
    }

    public function testTheInputListsAreNotMutatedStructurally(): void
    {
        $left = [self::human('1', 'Hello')];
        $right = [self::ai('2', 'Hi')];

        self::reduce($left, $right);

        self::assertCount(1, $left);
        self::assertCount(1, $right);
    }

    public function testAMessageReplacedInPlaceKeepsItsPosition(): void
    {
        $result = self::reduce(
            [self::human('1', 'a'), self::human('2', 'b'), self::human('3', 'c')],
            [self::human('2', 'B')],
        );

        self::assertSame(['a', 'B', 'c'], array_map(static fn (BaseMessage $m): string => $m->text(), $result));
    }

    // ---- messagesDeltaReducer --------------------------------------------

    public function testDeltaReducerAppendsAndReplacesByIdAcrossABatch(): void
    {
        $state = [self::human('1', 'a'), self::ai('2', 'b')];

        $result = MessagesReducer::messagesDeltaReducer($state, [
            [self::human('1', 'A')],
            self::ai('3', 'c'),
        ]);

        self::assertSame(['A', 'b', 'c'], array_map(static fn (BaseMessage $m): string => $m->text(), $result));
    }

    public function testDeltaReducerRemovesById(): void
    {
        $result = MessagesReducer::messagesDeltaReducer(
            [self::human('1', 'a'), self::ai('2', 'b')],
            [new RemoveMessage(['id' => '1'])],
        );

        self::assertEquals([self::ai('2', 'b')], $result);
    }

    public function testDeltaReducerIgnoresARemoveOfAnUnknownIdUnlikeTheStateReducer(): void
    {
        $result = MessagesReducer::messagesDeltaReducer([self::human('1', 'a')], [new RemoveMessage(['id' => 'nope'])]);

        self::assertEquals([self::human('1', 'a')], $result);
    }

    public function testDeltaReducerDoesNotAssignMissingIds(): void
    {
        $result = MessagesReducer::messagesDeltaReducer([], [new HumanMessage('x')]);

        self::assertNull($result[0]->id);
    }

    public function testDeltaReducerKeepsEveryMessageWithoutAnId(): void
    {
        $result = MessagesReducer::messagesDeltaReducer([], [new HumanMessage('x'), new HumanMessage('x')]);

        self::assertCount(2, $result);
    }

    public function testDeltaReducerRemoveAllClearsPriorStateAndEarlierWritesOnly(): void
    {
        $result = MessagesReducer::messagesDeltaReducer(
            [self::human('1', 'a')],
            [
                self::ai('2', 'b'),
                new RemoveMessage(['id' => MessagesReducer::REMOVE_ALL_MESSAGES]),
                self::ai('3', 'c'),
            ],
        );

        self::assertEquals([self::ai('3', 'c')], $result);
    }

    public function testDeltaReducerIsBatchingInvariant(): void
    {
        $state = [self::human('1', 'a'), self::ai('2', 'b')];
        $xs = [self::human('1', 'A'), new RemoveMessage(['id' => '2'])];
        $ys = [self::ai('3', 'c'), self::human('1', 'AA')];

        $oneShot = MessagesReducer::messagesDeltaReducer($state, array_merge($xs, $ys));
        $stepwise = MessagesReducer::messagesDeltaReducer(MessagesReducer::messagesDeltaReducer($state, $xs), $ys);

        self::assertEquals($oneShot, $stepwise);
    }

    public function testDeltaReducerCoercesRawInputs(): void
    {
        $result = MessagesReducer::messagesDeltaReducer(
            [['role' => 'user', 'content' => 'hi', 'id' => 'u']],
            ['plain string'],
        );

        self::assertInstanceOf(HumanMessage::class, $result[0]);
        self::assertSame('u', $result[0]->id);
        self::assertSame('plain string', $result[1]->content);
    }

    // ---- RemoveMessage -----------------------------------------------------

    public function testRemoveMessageRequiresAnId(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RemoveMessage(['content' => '']);
    }

    public function testRemoveMessageCarriesNoContentAndTheRemoveType(): void
    {
        $remove = new RemoveMessage(['id' => 'x']);

        self::assertSame('x', $remove->id);
        self::assertSame('', $remove->content);
        self::assertSame('remove', $remove->getType());
        self::assertTrue(RemoveMessage::isInstance($remove));
        self::assertFalse(RemoveMessage::isInstance(new HumanMessage('x')));
        self::assertFalse(RemoveMessage::isInstance(['id' => 'x']));
    }
}
