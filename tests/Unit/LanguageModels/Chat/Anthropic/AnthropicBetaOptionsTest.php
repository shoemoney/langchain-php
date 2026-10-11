<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Anthropic;

use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\Anthropic\Utils\Params;
use LangChain\Runnables\RunnableConfig;
use LangChain\Utils\Testing\FakeHttpClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ChatAnthropic::class)]
#[CoversClass(Params::class)]
final class AnthropicBetaOptionsTest extends TestCase
{
    private static function model(FakeHttpClient $http, array $fields = []): ChatAnthropic
    {
        return new ChatAnthropic($fields + ['apiKey' => 'test-key', 'httpClient' => $http, 'maxRetries' => 0]);
    }

    private static function http(): FakeHttpClient
    {
        return new FakeHttpClient([FakeHttpClient::json(200, [
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant',
            'content' => [['type' => 'text', 'text' => 'ok']], 'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1],
        ])]);
    }

    public function testAllBetaSourcesMergeAndLeaveBodyWithBoundCacheControl(): void
    {
        $http = self::http();
        $budget = ['type' => 'tokens', 'total' => 20000];
        $model = self::model($http, [
            'model' => 'claude-opus-4-7', 'betas' => ['custom', 'compact-2026-01-12'],
            'contextManagement' => ['edits' => [['type' => 'compact_20260112']]],
            'outputConfig' => ['effort' => 'high'],
        ])->bindTools([['type' => 'memory_20250818', 'name' => 'memory']], [
            'betas' => ['bound', 'custom'], 'cache_control' => ['type' => 'ephemeral'],
        ]);
        $model->invoke('hi', new RunnableConfig(options: ['betas' => ['call', 'bound'], 'outputConfig' => ['task_budget' => $budget]]));
        self::assertSame('custom,compact-2026-01-12,bound,call,context-management-2025-06-27,task-budgets-2026-03-13', $http->requests[0]['headers']['anthropic-beta']);
        $body = $http->lastRequestBody();
        self::assertArrayNotHasKey('betas', $body);
        self::assertSame(['effort' => 'high', 'task_budget' => $budget], $body['output_config']);
        self::assertSame(['edits' => [['type' => 'compact_20260112']]], $body['context_management']);
        self::assertSame(['type' => 'ephemeral'], $body['cache_control']);
    }

    public function testOutputConfigFormatAndResidencyPrecedenceOnWire(): void
    {
        $http = self::http();
        $format = ['type' => 'json_schema', 'schema' => ['type' => 'object']];
        $model = self::model($http, ['output_config' => ['effort' => 'medium', 'format' => $format], 'inference_geo' => 'us']);
        $model->invoke('hi', new RunnableConfig(options: ['outputConfig' => ['effort' => 'low'], 'outputFormat' => ['type' => 'ignored'], 'inferenceGeo' => 'global', 'container' => 'container-id', 'mcp_servers' => [['type' => 'url', 'url' => 'https://example.test/mcp', 'name' => 'test']]]));
        $body = $http->lastRequestBody();
        self::assertSame(['effort' => 'low', 'format' => $format], $body['output_config']);
        self::assertSame('global', $body['inference_geo']);
        self::assertSame('container-id', $body['container']);
        self::assertSame('test', $body['mcp_servers'][0]['name']);
        self::assertArrayNotHasKey('thinking', $body);
        self::assertArrayNotHasKey('anthropic-beta', $http->requests[0]['headers']);
        self::assertSame($format, self::model(self::http())->invocationParams(['outputFormat' => $format])['output_config']['format']);
    }

    public function testStreamingUsesInferredHeaderAndEffectiveBoundOptions(): void
    {
        $events = [
            ['type' => 'message_start', 'message' => ['id' => 'msg_stream', 'type' => 'message', 'role' => 'assistant', 'content' => [], 'usage' => ['input_tokens' => 1, 'output_tokens' => 0]]],
            ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']],
            ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'ok']],
            ['type' => 'content_block_stop', 'index' => 0],
            ['type' => 'message_delta', 'delta' => ['stop_reason' => 'end_turn'], 'usage' => ['output_tokens' => 1]],
            ['type' => 'message_stop'],
        ];
        $sse = implode('', array_map(static fn (array $event): string => 'event: ' . $event['type'] . "\ndata: " . json_encode($event) . "\n\n", $events));
        $http = new FakeHttpClient([], str_split($sse, 17));
        $model = self::model($http)->bindTools([], [
            'thinking' => ['type' => 'adaptive'], 'temperature' => 1,
            'context_management' => ['edits' => [['type' => 'compact_20260112']]],
            'inferenceGeo' => 'us', 'cache_control' => ['type' => 'ephemeral'],
        ]);
        $chunks = iterator_to_array($model->stream('hi', new RunnableConfig(options: ['cache_control' => ['type' => 'ephemeral', 'ttl' => '1h']])));
        self::assertNotEmpty($chunks);
        self::assertSame('compact-2026-01-12', $http->streamRequests[0]['headers']['anthropic-beta']);
        $body = $http->lastRequestBody();
        self::assertTrue($body['stream']);
        self::assertSame(['type' => 'adaptive'], $body['thinking']);
        self::assertArrayNotHasKey('temperature', $body);
        self::assertArrayNotHasKey('betas', $body);
        self::assertSame('us', $body['inference_geo']);
        self::assertSame(['type' => 'ephemeral', 'ttl' => '1h'], $body['cache_control']);
    }

    public static function rejected(): iterable
    {
        yield 'fable explicit disabled' => [['model' => 'claude-fable-5-1', 'thinking' => ['type' => 'disabled']], [], 'thinking.type="disabled"'];
        yield 'fable raw disabled' => [['model' => 'claude-fable-5-1', 'invocationKwargs' => ['thinking' => ['type' => 'disabled']]], [], 'thinking.type="disabled"'];
        yield 'opus5 effort' => [['model' => 'claude-opus-5', 'thinking' => ['type' => 'disabled']], ['outputConfig' => ['effort' => 'max']], 'outputConfig.effort="max"'];
        yield 'call sampling' => [['model' => 'claude-opus-4-7'], ['top_p' => 0.5], 'topP is not supported'];
        yield 'call model' => [[], ['model' => 'claude-sonnet-5', 'thinking' => ['type' => 'enabled']], 'thinking.type="enabled"'];
        yield 'adaptive sampling' => [['thinking' => ['type' => 'adaptive'], 'topK' => 10], [], 'topK is not supported when thinking is enabled'];
    }

    #[DataProvider('rejected')]
    public function testInvalidConfigurationDoesNotReachHttp(array $fields, array $options, string $error): void
    {
        $http = self::http();
        try {
            self::model($http, $fields)->invoke('hi', new RunnableConfig(options: $options));
            self::fail('Expected a compatibility error before HTTP.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString($error, $exception->getMessage());
        }
        try {
            iterator_to_array(self::model($http, $fields)->stream('hi', new RunnableConfig(options: $options)));
            self::fail('Expected a compatibility error before streaming HTTP.');
        } catch (\InvalidArgumentException $exception) {
            self::assertStringContainsString($error, $exception->getMessage());
        }
        self::assertSame([], $http->requests);
        self::assertSame([], $http->streamRequests);
    }

    public function testEnabledThinkingAndConstructorOptionsAreForwardedOnWire(): void
    {
        $http = self::http();
        $thinking = ['type' => 'enabled', 'budget_tokens' => 1024];
        $model = self::model($http, ['thinking' => $thinking, 'temperature' => 1, 'outputConfig' => ['effort' => 'medium'], 'inferenceGeo' => 'us']);
        $model->invoke('hi');
        $body = $http->lastRequestBody();
        self::assertSame($thinking, $body['thinking']);
        self::assertSame(['effort' => 'medium'], $body['output_config']);
        self::assertSame('us', $body['inference_geo']);
        self::assertArrayNotHasKey('temperature', $body);
        self::assertSame(['effort' => 'medium'], $model->kwargs()['outputConfig']);
        self::assertSame(['effort' => 'max'], self::model(self::http(), ['model' => 'claude-opus-5', 'thinking' => ['type' => 'adaptive'], 'outputConfig' => ['effort' => 'max']])->invocationParams()['output_config']);
    }

    public function testImplicitThinkingAndPermittedExplicitOptions(): void
    {
        foreach (['claude-fable-5', 'claude-mythos-5', 'claude-sonnet-5', 'claude-opus-5'] as $model) {
            $params = self::model(self::http(), ['model' => $model, 'temperature' => 1, 'topP' => 1])->invocationParams();
            self::assertArrayNotHasKey('thinking', $params);
            self::assertArrayNotHasKey('temperature', $params);
            self::assertArrayNotHasKey('top_p', $params);
        }
        $params = self::model(self::http(), ['model' => 'claude-opus-4-6', 'thinking' => ['type' => 'adaptive', 'display' => 'omitted'], 'temperature' => 1])->invocationParams();
        self::assertSame(['type' => 'adaptive', 'display' => 'omitted'], $params['thinking']);
        self::assertArrayNotHasKey('temperature', $params);
        self::assertSame(['type' => 'disabled'], self::model(self::http(), ['model' => 'claude-mythos-5', 'thinking' => ['type' => 'disabled']])->invocationParams()['thinking']);
        self::assertSame(['effort' => 'high'], self::model(self::http(), ['model' => 'claude-opus-5', 'thinking' => ['type' => 'disabled'], 'outputConfig' => ['effort' => 'high']])->invocationParams()['output_config']);
        self::assertSame(['type' => 'disabled'], self::model(self::http(), ['thinking' => ['type' => 'disabled']])->kwargs()['thinking']);
        self::assertArrayNotHasKey('thinking', self::model(self::http())->kwargs());
    }

    public function testNonCompactionEditsAndOtherTaskBudgetModelsInferNoBeta(): void
    {
        $params = self::model(self::http(), ['model' => 'claude-opus-4-8', 'contextManagement' => ['edits' => [['type' => 'clear_tool_uses_20250919']]], 'outputConfig' => ['task_budget' => ['type' => 'tokens', 'total' => 20000]]])->invocationParams();
        self::assertArrayNotHasKey('betas', $params);
        self::assertSame('clear_tool_uses_20250919', $params['context_management']['edits'][0]['type']);
    }
}
