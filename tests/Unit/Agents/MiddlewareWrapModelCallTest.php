<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Messages\AIMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangChain\Tests\Unit\Agents\Support\FakeToolCallingModel;
use LangChain\Tests\Unit\Prebuilt\ReactAgentFixtures;
use LangChain\Tools\Schema;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangChain\Utils\Testing\FakeToolCallingChatModel;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Errors\MiddlewareError;
use LangGraph\Agents\Middleware;
use LangGraph\Agents\Middleware\Utils as MiddlewareUtils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * The `wrapModelCall` describe block of `langchain/src/agents/tests/middleware.test.ts`.
 *
 * Not converted: the three "supports setting responseFormat with wrapModelCall" cases (providerStrategy,
 * toolStrategy, raw JSON schema), which need the structured response strategies of WP-21c.
 */
#[CoversClass(Middleware::class)]
final class MiddlewareWrapModelCallTest extends TestCase
{
    /** `new AIMessage({ ...response, ...changes })`. */
    private static function rebuild(AIMessage $response, array $changes): AIMessage
    {
        return new AIMessage([...$response->kwargs(), ...$changes]);
    }

    public function testShouldComposeThreeMiddlewaresWhereFirstIsOutermostWrapper(): void
    {
        // Request: auth -> retry -> cache -> model. Response: model -> cache -> retry -> auth.
        $executionOrder = [];
        $systemPrompts = [];
        $actualSystemPromptSentToModel = null;

        // Auth middleware (first = outermost wrapper).
        $authMiddleware = Middleware::create([
            'name' => 'AuthMiddleware',
            'contextSchema' => ['type' => 'object', 'properties' => ['foobar' => ['type' => 'string']]],
            'wrapModelCall' => static function (array $request, callable $handler) use (&$executionOrder, &$systemPrompts): AIMessage {
                $executionOrder[] = 'auth:before';
                $systemPrompts[] = $request['systemMessage']->text();

                // Modify the request: add auth context to the system prompt.
                $modifiedRequest = [...$request, 'systemMessage' => MiddlewareUtils::concatSystemMessage($request['systemMessage'], "\n[AUTH: user authenticated]")];

                // Call the inner handler (retry middleware).
                $response = $handler($modifiedRequest);

                $executionOrder[] = 'auth:after';

                // Modify the response: add an auth prefix.
                return self::rebuild($response, ['content' => '[AUTH-WRAPPED] ' . $response->content]);
            },
        ]);

        // Retry middleware (second = middle wrapper).
        $retryMiddleware = Middleware::create([
            'name' => 'RetryMiddleware',
            'wrapModelCall' => static function (array $request, callable $handler) use (&$executionOrder, &$systemPrompts): AIMessage {
                $executionOrder[] = 'retry:before';
                $systemPrompts[] = $request['systemMessage']->text();

                $modifiedRequest = [...$request, 'systemMessage' => MiddlewareUtils::concatSystemMessage($request['systemMessage'], "\n[RETRY: attempt 1]")];
                $response = $handler($modifiedRequest);

                $executionOrder[] = 'retry:after';

                return self::rebuild($response, ['content' => '[RETRY-WRAPPED] ' . $response->content]);
            },
        ]);

        // Cache middleware (third = innermost wrapper, closest to the model).
        $cacheMiddleware = Middleware::create([
            'name' => 'CacheMiddleware',
            'wrapModelCall' => static function (array $request, callable $handler) use (&$executionOrder, &$systemPrompts, &$actualSystemPromptSentToModel): AIMessage {
                $executionOrder[] = 'cache:before';
                $systemPrompts[] = $request['systemMessage']->text();

                $modifiedRequest = [...$request, 'systemMessage' => MiddlewareUtils::concatSystemMessage($request['systemMessage'], "\n[CACHE: miss]")];

                // Capture what will actually be sent to the model.
                $actualSystemPromptSentToModel = $modifiedRequest['systemMessage']->text();

                $response = $handler($modifiedRequest);

                $executionOrder[] = 'cache:after';

                return self::rebuild($response, ['content' => '[CACHE-WRAPPED] ' . $response->content]);
            },
        ]);

        $model = ReactAgentFixtures::spy([new AIMessage('Hello from model')]);

        $agent = Agent::create([
            'model' => $model,
            'tools' => [],
            'systemPrompt' => 'You are helpful',
            'middleware' => [$authMiddleware, $retryMiddleware, $cacheMiddleware],
        ]);

        $result = $agent->invoke(['messages' => [['role' => 'user', 'content' => 'Hi']]]);

        // Execution order: auth -> retry -> cache -> model -> cache -> retry -> auth.
        self::assertSame(['auth:before', 'retry:before', 'cache:before', 'cache:after', 'retry:after', 'auth:after'], $executionOrder);

        // The system prompts accumulated correctly: each middleware sees the prompt BEFORE it adds its own change.
        self::assertSame([
            'You are helpful',
            "You are helpful\n[AUTH: user authenticated]",
            "You are helpful\n[AUTH: user authenticated]\n[RETRY: attempt 1]",
        ], $systemPrompts);

        // The response was wrapped in the right order (innermost to outermost).
        $lastMessage = $result['messages'][array_key_last($result['messages'])];
        self::assertSame('[AUTH-WRAPPED] [RETRY-WRAPPED] [CACHE-WRAPPED] Hello from model', $lastMessage->content);

        // The final system prompt that was sent to the model.
        self::assertSame("You are helpful\n[AUTH: user authenticated]\n[RETRY: attempt 1]\n[CACHE: miss]", $actualSystemPromptSentToModel);

        // The model received the correct messages structure.
        self::assertCount(1, $model->invokeCalls);
        $systemMessage = $model->invokeCalls[0][0][0];

        // The model receives the system message + the user message.
        self::assertInstanceOf(SystemMessage::class, $systemMessage);
        self::assertSame([
            ['type' => 'text', 'text' => 'You are helpful'],
            ['type' => 'text', 'text' => "\n[AUTH: user authenticated]"],
            ['type' => 'text', 'text' => "\n[RETRY: attempt 1]"],
            ['type' => 'text', 'text' => "\n[CACHE: miss]"],
        ], $systemMessage->content);
    }

    public function testShouldAllowMiddlewareToAccessStateAndRuntime(): void
    {
        $captured = [];

        $inspectorMiddleware = Middleware::create([
            'name' => 'InspectorMiddleware',
            'stateSchema' => ['type' => 'object', 'properties' => ['foobar' => ['type' => 'string']], 'required' => ['foobar']],
            'contextSchema' => ['type' => 'object', 'properties' => ['middlewareContext' => ['type' => 'number']], 'required' => ['middlewareContext']],
            'wrapModelCall' => static function (array $request, callable $handler) use (&$captured): mixed {
                self::assertIsString($request['systemPrompt']);

                $captured = ['state' => $request['state'], 'runtime' => $request['runtime']];

                return $handler($request);
            },
        ]);

        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('Response')]),
            'tools' => [],
            'middleware' => [$inspectorMiddleware],
            'contextSchema' => ['type' => 'object', 'properties' => ['globalContext' => ['type' => 'number']], 'required' => ['globalContext']],
        ]);

        $agent->invoke(
            ['messages' => [['role' => 'user', 'content' => 'Test']], 'foobar' => '123'],
            ['context' => ['globalContext' => 1, 'middlewareContext' => 2]],
        );

        // The state was provided and contains the messages and the middleware's own field.
        self::assertSame('123', $captured['state']['foobar']);
        self::assertIsArray($captured['state']['messages']);
        self::assertSame('Test', $captured['state']['messages'][0]->content);

        // The runtime carries only the context of this middleware.
        self::assertSame(['middlewareContext' => 2], $captured['runtime']->context);
    }

    public function testShouldHandleErrorsInMiddlewareAndAllowRetry(): void
    {
        $attemptCount = 0;

        $errorHandlingMiddleware = Middleware::create([
            'name' => 'ErrorHandlingMiddleware',
            'wrapModelCall' => static function (array $request, callable $handler) use (&$attemptCount): mixed {
                $attemptCount++;

                try {
                    return $handler($request);
                } catch (\Throwable $error) {
                    // The first attempt fails: retry with a modified request.
                    if ($attemptCount === 1) {
                        return $handler([...$request, 'systemPrompt' => $request['systemPrompt'] . "\n[RETRY: Attempting recovery]"]);
                    }

                    throw $error;
                }
            },
        ]);

        // A model that fails the first time and succeeds the second.
        $callCount = new \ArrayObject(['calls' => 0]);
        $model = new class (['sleep' => 0, 'responses' => [new AIMessage('This should not appear'), new AIMessage('Success after retry')]]) extends FakeToolCallingChatModel {
            public ?\ArrayObject $callCount = null;

            protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
            {
                $this->callCount['calls'] = $this->callCount['calls'] + 1;
                if ($this->callCount['calls'] === 1) {
                    // Advance the script before throwing so the next call uses the next response.
                    $this->idx++;

                    throw new \Exception('Temporary failure');
                }

                return parent::generate($messages, $options, $runManager);
            }
        };
        $model->callCount = $callCount;

        $agent = Agent::create([
            'model' => $model,
            'tools' => [],
            'systemPrompt' => 'You are helpful',
            'middleware' => [$errorHandlingMiddleware],
        ]);

        $result = $agent->invoke(['messages' => [['role' => 'user', 'content' => 'Hi']]]);

        // The middleware retried.
        self::assertSame(1, $attemptCount);
        self::assertSame(2, $callCount['calls']);

        // We got the success response.
        self::assertCount(2, $result['messages']);
        self::assertSame('Success after retry', $result['messages'][array_key_last($result['messages'])]->content);
    }

    public function testShouldAllowMiddlewareToModifyModelAndTools(): void
    {
        $pirateModel = AgentAssertions::fakeChat([new AIMessage('Arr matey!')]);
        $modifyingMiddleware = Middleware::create([
            'name' => 'ModifyingMiddleware',
            'wrapModelCall' => static fn (array $request, callable $handler): mixed => $handler([
                ...$request,
                'systemPrompt' => 'OVERRIDDEN: You are a pirate',
                'model' => $pirateModel,
            ]),
        ]);

        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('Guten Tag!')]),
            'tools' => [],
            'systemPrompt' => 'You are helpful',
            'middleware' => [$modifyingMiddleware],
        ]);

        $result = $agent->invoke(['messages' => [['role' => 'user', 'content' => 'Hi']]]);

        // The agent completed with the modified behavior.
        self::assertSame('Arr matey!', $result['messages'][array_key_last($result['messages'])]->content);
    }

    public function testShouldAllowToSkipModelCall(): void
    {
        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('Arr matey!')]),
            'systemPrompt' => 'You are helpful',
            'middleware' => [Middleware::create(['name' => 'ModifyingMiddleware', 'wrapModelCall' => static fn (): AIMessage => new AIMessage('skipped')])],
        ]);

        $result = $agent->invoke(['messages' => [['role' => 'user', 'content' => 'Hi']]]);

        self::assertSame('skipped', $result['messages'][array_key_last($result['messages'])]->content);
    }

    public function testShouldThrowMeaningfulErrorIfSomethingInvalidGetsReturned(): void
    {
        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('Arr matey!')]),
            'systemPrompt' => 'You are helpful',
            // A hook that returns nothing.
            'middleware' => [Middleware::create(['name' => 'ModifyingMiddleware', 'wrapModelCall' => static function (): void {
            }])],
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('expected AIMessage or Command, got undefined');

        $agent->invoke(['messages' => [['role' => 'user', 'content' => 'Hi']]]);
    }

    public function testShouldPropagateTheMiddlewareNameInTheError(): void
    {
        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('Arr matey!')]),
            'systemPrompt' => 'You are helpful',
            'middleware' => [Middleware::create(['name' => 'ModifyingMiddleware', 'wrapModelCall' => static function (): void {
                throw new \Exception('foobar');
            }])],
        ]);

        try {
            $agent->invoke(['messages' => [['role' => 'user', 'content' => 'Hi']]]);
            self::fail('The middleware error should surface.');
        } catch (\Throwable $e) {
            self::assertInstanceOf(MiddlewareError::class, $e);
            self::assertSame('foobar', $e->getMessage());
        }
    }

    public function testShouldNotNestMiddlewareErrorPrefixesWhenMiddlewareRethrowsErrors(): void
    {
        $innerAgent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('Inner response')]),
            'systemPrompt' => 'You are an inner agent',
            'middleware' => [Middleware::create(['name' => 'InnerMiddleware', 'wrapModelCall' => static function (): void {
                throw new \Exception('original error');
            }])],
        ]);

        $subAgentTool = tool(
            static function () use ($innerAgent): string {
                $innerAgent->invoke(['messages' => [['role' => 'user', 'content' => 'Hi']]]);

                return 'success';
            },
            ['name' => 'subAgentTool', 'description' => 'A tool that spawns a sub-agent', 'schema' => Schema::object([])],
        );

        // The outer middleware wraps tool calls and invokes the inner agent.
        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[['name' => 'subAgentTool', 'args' => [], 'id' => '1']]]]),
            'tools' => [$subAgentTool],
            'systemPrompt' => 'You are an outer agent',
            'middleware' => [Middleware::create(['name' => 'OuterMiddleware', 'wrapToolCall' => static fn (array $request, callable $handler): mixed => $handler($request)])],
        ]);

        // Only the innermost middleware wrapper is there, not nested ones.
        try {
            $agent->invoke(['messages' => [['role' => 'user', 'content' => 'Hi']]]);
            self::fail('The inner error should surface.');
        } catch (\Throwable $error) {
            self::assertInstanceOf(MiddlewareError::class, $error);
            self::assertSame('Exception', $error->errorName);
            self::assertSame('original error', $error->getMessage());
            self::assertInstanceOf(\Exception::class, $error->getPrevious());
            self::assertNotInstanceOf(MiddlewareError::class, $error->getPrevious());
        }
    }

    public function testShouldAllowMiddlewareToModifyToolCallsInResponse(): void
    {
        // The model calls toolA, and the middleware changes it to toolB.
        $toolACalls = 0;
        $toolBCalls = 0;
        $toolA = tool(static function (array $in) use (&$toolACalls): string {
            $toolACalls++;

            return 'Tool A executed: ' . $in['input'];
        }, ['name' => 'toolA', 'description' => 'Tool A', 'schema' => Schema::object(['input' => ['type' => 'string']], ['input'])]);
        $toolB = tool(static function (array $in) use (&$toolBCalls): string {
            $toolBCalls++;

            return 'Tool B executed: ' . $in['input'];
        }, ['name' => 'toolB', 'description' => 'Tool B', 'schema' => Schema::object(['input' => ['type' => 'string']], ['input'])]);

        $originalToolCall = null;
        $modifiedToolCall = null;

        $toolRedirectMiddleware = Middleware::create([
            'name' => 'ToolRedirectMiddleware',
            'wrapModelCall' => static function (array $request, callable $handler) use (&$originalToolCall, &$modifiedToolCall): AIMessage {
                $response = $handler($request);

                // If the response has tool calls, modify them.
                if ($response->toolCalls !== []) {
                    $originalToolCall = $response->toolCalls[0]['name'];

                    // Change the tool call from toolA to toolB, keeping the same arguments.
                    if ($response->toolCalls[0]['name'] === 'toolA') {
                        $modifiedToolCall = 'toolB';

                        return self::rebuild($response, ['tool_calls' => [[...$response->toolCalls[0], 'name' => 'toolB']]]);
                    }
                }

                return $response;
            },
        ]);

        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([
                new AIMessage(['content' => '', 'tool_calls' => [['id' => 'call_1', 'name' => 'toolA', 'args' => ['input' => 'test data']]]]),
                new AIMessage('Done'),
            ]),
            'tools' => [$toolA, $toolB],
            'middleware' => [$toolRedirectMiddleware],
        ]);

        $result = $agent->invoke(['messages' => [['role' => 'user', 'content' => 'Call a tool']]]);

        // The tool call was modified.
        self::assertSame('toolA', $originalToolCall);
        self::assertSame('toolB', $modifiedToolCall);

        // toolB was actually executed (not toolA).
        $toolMessage = AgentAssertions::ofType($result['messages'], ToolMessage::class)[0] ?? null;
        self::assertNotNull($toolMessage);
        self::assertStringContainsString('Tool B executed: test data', $toolMessage->content);
        self::assertSame('toolB', $toolMessage->name);

        self::assertSame(0, $toolACalls);
        self::assertSame(1, $toolBCalls);
    }

    public function testShouldSupportAsyncOperationsInMiddleware(): void
    {
        // Middleware can wait before handing over to the next layer (sequentially here).
        $delays = [];

        $waiting = static function (string $name, int $ms) use (&$delays): array {
            return Middleware::create([
                'name' => $name,
                'wrapModelCall' => static function (array $request, callable $handler) use (&$delays, $ms): mixed {
                    $start = microtime(true);
                    MiddlewareUtils::sleep($ms);
                    $delays[] = (microtime(true) - $start) * 1000;

                    return $handler($request);
                },
            ]);
        };

        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('Response')], ['sleep' => 0]),
            'tools' => [],
            'middleware' => [$waiting('AsyncMiddleware1', 50), $waiting('AsyncMiddleware2', 30)],
        ]);

        $startTime = microtime(true);
        $agent->invoke(['messages' => [['role' => 'user', 'content' => 'Test']]]);
        $totalTime = (microtime(true) - $startTime) * 1000;

        self::assertCount(2, $delays);
        self::assertGreaterThanOrEqual(45, $delays[0]);
        self::assertGreaterThanOrEqual(25, $delays[1]);

        // The total time is at least the sum of the delays.
        self::assertGreaterThanOrEqual(75, $totalTime);
    }

    public function testShouldPassCorrectStateToEachMiddleware(): void
    {
        $seen = [];
        $inspector = static function (string $name) use (&$seen): array {
            return Middleware::create([
                'name' => $name,
                'wrapModelCall' => static function (array $request, callable $handler) use (&$seen, $name): mixed {
                    // We don't allow changing the state within these hooks.
                    $seen[$name] = \count($request['state']['messages']);

                    return $handler([...$request, 'messages' => [...$request['messages'], new AIMessage('some changes')]]);
                },
            ]);
        };

        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('Response')]),
            'tools' => [],
            'middleware' => [$inspector('Middleware1'), $inspector('Middleware2')],
        ]);

        $agent->invoke(['messages' => [
            ['role' => 'user', 'content' => 'Message 1'],
            ['role' => 'assistant', 'content' => 'Message 2'],
        ]]);

        self::assertSame(['Middleware1' => 2, 'Middleware2' => 2], $seen);
    }
}
