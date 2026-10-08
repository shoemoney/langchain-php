<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\Messages\AIMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Utils\Testing\FakeHttpClient;
use LangChain\Utils\Testing\FakeToolCallingChatModel;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `langchain/src/agents/tests/modelSettings.test.ts`: a middleware's `modelSettings` reach the model through
 * `bindTools()`.
 *
 * Upstream drives a real `ChatAnthropic` with a mocked client and asserts that `container` reaches the request
 * body and `headers` the request options. This port's `ChatAnthropic` forwards neither of those two as a call
 * option, so the real-model case asserts on settings it does forward (`temperature`, `max_tokens`), and the
 * pass-through of the whole settings bag is asserted on a recording model.
 */
#[CoversClass(Middleware::class)]
final class ModelSettingsTest extends TestCase
{
    public function testShouldPassModelSettingsToRealAnthropicModelViaBindTools(): void
    {
        $http = new FakeHttpClient([
            FakeHttpClient::json(200, [
                'id' => 'msg_123',
                'type' => 'message',
                'role' => 'assistant',
                'content' => [['type' => 'text', 'text' => 'Response from model']],
                'model' => 'claude-sonnet-4-20250514',
                'stop_reason' => 'end_turn',
                'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
            ]),
        ]);

        // A real ChatAnthropic with a mocked transport.
        $model = new ChatAnthropic([
            'apiKey' => 'sk-test',
            'model' => 'claude-sonnet-4-20250514',
            'temperature' => 0,
            'httpClient' => $http,
        ]);

        $middleware = Middleware::create([
            'name' => 'testMiddleware',
            'wrapModelCall' => static fn (array $request, callable $handler): mixed => $handler([
                ...$request,
                'modelSettings' => ['temperature' => 0.25, 'max_tokens' => 77],
            ]),
        ]);

        $agent = Agent::create(['model' => $model, 'tools' => [], 'middleware' => [$middleware]]);

        $result = $agent->invoke(['messages' => [new HumanMessage('Hello, world!')]]);

        // The client was called, with the parameters the middleware set.
        self::assertCount(1, $http->requests);
        $body = $http->lastRequestBody();
        self::assertSame(0.25, $body['temperature']);
        self::assertSame(77, $body['max_tokens']);
        self::assertSame('Response from model', $result['messages'][array_key_last($result['messages'])]->content);
    }

    public function testShouldPassTheWholeModelSettingsBagToBindTools(): void
    {
        $model = new class (['sleep' => 0, 'responses' => [new AIMessage('ok')]]) extends FakeToolCallingChatModel {
            /** @var list<array<string, mixed>> */
            public array $bindKwargs = [];

            public function bindTools(array $tools, array $kwargs = []): static
            {
                $this->bindKwargs[] = $kwargs;
                $next = parent::bindTools($tools, $kwargs);
                $next->bindKwargs = &$this->bindKwargs;

                return $next;
            }
        };

        $settings = ['headers' => ['anthropic-beta' => 'code-execution-2025-08-25,files-api-2025-04-14'], 'container' => 'container_abc123'];
        $agent = Agent::create([
            'model' => $model,
            'tools' => [],
            'middleware' => [Middleware::create([
                'name' => 'testMiddleware',
                'wrapModelCall' => static fn (array $request, callable $handler): mixed => $handler([...$request, 'modelSettings' => $settings]),
            ])],
        ]);

        $agent->invoke(['messages' => [new HumanMessage('Hello, world!')]]);

        // The model is bound once per request, with the settings the middleware set.
        $bound = array_values(array_filter($model->bindKwargs, static fn (array $kwargs): bool => isset($kwargs['container'])));
        self::assertCount(1, $bound);
        self::assertSame($settings['container'], $bound[0]['container']);
        self::assertSame($settings['headers'], $bound[0]['headers']);
    }
}
