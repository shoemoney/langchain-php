<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prebuilt;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Runnables\RunnableLambda;
use LangChain\Tools\Schema;
use LangGraph\Prebuilt\ReactAgent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/** Ports "createReactAgent with structured responses" of `prebuilt.test.ts`. */
#[CoversClass(ReactAgent::class)]
final class ReactAgentStructuredResponseTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function versions(): array
    {
        return ReactAgentFixtures::versions();
    }

    private const WEATHER_SCHEMA = [
        'type' => 'object',
        'properties' => ['temperature' => ['type' => 'number', 'description' => 'The temperature in fahrenheit']],
    ];

    private static function getWeather(): \LangChain\Tools\StructuredTool
    {
        return tool(static fn (): string => 'The weather is sunny and 75°F.', ['name' => 'get_weather', 'description' => 'Get the weather', 'schema' => Schema::object([])]);
    }

    /**
     * @param list<BaseMessage> $messages
     * @return list<string>
     */
    private static function texts(array $messages): array
    {
        return ReactAgentFixtures::texts($messages);
    }

    #[DataProvider('versions')]
    public function testBasicStructuredResponse(string $version): void
    {
        $expected = ['temperature' => 75];
        $llm = ReactAgentFixtures::spy(
            [
                new AIMessage(['content' => 'Checking the weather', 'tool_calls' => [ReactAgentFixtures::toolCall('get_weather', '1')]]),
                new AIMessage('The weather is nice'),
            ],
            ['structuredResponse' => $expected],
        );

        // Just the schema.
        $agent1 = ReactAgent::create(['llm' => $llm, 'tools' => [self::getWeather()], 'version' => $version, 'responseFormat' => self::WEATHER_SCHEMA]);
        $result1 = $agent1->invoke(['messages' => [new HumanMessage("What's the weather?")]]);

        self::assertSame($expected, $result1['structuredResponse']);
        self::assertCount(4, $result1['messages']);
        self::assertSame('The weather is sunny and 75°F.', $result1['messages'][2]->content);

        // The messages the model was shown for the structured call.
        self::assertCount(1, $llm->structuredOutputMessages);
        self::assertSame(
            ["What's the weather?", 'Checking the weather', 'The weather is sunny and 75°F.', 'The weather is nice'],
            self::texts($llm->structuredOutputMessages[0]),
        );

        // A prompt, the schema and options.
        $agent2 = ReactAgent::create([
            'llm' => $llm,
            'tools' => [self::getWeather()],
            'version' => $version,
            'responseFormat' => ['prompt' => 'Meow', 'schema' => self::WEATHER_SCHEMA, 'name' => 'generate_structured_response', 'strict' => true],
        ]);
        $result2 = $agent2->invoke(['messages' => [new HumanMessage("What's the weather?")]]);

        // `strict` and `name` reach withStructuredOutput as options; `prompt` and `schema` do not.
        self::assertSame(
            [self::WEATHER_SCHEMA, ['name' => 'generate_structured_response', 'strict' => true]],
            end($llm->structuredOutputCalls),
        );

        self::assertSame($expected, $result2['structuredResponse']);
        self::assertCount(4, $result2['messages']);
        self::assertSame('The weather is sunny and 75°F.', $result2['messages'][2]->content);

        self::assertCount(2, $llm->structuredOutputMessages);
        self::assertSame(
            ['Meow', "What's the weather?", 'Checking the weather', 'The weather is sunny and 75°F.', 'The weather is nice'],
            self::texts($llm->structuredOutputMessages[1]),
        );
        self::assertSame('system', $llm->structuredOutputMessages[1][0]->type);
    }

    #[DataProvider('versions')]
    public function testThrowsWhenStructuredOutputParserReturnsNull(string $version): void
    {
        $llm = ReactAgentFixtures::spy(
            [new AIMessage('{"items": ["apple", "banana"]}')],
            ['structuredResponse' => ['items' => ['apple', 'banana']]],
        );
        $llm->structuredOutputOverride['runnable'] = RunnableLambda::from(static fn (): mixed => null);

        $agent = ReactAgent::create(['llm' => $llm, 'tools' => [], 'version' => $version, 'responseFormat' => ['type' => 'object', 'properties' => ['items' => ['type' => 'array']]]]);

        try {
            $agent->invoke(['messages' => [new HumanMessage("What's in my cart?")]]);
            self::fail('a null structured response must be an error');
        } catch (\Exception $e) {
            self::assertMatchesRegularExpression('/Failed to parse structured response/', $e->getMessage());
            self::assertMatchesRegularExpression('/returned null\/undefined/', $e->getMessage());
        }
    }

    #[DataProvider('versions')]
    public function testNoStructuredResponseKeyIsWrittenWithoutAResponseFormat(string $version): void
    {
        $agent = ReactAgent::create(['llm' => ReactAgentFixtures::fake([new AIMessage('x')]), 'tools' => [], 'version' => $version]);

        $result = $agent->invoke(['messages' => 'hi']);

        self::assertArrayNotHasKey('structuredResponse', $result);
        self::assertArrayNotHasKey('generate_structured_response', $agent->nodes);
    }

    public function testAModelThatCannotProduceStructuredOutputIsRejectedByName(): void
    {
        // A sequence around a non-chat-model has no model to ask for structured output.
        $sequence = \LangChain\Runnables\RunnableSequence::from([
            RunnableLambda::from(static fn (mixed $m): mixed => $m),
            RunnableLambda::from(static fn (mixed $m): mixed => new AIMessage('x')),
        ]);
        $agent = ReactAgent::create(['llm' => $sequence, 'tools' => [], 'responseFormat' => self::WEATHER_SCHEMA]);

        // The agent node already refuses (no model to bind tools to); the message names what was given.
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('must define bindTools method.');

        $agent->invoke(['messages' => 'hi']);
    }
}
