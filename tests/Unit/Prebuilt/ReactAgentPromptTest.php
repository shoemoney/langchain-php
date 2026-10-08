<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prebuilt;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use LangChain\Tools\Schema;
use LangGraph\Graph\MessagesAnnotation;
use LangGraph\Pregel\Command;
use LangGraph\Prebuilt\ReactAgent;
use LangGraph\State\Annotation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * Ports the "createReactAgent with prompt/state modifier" and "createReactAgent with legacy messageModifier"
 * describes of `langgraph-core/src/tests/prebuilt.test.ts`, each run under `version` v1 and v2 as upstream's
 * `describe.each` does.
 *
 * Not ported: the Zod 3 / Zod 4 state-schema cases (no Zod here; the array-channel and `AnnotationRoot` spellings
 * of the same behaviour are covered below), and the two "Should respect a passed signal" cases, because the
 * engine has no abort path to observe (`RunnableConfig::$signal` is carried, never read).
 */
#[CoversClass(ReactAgent::class)]
final class ReactAgentPromptTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function versions(): array
    {
        return ReactAgentFixtures::versions();
    }

    /** The expected transcript of `searchThenAnswer()` after a human "Hello Input!" (or without one). */
    private static function searchTranscript(bool $withHuman): array
    {
        $expected = [];
        if ($withHuman) {
            $expected[] = new HumanMessage('Hello Input!');
        }
        $expected[] = new AIMessage(['content' => 'result1', 'tool_calls' => [ReactAgentFixtures::toolCall('search_api', 'tool_abcd123', ['query' => 'foo'])]]);
        $expected[] = new ToolMessage(['content' => 'result for foo', 'name' => 'search_api', 'tool_call_id' => 'tool_abcd123', 'additional_kwargs' => ['status' => 'success']]);
        $expected[] = new AIMessage('result2');

        return $expected;
    }

    #[DataProvider('versions')]
    public function testCanUseStringPrompt(string $version): void
    {
        $llm = ReactAgentFixtures::fake(ReactAgentFixtures::searchThenAnswer());
        $tools = [ReactAgentFixtures::searchApi()];

        $agent1 = ReactAgent::create(['llm' => $llm, 'tools' => $tools, 'version' => $version, 'prompt' => 'You are a helpful assistant']);
        $agent2 = ReactAgent::create(['llm' => $llm, 'tools' => $tools, 'version' => $version, 'stateModifier' => 'You are a helpful assistant']);

        foreach ([$agent1, $agent2] as $agent) {
            $result = $agent->invoke(['messages' => [new HumanMessage('Hello Input!')]]);

            ReactAgentFixtures::assertMessages(self::searchTranscript(true), $result['messages']);
        }
    }

    #[DataProvider('versions')]
    public function testTheStringPromptReachesTheModelAsALeadingSystemMessage(string $version): void
    {
        $llm = ReactAgentFixtures::spy([new AIMessage('hi')]);
        $agent = ReactAgent::create(['llm' => $llm, 'tools' => [], 'version' => $version, 'prompt' => 'You are a helpful assistant']);

        $agent->invoke(['messages' => [new HumanMessage('Hello Input!')]]);

        $seen = $llm->generateCalls[0];
        self::assertInstanceOf(SystemMessage::class, $seen[0]);
        self::assertSame('You are a helpful assistant', $seen[0]->content);
        self::assertSame('Hello Input!', $seen[1]->content);
        self::assertCount(2, $seen);
    }

    #[DataProvider('versions')]
    public function testCanUseSystemMessagePrompt(string $version): void
    {
        $llm = ReactAgentFixtures::fake(ReactAgentFixtures::searchThenAnswer());
        $tools = [ReactAgentFixtures::searchApi()];

        $agent1 = ReactAgent::create(['llm' => $llm, 'tools' => $tools, 'version' => $version, 'prompt' => new SystemMessage('You are a helpful assistant')]);
        $agent2 = ReactAgent::create(['llm' => $llm, 'tools' => $tools, 'version' => $version, 'stateModifier' => new SystemMessage('You are a helpful assistant')]);

        foreach ([$agent1, $agent2] as $agent) {
            $result = $agent->invoke(['messages' => []]);

            ReactAgentFixtures::assertMessages(self::searchTranscript(false), $result['messages']);
        }
    }

    #[DataProvider('versions')]
    public function testCanUseAFunctionAsAPrompt(string $version): void
    {
        $llm = ReactAgentFixtures::fake();
        $tools = [ReactAgentFixtures::searchApi()];
        $prompt = static fn (array $state): array => [new AIMessage('foobar'), ...$state['messages']];

        $agent1 = ReactAgent::create(['llm' => $llm, 'tools' => $tools, 'version' => $version, 'prompt' => $prompt]);
        $agent2 = ReactAgent::create(['llm' => $llm, 'tools' => $tools, 'version' => $version, 'stateModifier' => $prompt]);

        foreach ([$agent1, $agent2] as $agent) {
            $result = $agent->invoke(['messages' => []]);

            ReactAgentFixtures::assertMessages([new AIMessage('foobar')], $result['messages']);
        }
    }

    #[DataProvider('versions')]
    public function testAPromptFunctionMayAskForTheRunConfig(string $version): void
    {
        $seen = null;
        $llm = ReactAgentFixtures::fake([new AIMessage('ok')]);
        $agent = ReactAgent::create([
            'llm' => $llm,
            'tools' => [],
            'version' => $version,
            'prompt' => static function (array $state, RunnableConfig $config) use (&$seen): array {
                $seen = $config->configurable['tenant'] ?? null;

                return $state['messages'];
            },
        ]);

        $agent->invoke(['messages' => 'hi'], new RunnableConfig(configurable: ['tenant' => 'acme']));

        self::assertSame('acme', $seen);
    }

    #[DataProvider('versions')]
    public function testAPromptMayBeARunnable(string $version): void
    {
        $llm = ReactAgentFixtures::spy([new AIMessage('ok')]);
        $prompt = RunnableLambda::from(static fn (array $state): array => [new SystemMessage('from runnable'), ...$state['messages']]);
        $agent = ReactAgent::create(['llm' => $llm, 'tools' => [], 'version' => $version, 'prompt' => $prompt]);

        $agent->invoke(['messages' => 'hi']);

        self::assertSame('from runnable', $llm->generateCalls[0][0]->content);
    }

    #[DataProvider('versions')]
    public function testAllowsCustomStateSchemaThatExtendsMessagesAnnotation(string $version): void
    {
        $llm = ReactAgentFixtures::fake([
            new AIMessage(['content' => 'result1', 'tool_calls' => [ReactAgentFixtures::toolCall('test', 'test1234')]]),
            new AIMessage('result2'),
        ]);

        $agent = ReactAgent::create([
            'llm' => $llm,
            'tools' => [
                tool(static fn (): Command => new Command(update: ['foo' => 'baz']), ['name' => 'test', 'schema' => Schema::object([])]),
            ],
            'version' => $version,
            'stateSchema' => Annotation::root(MessagesAnnotation::root()->spec + ['foo' => Annotation::last()]),
        ]);

        $result = $agent->invoke(['messages' => [], 'foo' => 'bar']);

        ReactAgentFixtures::assertMessages([
            new AIMessage(['content' => 'result1', 'tool_calls' => [ReactAgentFixtures::toolCall('test', 'test1234')]]),
            new AIMessage('result2'),
        ], $result['messages']);
        self::assertSame('baz', $result['foo']);
    }

    #[DataProvider('versions')]
    public function testAcceptsAChannelMapAsTheStateSchema(string $version): void
    {
        $llm = ReactAgentFixtures::fake([new AIMessage('ok')]);

        $agent = ReactAgent::create([
            'llm' => $llm,
            'tools' => [],
            'version' => $version,
            'stateSchema' => ['messages' => ['reducer' => \LangGraph\Graph\MessagesReducer::messagesStateReducer(...), 'default' => static fn (): array => []], 'foo' => null],
        ]);

        $result = $agent->invoke(['messages' => 'hi', 'foo' => 'bar']);

        self::assertSame('bar', $result['foo']);
        self::assertSame(['hi', 'ok'], ReactAgentFixtures::texts($result['messages']));
    }

    #[DataProvider('versions')]
    public function testThrowsIfMessagesIsNotInStateSchema(string $version): void
    {
        $llm = ReactAgentFixtures::fake();

        // Annotation.
        try {
            ReactAgent::create(['llm' => $llm, 'tools' => [], 'version' => $version, 'stateSchema' => Annotation::root(['flag' => Annotation::last()])]);
            self::fail('an AnnotationRoot without messages must be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Missing required `messages` key in state schema.', $e->getMessage());
        }

        // Channel map (the JSON-schema-free spelling of a plain state object).
        try {
            ReactAgent::create(['llm' => $llm, 'tools' => [], 'version' => $version, 'stateSchema' => ['flag' => null]]);
            self::fail('a channel map without messages must be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Missing required `messages` key in state schema.', $e->getMessage());
        }

        // JSON Schema.
        try {
            ReactAgent::create(['llm' => $llm, 'tools' => [], 'version' => $version, 'stateSchema' => ['type' => 'object', 'properties' => ['flag' => ['type' => 'boolean']]]]);
            self::fail('a JSON schema without messages must be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Missing required `messages` key in state schema.', $e->getMessage());
        }
    }

    #[DataProvider('versions')]
    public function testWorksWithToolsThatReturnContentAndArtifactResponseFormat(string $version): void
    {
        $llm = ReactAgentFixtures::fake(ReactAgentFixtures::searchThenAnswer());
        $agent = ReactAgent::create([
            'llm' => $llm,
            'tools' => [ReactAgentFixtures::searchApiWithArtifact()],
            'version' => $version,
            'prompt' => 'You are a helpful assistant',
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Hello Input!')]]);

        ReactAgentFixtures::assertMessages([
            new HumanMessage('Hello Input!'),
            new AIMessage(['content' => 'result1', 'tool_calls' => [ReactAgentFixtures::toolCall('search_api', 'tool_abcd123', ['query' => 'foo'])]]),
            new ToolMessage(['content' => 'some response format', 'name' => 'search_api', 'tool_call_id' => 'tool_abcd123', 'artifact' => '123', 'additional_kwargs' => ['status' => 'success']]),
            new AIMessage('result2'),
        ], $result['messages']);
    }

    #[DataProvider('versions')]
    public function testCanAcceptARunnableInPlaceOfAStructuredTool(string $version): void
    {
        $llm = ReactAgentFixtures::fake(ReactAgentFixtures::searchThenAnswer());
        $searchApi = ReactAgentFixtures::searchApi();

        // Upstream wraps the tool in a RunnableLambda and calls `asTool()`; there is no `asTool()` here, so a
        // runnable that names itself plays the RunnableToolLike.
        $runnableToolLike = new class($searchApi) extends Runnable {
            public function __construct(private readonly \LangChain\Tools\StructuredTool $inner)
            {
            }

            public function getName(): string
            {
                return $this->inner->name;
            }

            public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
            {
                return $this->inner->invoke($input, $config);
            }
        };

        $agent = ReactAgent::create(['llm' => $llm, 'tools' => [$runnableToolLike], 'version' => $version, 'prompt' => 'You are a helpful assistant']);

        $result = $agent->invoke(['messages' => [new HumanMessage('Hello Input!')]]);

        $texts = ReactAgentFixtures::texts($result['messages']);
        self::assertSame(['Hello Input!', 'result1', 'result for foo', 'result2'], $texts);
        self::assertSame('tool_abcd123', $result['messages'][2]->toolCallId);
    }

    #[DataProvider('versions')]
    public function testRejectsMoreThanOneOfPromptStateModifierAndMessageModifier(string $version): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Expected only one of prompt, stateModifier, or messageModifier, got multiple values');

        $agent = ReactAgent::create(['llm' => ReactAgentFixtures::fake(), 'tools' => [], 'version' => $version, 'prompt' => 'a', 'messageModifier' => 'b']);
        $agent->invoke(['messages' => 'hi']);
    }

    public function testRejectsAPromptOfTheWrongType(): void
    {
        $agent = ReactAgent::create(['llm' => ReactAgentFixtures::fake([new AIMessage('x')]), 'tools' => [], 'prompt' => 42]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Got unexpected type for 'prompt': int");

        $agent->invoke(['messages' => 'hi']);
    }

    public function testRejectsAnUnknownVersion(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown version "v3"');

        ReactAgent::create(['llm' => ReactAgentFixtures::fake(), 'tools' => [], 'version' => 'v3']);
    }

    // ---- legacy messageModifier ------------------------------------------

    #[DataProvider('versions')]
    public function testCanUseStringMessageModifier(string $version): void
    {
        $llm = ReactAgentFixtures::fake(ReactAgentFixtures::searchThenAnswer());
        $agent = ReactAgent::create(['llm' => $llm, 'tools' => [ReactAgentFixtures::searchApi()], 'version' => $version, 'messageModifier' => 'You are a helpful assistant']);

        $result = $agent->invoke(['messages' => [new HumanMessage('Hello Input!')]]);

        ReactAgentFixtures::assertMessages(self::searchTranscript(true), $result['messages']);
    }

    #[DataProvider('versions')]
    public function testCanUseSystemMessageMessageModifier(string $version): void
    {
        $llm = ReactAgentFixtures::fake(ReactAgentFixtures::searchThenAnswer());
        $agent = ReactAgent::create(['llm' => $llm, 'tools' => [ReactAgentFixtures::searchApi()], 'version' => $version, 'messageModifier' => new SystemMessage('You are a helpful assistant')]);

        $result = $agent->invoke(['messages' => []]);

        ReactAgentFixtures::assertMessages(self::searchTranscript(false), $result['messages']);
    }

    #[DataProvider('versions')]
    public function testCanUseAFunctionAsAMessageModifier(string $version): void
    {
        $llm = ReactAgentFixtures::fake();
        $agent = ReactAgent::create([
            'llm' => $llm,
            'tools' => [ReactAgentFixtures::searchApi()],
            'version' => $version,
            'messageModifier' => static fn (array $messages): array => [new AIMessage('foobar'), ...$messages],
        ]);

        $result = $agent->invoke(['messages' => []]);

        ReactAgentFixtures::assertMessages([new AIMessage('foobar')], $result['messages']);
    }

    #[DataProvider('versions')]
    public function testAMessageModifierMayBeARunnableOverTheMessages(string $version): void
    {
        $llm = ReactAgentFixtures::spy([new AIMessage('ok')]);
        $agent = ReactAgent::create([
            'llm' => $llm,
            'tools' => [],
            'version' => $version,
            'messageModifier' => RunnableLambda::from(static fn (array $messages): array => [new SystemMessage('sys'), ...$messages]),
        ]);

        $agent->invoke(['messages' => 'hi']);

        self::assertSame(['sys', 'hi'], ReactAgentFixtures::texts($llm->generateCalls[0]));
    }

    #[DataProvider('versions')]
    public function testWorksWithMessageModifierAndContentAndArtifactTools(string $version): void
    {
        $llm = ReactAgentFixtures::fake(ReactAgentFixtures::searchThenAnswer());
        $agent = ReactAgent::create([
            'llm' => $llm,
            'tools' => [ReactAgentFixtures::searchApiWithArtifact()],
            'version' => $version,
            'messageModifier' => 'You are a helpful assistant',
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Hello Input!')]]);

        self::assertSame('some response format', $result['messages'][2]->content);
        self::assertSame('123', $result['messages'][2]->artifact);
        self::assertSame('success', $result['messages'][2]->additional_kwargs['status']);
    }

    public function testRejectsAMessageModifierOfTheWrongType(): void
    {
        $agent = ReactAgent::create(['llm' => ReactAgentFixtures::fake([new AIMessage('x')]), 'tools' => [], 'messageModifier' => 42]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unexpected type for messageModifier: int');

        $agent->invoke(['messages' => 'hi']);
    }
}
