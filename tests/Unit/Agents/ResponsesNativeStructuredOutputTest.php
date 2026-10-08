<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Tests\Unit\Agents\Responses\Support\ScriptedChatModel;
use LangChain\Tools\Schema;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware;
use LangGraph\Agents\Responses\ProviderStrategy;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * "native structured output does not force strict tools" in `agents/tests/responses.test.ts`: the options the agent
 * binds to the model for a provider strategy.
 */
#[CoversClass(ProviderStrategy::class)]
final class ResponsesNativeStructuredOutputTest extends TestCase
{
    private const SCHEMA = ['type' => 'object', 'properties' => ['answer' => ['type' => 'string']], 'required' => ['answer']];

    /**
     * Run the agent and return the options its model was bound with.
     *
     * @param list<array<string, mixed>|object> $middleware
     * @return array<string, mixed>
     */
    private static function bindOptions(ProviderStrategy $responseFormat, array $middleware = []): array
    {
        $echo = tool(
            static fn (array $in): string => 'ok',
            ['name' => 'echo', 'description' => 'Echo the text.', 'schema' => Schema::object(['text' => ['type' => 'string'], 'times' => ['type' => 'number']], ['text'])],
        );
        $model = new ScriptedChatModel(['responses' => [new AIMessage('{"answer":"ok"}')]]);
        $agent = Agent::create(['model' => $model, 'tools' => [$echo], 'responseFormat' => $responseFormat, 'middleware' => $middleware]);

        $agent->invoke(['messages' => [new HumanMessage('hi')]]);

        return $model->bindCalls[0]['options'];
    }

    public function testLeavesToolsNonStrictForADefaultProviderStrategy(): void
    {
        $options = self::bindOptions(ProviderStrategy::fromSchema(self::SCHEMA));

        self::assertArrayNotHasKey('strict', $options);
        // Native output is still requested.
        self::assertSame('json_schema', $options['response_format']['type']);
        self::assertTrue($options['response_format']['json_schema']['strict']);
    }

    public function testHonorsAnExplicitModelSettingsStrictOverride(): void
    {
        $forceStrict = Middleware::create([
            'name' => 'forceStrict',
            'wrapModelCall' => static fn (array $request, callable $handler): mixed => $handler([...$request, 'modelSettings' => ['strict' => true]]),
        ]);

        $options = self::bindOptions(ProviderStrategy::fromSchema(self::SCHEMA), [$forceStrict]);

        self::assertTrue($options['strict']);
    }

    public function testKeepsToolsNonStrictForAnExplicitProviderStrategyStrictFalse(): void
    {
        $options = self::bindOptions(ProviderStrategy::fromSchema(self::SCHEMA, false));

        self::assertArrayNotHasKey('strict', $options);
        self::assertFalse($options['response_format']['json_schema']['strict']);
    }
}
