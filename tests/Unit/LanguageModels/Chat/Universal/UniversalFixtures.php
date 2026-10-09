<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Universal;

use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;
use LangChain\Utils\Testing\FakeHttpClient;

use function LangChain\Tools\tool;

/**
 * Scripted provider replies for the `initChatModel` tests, standing in for the live calls the upstream
 * integration suite makes. One fake transport per provider wire format.
 */
final class UniversalFixtures
{
    private function __construct()
    {
    }

    /** An OpenAI Chat Completions reply, as every OpenAI-compatible provider answers it. */
    public static function completion(string $text, ?array $toolCalls = null, string $id = 'chatcmpl-1'): array
    {
        $message = ['role' => 'assistant', 'content' => $text];
        if ($toolCalls !== null) {
            $message['content'] = null;
            $message['tool_calls'] = $toolCalls;
        }

        return [
            'id' => $id,
            'object' => 'chat.completion',
            'created' => 1,
            'model' => 'gpt-4o-mini',
            'choices' => [['index' => 0, 'message' => $message, 'finish_reason' => $toolCalls !== null ? 'tool_calls' : 'stop']],
            'usage' => ['prompt_tokens' => 7, 'completion_tokens' => 3, 'total_tokens' => 10],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function openAiToolCall(string $name, array $args, string $id = 'call_1'): array
    {
        return [['id' => $id, 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($args)]]];
    }

    /** An Anthropic Messages reply. */
    public static function anthropicMessage(string $text, ?array $toolUse = null, string $id = 'msg_1'): array
    {
        return [
            'id' => $id,
            'type' => 'message',
            'role' => 'assistant',
            'model' => 'claude-sonnet-4-5',
            'content' => $toolUse !== null ? [$toolUse] : [['type' => 'text', 'text' => $text]],
            'stop_reason' => $toolUse !== null ? 'tool_use' : 'end_turn',
            'usage' => ['input_tokens' => 12, 'output_tokens' => 4],
        ];
    }

    /** An Ollama NDJSON reply, which Ollama's client reads as a stream even for `invoke()`. */
    public static function ollamaStream(string $text): array
    {
        $records = [
            ['model' => 'llama3', 'message' => ['role' => 'assistant', 'content' => $text], 'done' => false],
            [
                'model' => 'llama3',
                'created_at' => '2026-10-08T00:00:00Z',
                'message' => ['role' => 'assistant', 'content' => ''],
                'done' => true,
                'done_reason' => 'stop',
                'prompt_eval_count' => 19,
                'eval_count' => 20,
            ],
        ];

        return [implode('', array_map(static fn (array $r): string => json_encode($r) . "\n", $records))];
    }

    /**
     * Server-sent events for a streamed OpenAI-compatible reply.
     *
     * @param list<string> $pieces
     *
     * @return list<string>
     */
    public static function sse(array $pieces): array
    {
        $events = [];
        foreach ($pieces as $piece) {
            $events[] = 'data: ' . json_encode(['id' => '1', 'model' => 'gpt-4o-mini', 'choices' => [['index' => 0, 'delta' => ['content' => $piece]]]]) . "\n\n";
        }
        $events[] = 'data: ' . json_encode(['id' => '1', 'model' => 'gpt-4o-mini', 'choices' => [['index' => 0, 'delta' => [], 'finish_reason' => 'stop']]]) . "\n\n";
        $events[] = "data: [DONE]\n\n";

        return $events;
    }

    /** One OpenAI-compatible reply, enough for several calls. */
    public static function openAiHttp(string $text = 'I am a model.', int $replies = 1): FakeHttpClient
    {
        return new FakeHttpClient(
            array_map(static fn (int $i): \LangChain\Utils\Http\HttpResponse => FakeHttpClient::json(200, self::completion($text, null, 'chatcmpl-' . $i)), range(1, $replies)),
            self::sse(str_split($text, 4)),
        );
    }

    public static function anthropicHttp(string $text = 'I am Claude.', int $replies = 1): FakeHttpClient
    {
        return new FakeHttpClient(array_map(
            static fn (int $i): \LangChain\Utils\Http\HttpResponse => FakeHttpClient::json(200, self::anthropicMessage($text, null, 'msg_' . $i)),
            range(1, $replies),
        ));
    }

    /**
     * The transport and constructor fields for each provider the registry ships, keyed by provider.
     *
     * @return array<string, array{0: FakeHttpClient, 1: array<string, mixed>}>
     */
    public static function providers(): array
    {
        $azure = [
            'azureOpenAIEndpoint' => 'https://res.openai.azure.com',
            'azureOpenAIApiVersion' => '2024-10-21',
            'azureOpenAIApiKey' => 'azure-key',
            'azureOpenAIApiDeploymentName' => 'gpt-4o',
        ];

        return [
            'openai' => [self::openAiHttp('I am OpenAI.'), ['apiKey' => 'sk-test']],
            'anthropic' => [self::anthropicHttp('I am Claude.'), ['apiKey' => 'sk-ant-test']],
            'azure_openai' => [self::openAiHttp('I am Azure.'), $azure],
            'ollama' => [new FakeHttpClient([], self::ollamaStream('I am Llama.')), ['model' => 'llama3']],
            'fireworks' => [self::openAiHttp('I am Fireworks.'), ['apiKey' => 'fw-test']],
            'together' => [self::openAiHttp('I am Together.'), ['apiKey' => 'tg-test']],
            'deepseek' => [self::openAiHttp('I am DeepSeek.'), ['apiKey' => 'ds-test', 'model' => 'deepseek-chat']],
            'xai' => [self::openAiHttp('I am Grok.'), ['apiKey' => 'xai-test']],
        ];
    }

    public static function weatherTool(): StructuredTool
    {
        return tool(
            static fn (array $in): string => json_encode($in),
            [
                'name' => 'GetWeather',
                'description' => 'Get the current weather in a given location',
                'schema' => Schema::object(['location' => ['type' => 'string', 'description' => 'The city and state, e.g. San Francisco, CA']], ['location']),
            ],
        );
    }

    public static function populationTool(): StructuredTool
    {
        return tool(
            static fn (array $in): string => json_encode($in),
            [
                'name' => 'GetPopulation',
                'description' => 'Get the current population in a given location',
                'schema' => Schema::object(['location' => ['type' => 'string', 'description' => 'The city and state, e.g. San Francisco, CA']], ['location']),
            ],
        );
    }
}
