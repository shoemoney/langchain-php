<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prebuilt;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\RemoveMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tools\Schema;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Graph\MessagesAnnotation;
use LangGraph\Graph\MessagesReducer;
use LangGraph\Pregel\Constants;
use LangGraph\Pregel\CompiledStateGraph;
use LangGraph\Prebuilt\ReactAgent;
use LangGraph\State\Annotation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use LangChain\Runnables\RunnableConfig;

use function LangChain\Tools\tool;

/**
 * Ports "createReactAgent with hooks" of `prebuilt.test.ts`, including the eight graph-structure cases
 * (`mermaid $name`).
 *
 * The structure cases compare edges rather than rendered Mermaid text: `getGraph()` / `getGraphAsync()` do not
 * exist yet (WP-06), so the edge list upstream renders from is read off the builder instead. A solid edge is
 * `a --> b`; a conditional edge is `a -.-> b` for each destination of its path map, which is how Mermaid draws
 * them.
 */
#[CoversClass(ReactAgent::class)]
final class ReactAgentHooksTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function versions(): array
    {
        return ReactAgentFixtures::versions();
    }

    private static function thread(string $id = '1'): RunnableConfig
    {
        return new RunnableConfig(configurable: ['thread_id' => $id]);
    }

    private static function flagSchema(): \LangGraph\State\AnnotationRoot
    {
        return Annotation::root(MessagesAnnotation::root()->spec + ['flag' => Annotation::last()]);
    }

    #[DataProvider('versions')]
    public function testPreModelHookCanSetLlmInputMessages(string $version): void
    {
        $llm = ReactAgentFixtures::spy([new AIMessage(['id' => '0', 'content' => 'Hello!']), new AIMessage(['id' => '1', 'content' => 'Hello again!'])]);
        $agent = ReactAgent::create([
            'llm' => $llm,
            'tools' => [],
            'preModelHook' => static fn (): array => ['llmInputMessages' => 'pre-hook'],
            'checkpointer' => new MemorySaver(),
            'version' => $version,
        ]);

        self::assertArrayHasKey('pre_model_hook', $agent->nodes);

        $result = $agent->invoke(['messages' => [new HumanMessage('hi?')]], self::thread());

        // The state keeps the real conversation; the model was shown only the hook's messages.
        self::assertSame(['hi?', 'Hello!'], ReactAgentFixtures::texts($result['messages']));
        self::assertArrayNotHasKey('llmInputMessages', $result, 'the hook channel is input-only, not part of the output');
        self::assertSame(['pre-hook'], ReactAgentFixtures::texts($llm->generateCalls[0]));
        self::assertInstanceOf(HumanMessage::class, $llm->generateCalls[0][0]);

        // Second invocation, same thread.
        $second = $agent->invoke(['messages' => [new HumanMessage('hi again')]], self::thread());

        self::assertSame(['hi?', 'Hello!', 'hi again', 'Hello again!'], ReactAgentFixtures::texts($second['messages']));
        self::assertSame(['pre-hook'], ReactAgentFixtures::texts($llm->generateCalls[1]));
    }

    #[DataProvider('versions')]
    public function testPreModelHookCanRewriteMessages(string $version): void
    {
        $llm = ReactAgentFixtures::fake([new AIMessage(['id' => '0', 'content' => 'Hello!'])]);
        $agent = ReactAgent::create([
            'llm' => $llm,
            'tools' => [],
            'version' => $version,
            'preModelHook' => static fn (): array => [
                'messages' => [new RemoveMessage(['id' => MessagesReducer::REMOVE_ALL_MESSAGES]), new HumanMessage('Hello!')],
            ],
        ]);

        self::assertArrayHasKey('pre_model_hook', $agent->nodes);

        $result = $agent->invoke(['messages' => [new HumanMessage('hi?')]]);

        self::assertSame(['Hello!', 'Hello!'], ReactAgentFixtures::texts($result['messages']));
        self::assertInstanceOf(HumanMessage::class, $result['messages'][0]);
        self::assertInstanceOf(AIMessage::class, $result['messages'][1]);
        self::assertSame('0', $result['messages'][1]->id);
    }

    #[DataProvider('versions')]
    public function testPreModelHookWithACustomStateSchemaAndPostModelHook(string $version): void
    {
        $llm = ReactAgentFixtures::spy([new AIMessage(['id' => '0', 'content' => 'Hello!'])]);
        $schema = Annotation::root(MessagesAnnotation::root()->spec + [
            'flag' => Annotation::withReducer(
                static fn (mixed $a, mixed $b): bool => array_reduce([$a, ...(array) $b], static fn (bool $acc, mixed $curr): bool => $acc || (bool) $curr, false),
                static fn (): bool => false,
            ),
        ]);

        $agent = ReactAgent::create([
            'llm' => $llm,
            'tools' => [],
            'version' => $version,
            'preModelHook' => static fn (): array => ['llmInputMessages' => [new HumanMessage(['id' => 'human', 'content' => 'pre-hook'])]],
            'stateSchema' => $schema,
            'postModelHook' => static fn (): array => ['flag' => [false, false, true]],
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('hi?')]]);

        self::assertSame(['hi?', 'Hello!'], ReactAgentFixtures::texts($result['messages']));
        self::assertTrue($result['flag']);
        self::assertSame('human', $llm->generateCalls[0][0]->id);
        self::assertSame(['pre-hook'], ReactAgentFixtures::texts($llm->generateCalls[0]));
    }

    #[DataProvider('versions')]
    public function testPostModelHook(string $version): void
    {
        $llm = ReactAgentFixtures::fake([new AIMessage(['id' => '1', 'content' => 'hi?'])]);
        $agent = ReactAgent::create([
            'llm' => $llm,
            'tools' => [],
            'version' => $version,
            'postModelHook' => static fn (): array => ['flag' => true],
            'stateSchema' => self::flagSchema(),
        ]);

        self::assertArrayHasKey('post_model_hook', $agent->nodes);

        $result = $agent->invoke(['messages' => [new HumanMessage('hi?')], 'flag' => false]);
        self::assertTrue($result['flag']);

        $updates = [];
        foreach ($agent->stream(['messages' => [new HumanMessage('hi?')], 'flag' => false]) as [$mode, $chunk]) {
            if ($mode === 'updates') {
                $updates[] = $chunk;
            }
        }

        self::assertCount(2, $updates);
        self::assertSame(['agent'], array_keys($updates[0]));
        self::assertSame('1', $updates[0]['agent']['messages'][0]->id);
        self::assertSame('hi?', $updates[0]['agent']['messages'][0]->content);
        self::assertSame([['post_model_hook' => ['flag' => true]]], [$updates[1]]);
    }

    #[DataProvider('versions')]
    public function testPostModelHookWithStructuredResponse(string $version): void
    {
        $llm = ReactAgentFixtures::fake(
            [
                new AIMessage(['id' => '1', 'content' => "What's the weather?", 'tool_calls' => [['name' => 'get_weather', 'args' => [], 'id' => '1', 'type' => 'tool_call']]]),
                new AIMessage(['id' => '3', 'content' => 'The weather is nice']),
            ],
            ['structuredResponse' => ['temperature' => 75]],
        );
        $getWeather = tool(static fn (): string => 'The weather is sunny and 75°F.', ['name' => 'get_weather', 'description' => 'Get the weather', 'schema' => Schema::object([])]);

        $agent = ReactAgent::create([
            'llm' => $llm,
            'tools' => [$getWeather],
            'version' => $version,
            'responseFormat' => ['type' => 'object', 'properties' => ['temperature' => ['type' => 'number']]],
            'postModelHook' => static fn (): array => ['flag' => true],
            'stateSchema' => Annotation::root(self::flagSchema()->spec + ['structuredResponse' => Annotation::last()]),
        ]);

        self::assertArrayHasKey('post_model_hook', $agent->nodes);
        self::assertArrayHasKey('generate_structured_response', $agent->nodes);

        $input = ['messages' => [new HumanMessage(['id' => '0', 'content' => "What's the weather?"])], 'flag' => false];
        $response = $agent->invoke($input);

        self::assertTrue($response['flag']);
        self::assertSame(['temperature' => 75], $response['structuredResponse']);

        $steps = [];
        foreach ($agent->stream($input) as [$mode, $chunk]) {
            if ($mode === 'updates') {
                $steps[] = array_key_first($chunk);
            }
        }

        self::assertSame(['agent', 'post_model_hook', 'tools', 'agent', 'post_model_hook', 'generate_structured_response'], $steps);
    }

    #[DataProvider('versions')]
    public function testPostModelHookWithPartialToolCallApplication(string $version): void
    {
        $llm = ReactAgentFixtures::fake([
            new AIMessage([
                'content' => 'result1',
                'tool_calls' => [
                    ReactAgentFixtures::toolCall('search_api', 'tool_a', ['query' => 'foo']),
                    ReactAgentFixtures::toolCall('search_api', 'tool_b', ['query' => 'bar']),
                ],
            ]),
            new AIMessage('done'),
        ]);

        $agent = ReactAgent::create([
            'llm' => $llm,
            'tools' => [ReactAgentFixtures::searchApi()],
            'version' => $version,
            'postModelHook' => static function (array $state): array {
                $last = end($state['messages']);
                if ($last instanceof AIMessage && $last->toolCalls !== []) {
                    // Apply only the first tool call.
                    return ['messages' => [new ToolMessage(['content' => 'post-model-hook', 'tool_call_id' => $last->toolCalls[0]['id']])]];
                }

                return [];
            },
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage("What's the weather?")]]);

        // The tools node ran only the call the hook left unanswered.
        self::assertSame(
            ["What's the weather?", 'result1', 'post-model-hook', 'result for bar', 'done'],
            ReactAgentFixtures::texts($result['messages']),
        );
        self::assertSame('tool_a', $result['messages'][1]->toolCalls[0]['id']);
        self::assertSame('tool_b', $result['messages'][1]->toolCalls[1]['id']);
    }

    // ---- graph structure ("mermaid $name") --------------------------------

    /**
     * Edges as the diagram would draw them, sorted: `a --> b` for a plain edge, `a -.-> b` per conditional destination.
     *
     * @return list<string>
     */
    private static function structure(CompiledStateGraph $agent): array
    {
        $builder = $agent->builder;
        $lines = [];
        foreach ($builder->edges as [$from, $to]) {
            $lines[] = "$from --> $to";
        }
        foreach ($builder->branches as $from => $branches) {
            foreach ($branches as $branch) {
                foreach ($branch->ends ?? [] as $to) {
                    $lines[] = "$from -.-> $to";
                }
            }
        }
        sort($lines);

        return $lines;
    }

    /** @return array<string, array{0: string, 1: array<string, mixed>, 2: list<string>}> */
    public static function structureCases(): array
    {
        $tool = static fn () => tool(static fn (): string => 'The weather is sunny and 75°F.', ['name' => 'get_weather', 'description' => 'Get the weather', 'schema' => Schema::object([])]);
        $format = ['type' => 'object', 'properties' => ['temperature' => ['type' => 'number']]];
        $hooks = static fn (): array => [
            'postModelHook' => static fn (): array => ['flag' => true],
            'stateSchema' => self::flagSchema(),
        ];

        $cases = [
            'no tools' => [[], ['__start__ --> agent', 'agent -.-> __end__', 'agent -.-> tools', 'tools --> agent']],
            'tools' => [['tools' => [$tool()]], ['__start__ --> agent', 'agent -.-> __end__', 'agent -.-> tools', 'tools --> agent']],
            'pre + tools' => [
                ['tools' => [$tool()], 'preModelHook' => static fn (): array => ['messages' => []]],
                ['__start__ --> pre_model_hook', 'agent -.-> __end__', 'agent -.-> tools', 'pre_model_hook --> agent', 'tools --> pre_model_hook'],
            ],
            'tools + post' => [
                ['tools' => [$tool()]] + $hooks(),
                ['__start__ --> agent', 'agent --> post_model_hook', 'tools --> agent', 'post_model_hook -.-> tools', 'post_model_hook -.-> agent', 'post_model_hook -.-> __end__'],
            ],
            'tools + response format' => [
                ['tools' => [$tool()], 'responseFormat' => $format],
                ['__start__ --> agent', 'generate_structured_response --> __end__', 'tools --> agent', 'agent -.-> tools', 'agent -.-> generate_structured_response'],
            ],
            'pre + tools + response format' => [
                ['tools' => [$tool()], 'preModelHook' => static fn (): array => ['messages' => []], 'responseFormat' => $format],
                ['__start__ --> pre_model_hook', 'pre_model_hook --> agent', 'agent -.-> tools', 'agent -.-> generate_structured_response', 'generate_structured_response --> __end__', 'tools --> pre_model_hook'],
            ],
            'tools + post + response format' => [
                ['tools' => [$tool()], 'responseFormat' => $format] + $hooks(),
                ['__start__ --> agent', 'agent --> post_model_hook', 'generate_structured_response --> __end__', 'tools --> agent', 'post_model_hook -.-> tools', 'post_model_hook -.-> agent', 'post_model_hook -.-> generate_structured_response'],
            ],
            'pre + tools + post' => [
                ['tools' => [$tool()], 'preModelHook' => static fn (): array => ['messages' => []]] + $hooks(),
                ['__start__ --> pre_model_hook', 'pre_model_hook --> agent', 'agent --> post_model_hook', 'tools --> pre_model_hook', 'post_model_hook -.-> tools', 'post_model_hook -.-> pre_model_hook', 'post_model_hook -.-> __end__'],
            ],
            'pre + tools + post + response format' => [
                ['tools' => [$tool()], 'responseFormat' => $format, 'preModelHook' => static fn (): array => ['messages' => []]] + $hooks(),
                ['__start__ --> pre_model_hook', 'pre_model_hook --> agent', 'agent --> post_model_hook', 'generate_structured_response --> __end__', 'tools --> pre_model_hook', 'post_model_hook -.-> tools', 'post_model_hook -.-> pre_model_hook', 'post_model_hook -.-> generate_structured_response'],
            ],
        ];

        $rows = [];
        foreach (ReactAgentFixtures::versionNames() as $version) {
            foreach ($cases as $name => [$params, $structure]) {
                $rows["$version $name"] = [$version, $params, $structure];
            }
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $params
     * @param list<string>         $expected
     */
    #[DataProvider('structureCases')]
    public function testGraphStructure(string $version, array $params, array $expected): void
    {
        $agent = ReactAgent::create(['llm' => ReactAgentFixtures::fake(), 'tools' => [], 'version' => $version] + $params);

        sort($expected);
        self::assertSame($expected, self::structure($agent));
    }

    #[DataProvider('versions')]
    public function testTheDefaultGraphHasExactlyAnAgentAndAToolsNode(string $version): void
    {
        $agent = ReactAgent::create(['llm' => ReactAgentFixtures::fake(), 'tools' => [], 'version' => $version]);
        $names = array_values(array_diff(array_keys($agent->nodes), [Constants::START]));
        sort($names);
        self::assertSame(['agent', 'tools'], $names);
    }
}
