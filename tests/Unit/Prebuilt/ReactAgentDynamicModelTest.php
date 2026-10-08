<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prebuilt;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tools\Schema;
use LangGraph\Graph\MessagesAnnotation;
use LangGraph\Prebuilt\ReactAgent;
use LangGraph\State\Annotation;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * Ports the "Dynamic Model" describe of `prebuilt.test.ts`: `llm` as a `fn(state, config)` that picks the model on
 * every call. Upstream's second argument is a `Runtime` whose `context` carries the caller's context; here it is
 * the {@see RunnableConfig}, whose `context` and `configurable` are the same data.
 */
#[CoversClass(ReactAgent::class)]
final class ReactAgentDynamicModelTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function versions(): array
    {
        return ReactAgentFixtures::versions();
    }

    private static function lastText(array $state): string
    {
        $last = end($state['messages']);

        return $last === false || !is_string($last->content) ? '' : $last->content;
    }

    private static function numberTool(string $name, string $prefix): \LangChain\Tools\StructuredTool
    {
        return tool(
            static fn (array $args): string => $prefix . ': ' . $args['x'],
            ['name' => $name, 'description' => ucfirst($prefix) . ' tool.', 'schema' => Schema::object(['x' => ['type' => 'number']], ['x'])],
        );
    }

    public function testShouldHandleBasicDynamicModelFunctionality(): void
    {
        $dynamicModel = static function (array $state): object {
            if (str_contains(self::lastText($state), 'urgent')) {
                return ReactAgentFixtures::fake([new AIMessage('urgent called')]);
            }

            return ReactAgentFixtures::fake([]);
        };

        $agent = ReactAgent::create(['llm' => $dynamicModel, 'tools' => []]);

        // With nothing scripted the fake echoes its input, so the last message is the human's own.
        $result = $agent->invoke(['messages' => 'hello']);
        self::assertSame('hello', self::lastText($result));

        $result2 = $agent->invoke(['messages' => 'urgent help']);
        self::assertSame('urgent called', self::lastText($result2));
    }

    #[DataProvider('versions')]
    public function testShouldHandleDynamicModelWithToolCalling(string $version): void
    {
        $basicModel = ReactAgentFixtures::fake([
            new AIMessage(['content' => '', 'tool_calls' => [ReactAgentFixtures::toolCall('basic_tool', '1', ['x' => 1])]]),
            new AIMessage('basic request'),
        ]);
        $advancedModel = ReactAgentFixtures::fake([
            new AIMessage(['content' => '', 'tool_calls' => [ReactAgentFixtures::toolCall('advanced_tool', '1', ['x' => 1])]]),
            new AIMessage('advanced request'),
        ]);

        $agent = ReactAgent::create([
            'llm' => static fn (array $state): object => str_contains(self::lastText($state), 'advanced') ? $advancedModel : $basicModel,
            'tools' => [self::numberTool('basic_tool', 'basic'), self::numberTool('advanced_tool', 'advanced')],
            'version' => $version,
        ]);

        $result = $agent->invoke(['messages' => 'basic request']);
        $tail = array_slice($result['messages'], -2);
        self::assertSame(['basic: 1', 'basic request'], ReactAgentFixtures::texts($tail));
        self::assertSame('basic_tool', $tail[0]->name);

        $result2 = $agent->invoke(['messages' => 'advanced request']);
        $tail2 = array_slice($result2['messages'], -2);
        self::assertSame(['advanced: 1', 'advanced request'], ReactAgentFixtures::texts($tail2));
        self::assertSame('advanced_tool', $tail2[0]->name);
    }

    #[DataProvider('versions')]
    public function testShouldHandleDynamicModelUsingConfigParameters(string $version): void
    {
        $agent = ReactAgent::create([
            'llm' => static fn (array $state, RunnableConfig $runtime): object => ($runtime->context['user_id'] ?? null) === 'user_premium'
                ? ReactAgentFixtures::fake([new AIMessage('premium')])
                : ReactAgentFixtures::fake([new AIMessage('basic')]),
            'tools' => [],
            'version' => $version,
            'contextSchema' => Annotation::root(['user_id' => Annotation::last()]),
        ]);

        $basic = $agent->invoke(['messages' => 'hello'], new RunnableConfig(context: ['user_id' => 'user_basic']));
        self::assertSame(['hello', 'basic'], ReactAgentFixtures::texts($basic['messages']));

        $premium = $agent->invoke(['messages' => 'hello'], new RunnableConfig(context: ['user_id' => 'user_premium']));
        self::assertSame(['hello', 'premium'], ReactAgentFixtures::texts($premium['messages']));
    }

    #[DataProvider('versions')]
    public function testShouldHandleDynamicModelWithCustomStateSchema(string $version): void
    {
        $schema = Annotation::root([
            'messages' => MessagesAnnotation::root()->spec['messages'],
            'model_preference' => Annotation::withReducer(static fn (mixed $_, mixed $next): mixed => $next, static fn (): string => 'basic'),
        ]);

        $agent = ReactAgent::create([
            'llm' => static fn (array $state): object => ($state['model_preference'] ?? null) === 'advanced'
                ? ReactAgentFixtures::fake([new AIMessage('advanced')])
                : ReactAgentFixtures::fake([new AIMessage('basic')]),
            'tools' => [],
            'version' => $version,
            'stateSchema' => $schema,
        ]);

        $advanced = $agent->invoke(['messages' => [new HumanMessage('hello')], 'model_preference' => 'advanced']);
        self::assertSame(['hello', 'advanced'], ReactAgentFixtures::texts($advanced['messages']));

        $default = $agent->invoke(['messages' => [new HumanMessage('hello')]]);
        self::assertSame(['hello', 'basic'], ReactAgentFixtures::texts($default['messages']));
    }

    #[DataProvider('versions')]
    public function testShouldHandleDynamicModelWithDifferentPromptTypes(string $version): void
    {
        $model = ReactAgentFixtures::spy([new AIMessage('ai response')]);

        // String prompt.
        $agent = ReactAgent::create(['llm' => static fn (): object => $model, 'tools' => [], 'version' => $version, 'prompt' => 'system_msg']);
        $result = $agent->invoke(['messages' => 'human_msg']);

        self::assertSame(['human_msg', 'ai response'], ReactAgentFixtures::texts($result['messages']));
        self::assertSame(['system_msg', 'human_msg'], ReactAgentFixtures::texts(end($model->invokeCalls)[0]));

        // Callable prompt.
        $agent2 = ReactAgent::create([
            'llm' => static fn (): object => $model,
            'tools' => [],
            'version' => $version,
            'prompt' => static fn (array $state): array => [new SystemMessage('system_msg'), ...$state['messages']],
        ]);
        $result2 = $agent2->invoke(['messages' => 'human_msg']);

        self::assertSame(['human_msg', 'ai response'], ReactAgentFixtures::texts($result2['messages']));
        self::assertSame(['system_msg', 'human_msg'], ReactAgentFixtures::texts(end($model->invokeCalls)[0]));
        self::assertCount(2, $model->invokeCalls);
    }

    #[DataProvider('versions')]
    public function testShouldHandleDynamicModelWithStructuredResponseFormat(string $version): void
    {
        $expected = ['message' => 'dynamic response', 'confidence' => 0.9];

        $agent = ReactAgent::create([
            'llm' => static fn (): object => ReactAgentFixtures::fake([new AIMessage('dynamic response')], ['structuredResponse' => $expected]),
            'tools' => [],
            'version' => $version,
            'responseFormat' => ['type' => 'object', 'properties' => ['message' => ['type' => 'string'], 'confidence' => ['type' => 'number']]],
        ]);

        $result = $agent->invoke(['messages' => 'hello']);

        self::assertSame(['hello', 'dynamic response'], ReactAgentFixtures::texts($result['messages']));
        self::assertSame($expected, $result['structuredResponse']);
    }

    #[DataProvider('versions')]
    public function testShouldHandleDynamicModelThatChangesAvailableToolsBasedOnState(string $version): void
    {
        $modelA = ReactAgentFixtures::fake([
            new AIMessage(['content' => '', 'tool_calls' => [ReactAgentFixtures::toolCall('tool_a', '1', ['x' => 1])]]),
            new AIMessage(['content' => 'use_a please']),
        ]);
        $modelB = ReactAgentFixtures::fake([
            new AIMessage(['content' => '', 'tool_calls' => [ReactAgentFixtures::toolCall('tool_b', '1', ['x' => 2])]]),
            new AIMessage(['content' => 'use_b please']),
        ]);

        $agent = ReactAgent::create([
            'llm' => static function (array $state) use ($modelA, $modelB): object {
                foreach ($state['messages'] as $message) {
                    if (is_string($message->content) && str_contains($message->content, 'use_b')) {
                        return $modelB;
                    }
                }

                return $modelA;
            },
            'tools' => [self::numberTool('tool_a', 'A'), self::numberTool('tool_b', 'B')],
            'version' => $version,
        ]);

        $a = $agent->invoke(['messages' => 'use_a']);
        self::assertSame(['use_a', '', 'A: 1', 'use_a please'], ReactAgentFixtures::texts($a['messages']));
        self::assertSame('tool_a', $a['messages'][1]->toolCalls[0]['name']);
        self::assertSame(['x' => 1], $a['messages'][1]->toolCalls[0]['args']);

        $b = $agent->invoke(['messages' => 'use_b']);
        self::assertSame(['use_b', '', 'B: 2', 'use_b please'], ReactAgentFixtures::texts($b['messages']));
        self::assertSame('tool_b', $b['messages'][1]->toolCalls[0]['name']);
    }

    #[DataProvider('versions')]
    public function testShouldHandleErrorHandlingInDynamicModel(string $version): void
    {
        $agent = ReactAgent::create([
            'llm' => static function (array $state): object {
                if (str_contains(self::lastText($state), 'fail')) {
                    throw new \Exception('Dynamic model failed');
                }

                return ReactAgentFixtures::fake([new AIMessage('ai response')]);
            },
            'tools' => [],
            'version' => $version,
        ]);

        $ok = $agent->invoke(['messages' => 'hello']);
        self::assertSame(['hello', 'ai response'], ReactAgentFixtures::texts($ok['messages']));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Dynamic model failed');
        $agent->invoke(['messages' => [new HumanMessage('fail now')]]);
    }

    #[DataProvider('versions')]
    public function testShouldProduceEquivalentResultsWhenConfiguredTheSame(string $version): void
    {
        $static = ReactAgent::create(['llm' => ReactAgentFixtures::fake([new AIMessage('ai response')]), 'tools' => [], 'version' => $version]);
        $dynamic = ReactAgent::create(['llm' => static fn (): object => ReactAgentFixtures::fake([new AIMessage('ai response')]), 'tools' => [], 'version' => $version]);

        $staticResult = $static->invoke(['messages' => 'test message']);
        $dynamicResult = $dynamic->invoke(['messages' => 'test message']);

        self::assertSame(
            ReactAgentFixtures::texts($staticResult['messages']),
            ReactAgentFixtures::texts($dynamicResult['messages']),
        );
        self::assertSame(['test message', 'ai response'], ReactAgentFixtures::texts($dynamicResult['messages']));
    }

    #[DataProvider('versions')]
    public function testShouldReceiveCorrectStateNotTheModelInput(string $version): void
    {
        $calls = [];
        $agent = ReactAgent::create([
            'llm' => static function (array $state, RunnableConfig $config) use (&$calls): object {
                $calls[] = [$state, $config];

                return ReactAgentFixtures::fake([new AIMessage('ai response')]);
            },
            'tools' => [],
            'version' => $version,
            'stateSchema' => Annotation::root(MessagesAnnotation::root()->spec + ['custom_field' => Annotation::last()]),
        ]);

        $agent->invoke(['messages' => [new HumanMessage('hello')], 'custom_field' => 'test_value']);

        self::assertCount(1, $calls);
        // The function got the graph's state (every channel), not what the prompt turned it into.
        self::assertSame('test_value', $calls[0][0]['custom_field']);
        self::assertSame(['hello'], ReactAgentFixtures::texts($calls[0][0]['messages']));
        self::assertInstanceOf(RunnableConfig::class, $calls[0][1]);
    }

    public function testADynamicModelThatReturnsNonModelIsRefused(): void
    {
        $agent = ReactAgent::create(['llm' => static fn (): string => 'nope', 'tools' => []]);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must return a model');

        $agent->invoke(['messages' => 'hi']);
    }
}
