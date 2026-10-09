<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Middleware;

use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Tests\Unit\Agents\Support\BindRecordingModel;
use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;
use LangChain\Utils\Testing\FakeHttpClient;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware\ProviderToolSearchMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * `langchain/src/agents/middleware/tests/providerToolSearch.test.ts`.
 *
 * Upstream's mock model reports `getName()` and records `bindTools` calls; here that is a fake chat model with a
 * settable name that records the tools it was bound with. The wire shape is asserted on a real `ChatAnthropic`
 * and `ChatOpenAI` over a fake transport.
 */
#[CoversClass(ProviderToolSearchMiddleware::class)]
final class ProviderToolSearchMiddlewareTest extends TestCase
{
    private const ANTHROPIC_SEARCH_TOOL_TYPE = 'tool_search_tool_bm25_20251119';
    private const OPENAI_SEARCH_TOOL_TYPE = 'tool_search';

    private static function mockModel(string $name = 'ChatAnthropic'): BindRecordingModel
    {
        return new BindRecordingModel($name, ['sleep' => 0, 'responses' => [new AIMessage('Response from model')]]);
    }

    private static function getWeather(): StructuredTool
    {
        return tool(static fn (): string => 'sunny', ['name' => 'get_weather', 'description' => 'Get the weather for a city', 'schema' => Schema::object(['city' => ['type' => 'string']], ['city'])]);
    }

    private static function sendEmail(): StructuredTool
    {
        return tool(static fn (): string => 'sent', ['name' => 'send_email', 'description' => 'Send an email', 'schema' => Schema::object(['to' => ['type' => 'string']], ['to'])]);
    }

    /** @param list<mixed> $boundTools */
    private static function find(array $boundTools, string $name): mixed
    {
        foreach ($boundTools as $tool) {
            if (\is_array($tool) && ($tool['name'] ?? null) === $name) {
                return $tool;
            }
        }

        return null;
    }

    /** @param list<mixed> $boundTools */
    private static function hasSearchTool(array $boundTools): bool
    {
        foreach ($boundTools as $tool) {
            if (\is_array($tool) && \in_array($tool['type'] ?? null, [self::ANTHROPIC_SEARCH_TOOL_TYPE, self::OPENAI_SEARCH_TOOL_TYPE], true)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<StructuredTool> $tools */
    private static function runAgent(BindRecordingModel $model, array $tools, array $middleware): void
    {
        Agent::create(['model' => $model, 'tools' => $tools, 'middleware' => [ProviderToolSearchMiddleware::create(...$middleware)]])
            ->invoke(['messages' => [new HumanMessage('hi')]]);
    }

    public function testPassesTheRequestThroughUnchangedWhenNoToolsAreDeferred(): void
    {
        $model = self::mockModel();

        self::runAgent($model, [self::getWeather(), self::sendEmail()], []);

        self::assertFalse(self::hasSearchTool($model->firstBoundTools()));
        self::assertContainsOnlyInstancesOf(StructuredTool::class, $model->firstBoundTools());
    }

    public function testDefersToolsNamedInSearchableTools(): void
    {
        $model = self::mockModel();

        self::runAgent($model, [self::sendEmail()], [['searchableTools' => ['send_email']]]);

        $email = self::find($model->firstBoundTools(), 'send_email');
        self::assertTrue($email['defer_loading'] ?? false);
    }

    public function testAppendsTheNativeSearchToolWhenAToolIsDeferred(): void
    {
        $model = self::mockModel();

        self::runAgent($model, [self::sendEmail()], [['searchableTools' => ['send_email']]]);

        $bound = $model->firstBoundTools();
        self::assertSame(['type' => self::ANTHROPIC_SEARCH_TOOL_TYPE, 'name' => 'tool_search_tool_bm25'], end($bound));
    }

    public function testAcceptsToolInstancesAndNamesInSearchableTools(): void
    {
        $model = self::mockModel();

        self::runAgent($model, [self::getWeather(), self::sendEmail()], [['searchableTools' => ['get_weather', self::sendEmail()]]]);

        $bound = $model->firstBoundTools();
        self::assertTrue(self::find($bound, 'send_email')['defer_loading'] ?? false);
        self::assertTrue(self::find($bound, 'get_weather')['defer_loading'] ?? false);
    }

    public function testHonorsToolsPreMarkedWithExtrasDeferLoading(): void
    {
        $preMarked = tool(static fn (): string => 'ok', [
            'name' => 'deferred_tool',
            'description' => 'A tool deferred at construction time',
            'schema' => Schema::object([]),
            'extras' => ['defer_loading' => true],
        ]);
        $model = self::mockModel();

        self::runAgent($model, [self::getWeather(), $preMarked], []);

        $bound = $model->firstBoundTools();
        self::assertTrue(self::find($bound, 'deferred_tool')['defer_loading'] ?? false);
        self::assertTrue(self::hasSearchTool($bound));
        self::assertInstanceOf(StructuredTool::class, $bound[0], 'a tool that is not deferred is bound as it is');
    }

    public function testThrowsWhenAToolIsDeferredButTheProviderHasNoServerSideToolSearch(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/requires a provider with server-side tool search/');

        self::runAgent(self::mockModel('ChatMistralAI'), [self::getWeather(), self::sendEmail()], [['searchableTools' => ['send_email']]]);
    }

    public function testThrowsWhenSearchableToolsReferencesAToolThatIsNotPresent(): void
    {
        $this->expectException(\Throwable::class);
        $this->expectExceptionMessageMatches('/searchableTools references tool\(s\) not bound.*does_not_exist/');

        self::runAgent(self::mockModel(), [self::getWeather()], [['searchableTools' => ['does_not_exist']]]);
    }

    // ---- Beyond the upstream cases: the request body a real provider client sends -------------------

    public function testAnthropicRequestBodyCarriesDeferLoadingAndTheSearchTool(): void
    {
        $http = new FakeHttpClient([FakeHttpClient::json(200, [
            'id' => 'msg_1', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-sonnet-4-5',
            'content' => [['type' => 'text', 'text' => 'done']], 'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);
        $model = new ChatAnthropic(['apiKey' => 'sk-test', 'model' => 'claude-sonnet-4-5', 'httpClient' => $http]);

        $agent = Agent::create([
            'model' => $model,
            'tools' => [self::getWeather(), self::sendEmail()],
            'middleware' => [ProviderToolSearchMiddleware::create(['searchableTools' => ['send_email']])],
        ]);
        $agent->invoke(['messages' => [new HumanMessage('hi')]]);

        $tools = $http->lastRequestBody()['tools'];
        self::assertCount(3, $tools);
        self::assertSame('get_weather', $tools[0]['name']);
        self::assertArrayNotHasKey('defer_loading', $tools[0]);
        self::assertSame('send_email', $tools[1]['name']);
        self::assertTrue($tools[1]['defer_loading']);
        self::assertSame('object', $tools[1]['input_schema']['type']);
        self::assertSame(['type' => self::ANTHROPIC_SEARCH_TOOL_TYPE, 'name' => 'tool_search_tool_bm25'], $tools[2]);
        self::assertStringContainsString('advanced-tool-use-2025-11-20', $http->requests[0]['headers']['anthropic-beta'] ?? '');
    }

    public function testOpenAIRequestBodyCarriesDeferLoadingAndTheSearchTool(): void
    {
        // gpt-5.4 is a Responses-API model, so the tools go out in the flat Responses shape.
        $http = new FakeHttpClient([FakeHttpClient::json(200, [
            'id' => 'resp_1', 'object' => 'response', 'model' => 'gpt-5.4', 'status' => 'completed',
            'output' => [['type' => 'message', 'id' => 'msg_1', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'done', 'annotations' => []]]]],
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
        ])]);
        $model = new ChatOpenAI(['apiKey' => 'sk-test', 'model' => 'gpt-5.4', 'httpClient' => $http]);

        $agent = Agent::create([
            'model' => $model,
            'tools' => [self::getWeather(), self::sendEmail()],
            'middleware' => [ProviderToolSearchMiddleware::create(['searchableTools' => ['send_email']])],
        ]);
        $agent->invoke(['messages' => [new HumanMessage('hi')]]);

        $tools = $http->lastRequestBody()['tools'];
        self::assertCount(3, $tools);
        self::assertSame('get_weather', $tools[0]['name']);
        self::assertArrayNotHasKey('defer_loading', $tools[0]);
        self::assertSame('send_email', $tools[1]['name']);
        self::assertTrue($tools[1]['defer_loading']);
        self::assertSame(['type' => self::OPENAI_SEARCH_TOOL_TYPE], $tools[2]);
    }
}
