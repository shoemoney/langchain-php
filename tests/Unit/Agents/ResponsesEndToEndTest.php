<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\Messages\HumanMessage;
use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Testing\FakeHttpClient;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Responses\ResponseFormats;
use LangGraph\Checkpoint\MemorySaver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Structured responses through a real provider client (`ChatOpenAI` over a scripted transport), the agent graph
 * and a checkpointer: what the request carries for each strategy, and what comes back in the state.
 */
#[CoversClass(ResponseFormats::class)]
final class ResponsesEndToEndTest extends TestCase
{
    private const SCHEMA = [
        'type' => 'object',
        'title' => 'weather_report',
        'description' => 'A weather report.',
        'properties' => ['city' => ['type' => 'string'], 'temperature' => ['type' => 'number']],
        'required' => ['city', 'temperature'],
    ];

    private static function completion(array $message, string $finish, string $id): HttpResponse
    {
        return FakeHttpClient::json(200, [
            'id' => $id, 'object' => 'chat.completion', 'model' => 'gpt-4o',
            'choices' => [['index' => 0, 'message' => ['role' => 'assistant', ...$message], 'finish_reason' => $finish]],
            'usage' => ['prompt_tokens' => 5, 'completion_tokens' => 5, 'total_tokens' => 10],
        ]);
    }

    private static function model(FakeHttpClient $http): ChatOpenAI
    {
        return new ChatOpenAI(['model' => 'gpt-4o', 'apiKey' => 'sk-test', 'useResponsesApi' => false, 'maxRetries' => 0, 'httpClient' => $http]);
    }

    public function testToolStrategyOffersTheStructuredToolAndReturnsItsArgumentsAsTheStructuredResponse(): void
    {
        $http = new FakeHttpClient([self::completion([
            'content' => null,
            'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'weather_report', 'arguments' => '{"city":"Tokyo","temperature":21.5}']]],
        ], 'tool_calls', 'chatcmpl-1')]);
        $agent = Agent::create([
            'model' => self::model($http),
            'tools' => [],
            'responseFormat' => ResponseFormats::toolStrategy(self::SCHEMA),
            'checkpointer' => new MemorySaver(),
        ]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Weather in Tokyo?')]], ['configurable' => ['thread_id' => 'tool-strategy']]);

        self::assertSame(['city' => 'Tokyo', 'temperature' => 21.5], $result['structuredResponse']);
        // human, ai (the structured call), tool (the parsed value), ai ("Returning structured response").
        self::assertCount(4, $result['messages']);
        self::assertStringContainsString('Returning structured response', $result['messages'][3]->content);

        $body = json_decode($http->requests[0]['body'], true);
        self::assertSame('weather_report', $body['tools'][0]['function']['name']);
        self::assertSame(self::SCHEMA['properties'], $body['tools'][0]['function']['parameters']['properties']);
        self::assertSame('required', $body['tool_choice']);

        $saved = $agent->checkpointer->getTuple(['configurable' => ['thread_id' => 'tool-strategy']]);
        // The structured response channel is untracked (as upstream's UntrackedValue): the messages are what persists.
        self::assertCount(4, $saved->checkpoint->channelValues['messages']);
    }

    public function testToolStrategyRetriesWithTheParseErrorWhenTheArgumentsDoNotMatch(): void
    {
        $http = new FakeHttpClient([
            self::completion([
                'content' => null,
                'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'weather_report', 'arguments' => '{"city":"Tokyo"}']]],
            ], 'tool_calls', 'chatcmpl-1'),
            self::completion([
                'content' => null,
                'tool_calls' => [['id' => 'call_2', 'type' => 'function', 'function' => ['name' => 'weather_report', 'arguments' => '{"city":"Tokyo","temperature":3}']]],
            ], 'tool_calls', 'chatcmpl-2'),
        ]);
        $agent = Agent::create(['model' => self::model($http), 'tools' => [], 'responseFormat' => ResponseFormats::toolStrategy(self::SCHEMA)]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Weather in Tokyo?')]]);

        self::assertSame(['city' => 'Tokyo', 'temperature' => 3], $result['structuredResponse']);
        // The second request carries the first failure back as the tool result.
        $second = json_decode($http->requests[1]['body'], true);
        $toolMessage = array_values(array_filter($second['messages'], static fn (array $m): bool => $m['role'] === 'tool'))[0];
        self::assertSame('call_1', $toolMessage['tool_call_id']);
        self::assertStringContainsString("Failed to parse structured output for tool 'weather_report'", $toolMessage['content']);
    }
}
