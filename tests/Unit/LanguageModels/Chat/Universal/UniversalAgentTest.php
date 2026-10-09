<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Universal;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\LanguageModels\Chat\Universal\ConfigurableModel;
use LangChain\LanguageModels\Chat\Universal\InitChatModel;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tools\Schema;
use LangChain\Utils\Testing\FakeHttpClient;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Model;
use LangGraph\Prebuilt\ReactAgent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * The agent-facing cases of `universal.int.test.ts` (`Is compatible with agents`, `ConfigurableModel works
 * with agent after withStructuredOutput is called`), plus the LangGraph side recognising the class: a real
 * graph runs a tool loop through a `ConfigurableModel`.
 */
#[CoversClass(ConfigurableModel::class)]
#[CoversClass(InitChatModel::class)]
final class UniversalAgentTest extends TestCase
{
    private static function weather(): \LangChain\Tools\StructuredTool
    {
        return tool(
            static fn (array $in): string => 'The current weather is partly cloudy with a high of 75 degrees.',
            ['name' => 'GetWeather', 'description' => 'Get the current weather in a given location', 'schema' => Schema::object(['city' => ['type' => 'string']], ['city'])],
        );
    }

    private static function toolLoopHttp(): FakeHttpClient
    {
        return new FakeHttpClient([
            FakeHttpClient::json(200, UniversalFixtures::completion('', UniversalFixtures::openAiToolCall('GetWeather', ['city' => 'San Francisco']), 'chatcmpl-1')),
            FakeHttpClient::json(200, UniversalFixtures::completion("It's partly cloudy.", null, 'chatcmpl-2')),
        ]);
    }

    private static function model(FakeHttpClient $http): ConfigurableModel
    {
        return InitChatModel::init(null, ['modelProvider' => 'openai', 'temperature' => 0.25, 'apiKey' => 'sk-test', 'httpClient' => $http]);
    }

    public function testIsCompatibleWithAgents(): void
    {
        $http = self::toolLoopHttp();

        $agent = Agent::create(['model' => self::model($http), 'tools' => [self::weather()]]);
        $result = $agent->invoke(['messages' => [new HumanMessage("What's the weather in San Francisco right now? Ensure you use the 'GetWeather' tool to answer.")]]);

        self::assertArrayHasKey('messages', $result);
        self::assertNotSame('', $result['messages'][0]->content);
        $messages = $result['messages'];
        self::assertCount(4, $messages);
        self::assertInstanceOf(ToolMessage::class, $messages[2]);
        self::assertSame('The current weather is partly cloudy with a high of 75 degrees.', $messages[2]->content);
        self::assertSame("It's partly cloudy.", $messages[3]->content);

        // The agent bound its tools through the wrapper: the very first request already offered them.
        self::assertCount(2, $http->requests);
        self::assertSame('GetWeather', json_decode($http->requests[0]['body'], true)['tools'][0]['function']['name']);
    }

    public function testThePrebuiltReactAgentRunsTheSameLoop(): void
    {
        $http = self::toolLoopHttp();

        $agent = ReactAgent::create(['llm' => self::model($http), 'tools' => [self::weather()]]);
        $result = $agent->invoke(['messages' => [new HumanMessage('Weather in SF?')]]);

        self::assertCount(4, $result['messages']);
        self::assertSame("It's partly cloudy.", $result['messages'][3]->content);
        self::assertSame('GetWeather', json_decode($http->requests[0]['body'], true)['tools'][0]['function']['name']);
    }

    public function testConfigurableModelWorksWithAgentAfterWithStructuredOutputIsCalled(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, UniversalFixtures::completion('Found: Item 1, Item 2, Item 3'))]);
        $model = self::model($http);

        // Call withStructuredOutput on the model (but don't use the result).
        $model->withStructuredOutput(['type' => 'object', 'properties' => ['result' => ['type' => 'string']], 'required' => ['result']]);

        $search = tool(static fn (array $in): string => 'Found: Item 1, Item 2, Item 3', [
            'name' => 'search',
            'description' => 'Search for items',
            'schema' => Schema::object(['query' => ['type' => 'string']], ['query']),
        ]);

        // The original model should still work with the agent.
        $agent = Agent::create(['model' => $model, 'tools' => [$search]]);
        $result = $agent->invoke(['messages' => [new HumanMessage('Search for items please')]]);

        self::assertNotEmpty($result['messages']);
        $last = $result['messages'][\count($result['messages']) - 1];
        self::assertInstanceOf(AIMessage::class, $last);
        self::assertNotSame('', $last->content);
        self::assertSame([], $model->getQueuedMethodOperations());
    }

    public function testTheReactAgentLooksThroughAConfigurableModelToBindTools(): void
    {
        $model = self::model(UniversalFixtures::openAiHttp());

        // `getModel` resolves a configurable model to the client it builds.
        self::assertInstanceOf(ChatOpenAI::class, ReactAgent::getModel($model));
        $bound = $model->bindTools([self::weather()]);
        self::assertInstanceOf(ChatOpenAI::class, ReactAgent::getModel($bound));
        self::assertFalse(ReactAgent::shouldBindTools($bound, [self::weather()]), 'tools are already bound behind the wrapper');
        self::assertTrue(ReactAgent::shouldBindTools($model, [self::weather()]), 'an unbound wrapper still needs them');
    }

    public function testAModelIdStringIsResolvedThroughInitChatModel(): void
    {
        // The string branch builds the client from the environment, so give it a key.
        $previous = getenv('OPENAI_API_KEY');
        putenv('OPENAI_API_KEY=sk-env');
        try {
            $node = new \ReflectionMethod(\LangGraph\Agents\Nodes\AgentNode::class, 'deriveModel');
            $agentNode = (new \ReflectionClass(\LangGraph\Agents\Nodes\AgentNode::class))->newInstanceWithoutConstructor();
            $options = new \ReflectionProperty(\LangGraph\Agents\Nodes\AgentNode::class, 'options');
            $options->setValue($agentNode, ['model' => 'openai:gpt-4o-mini']);

            $derived = $node->invoke($agentNode);
        } finally {
            $previous === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previous);
        }

        self::assertInstanceOf(ConfigurableModel::class, $derived);
        self::assertInstanceOf(ChatOpenAI::class, $derived->getModelInstance());
        self::assertSame('gpt-4o-mini', $derived->getModelInstance()->model);
        self::assertTrue($derived->getModelInstance()->useResponsesApi, 'openai: strings default to the Responses API');
    }

    public function testANonOpenAiModelIdStringDoesNotForceTheResponsesApi(): void
    {
        $previous = getenv('ANTHROPIC_API_KEY');
        putenv('ANTHROPIC_API_KEY=sk-env');
        try {
            $agentNode = (new \ReflectionClass(\LangGraph\Agents\Nodes\AgentNode::class))->newInstanceWithoutConstructor();
            (new \ReflectionProperty(\LangGraph\Agents\Nodes\AgentNode::class, 'options'))->setValue($agentNode, ['model' => 'anthropic:claude-3-5-sonnet-latest']);
            $derived = (new \ReflectionMethod(\LangGraph\Agents\Nodes\AgentNode::class, 'deriveModel'))->invoke($agentNode);
        } finally {
            $previous === false ? putenv('ANTHROPIC_API_KEY') : putenv('ANTHROPIC_API_KEY=' . $previous);
        }

        self::assertInstanceOf(ConfigurableModel::class, $derived);
        self::assertNotInstanceOf(ChatOpenAI::class, $derived->getModelInstance());
        self::assertFalse(property_exists($derived->getModelInstance(), 'useResponsesApi') && $derived->getModelInstance()->useResponsesApi);
    }

    public function testAnUnresolvableModelIdStringFailsAtTheCall(): void
    {
        $node = (new \ReflectionClass(\LangGraph\Agents\Nodes\AgentNode::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(\LangGraph\Agents\Nodes\AgentNode::class, 'options'))->setValue($node, ['model' => 'cohere:command-r']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unable to infer model provider');
        (new \ReflectionMethod(\LangGraph\Agents\Nodes\AgentNode::class, 'deriveModel'))->invoke($node);
    }

    public function testTheAgentSideRecognisesTheClassByItsSharedInterface(): void
    {
        $model = self::model(UniversalFixtures::openAiHttp());

        self::assertInstanceOf(\LangChain\LanguageModels\Chat\Universal\ConfigurableModelInterface::class, $model);
        self::assertTrue(Model::isBaseChatModel($model));
        self::assertInstanceOf(\LangChain\LanguageModels\Chat\Universal\ConfigurableModelInterface::class, new \LangChain\Tests\Unit\Prebuilt\FakeConfigurableModel(['model' => $model]));
        self::assertInstanceOf(\LangChain\LanguageModels\Chat\Universal\ConfigurableModelInterface::class, new \LangChain\Tests\Unit\Agents\Support\FakeConfigurableModel(['model' => $model]));
        self::assertTrue(Model::isConfigurableModel(new \LangChain\Tests\Unit\Agents\Support\FakeConfigurableModel(['model' => $model])));
    }
}
