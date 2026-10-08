<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI\Tools;

use LangChain\LanguageModels\Chat\OpenAI\Tools\ApplyPatch;
use LangChain\LanguageModels\Chat\OpenAI\Tools\Shell;
use LangChain\LanguageModels\Chat\OpenAI\Tools\WebSearch;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAIResponses;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Utils\Http\HttpResponse;
use LangChain\Utils\Testing\FakeHttpClient;
use LangGraph\Prebuilt\ReactAgent;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A real ReAct graph over a scripted Responses API: the hosted-tool arrays are sent verbatim, the
 * executor tool is bound as its provider definition, and the model's `shell` call runs the callable.
 */
#[CoversNothing]
final class HostedToolsEndToEndTest extends TestCase
{
    private static function response(string $id, array $output): HttpResponse
    {
        return new HttpResponse(200, ['content-type' => 'application/json'], (string) json_encode([
            'id' => $id, 'object' => 'response', 'created_at' => 1, 'status' => 'completed', 'model' => 'gpt-4o',
            'output' => $output,
            'usage' => ['input_tokens' => 1, 'output_tokens' => 1, 'total_tokens' => 2],
        ]));
    }

    public function testShellCallRunsThroughAReactAgentWithHostedToolsBound(): void
    {
        $http = new FakeHttpClient([
            self::response('resp_1', [[
                'type' => 'function_call', 'id' => 'fc_1', 'call_id' => 'call_shell', 'name' => 'shell',
                'arguments' => '{"commands":["echo hi"]}', 'status' => 'completed',
            ]]),
            self::response('resp_2', [[
                'type' => 'message', 'id' => 'msg_1', 'role' => 'assistant', 'status' => 'completed',
                'content' => [['type' => 'output_text', 'text' => 'It printed hi.', 'annotations' => []]],
            ]]),
        ]);
        $model = new ChatOpenAIResponses(['model' => 'gpt-4o', 'apiKey' => 'sk-test', 'httpClient' => $http, 'maxRetries' => 0]);

        $ran = [];
        $shell = Shell::create(['execute' => static function (array $action) use (&$ran): array {
            $ran[] = $action['commands'];

            return ['output' => [['stdout' => "hi\n", 'stderr' => '', 'outcome' => ['type' => 'exit', 'exit_code' => 0]]]];
        }]);
        $patch = ApplyPatch::create(['execute' => static fn (array $op): string => 'patched']);

        $agent = ReactAgent::create(['llm' => $model, 'tools' => [WebSearch::tool(['search_context_size' => 'low']), $shell, $patch]]);
        $result = $agent->invoke(['messages' => [new HumanMessage('run echo hi')]]);

        self::assertSame([['echo hi']], $ran);

        $tools = json_decode($http->requests[0]['body'], true)['tools'];
        self::assertContains(['type' => 'web_search', 'search_context_size' => 'low'], $tools);
        self::assertContains(['type' => 'shell'], $tools);
        self::assertContains(['type' => 'apply_patch'], $tools);

        $messages = $result['messages'];
        $toolMessage = array_values(array_filter($messages, static fn (object $m): bool => $m instanceof ToolMessage))[0];
        self::assertSame('call_shell', $toolMessage->toolCallId);
        self::assertSame("hi\n", json_decode((string) $toolMessage->content, true)['output'][0]['stdout']);
        self::assertSame('It printed hi.', end($messages)->content[0]['text']);
    }
}
