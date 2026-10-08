<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Utils\Testing;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tools\Schema;
use LangChain\Tracers\CallbackHandler;
use LangChain\Utils\Testing\FakeToolCallingChatModel;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * The double itself: `FakeToolCallingChatModel` from `langgraph-core/src/tests/utils.models.ts`.
 *
 * Upstream has no test of its own for it (it is exercised through `prebuilt.test.ts`), so these pin the contract
 * those tests rely on: replay and cycling, echo with no script, the four tool styles, shared progress between a model
 * and the models bound from it, and the structured-output stand-in.
 */
#[CoversClass(FakeToolCallingChatModel::class)]
final class FakeToolCallingChatModelTest extends TestCase
{
    private static function fake(array $responses = [], array $extra = []): FakeToolCallingChatModel
    {
        return new FakeToolCallingChatModel(['sleep' => 0, 'responses' => $responses] + $extra);
    }

    private static function tool(string $name): \LangChain\Tools\StructuredTool
    {
        return tool(static fn (): string => 'ok', ['name' => $name, 'description' => 'd', 'schema' => Schema::object([])]);
    }

    public function testItIsAChatModel(): void
    {
        $model = self::fake();

        self::assertInstanceOf(BaseChatModel::class, $model);
        self::assertSame('fake', $model->llmType());
        self::assertSame('chat', $model->modelType());
    }

    public function testReplaysScriptedResponsesInOrderAndCycles(): void
    {
        $model = self::fake([new AIMessage('one'), new AIMessage('two')]);

        $got = [];
        for ($i = 0; $i < 5; $i++) {
            $got[] = $model->invoke([new HumanMessage('hi')])->content;
        }

        self::assertSame(['one', 'two', 'one', 'two', 'one'], $got);
        self::assertSame(5, $model->idx);
    }

    public function testEchoesItsInputMessagesWhenNothingIsScripted(): void
    {
        $model = self::fake();

        $first = $model->invoke([new HumanMessage('a'), new HumanMessage('b')]);
        $second = $model->invoke([new HumanMessage('a'), new HumanMessage('b')]);

        self::assertSame('a', $first->content);
        self::assertSame('b', $second->content);
    }

    public function testAnEmptyScriptEchoesLikeNoScript(): void
    {
        self::assertSame('hi', self::fake([])->invoke([new HumanMessage('hi')])->content);
    }

    public function testToolCallsInAResponseSurviveAsTheyWereScripted(): void
    {
        $calls = [['name' => 'search_api', 'id' => 'c1', 'args' => ['query' => 'foo']]];
        $model = self::fake([new AIMessage(['content' => 'x', 'tool_calls' => $calls])]);

        $out = $model->invoke([new HumanMessage('hi')]);

        self::assertSame('x', $out->content);
        self::assertSame($calls, $out->toolCalls);
    }

    public function testThrowsTheConfiguredError(): void
    {
        $model = self::fake([new AIMessage('x')], ['thrownErrorString' => 'boom']);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('boom');

        $model->invoke([new HumanMessage('hi')]);
    }

    public function testSleepsBeforeReplying(): void
    {
        $model = new FakeToolCallingChatModel(['sleep' => 30, 'responses' => [new AIMessage('x')]]);

        $start = microtime(true);
        $model->invoke([new HumanMessage('hi')]);

        self::assertGreaterThanOrEqual(0.025, microtime(true) - $start);
    }

    public function testTheDefaultSleepIsFiftyMilliseconds(): void
    {
        self::assertSame(50, (new FakeToolCallingChatModel(['responses' => [new AIMessage('x')]]))->sleep);
    }

    public function testStreamsTheContentOfTheReplyToCallbacks(): void
    {
        $tokens = [];
        $model = self::fake([new AIMessage('streamed')]);

        $model->invoke([new HumanMessage('hi')], new RunnableConfig(callbacks: [
            CallbackHandler::fromMethods(['handleLLMNewToken' => static function (string $token) use (&$tokens): void {
                $tokens[] = $token;
            }]),
        ]));

        self::assertSame(['streamed'], $tokens);
    }

    /** @return array<string, array{0: string, 1: list<array<string, mixed>>}> */
    public static function toolStyles(): array
    {
        return [
            'openai' => ['openai', [['type' => 'function', 'function' => ['name' => 'a']], ['type' => 'function', 'function' => ['name' => 'b']]]],
            'anthropic' => ['anthropic', [['name' => 'a'], ['name' => 'b']]],
            'bedrock' => ['bedrock', [['toolSpec' => ['name' => 'a']], ['toolSpec' => ['name' => 'b']]]],
            'google' => ['google', [['functionDeclarations' => [['name' => 'a'], ['name' => 'b']]]]],
        ];
    }

    /** @param list<array<string, mixed>> $expected */
    #[DataProvider('toolStyles')]
    public function testBindToolsRecordsOnlyTheNamesInTheProvidersShape(string $style, array $expected): void
    {
        $model = self::fake([], ['toolStyle' => $style]);

        $bound = $model->bindTools([self::tool('a'), self::tool('b')]);

        self::assertSame($expected, $bound->kwargs()['tools']);
        self::assertArrayNotHasKey('tools', $model->kwargs(), 'binding never mutates the model it came from');
    }

    public function testServerToolsPassThroughAfterTheNamedOnes(): void
    {
        $server = ['type' => 'web_search_preview'];

        $bound = self::fake()->bindTools([$server, self::tool('a')]);

        self::assertSame([['type' => 'function', 'function' => ['name' => 'a']], $server], $bound->kwargs()['tools']);
    }

    public function testBindToolsAcceptsARunnableByItsName(): void
    {
        $runnable = new class extends \LangChain\Runnables\Runnable {
            public function getName(): string
            {
                return 'named_runnable';
            }

            public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
            {
                return $input;
            }
        };

        $bound = self::fake()->bindTools([$runnable]);

        self::assertSame([['type' => 'function', 'function' => ['name' => 'named_runnable']]], $bound->kwargs()['tools']);
    }

    public function testBindToolsKeepsExtraKwargs(): void
    {
        $bound = self::fake()->bindTools([], ['tool_choice' => 'any']);

        self::assertSame('any', $bound->kwargs()['tool_choice']);
    }

    public function testABoundModelAndItsOriginSharePlaybackProgress(): void
    {
        $model = self::fake([new AIMessage('one'), new AIMessage('two'), new AIMessage('three')]);
        $bound = $model->bindTools([self::tool('a')]);

        self::assertSame('one', $model->invoke([new HumanMessage('x')])->content);
        self::assertSame('two', $bound->invoke([new HumanMessage('x')])->content);
        self::assertSame('three', $model->invoke([new HumanMessage('x')])->content);
        self::assertSame(3, $bound->idx);
    }

    public function testBindingTwiceKeepsTheSharedScript(): void
    {
        $model = self::fake([new AIMessage('one'), new AIMessage('two')]);
        $twice = $model->bindTools([self::tool('a')])->bindTools([self::tool('b')]);

        $twice->invoke([new HumanMessage('x')]);

        self::assertSame(1, $model->idx);
        self::assertSame([['type' => 'function', 'function' => ['name' => 'b']]], $twice->kwargs()['tools'], 'a later bind replaces the earlier tools');
    }

    public function testWithStructuredOutputAnswersWithTheStructuredResponseAndRecordsTheMessages(): void
    {
        $model = self::fake([], ['structuredResponse' => ['temperature' => 75]]);

        $runnable = $model->withStructuredOutput(['type' => 'object']);
        $messages = [new HumanMessage('a'), new AIMessage('b')];
        $out = $runnable->invoke($messages);

        self::assertSame(['temperature' => 75], $out);
        self::assertCount(1, $model->structuredOutputMessages);
        self::assertSame($messages, $model->structuredOutputMessages[0]);
    }

    public function testStructuredOutputMessagesAreSharedWithBoundModels(): void
    {
        $model = self::fake([], ['structuredResponse' => ['x' => 1]]);
        $bound = $model->bindTools([self::tool('a')]);

        $bound->withStructuredOutput([])->invoke([new HumanMessage('hi')]);

        self::assertCount(1, $model->structuredOutputMessages);
    }

    public function testWithStructuredOutputNeedsAStructuredResponse(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No structured response provided');

        self::fake()->withStructuredOutput([]);
    }

    public function testItNeverReferencesTheGraphLayer(): void
    {
        // Upstream's @langchain/core has no dependency on langgraph; neither may the fakes it ships.
        $source = (string) file_get_contents((new \ReflectionClass(FakeToolCallingChatModel::class))->getFileName());
        $code = (string) preg_replace('!/\*.*?\*/!s', '', $source);

        self::assertDoesNotMatchRegularExpression('/\bLangGraph\\\\/', $code);
    }
}
