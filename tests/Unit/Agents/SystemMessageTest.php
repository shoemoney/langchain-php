<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Tests\Unit\Prebuilt\ReactAgentFixtures;
use LangChain\Tests\Unit\Prebuilt\SpyingToolCallingChatModel;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware;
use LangGraph\Agents\Middleware\Utils as MiddlewareUtils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `langchain/src/agents/tests/systemMessage.test.ts`: how a system prompt (a string or a `SystemMessage`) set on
 * the agent, or changed by `wrapModelCall` middleware, reaches the model.
 *
 * `request.systemMessage.concat(x)` is `MiddlewareUtils::concatSystemMessage()`; `systemPrompt: undefined` is a
 * `null` entry in the request.
 */
#[CoversClass(Agent::class)]
final class SystemMessageTest extends TestCase
{
    private SpyingToolCallingChatModel $model;

    protected function setUp(): void
    {
        $this->model = ReactAgentFixtures::spy([new AIMessage('Mocked response')]);
    }

    private static function concat(SystemMessage $message, string|SystemMessage $chunk): SystemMessage
    {
        return MiddlewareUtils::concatSystemMessage($message, $chunk);
    }

    /** @return list<BaseMessage> the messages the model received in its only call */
    private function payload(): array
    {
        self::assertCount(1, $this->model->invokeCalls);

        return $this->model->invokeCalls[0][0];
    }

    /** @param callable(array<string, mixed>, callable): mixed $wrap */
    private function agentWith(callable $wrap, array $options = []): \LangGraph\Agents\ReactAgent
    {
        return Agent::create([
            'model' => $this->model,
            ...$options,
            'middleware' => [Middleware::create(['name' => 'TestMiddleware', 'wrapModelCall' => $wrap])],
        ]);
    }

    public function testShouldAllowToSetNothingAsSystemMessage(): void
    {
        $agent = Agent::create(['model' => $this->model]);
        $agent->invoke(['messages' => 'Hello World!']);

        $payload = $this->payload();
        self::assertCount(1, $payload);
        self::assertInstanceOf(HumanMessage::class, $payload[0]);
    }

    public function testShouldAllowToSetASystemMessageAsString(): void
    {
        $agent = Agent::create(['model' => $this->model, 'systemPrompt' => 'You are a helpful assistant.']);
        $agent->invoke(['messages' => 'Hello World!']);

        $payload = $this->payload();
        self::assertCount(2, $payload);
        self::assertInstanceOf(SystemMessage::class, $payload[0]);
        self::assertInstanceOf(HumanMessage::class, $payload[1]);
    }

    public function testShouldAllowToSetASystemMessageAsSystemMessageObject(): void
    {
        $agent = Agent::create(['model' => $this->model, 'systemPrompt' => new SystemMessage('You are a helpful assistant.')]);
        $agent->invoke(['messages' => 'Hello World!']);

        $payload = $this->payload();
        self::assertCount(2, $payload);
        self::assertInstanceOf(SystemMessage::class, $payload[0]);
        self::assertInstanceOf(HumanMessage::class, $payload[1]);
    }

    public function testShouldAllowSetSystemMessageAsStringInWrapModelCall(): void
    {
        $agent = $this->agentWith(static fn (array $request, callable $handler): mixed => $handler([...$request, 'systemPrompt' => 'You are a helpful assistant.']));
        $agent->invoke(['messages' => 'Hello World!']);

        $payload = $this->payload();
        self::assertCount(2, $payload);
        self::assertInstanceOf(SystemMessage::class, $payload[0]);
        self::assertInstanceOf(HumanMessage::class, $payload[1]);
        self::assertSame('You are a helpful assistant.', $payload[0]->text());
    }

    public function testShouldAllowSetSystemMessageAsSystemMessageObjectInWrapModelCall(): void
    {
        $agent = $this->agentWith(static fn (array $request, callable $handler): mixed => $handler([...$request, 'systemMessage' => new SystemMessage('You are a helpful assistant.')]));
        $agent->invoke(['messages' => 'Hello World!']);

        $payload = $this->payload();
        self::assertCount(2, $payload);
        self::assertInstanceOf(SystemMessage::class, $payload[0]);
        self::assertInstanceOf(HumanMessage::class, $payload[1]);
        self::assertSame('You are a helpful assistant.', $payload[0]->text());
    }

    public function testShouldAllowToUpdateSystemPromptInWrapModelCall(): void
    {
        $agent = $this->agentWith(
            static fn (array $request, callable $handler): mixed => $handler([...$request, 'systemPrompt' => $request['systemPrompt'] . ' I know!']),
            ['systemPrompt' => 'You are a helpful assistant.'],
        );
        $agent->invoke(['messages' => 'Hello World!']);

        $payload = $this->payload();
        self::assertCount(2, $payload);
        self::assertSame('You are a helpful assistant. I know!', $payload[0]->text());
        self::assertSame([['type' => 'text', 'text' => 'You are a helpful assistant. I know!']], $payload[0]->content);
    }

    public function testShouldAllowToUpdateSystemMessageInWrapModelCall(): void
    {
        $agent = $this->agentWith(
            fn (array $request, callable $handler): mixed => $handler([...$request, 'systemMessage' => self::concat($request['systemMessage'], ' I know!')]),
            ['systemPrompt' => 'You are a helpful assistant.'],
        );
        $agent->invoke(['messages' => 'Hello World!']);

        $payload = $this->payload();
        self::assertCount(2, $payload);
        self::assertSame('You are a helpful assistant. I know!', $payload[0]->text());
        self::assertSame([
            ['type' => 'text', 'text' => 'You are a helpful assistant.'],
            ['type' => 'text', 'text' => ' I know!'],
        ], $payload[0]->content);
    }

    public function testShouldNotAllowToSetSystemMessageAndSystemPromptInWrapModelCall(): void
    {
        $agent = $this->agentWith(static fn (array $request, callable $handler): mixed => $handler([
            ...$request,
            'systemPrompt' => 'You are a helpful assistant.',
            'systemMessage' => new SystemMessage('You are a helpful assistant.'),
        ]));

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('Cannot change both systemPrompt and systemMessage in the same request.');

        $agent->invoke(['messages' => 'Hello World!']);
    }

    public function testShouldNotAllowToUpdateSystemMessageAndUpdateSystemPromptInWrapModelCall(): void
    {
        $agent = $this->agentWith(
            fn (array $request, callable $handler): mixed => $handler([
                ...$request,
                'systemPrompt' => $request['systemPrompt'] . ' I know!',
                'systemMessage' => self::concat($request['systemMessage'], " I don't know!"),
            ]),
            ['systemPrompt' => 'You are a helpful assistant.'],
        );

        $this->expectException(\Throwable::class);
        $this->expectExceptionMessage('Cannot change both systemPrompt and systemMessage in the same request.');

        $agent->invoke(['messages' => 'Hello World!']);
    }

    public function testShouldAllowToSetCacheControlInWrapModelCall(): void
    {
        $cached = new SystemMessage(['content' => [['type' => 'text', 'text' => 'I am cached', 'cache_control' => ['type' => 'ephemeral', 'ttl' => '5m']]]]);
        $agent = $this->agentWith(
            fn (array $request, callable $handler): mixed => $handler([...$request, 'systemMessage' => self::concat($request['systemMessage'], $cached)]),
            ['systemPrompt' => 'You are a helpful assistant.'],
        );
        $agent->invoke(['messages' => 'Hello World!']);

        $payload = $this->payload();
        self::assertCount(2, $payload);
        self::assertSame('You are a helpful assistant.I am cached', $payload[0]->text());
        self::assertSame([
            ['type' => 'text', 'text' => 'You are a helpful assistant.'],
            ['type' => 'text', 'text' => 'I am cached', 'cache_control' => ['type' => 'ephemeral', 'ttl' => '5m']],
        ], $payload[0]->content);
    }

    public function testShouldAllowToSetCacheControlInWrapModelCallWithExistingSystemMessage(): void
    {
        $cached = new SystemMessage(['content' => [['type' => 'text', 'text' => 'I am also cached', 'cache_control' => ['type' => 'ephemeral', 'ttl' => '5m']]]]);
        $agent = $this->agentWith(
            fn (array $request, callable $handler): mixed => $handler([...$request, 'systemMessage' => self::concat($request['systemMessage'], $cached)]),
            ['systemPrompt' => new SystemMessage(['content' => [['type' => 'text', 'text' => 'You are a helpful assistant.', 'cache_control' => ['type' => 'ephemeral', 'ttl' => '1m']]]])],
        );
        $agent->invoke(['messages' => 'Hello World!']);

        $payload = $this->payload();
        self::assertCount(2, $payload);
        self::assertSame('You are a helpful assistant.I am also cached', $payload[0]->text());
        self::assertSame([
            ['type' => 'text', 'text' => 'You are a helpful assistant.', 'cache_control' => ['type' => 'ephemeral', 'ttl' => '1m']],
            ['type' => 'text', 'text' => 'I am also cached', 'cache_control' => ['type' => 'ephemeral', 'ttl' => '5m']],
        ], $payload[0]->content);
    }

    public function testAllowsUpdatesOfSystemMessageInByMultipleMiddleware(): void
    {
        $middleware1 = Middleware::create([
            'name' => 'TestMiddleware1',
            'wrapModelCall' => fn (array $request, callable $handler): mixed => $handler([...$request, 'systemMessage' => self::concat($request['systemMessage'], ' I know!')]),
        ]);
        $middleware2 = Middleware::create([
            'name' => 'TestMiddleware2',
            'wrapModelCall' => static fn (array $request, callable $handler): mixed => $handler([...$request, 'systemPrompt' => $request['systemPrompt'] . " I don't know!"]),
        ]);
        $middleware3 = Middleware::create([
            'name' => 'TestMiddleware3',
            'wrapModelCall' => fn (array $request, callable $handler): mixed => $handler([...$request, 'systemMessage' => self::concat($request['systemMessage'], ' Oh no, I know!')]),
        ]);
        $agent = Agent::create([
            'model' => $this->model,
            'systemPrompt' => 'You are a helpful assistant.',
            'middleware' => [$middleware1, $middleware2, $middleware3],
        ]);
        $agent->invoke(['messages' => 'Hello World!']);

        $payload = $this->payload();
        self::assertCount(2, $payload);
        self::assertSame("You are a helpful assistant. I know! I don't know! Oh no, I know!", $payload[0]->text());
        self::assertSame([
            ['type' => 'text', 'text' => "You are a helpful assistant. I know! I don't know!"],
            ['type' => 'text', 'text' => ' Oh no, I know!'],
        ], $payload[0]->content);
    }

    public function testWillOverwriteCacheControlIfMiddlewareUpdateSystemPrompt(): void
    {
        $agent = $this->agentWith(
            static fn (array $request, callable $handler): mixed => $handler([...$request, 'systemPrompt' => $request['systemPrompt'] . ' I know!']),
            ['systemPrompt' => new SystemMessage(['content' => [['type' => 'text', 'text' => 'You are a helpful assistant.', 'cache_control' => ['type' => 'ephemeral', 'ttl' => '1m']]]])],
        );
        $agent->invoke(['messages' => 'Hello World!']);

        $payload = $this->payload();
        self::assertCount(2, $payload);
        self::assertSame('You are a helpful assistant. I know!', $payload[0]->text());
        self::assertSame([['type' => 'text', 'text' => 'You are a helpful assistant. I know!']], $payload[0]->content);
    }

    public function testShouldAllowToResetSystemPromptInWrapModelCall(): void
    {
        $agent = $this->agentWith(
            static fn (array $request, callable $handler): mixed => $handler([...$request, 'systemPrompt' => null]),
            ['systemPrompt' => 'You are a helpful assistant.'],
        );
        $agent->invoke(['messages' => 'Hello World!']);

        $payload = $this->payload();
        self::assertCount(1, $payload);
        self::assertInstanceOf(HumanMessage::class, $payload[0]);
    }

    public function testShouldAllowToResetSystemMessageInWrapModelCall(): void
    {
        $agent = $this->agentWith(
            static fn (array $request, callable $handler): mixed => $handler([...$request, 'systemMessage' => null]),
            ['systemPrompt' => 'You are a helpful assistant.'],
        );
        $agent->invoke(['messages' => 'Hello World!']);

        $payload = $this->payload();
        self::assertCount(1, $payload);
        self::assertInstanceOf(HumanMessage::class, $payload[0]);
    }

    public function testShouldHandleMiddlewareSettingSystemMessageToEmptySystemMessage(): void
    {
        $agent = $this->agentWith(
            static fn (array $request, callable $handler): mixed => $handler([...$request, 'systemMessage' => new SystemMessage('')]),
            ['systemPrompt' => 'You are a helpful assistant.'],
        );
        $agent->invoke(['messages' => 'Hello World!']);

        $payload = $this->payload();
        self::assertCount(1, $payload);
        self::assertInstanceOf(HumanMessage::class, $payload[0]);
    }

    public function testShouldHandleMiddlewareConcatenatingEmptyString(): void
    {
        $agent = $this->agentWith(
            fn (array $request, callable $handler): mixed => $handler([...$request, 'systemMessage' => self::concat($request['systemMessage'], '')]),
            ['systemPrompt' => 'You are a helpful assistant.'],
        );
        $agent->invoke(['messages' => 'Hello World!']);

        $payload = $this->payload();
        self::assertCount(2, $payload);
        self::assertInstanceOf(SystemMessage::class, $payload[0]);
        self::assertSame('You are a helpful assistant.', $payload[0]->text());
        self::assertSame([['type' => 'text', 'text' => 'You are a helpful assistant.']], $payload[0]->content);
    }

    public function testShouldHandleSystemMessageWithMultipleContentBlocks(): void
    {
        $fourFive = new SystemMessage(['content' => [['type' => 'text', 'text' => 'Fourth block'], ['type' => 'text', 'text' => 'Fifth block']]]);
        $agent = $this->agentWith(
            fn (array $request, callable $handler): mixed => $handler([...$request, 'systemMessage' => self::concat($request['systemMessage'], $fourFive)]),
            ['systemPrompt' => new SystemMessage(['content' => [
                ['type' => 'text', 'text' => 'First block'],
                ['type' => 'text', 'text' => 'Second block'],
                ['type' => 'text', 'text' => 'Third block'],
            ]])],
        );
        $agent->invoke(['messages' => 'Hello World!']);

        $payload = $this->payload();
        self::assertSame([
            ['type' => 'text', 'text' => 'First block'],
            ['type' => 'text', 'text' => 'Second block'],
            ['type' => 'text', 'text' => 'Third block'],
            ['type' => 'text', 'text' => 'Fourth block'],
            ['type' => 'text', 'text' => 'Fifth block'],
        ], $payload[0]->content);
    }

    public function testShouldAllowOneMiddlewareToSetSystemPromptAndNextToSetSystemMessage(): void
    {
        $middleware1 = Middleware::create([
            'name' => 'TestMiddleware1',
            'wrapModelCall' => static fn (array $request, callable $handler): mixed => $handler([...$request, 'systemPrompt' => 'First']),
        ]);
        $middleware2 = Middleware::create([
            'name' => 'TestMiddleware2',
            'wrapModelCall' => fn (array $request, callable $handler): mixed => $handler([...$request, 'systemMessage' => self::concat($request['systemMessage'], ' Second')]),
        ]);
        $agent = Agent::create(['model' => $this->model, 'middleware' => [$middleware1, $middleware2]]);
        $agent->invoke(['messages' => 'Hello World!']);

        $payload = $this->payload();
        self::assertCount(2, $payload);
        self::assertInstanceOf(SystemMessage::class, $payload[0]);
        self::assertInstanceOf(HumanMessage::class, $payload[1]);
        self::assertSame('First Second', $payload[0]->text());
        self::assertSame([
            ['type' => 'text', 'text' => 'First'],
            ['type' => 'text', 'text' => ' Second'],
        ], $payload[0]->content);
    }

    public function testShouldHandleSystemMessageWithAdditionalKwargs(): void
    {
        $agent = Agent::create([
            'model' => $this->model,
            'systemPrompt' => new SystemMessage(['content' => 'Test', 'additional_kwargs' => ['custom' => 'value']]),
        ]);
        $agent->invoke(['messages' => 'Hello World!']);

        $payload = $this->payload();
        self::assertSame(['custom' => 'value'], $payload[0]->additional_kwargs);
    }

    public function testShouldHandleSystemMessageWithAdditionalKwargsInMiddleware(): void
    {
        $extra = new SystemMessage([
            'additional_kwargs' => ['another' => 'value'],
            'content' => [['type' => 'text', 'text' => 'Fourth block'], ['type' => 'text', 'text' => 'Fifth block']],
        ]);
        $agent = $this->agentWith(
            fn (array $request, callable $handler): mixed => $handler([...$request, 'systemMessage' => self::concat($request['systemMessage'], $extra)]),
            ['systemPrompt' => new SystemMessage(['content' => 'Test', 'additional_kwargs' => ['custom' => 'value']])],
        );
        $agent->invoke(['messages' => 'Hello World!']);

        $payload = $this->payload();
        self::assertCount(2, $payload);
        self::assertInstanceOf(SystemMessage::class, $payload[0]);
        self::assertInstanceOf(HumanMessage::class, $payload[1]);
        self::assertSame(['custom' => 'value', 'another' => 'value'], $payload[0]->additional_kwargs);
        self::assertSame([
            ['type' => 'text', 'text' => 'Test'],
            ['type' => 'text', 'text' => 'Fourth block'],
            ['type' => 'text', 'text' => 'Fifth block'],
        ], $payload[0]->content);
    }

    public function testShouldHandleMiddlewareChainingSystemMessageConcatMultipleTimes(): void
    {
        $agent = $this->agentWith(
            fn (array $request, callable $handler): mixed => $handler([
                ...$request,
                'systemMessage' => self::concat(self::concat(self::concat($request['systemMessage'], ' First'), ' Second'), ' Third'),
            ]),
            ['systemPrompt' => 'Base'],
        );
        $agent->invoke(['messages' => 'Hello World!']);

        $payload = $this->payload();
        self::assertSame('Base First Second Third', $payload[0]->text());
    }
}
