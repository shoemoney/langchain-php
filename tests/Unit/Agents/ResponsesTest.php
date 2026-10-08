<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\Messages\AIMessage;
use LangChain\Tests\Unit\Agents\Responses\Support\ScriptedChatModel;
use LangChain\Tests\Unit\Agents\Support\FakeToolCallingModel;
use LangChain\Tools\Schema;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Errors\MultipleStructuredOutputsError;
use LangGraph\Agents\Errors\StructuredOutputParsingError;
use LangGraph\Agents\Responses\ProviderStrategy;
use LangGraph\Agents\Responses\ResponseFormats;
use LangGraph\Agents\Responses\ToolStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `langchain/src/agents/tests/responses.test.ts`: structured output handling.
 *
 * Differences from upstream, all forced by PHP having no Zod:
 *  - schemas are JSON Schema arrays (and {@see Schema} objects for the Zod arm);
 *  - upstream hard-codes the generated tool names (`extract-3`); a global counter makes those depend on test order,
 *    so the tests read each name off the strategy they built;
 *  - the OpenAI `gpt-4o` capability case needs the OpenAI profiles of WP-17a, so the "supported" case runs on Anthropic.
 */
#[CoversClass(ResponseFormats::class)]
#[CoversClass(ToolStrategy::class)]
#[CoversClass(ProviderStrategy::class)]
final class ResponsesTest extends TestCase
{
    private const FOO = ['type' => 'object', 'properties' => ['foo' => ['type' => 'string']], 'required' => ['foo'], 'additionalProperties' => false];

    private const BAR = ['type' => 'object', 'properties' => ['bar' => ['type' => 'string']], 'required' => ['bar'], 'additionalProperties' => false];

    private static function human(string $text): array
    {
        return ['messages' => [['role' => 'user', 'content' => $text]]];
    }

    /**
     * @param list<array<string, mixed>> $toolCalls
     */
    private static function ai(array $toolCalls, string $content = ''): AIMessage
    {
        return new AIMessage(['content' => $content, 'tool_calls' => array_map(static fn (array $c): array => [...$c, 'type' => 'tool_call'], $toolCalls)]);
    }

    /**
     * @return array<int, string>
     */
    private static function textsOf(array $messages): array
    {
        return array_map(static fn ($m): string => \is_string($m->content) ? $m->content : '', $messages);
    }

    // ---- toolStrategy: multiple structured output tool calls -----------------------------------

    public function testShouldRetryByDefaultWhenMultipleStructuredOutputsAreCalled(): void
    {
        [$foo, $bar] = ResponseFormats::toolStrategy([self::FOO, self::BAR]);
        $model = new ScriptedChatModel(['responses' => [
            self::ai([['name' => $foo->name(), 'args' => ['foo' => 'foo'], 'id' => 'call_1'], ['name' => $bar->name(), 'args' => ['bar' => 'bar'], 'id' => 'call_2']]),
            self::ai([['name' => $foo->name(), 'args' => ['foo' => 'valid structured value'], 'id' => 'call_1']]),
        ]]);
        $agent = Agent::create(['model' => $model, 'tools' => [], 'responseFormat' => [$foo, $bar]]);

        $res = $agent->invoke(self::human('hi'));

        self::assertGreaterThan(1, \count($res['messages']));
        self::assertTrue(array_reduce(
            self::textsOf($res['messages']),
            static fn (bool $found, string $t): bool => $found || str_contains($t, 'The model has called multiple tools'),
            false,
        ));
        self::assertSame(['foo' => 'valid structured value'], $res['structuredResponse']);
    }

    public function testShouldThrowIfErrorHandlerIsSetToFalse(): void
    {
        [$foo, $bar] = ResponseFormats::toolStrategy([self::FOO, self::BAR], ['handleError' => false]);
        $model = new FakeToolCallingModel(['toolCalls' => [[
            ['name' => $foo->name(), 'args' => ['foo' => 'foo'], 'id' => 'call_1'],
            ['name' => $bar->name(), 'args' => ['bar' => 'bar'], 'id' => 'call_2'],
        ]]]);
        $agent = Agent::create(['model' => $model, 'tools' => [], 'responseFormat' => [$foo, $bar]]);

        $this->expectException(MultipleStructuredOutputsError::class);
        $this->expectExceptionMessage('The model has called multiple tools');
        $agent->invoke(self::human('hi'));
    }

    public function testShouldRetryIfErrorHandlerIsSetToTrue(): void
    {
        [$foo, $bar] = ResponseFormats::toolStrategy([self::FOO, self::BAR], ['handleError' => true]);
        $toolCalls = [
            ['name' => $foo->name(), 'args' => ['foo' => 'foo'], 'id' => 'call_1'],
            ['name' => $bar->name(), 'args' => ['bar' => 'bar'], 'id' => 'call_2'],
        ];
        $toolCall2 = [['name' => $foo->name(), 'args' => ['foo' => 'valid structured value'], 'id' => 'call_3']];
        $model = new ScriptedChatModel(['responses' => [self::ai($toolCalls), self::ai($toolCall2)]]);
        $agent = Agent::create(['model' => $model, 'tools' => [], 'responseFormat' => [$foo, $bar]]);

        $res = $agent->invoke(self::human('hi!'));

        $messages = $res['messages'];
        self::assertCount(6, $messages);
        self::assertStringContainsString('hi!', $messages[0]->content);
        self::assertSame(self::withType($toolCalls), $messages[1]->toolCalls);
        self::assertStringContainsString('The model has called multiple tools', $messages[2]->content);
        self::assertSame(self::withType($toolCall2), $messages[3]->toolCalls);
        self::assertStringContainsString(json_encode(['foo' => 'valid structured value']), $messages[4]->content);
        self::assertStringContainsString('Returning structured response', $messages[5]->content);
        self::assertSame(['foo' => 'valid structured value'], $res['structuredResponse']);
    }

    public function testShouldRetryIfTheErrorHandlerIsAFunctionReturningAMessage(): void
    {
        [$foo, $bar] = ResponseFormats::toolStrategy([self::FOO, self::BAR], ['handleError' => static fn (): string => 'foobar']);
        $toolCalls = [
            ['name' => $foo->name(), 'args' => ['foo' => 'foo'], 'id' => 'call_1'],
            ['name' => $bar->name(), 'args' => ['bar' => 'bar'], 'id' => 'call_2'],
        ];
        $toolCall2 = [['name' => $foo->name(), 'args' => ['foo' => 'fixed structured value'], 'id' => 'call_3']];
        $model = new ScriptedChatModel(['responses' => [self::ai($toolCalls), self::ai($toolCall2)]]);
        $agent = Agent::create(['model' => $model, 'tools' => [], 'responseFormat' => [$foo, $bar]]);

        $res = $agent->invoke(self::human('hi!'));

        $messages = $res['messages'];
        self::assertCount(6, $messages);
        self::assertStringContainsString('hi!', $messages[0]->content);
        self::assertSame(self::withType($toolCalls), $messages[1]->toolCalls);
        self::assertStringContainsString('foobar', $messages[2]->content);
        self::assertSame(self::withType($toolCall2), $messages[3]->toolCalls);
        self::assertStringContainsString(json_encode(['foo' => 'fixed structured value']), $messages[4]->content);
        self::assertStringContainsString('Returning structured response', $messages[5]->content);
        self::assertSame(['foo' => 'fixed structured value'], $res['structuredResponse']);
    }

    public function testShouldThrowIfErrorHandlerThrowsAnError(): void
    {
        [$foo, $bar] = ResponseFormats::toolStrategy([self::FOO, self::BAR], ['handleError' => static function (): string {
            throw new \Exception('foobar');
        }]);
        $model = new ScriptedChatModel(['responses' => [
            self::ai([['name' => $foo->name(), 'args' => ['foo' => 'foo'], 'id' => 'call_1'], ['name' => $bar->name(), 'args' => ['bar' => 'bar'], 'id' => 'call_2']]),
        ]]);
        $agent = Agent::create(['model' => $model, 'tools' => [], 'responseFormat' => [$foo, $bar]]);

        $this->expectExceptionMessage('foobar');
        $agent->invoke(self::human('hi'));
    }

    // ---- toolStrategy: single structured output tool call --------------------------------------

    public function testShouldRetryIfErrorHandlerIsSetToTrueForASingleCall(): void
    {
        [$foo] = ResponseFormats::toolStrategy(self::FOO, ['handleError' => true]);
        $model = new FakeToolCallingModel(['toolCalls' => [
            [['name' => $foo->name(), 'args' => ['bar' => 'foo'], 'id' => 'call_1']],
            [['name' => $foo->name(), 'args' => ['foo' => 'fixed structured value'], 'id' => 'call_2']],
        ]]);
        $agent = Agent::create(['model' => $model, 'tools' => [], 'responseFormat' => [$foo]]);

        $res = $agent->invoke(self::human('hi'));

        self::assertCount(6, $res['messages']);
        self::assertTrue(array_reduce(
            self::textsOf($res['messages']),
            static fn (bool $found, string $t): bool => $found || str_contains($t, 'Failed to parse structured output'),
            false,
        ));
        self::assertSame(['foo' => 'fixed structured value'], $res['structuredResponse']);
    }

    public function testShouldReturnAStructuredResponseIfItMatchesTheSchema(): void
    {
        [$foo] = ResponseFormats::toolStrategy(self::FOO);
        $model = new FakeToolCallingModel(['toolCalls' => [[
            ['name' => 'something', 'args' => ['result' => 123], 'id' => 'call_1'],
            ['name' => $foo->name(), 'args' => ['foo' => 'bar'], 'id' => 'call_2'],
        ]]]);
        $agent = Agent::create(['model' => $model, 'tools' => [], 'responseFormat' => [$foo]]);

        $res = $agent->invoke(self::human('hi'));

        self::assertSame(['foo' => 'bar'], $res['structuredResponse']);
    }

    public function testShouldReturnAStructuredResponseIfItMatchesTheSchemaAndToolMessageContentIsProvided(): void
    {
        [$foo] = ResponseFormats::toolStrategy(self::FOO, ['toolMessageContent' => 'foobar']);
        $model = new FakeToolCallingModel(['toolCalls' => [[['name' => $foo->name(), 'args' => ['foo' => 'bar'], 'id' => 'call_1']]]]);
        $agent = Agent::create(['model' => $model, 'tools' => [], 'responseFormat' => [$foo]]);

        $res = $agent->invoke(self::human('hi'));

        self::assertSame(['foo' => 'bar'], $res['structuredResponse']);
        // The user message, the AI message calling the tool, the tool message and a structured response message.
        self::assertCount(4, $res['messages']);
        self::assertStringContainsString('foobar', end($res['messages'])->content);
    }

    public function testShouldReturnStructuredResponseIfItMatchesOneOfTheSchemas(): void
    {
        $strategies = ResponseFormats::toolStrategy([self::FOO, self::BAR]);
        $model = new FakeToolCallingModel(['toolCalls' => [[['name' => $strategies[1]->name(), 'args' => ['bar' => 'foo'], 'id' => 'call_1']]]]);
        $agent = Agent::create(['model' => $model, 'tools' => [], 'responseFormat' => $strategies]);

        $res = $agent->invoke(self::human('hi'));

        self::assertSame(['bar' => 'foo'], $res['structuredResponse']);
    }

    // ---- toolStrategy: schema title extraction -------------------------------------------------

    public function testShouldUseTitleFromASchemaObject(): void
    {
        $schema = new Schema(['type' => 'object', 'title' => 'my_custom_tool', 'properties' => ['status' => ['type' => 'string']]]);

        [$strategy] = ResponseFormats::toolStrategy($schema);

        self::assertSame('my_custom_tool', $strategy->name());
    }

    public function testShouldUseTitleFromJsonSchema(): void
    {
        [$strategy] = ResponseFormats::toolStrategy(['type' => 'object', 'title' => 'my_json_tool', 'properties' => ['status' => ['type' => 'string']]]);

        self::assertSame('my_json_tool', $strategy->name());
    }

    public function testShouldFallBackToExtractNWhenNoTitleIsProvided(): void
    {
        [$strategy] = ResponseFormats::toolStrategy(Schema::object(['status' => ['type' => 'string']]));

        self::assertMatchesRegularExpression('/^extract-\d+$/', $strategy->name());
    }

    public function testShouldUseTitleFromToolStrategyFromSchemaWithASchemaObject(): void
    {
        $strategy = ToolStrategy::fromSchema(new Schema(['type' => 'object', 'title' => 'calculate_result', 'properties' => ['result' => ['type' => 'number']]]));

        self::assertSame('calculate_result', $strategy->name());
    }

    public function testShouldUseTitleFromToolStrategyFromSchemaWithJsonSchema(): void
    {
        $strategy = ToolStrategy::fromSchema(['type' => 'object', 'title' => 'get_data', 'properties' => ['value' => ['type' => 'number']]]);

        self::assertSame('get_data', $strategy->name());
    }

    // ---- providerStrategy ----------------------------------------------------------------------

    public function testShouldNotThrowErrorIfUseProviderStrategyDirectly(): void
    {
        $model = new FakeToolCallingModel(['toolCalls' => [[['name' => 'extract-16', 'args' => ['foo' => 'bar'], 'id' => 'call_2']]]]);
        $agent = Agent::create(['model' => $model, 'tools' => [], 'responseFormat' => ResponseFormats::providerStrategy(self::FOO)]);

        $result = $agent->invoke(self::human('hi'));

        self::assertArrayHasKey('messages', $result);
    }

    public function testShouldThrowWhenATerminalResponseCannotBeParsedAsJson(): void
    {
        $model = new ScriptedChatModel(['responses' => [new AIMessage(['content' => 'I cannot answer that question.'])]]);
        $agent = Agent::create([
            'model' => $model,
            'tools' => [],
            'responseFormat' => ResponseFormats::providerStrategy(['type' => 'object', 'properties' => ['temperature' => ['type' => 'number']], 'required' => ['temperature']]),
        ]);

        $this->expectException(StructuredOutputParsingError::class);
        $this->expectExceptionMessageMatches('/did not satisfy the provided response/');
        $agent->invoke(self::human('hi'));
    }

    public function testShouldThrowWhenATerminalResponseIsValidJsonButDoesNotSatisfyTheSchema(): void
    {
        $model = new ScriptedChatModel(['responses' => [new AIMessage(['content' => '{"foo":"bar"}'])]]);
        $agent = Agent::create([
            'model' => $model,
            'tools' => [],
            'responseFormat' => ResponseFormats::providerStrategy(['type' => 'object', 'properties' => ['temperature' => ['type' => 'number']], 'required' => ['temperature']]),
        ]);

        $this->expectException(StructuredOutputParsingError::class);
        $this->expectExceptionMessageMatches('/did not satisfy the provided response/');
        $agent->invoke(self::human('hi'));
    }

    public function testShouldThrowWhenABareSchemaAutoPromotedToProviderStrategyFailsToParse(): void
    {
        $model = new ScriptedChatModel(['responses' => [new AIMessage(['content' => 'I cannot answer that question.'])], 'structuredOutput' => true]);
        $agent = Agent::create([
            'model' => $model,
            'tools' => [],
            'responseFormat' => ['type' => 'object', 'properties' => ['temperature' => ['type' => 'number']], 'required' => ['temperature']],
        ]);

        $this->expectException(StructuredOutputParsingError::class);
        $this->expectExceptionMessageMatches('/did not satisfy the provided response/');
        $agent->invoke(self::human('hi'));
    }

    // ---- strict flag ---------------------------------------------------------------------------

    public function testShouldDefaultStrictToTrueWhenNotProvided(): void
    {
        self::assertTrue(ProviderStrategy::fromSchema(self::FOO)->strict);
    }

    public function testShouldSetStrictToFalseWhenExplicitlyProvidedAsFalse(): void
    {
        self::assertFalse(ProviderStrategy::fromSchema(self::FOO, false)->strict);
    }

    public function testShouldWorkWithTheProviderStrategyHelperFunction(): void
    {
        self::assertTrue(ResponseFormats::providerStrategy(self::FOO)->strict);
        self::assertFalse(ResponseFormats::providerStrategy(['schema' => self::FOO, 'strict' => false])->strict);
    }

    // ---- Standard Schema support ---------------------------------------------------------------

    /**
     * @param array<string, mixed> $jsonSchema
     * @return array<string, mixed>
     */
    private static function makeSerializableSchema(array $jsonSchema = self::FOO_PLAIN): array
    {
        return ['~standard' => [
            'version' => 1,
            'vendor' => 'test',
            'validate' => static fn (mixed $value): array => ['value' => $value],
            'jsonSchema' => ['input' => static fn (): array => $jsonSchema, 'output' => static fn (): array => $jsonSchema],
        ]];
    }

    private const FOO_PLAIN = ['type' => 'object', 'properties' => ['foo' => ['type' => 'string']], 'required' => ['foo']];

    public function testToolStrategyShouldAcceptASingleStandardSchema(): void
    {
        [$strategy] = ResponseFormats::toolStrategy(self::makeSerializableSchema());

        self::assertInstanceOf(ToolStrategy::class, $strategy);
        self::assertSame(self::FOO_PLAIN, $strategy->schema);
    }

    public function testToolStrategyShouldAcceptAnArrayOfStandardSchemas(): void
    {
        $strategies = ResponseFormats::toolStrategy([
            self::makeSerializableSchema(),
            self::makeSerializableSchema(['type' => 'object', 'properties' => ['bar' => ['type' => 'number']], 'required' => ['bar']]),
        ]);

        self::assertCount(2, $strategies);
        self::assertInstanceOf(ToolStrategy::class, $strategies[0]);
        self::assertInstanceOf(ToolStrategy::class, $strategies[1]);
    }

    public function testShouldReturnAStructuredResponseWithStandardSchemaViaToolStrategy(): void
    {
        $strategies = ResponseFormats::toolStrategy(self::makeSerializableSchema());
        $model = new FakeToolCallingModel(['toolCalls' => [[['name' => $strategies[0]->name(), 'args' => ['foo' => 'bar'], 'id' => 'call_1']]]]);
        $agent = Agent::create(['model' => $model, 'tools' => [], 'responseFormat' => $strategies]);

        $res = $agent->invoke(self::human('hi'));

        self::assertSame(['foo' => 'bar'], $res['structuredResponse']);
    }

    public function testProviderStrategyShouldAcceptAStandardSchemaDirectly(): void
    {
        $strategy = ResponseFormats::providerStrategy(self::makeSerializableSchema());

        self::assertInstanceOf(ProviderStrategy::class, $strategy);
        self::assertSame(self::FOO_PLAIN, $strategy->schema);
    }

    public function testProviderStrategyShouldAcceptAStandardSchemaInTheOptionsArray(): void
    {
        $strategy = ResponseFormats::providerStrategy(['schema' => self::makeSerializableSchema(), 'strict' => false]);

        self::assertInstanceOf(ProviderStrategy::class, $strategy);
        self::assertFalse($strategy->strict);
        self::assertSame(self::FOO_PLAIN, $strategy->schema);
    }

    private const RESULT_SCHEMA = ['type' => 'object', 'properties' => ['result' => ['type' => 'string']], 'required' => ['result']];

    public function testShouldParseStructuredOutputFromAPlainStringResponse(): void
    {
        $parsed = ResponseFormats::providerStrategy(self::RESULT_SCHEMA)->parse(new AIMessage(['content' => json_encode(['result' => 'ok'])]));

        self::assertSame(['result' => 'ok'], $parsed);
    }

    public function testShouldParseStructuredOutputFromTheFirstTextBlockInArrayContent(): void
    {
        $parsed = ResponseFormats::providerStrategy(self::RESULT_SCHEMA)->parse(
            new AIMessage(['content' => [['type' => 'text', 'text' => json_encode(['result' => 'ok'])]]]),
        );

        self::assertSame(['result' => 'ok'], $parsed);
    }

    public function testShouldSkipALeadingThoughtTextBlockAndParseTheStructuredResponse(): void
    {
        // Regression test for #11435: a Gemini thought summary is `{ type: "text", thought: true }` followed by
        // the structured JSON block. parse() must ignore the thought block.
        $parsed = ResponseFormats::providerStrategy(self::RESULT_SCHEMA)->parse(new AIMessage(['content' => [
            ['type' => 'text', 'thought' => true, 'text' => 'A returned thought summary.'],
            ['type' => 'text', 'text' => json_encode(['result' => 'ok'])],
        ]]));

        self::assertSame(['result' => 'ok'], $parsed);
    }

    public function testShouldCreateAToolStrategyFromAStandardSchema(): void
    {
        $jsonSchema = ['type' => 'object', 'title' => 'my_standard_tool', 'properties' => ['foo' => ['type' => 'string']], 'required' => ['foo']];

        $strategy = ToolStrategy::fromSchema(self::makeSerializableSchema($jsonSchema));

        self::assertSame('my_standard_tool', $strategy->name());
        self::assertSame($jsonSchema, $strategy->schema);
    }

    public function testShouldCreateAProviderStrategyFromAStandardSchema(): void
    {
        $strategy = ProviderStrategy::fromSchema(self::makeSerializableSchema());

        self::assertInstanceOf(ProviderStrategy::class, $strategy);
        self::assertSame(self::FOO_PLAIN, $strategy->schema);
        self::assertTrue($strategy->strict);
    }

    // ---- hasSupportForJsonSchemaOutput ---------------------------------------------------------

    public function testHasSupportShouldReturnFalseForUndefinedModel(): void
    {
        self::assertFalse(ResponseFormats::hasSupportForJsonSchemaOutput(null));
    }

    public function testShouldUseModelProfileStructuredOutputToDetermineSupport(): void
    {
        self::assertFalse(ResponseFormats::hasSupportForJsonSchemaOutput(new FakeToolCallingModel([])));
        self::assertTrue(ResponseFormats::hasSupportForJsonSchemaOutput(new ScriptedChatModel([])));
    }

    public function testShouldReturnTrueForAModelWhoseProfileReportsStructuredOutput(): void
    {
        $model = new ChatAnthropic(['model' => 'claude-fable-5-1', 'apiKey' => 'foobar']);

        self::assertTrue(ResponseFormats::hasSupportForJsonSchemaOutput($model));
    }

    public function testShouldReturnFalseForOpenAiModelsWhoseProfileDoesNotReportStructuredOutput(): void
    {
        $model = new ChatOpenAI(['model' => 'gpt-3.5-turbo', 'apiKey' => 'sk-test']);

        self::assertFalse(ResponseFormats::hasSupportForJsonSchemaOutput($model));
    }

    public function testShouldReturnFalseForAnthropicModelsWhoseProfileDoesNotReportStructuredOutput(): void
    {
        $model = new ChatAnthropic(['model' => 'claude-sonnet-4-6', 'apiKey' => 'foobar']);

        self::assertFalse(ResponseFormats::hasSupportForJsonSchemaOutput($model));
    }

    /**
     * @param list<array<string, mixed>> $calls
     * @return list<array<string, mixed>>
     */
    private static function withType(array $calls): array
    {
        return array_map(static fn (array $c): array => [...$c, 'type' => 'tool_call'], $calls);
    }
}
