<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Messages\AIMessage;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableBinding;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use LangChain\Runnables\RunnableSequence;
use LangChain\Tests\Unit\Agents\Support\FakeConfigurableModel;
use LangChain\Tests\Unit\Agents\Support\FakeToolCallingChatModel;
use LangChain\Tools\Schema;
use LangGraph\Agents\Errors\MiddlewareError;
use LangGraph\Agents\Errors\MultipleToolsBoundError;
use LangGraph\Agents\Utils;
use LangGraph\Pregel\Command;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * Port of `langchain/src/agents/tests/utils.test.ts` (`_addInlineAgentName`, `_removeInlineAgentName`,
 * `bindTools`), plus the helpers of `agents/utils.ts` that have no upstream test of their own.
 */
#[CoversClass(Utils::class)]
final class UtilsTest extends TestCase
{
    private static function image(): array
    {
        return ['type' => 'image', 'image_url' => 'http://example.com/image.jpg'];
    }

    // ---- _addInlineAgentName -------------------------------------------------------------------

    public function testAddReturnsNonAiMessagesUnchanged(): void
    {
        $human = new HumanMessage('Hello');

        self::assertSame($human, Utils::addInlineAgentName($human));
    }

    public function testAddReturnsAiMessagesWithNoNameUnchanged(): void
    {
        $ai = new AIMessage('Hello world');

        self::assertSame($ai, Utils::addInlineAgentName($ai));
    }

    public function testAddFormatsAiMessagesWithNameAndContentTags(): void
    {
        $result = Utils::addInlineAgentName(new AIMessage(['content' => 'Hello world', 'name' => 'assistant']));

        self::assertSame('<name>assistant</name><content>Hello world</content>', $result->content);
        self::assertNull($result->name);
    }

    public function testAddHandlesContentBlocks(): void
    {
        $result = Utils::addInlineAgentName(new AIMessage([
            'content' => [['type' => 'text', 'text' => 'Hello world'], self::image()],
            'name' => 'assistant',
        ]));

        self::assertSame([
            ['type' => 'text', 'text' => '<name>assistant</name><content>Hello world</content>'],
            self::image(),
        ], $result->content);
    }

    public function testAddHandlesContentBlocksWithoutTextBlocks(): void
    {
        $blocks = [self::image(), ['type' => 'file', 'file_url' => 'http://example.com/document.pdf']];

        $result = Utils::addInlineAgentName(new AIMessage(['content' => $blocks, 'name' => 'assistant']));

        self::assertSame([
            ['type' => 'text', 'text' => '<name>assistant</name><content></content>'],
            ...$blocks,
        ], $result->content);
    }

    public function testAddHandlesContentBlocksThatAreStrings(): void
    {
        $result = Utils::addInlineAgentName(new AIMessage([
            'name' => 'test-agent',
            'content' => ['<name>test-agent</name><content>Hello world</content>'],
        ]));

        self::assertInstanceOf(AIMessage::class, $result);
        self::assertSame(
            ['<name>test-agent</name><content><name>test-agent</name><content>Hello world</content></content>'],
            $result->content,
        );
    }

    public function testAddKeepsEveryOtherFieldOfTheMessage(): void
    {
        $result = Utils::addInlineAgentName(new AIMessage([
            'content' => 'hi',
            'name' => 'bob',
            'id' => 'm1',
            'additional_kwargs' => ['k' => 'v'],
            'tool_calls' => [['id' => 'c1', 'name' => 'search', 'args' => ['q' => 'x']]],
        ]));

        self::assertSame('m1', $result->id);
        self::assertSame(['k' => 'v'], $result->additional_kwargs);
        self::assertSame('c1', $result->toolCalls[0]['id']);
    }

    public function testAddLeavesStreamedChunksAlone(): void
    {
        $chunk = new AIMessageChunk(['content' => 'partial', 'name' => 'bob']);

        self::assertSame($chunk, Utils::addInlineAgentName($chunk));
    }

    // ---- _removeInlineAgentName ----------------------------------------------------------------

    public function testRemoveReturnsNonAiMessagesUnchanged(): void
    {
        $human = new HumanMessage('<name>test</name><content>Hello</content>');

        self::assertSame($human, Utils::removeInlineAgentName($human));
    }

    public function testRemoveReturnsMessagesWithEmptyContentUnchanged(): void
    {
        $ai = new AIMessage(['content' => '', 'name' => 'assistant']);

        self::assertSame($ai, Utils::removeInlineAgentName($ai));
    }

    public function testRemoveReturnsMessagesWithoutTagsUnchanged(): void
    {
        $ai = new AIMessage(['content' => 'Hello world', 'name' => 'assistant']);

        self::assertSame($ai, Utils::removeInlineAgentName($ai));
    }

    public function testRemoveHandlesMalformedTagsGracefully(): void
    {
        $ai = new AIMessage('<name>test-agent</name>Hello without proper closing');

        self::assertSame($ai, Utils::removeInlineAgentName($ai));
    }

    public function testRemoveExtractsContentFromTags(): void
    {
        $result = Utils::removeInlineAgentName(new AIMessage([
            'content' => '<name>assistant</name><content>Hello world</content>',
            'name' => 'assistant',
        ]));

        self::assertSame('Hello world', $result->content);
        self::assertSame('assistant', $result->name);
    }

    public function testRemoveRecoversTheNameFromTagsAlone(): void
    {
        $result = Utils::removeInlineAgentName(new AIMessage('<name>test-agent</name><content>Hello world</content>'));

        self::assertInstanceOf(AIMessage::class, $result);
        self::assertSame('Hello world', $result->content);
        self::assertSame('test-agent', $result->name);
    }

    public function testRemoveHandlesContentBlocks(): void
    {
        $result = Utils::removeInlineAgentName(new AIMessage([
            'content' => [['type' => 'text', 'text' => '<name>assistant</name><content>Hello world</content>'], self::image()],
            'name' => 'assistant',
        ]));

        self::assertSame([['type' => 'text', 'text' => 'Hello world'], self::image()], $result->content);
        self::assertSame('assistant', $result->name);
    }

    public function testRemoveHandlesContentBlocksWithEmptyTextContent(): void
    {
        $blocks = [
            ['type' => 'text', 'text' => '<name>assistant</name><content></content>'],
            self::image(),
            ['type' => 'file', 'file_url' => 'http://example.com/document.pdf'],
        ];

        $result = Utils::removeInlineAgentName(new AIMessage(['content' => $blocks, 'name' => 'assistant']));

        self::assertSame(array_slice($blocks, 1), $result->content);
        self::assertSame('assistant', $result->name);
    }

    public function testRemovePassesArrayContentWithoutTagsThrough(): void
    {
        $blocks = [['type' => 'text', 'text' => 'Hello world'], self::image()];

        $result = Utils::removeInlineAgentName(new AIMessage(['content' => $blocks]));

        self::assertInstanceOf(AIMessage::class, $result);
        self::assertSame($blocks, $result->content);
    }

    public function testRemoveHandlesMultilineContent(): void
    {
        $result = Utils::removeInlineAgentName(new AIMessage([
            'content' => "<name>assistant</name><content>This is\na multiline\nmessage</content>",
            'name' => 'assistant',
        ]));

        self::assertSame("This is\na multiline\nmessage", $result->content);
    }

    public function testAddThenRemoveRoundTrips(): void
    {
        $original = new AIMessage(['content' => 'Hello world', 'name' => 'assistant', 'id' => 'm1']);

        $back = Utils::removeInlineAgentName(Utils::addInlineAgentName($original));

        self::assertSame('Hello world', $back->content);
        self::assertSame('assistant', $back->name);
        self::assertSame('m1', $back->id);
    }

    // ---- bindTools -----------------------------------------------------------------------------

    private static function tool1(): \LangChain\Tools\StructuredTool
    {
        return tool(
            static fn (array $in): string => 'Tool 1: ' . $in['someVal'],
            [
                'name' => 'tool1',
                'description' => 'Tool 1 docstring.',
                'schema' => Schema::object(['someVal' => ['type' => 'number', 'description' => 'Input value']], ['someVal']),
            ],
        );
    }

    public function testBindToolsBindsEveryModelShape(): void
    {
        $tool1 = self::tool1();
        $model = new FakeToolCallingChatModel(['responses' => [new AIMessage('test')]]);
        $confModel = new FakeConfigurableModel(['model' => $model]);
        $identity = RunnableLambda::from(static fn (mixed $message): mixed => $message);

        // A regular model.
        self::assertEquals($model->bindTools([$tool1]), Utils::bindTools($model, [$tool1]));

        // A model wrapped in `withConfig`.
        self::assertEquals(
            $model->bindTools([$tool1])->withConfig(['tags' => ['nostream']]),
            Utils::bindTools($model->withConfig(['tags' => ['nostream']]), [$tool1]),
        );

        // A model wrapped in several `withConfig`s.
        self::assertEquals(
            $model->bindTools([$tool1])->withConfig(['tags' => ['nostream']])->withConfig(['metadata' => ['hello' => 'world']]),
            Utils::bindTools($model->withConfig(['tags' => ['nostream']])->withConfig(['metadata' => ['hello' => 'world']]), [$tool1]),
        );

        // A configurable model.
        self::assertEquals($confModel->bindTools([$tool1]), Utils::bindTools($confModel, [$tool1]));

        // A sequence.
        self::assertEquals(
            RunnableSequence::from([$model->bindTools([$tool1]), $identity]),
            Utils::bindTools(RunnableSequence::from([$model, $identity]), [$tool1]),
        );

        // A sequence with a configurable model.
        self::assertEquals(
            RunnableSequence::from([$confModel->bindTools([$tool1]), $identity]),
            Utils::bindTools(RunnableSequence::from([$confModel, $identity]), [$tool1]),
        );

        // A sequence with a configured configurable model.
        self::assertEquals(
            RunnableSequence::from([$confModel->bindTools([$tool1])->withConfig(['tags' => ['nostream']]), $identity]),
            Utils::bindTools(RunnableSequence::from([$confModel->withConfig(['tags' => ['nostream']]), $identity]), [$tool1]),
        );
    }

    public function testBindToolsActuallyBindsTheToolToTheModel(): void
    {
        $bound = Utils::bindTools(new FakeToolCallingChatModel(), [self::tool1()]);

        self::assertSame([['type' => 'function', 'function' => ['name' => 'tool1']]], $bound->tools);
    }

    public function testBindToolsThrowsWhenNothingCanBindTools(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('must define bindTools method');

        Utils::bindTools(RunnableLambda::from(static fn (mixed $x): mixed => $x), [self::tool1()]);
    }

    // ---- validateLLMHasNoBoundTools ------------------------------------------------------------

    public function testAModelWithBoundToolsIsRejected(): void
    {
        $bound = (new FakeToolCallingChatModel())->bindTools([self::tool1()]);

        $this->expectException(MultipleToolsBoundError::class);

        Utils::validateLLMHasNoBoundTools($bound);
    }

    public function testABindingWithToolsInItsKwargsIsRejected(): void
    {
        $this->expectException(MultipleToolsBoundError::class);

        Utils::validateLLMHasNoBoundTools(new RunnableBinding(new FakeToolCallingChatModel(), ['tools' => [['name' => 't']]]));
    }

    public function testABindingFoundInsideASequenceIsChecked(): void
    {
        $binding = new RunnableBinding(new FakeToolCallingChatModel(), [], ['tools' => [['name' => 't']]]);

        $this->expectException(MultipleToolsBoundError::class);

        Utils::validateLLMHasNoBoundTools(RunnableSequence::from([RunnableLambda::from(static fn (mixed $x): mixed => $x), $binding]));
    }

    public function testCleanModelsConfigurableModelsAndCallablesPass(): void
    {
        $model = new FakeToolCallingChatModel();
        Utils::validateLLMHasNoBoundTools($model);
        Utils::validateLLMHasNoBoundTools(new RunnableBinding($model, ['temperature' => 0]));
        Utils::validateLLMHasNoBoundTools(new FakeConfigurableModel(['model' => $model->bindTools([self::tool1()])]));
        Utils::validateLLMHasNoBoundTools(static fn (): FakeToolCallingChatModel => new FakeToolCallingChatModel());

        $this->addToAssertionCount(1);
    }

    // ---- small helpers -------------------------------------------------------------------------

    public function testHasToolCallsIsTrueOnlyForAiMessagesWithCalls(): void
    {
        self::assertTrue(Utils::hasToolCalls(new AIMessage(['content' => '', 'tool_calls' => [['id' => '1', 'name' => 't', 'args' => []]]])));
        self::assertFalse(Utils::hasToolCalls(new AIMessage('no calls')));
        self::assertFalse(Utils::hasToolCalls(new HumanMessage('hi')));
        self::assertFalse(Utils::hasToolCalls(null));
        self::assertFalse(Utils::hasToolCalls());
    }

    public function testNormalizeSystemPrompt(): void
    {
        $empty = Utils::normalizeSystemPrompt(null);
        self::assertInstanceOf(SystemMessage::class, $empty);
        self::assertSame('', $empty->content);

        $given = new SystemMessage('be nice');
        self::assertSame($given, Utils::normalizeSystemPrompt($given));

        $fromString = Utils::normalizeSystemPrompt('be brief');
        self::assertSame([['type' => 'text', 'text' => 'be brief']], $fromString->content);
    }

    public function testIsClientToolMeansRunnable(): void
    {
        self::assertTrue(Utils::isClientTool(self::tool1()));
        self::assertFalse(Utils::isClientTool(['type' => 'web_search']));
    }

    public function testToGraphDefaultConfigKeepsOnlyWhatWasSet(): void
    {
        self::assertSame([], Utils::toGraphDefaultConfig(new RunnableConfig()));

        $config = new RunnableConfig(
            tags: ['a'],
            metadata: ['k' => 'v'],
            recursionLimit: 7,
            configurable: ['thread_id' => 't'],
            options: ['ignored' => true],
        );

        self::assertSame(
            ['tags' => ['a'], 'metadata' => ['k' => 'v'], 'recursionLimit' => 7, 'configurable' => ['thread_id' => 't']],
            Utils::toGraphDefaultConfig($config),
        );
    }

    public function testParseMiddlewareStateKeepsOnlyDeclaredKeys(): void
    {
        $schema = ['type' => 'object', 'properties' => ['counter' => ['type' => 'number'], '_private' => []]];

        self::assertSame(
            ['counter' => 2],
            Utils::parseMiddlewareState($schema, ['counter' => 2, 'messages' => [], 'other' => 1]),
        );
    }

    public function testParseMiddlewareStateRejectsAnInvalidSchema(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid state schema type');

        Utils::parseMiddlewareState('nope', []);
    }

    // ---- wrapToolCall --------------------------------------------------------------------------

    private static function request(array $state = ['messages' => [], 'a' => 1, 'b' => 2]): array
    {
        return [
            'toolCall' => ['id' => '1', 'name' => 'tool1', 'args' => []],
            'tool' => null,
            'state' => $state,
            'runtime' => null,
        ];
    }

    private static function okHandler(): callable
    {
        return static fn (array $request): ToolMessage => new ToolMessage([
            'content' => 'ok:' . json_encode($request['state']),
            'tool_call_id' => '1',
        ]);
    }

    public function testWrapToolCallReturnsNullWithoutHooks(): void
    {
        self::assertNull(Utils::wrapToolCall([]));
        self::assertNull(Utils::wrapToolCall([['name' => 'no-hook']]));
    }

    public function testWrapToolCallComposesFirstMiddlewareOutermost(): void
    {
        $order = [];
        $make = static function (string $name) use (&$order): array {
            return ['name' => $name, 'wrapToolCall' => static function (array $request, callable $handler) use ($name, &$order): mixed {
                $order[] = $name . ':in';
                $result = $handler($request);
                $order[] = $name . ':out';

                return $result;
            }];
        };

        $wrapped = Utils::wrapToolCall([$make('auth'), $make('retry')]);
        $result = $wrapped(self::request(), self::okHandler());

        self::assertInstanceOf(ToolMessage::class, $result);
        self::assertSame(['auth:in', 'retry:in', 'retry:out', 'auth:out'], $order);
    }

    public function testEachMiddlewareSeesItsOwnStateSliceButTheHandlerGetsTheMergedState(): void
    {
        $seen = [];
        $middleware = [
            'name' => 'slicer',
            'stateSchema' => ['type' => 'object', 'properties' => ['a' => []]],
            'wrapToolCall' => static function (array $request, callable $handler) use (&$seen): mixed {
                $seen = $request['state'];

                return $handler($request);
            },
        ];

        $result = Utils::wrapToolCall([$middleware])(self::request(), self::okHandler());

        self::assertSame(['messages' => [], 'a' => 1], $seen);
        // The base handler still sees the full original state.
        self::assertSame('ok:{"messages":[],"a":1,"b":2}', $result->content);
    }

    public function testAnInvalidWrapToolCallResultIsRejectedNamingTheMiddleware(): void
    {
        $wrapped = Utils::wrapToolCall([['name' => 'bad', 'wrapToolCall' => static fn (): string => 'oops']]);

        try {
            $wrapped(self::request(), self::okHandler());
            self::fail('expected a MiddlewareError');
        } catch (MiddlewareError $e) {
            self::assertStringContainsString('Invalid response from "wrapToolCall" in middleware "bad"', $e->getMessage());
            self::assertStringContainsString('expected ToolMessage or Command, got string', $e->getMessage());
        }
    }

    public function testACommandResultIsAccepted(): void
    {
        $command = new Command(goto: 'bob');
        $wrapped = Utils::wrapToolCall([['name' => 'router', 'wrapToolCall' => static fn (): Command => $command]]);

        self::assertSame($command, $wrapped(self::request(), self::okHandler()));
    }

    public function testAnErrorFromTheMiddlewareItselfIsWrappedWithItsName(): void
    {
        $wrapped = Utils::wrapToolCall([['name' => 'boom', 'wrapToolCall' => static function (): never {
            throw new \RuntimeException('exploded');
        }]]);

        try {
            $wrapped(self::request(), self::okHandler());
            self::fail('expected a MiddlewareError');
        } catch (MiddlewareError $e) {
            self::assertSame('exploded', $e->getMessage());
            self::assertSame('RuntimeException', $e->errorName);
            self::assertInstanceOf(\RuntimeException::class, $e->getPrevious());
        }
    }

    public function testAnErrorThrownDownstreamPropagatesUnchanged(): void
    {
        $original = new \RuntimeException('from the tool');
        $wrapped = Utils::wrapToolCall([['name' => 'passthrough', 'wrapToolCall' => static fn (array $r, callable $h): mixed => $h($r)]]);

        try {
            $wrapped(self::request(), static function () use ($original): never {
                throw $original;
            });
            self::fail('expected the original error');
        } catch (\Throwable $e) {
            self::assertSame($original, $e);
        }
    }
}
