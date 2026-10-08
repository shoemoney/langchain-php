<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Nodes;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\SystemMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tests\Unit\Agents\Support\FlakyChatModel;
use LangGraph\Agents\Middleware;
use LangGraph\Agents\Middleware\Utils as MiddlewareUtils;
use LangGraph\Agents\Nodes\AgentNode;
use LangGraph\Pregel\Command;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `langchain/src/agents/nodes/tests/AgentNode.test.ts`.
 *
 * "Concurrent" invocations (`Promise.all` upstream) run one after the other here; what is asserted is the same:
 * each invocation gets its own system message. Not converted: "should preserve structured-output retry Command
 * through middleware", which needs a `toolStrategy` response format (WP-21c).
 */
#[CoversClass(AgentNode::class)]
final class AgentNodeTest extends TestCase
{
    /** The node the tests build: no tools, the given middleware wrapping the model call, outermost first. */
    private function node(FlakyChatModel $model, string $systemPrompt, array $middleware): AgentNode
    {
        return new AgentNode([
            'model' => $model,
            'systemMessage' => new SystemMessage($systemPrompt),
            'toolClasses' => [],
            'shouldReturnDirect' => [],
            'middleware' => $middleware,
            'wrapModelCallHookMiddleware' => array_map(static fn (array $m): array => [$m, static fn (): array => []], $middleware),
        ]);
    }

    /** @param list<BaseMessage> $messages */
    private function invokeNode(AgentNode $node, array $messages): mixed
    {
        return $node->invoke(['messages' => $messages, 'structuredResponse' => []], new RunnableConfig(configurable: []));
    }

    /** @return list<BaseMessage> */
    private static function lastCallMessages(FlakyChatModel $model): array
    {
        $calls = $model->invokeCalls();

        return $calls[array_key_last($calls)];
    }

    public function testConcurrentInvocationsGetIsolatedSystemMessagesViaSystemPrompt(): void
    {
        $model = new FlakyChatModel(['responses' => [new AIMessage('ok')]]);
        $middleware = Middleware::create([
            'name' => 'TagMiddleware',
            'wrapModelCall' => static function (array $request, callable $handler): mixed {
                $userMsg = $request['messages'][array_key_last($request['messages'])]->content;

                return $handler([...$request, 'systemPrompt' => 'prompt:' . $userMsg]);
            },
        ]);
        $node = $this->node($model, 'default', [$middleware]);

        $this->invokeNode($node, [new HumanMessage('A')]);
        $this->invokeNode($node, [new HumanMessage('B')]);

        self::assertCount(2, $model->invokeCalls());
        $systemTexts = array_map(static fn (array $call): string => $call[0]->text(), $model->invokeCalls());
        sort($systemTexts);
        self::assertSame(['prompt:A', 'prompt:B'], $systemTexts);
    }

    public function testConcurrentInvocationsGetIsolatedSystemMessagesViaSystemMessage(): void
    {
        $model = new FlakyChatModel(['responses' => [new AIMessage('ok')]]);
        $middleware = Middleware::create([
            'name' => 'TagMiddleware',
            'wrapModelCall' => static function (array $request, callable $handler): mixed {
                $userMsg = $request['messages'][array_key_last($request['messages'])]->content;

                return $handler([...$request, 'systemMessage' => new SystemMessage('msg:' . $userMsg)]);
            },
        ]);
        $node = $this->node($model, 'default', [$middleware]);

        $this->invokeNode($node, [new HumanMessage('X')]);
        $this->invokeNode($node, [new HumanMessage('Y')]);

        self::assertCount(2, $model->invokeCalls());
        $systemTexts = array_map(static fn (array $call): string => $call[0]->text(), $model->invokeCalls());
        sort($systemTexts);
        self::assertSame(['msg:X', 'msg:Y'], $systemTexts);
    }

    /** An outer middleware that calls the handler, and calls it again if that fails. */
    private function retrying(\ArrayObject $counter): array
    {
        return Middleware::create([
            'name' => 'OuterMiddleware',
            'wrapModelCall' => static function (array $request, callable $handler) use ($counter): mixed {
                try {
                    $counter['calls'] = $counter['calls'] + 1;

                    return $handler($request);
                } catch (\Throwable) {
                    $counter['calls'] = $counter['calls'] + 1;

                    return $handler($request);
                }
            },
        ]);
    }

    public function testShouldNotThrowWhenOuterMiddlewareRetriesAfterInnerMiddlewareModifiedSystemMessage(): void
    {
        $handlerCalls = new \ArrayObject(['calls' => 0]);
        $model = new FlakyChatModel(['responses' => [new AIMessage('ok')], 'failFirst' => 1, 'failMessage' => 'simulated context overflow']);

        $innerMiddleware = Middleware::create([
            'name' => 'InnerMiddleware',
            'wrapModelCall' => static fn (array $request, callable $handler): mixed => $handler([
                ...$request,
                'systemMessage' => MiddlewareUtils::concatSystemMessage($request['systemMessage'], "\nExtra instructions from inner middleware"),
            ]),
        ]);
        $node = $this->node($model, 'base prompt', [$this->retrying($handlerCalls), $innerMiddleware]);

        self::assertNotNull($this->invokeNode($node, [new HumanMessage('hello')]));
        self::assertSame(2, $handlerCalls['calls']);
    }

    public function testShouldNotThrowWhenOuterMiddlewareRetriesAfterInnerMiddlewareModifiedSystemPrompt(): void
    {
        $handlerCalls = new \ArrayObject(['calls' => 0]);
        $model = new FlakyChatModel(['responses' => [new AIMessage('ok')], 'failFirst' => 1, 'failMessage' => 'simulated error']);

        $innerMiddleware = Middleware::create([
            'name' => 'InnerMiddleware',
            'wrapModelCall' => static fn (array $request, callable $handler): mixed => $handler([
                ...$request,
                'systemPrompt' => $request['systemPrompt'] . "\nExtra prompt from inner",
            ]),
        ]);
        $node = $this->node($model, 'base prompt', [$this->retrying($handlerCalls), $innerMiddleware]);

        self::assertNotNull($this->invokeNode($node, [new HumanMessage('hello')]));
        self::assertSame(2, $handlerCalls['calls']);
    }

    public function testShouldPreserveInnerMiddlewareSystemMessageChangesOnEachRetry(): void
    {
        $model = new FlakyChatModel(['responses' => [new AIMessage('ok')], 'failFirst' => 1, 'failMessage' => 'first attempt fails']);

        $innerMiddleware = Middleware::create([
            'name' => 'InnerMiddleware',
            'wrapModelCall' => static fn (array $request, callable $handler): mixed => $handler([
                ...$request,
                'systemMessage' => MiddlewareUtils::concatSystemMessage($request['systemMessage'], "\n[inner-addition]"),
            ]),
        ]);

        $attempt = 0;
        $outerMiddleware = Middleware::create([
            'name' => 'OuterMiddleware',
            'wrapModelCall' => static function (array $request, callable $handler) use (&$attempt): mixed {
                $attempt++;
                if ($attempt === 1) {
                    try {
                        return $handler($request);
                    } catch (\Throwable) {
                        // Fall through to retry.
                    }
                }

                return $handler($request);
            },
        ]);
        $node = $this->node($model, 'base', [$outerMiddleware, $innerMiddleware]);

        $this->invokeNode($node, [new HumanMessage('test')]);

        $systemText = self::lastCallMessages($model)[0]->text();
        self::assertStringContainsString('base', $systemText);
        self::assertStringContainsString('[inner-addition]', $systemText);
    }

    public function testShouldHandleThreeMiddlewareLayersWithRetryInOutermost(): void
    {
        $model = new FlakyChatModel(['responses' => [new AIMessage('ok')], 'failFirst' => 1, 'failMessage' => 'fail first']);

        $appender = static fn (string $name, string $tag): array => Middleware::create([
            'name' => $name,
            'wrapModelCall' => static fn (array $request, callable $handler): mixed => $handler([
                ...$request,
                'systemMessage' => MiddlewareUtils::concatSystemMessage($request['systemMessage'], "\n[" . $tag . ']'),
            ]),
        ]);

        $attempt = 0;
        $retryMiddleware = Middleware::create([
            'name' => 'RetryMiddleware',
            'wrapModelCall' => static function (array $request, callable $handler) use (&$attempt): mixed {
                $attempt++;
                if ($attempt === 1) {
                    try {
                        return $handler($request);
                    } catch (\Throwable) {
                        // Retry.
                    }
                }

                return $handler($request);
            },
        ]);
        $node = $this->node($model, 'root', [$retryMiddleware, $appender('MiddlewareA', 'A'), $appender('MiddlewareB', 'B')]);

        $this->invokeNode($node, [new HumanMessage('go')]);

        $systemText = self::lastCallMessages($model)[0]->text();
        self::assertStringContainsString('root', $systemText);
        self::assertStringContainsString('[A]', $systemText);
        self::assertStringContainsString('[B]', $systemText);
    }

    public function testShouldAllowMiddlewareToCallHandlerMultipleTimesForFallbackLogic(): void
    {
        $model = new FlakyChatModel(['responses' => [new AIMessage('ok')], 'failFirst' => 1, 'failMessage' => 'context too large']);

        $innerMiddleware = Middleware::create([
            'name' => 'InnerMiddleware',
            'wrapModelCall' => static fn (array $request, callable $handler): mixed => $handler([
                ...$request,
                'systemPrompt' => $request['systemPrompt'] . "\n[inner]",
            ]),
        ]);
        $fallbackMiddleware = Middleware::create([
            'name' => 'FallbackMiddleware',
            'wrapModelCall' => static function (array $request, callable $handler): mixed {
                try {
                    return $handler($request);
                } catch (\Throwable) {
                    return $handler([...$request, 'messages' => [new HumanMessage('summarized context')]]);
                }
            },
        ]);
        $node = $this->node($model, 'original', [$fallbackMiddleware, $innerMiddleware]);

        $this->invokeNode($node, [new HumanMessage('long message')]);

        self::assertCount(2, $model->invokeCalls());
        $retryMessages = $model->invokeCalls()[1];
        self::assertStringContainsString('original', $retryMessages[0]->text());
        self::assertStringContainsString('[inner]', $retryMessages[0]->text());
        self::assertInstanceOf(HumanMessage::class, $retryMessages[1]);
        self::assertSame('summarized context', $retryMessages[1]->text());
    }

    public function testShouldNotDoubleCollectCommandReturnedByInnerMiddleware(): void
    {
        $retryCommand = new Command(update: ['messages' => []], goto: 'model_request');

        $model = new FlakyChatModel(['responses' => [new AIMessage('ok')]]);

        $inner = Middleware::create([
            'name' => 'CommandMiddleware',
            'wrapModelCall' => static function (array $request, callable $handler) use ($retryCommand): Command {
                $handler($request);

                return $retryCommand;
            },
        ]);
        $outer = Middleware::create([
            'name' => 'Outer',
            'wrapModelCall' => static fn (array $request, callable $handler): mixed => $handler($request),
        ]);
        $node = $this->node($model, 'test', [$outer, $inner]);

        $commands = $this->invokeNode($node, [new HumanMessage('hi')]);

        $matching = array_filter($commands, static fn (mixed $command): bool => $command === $retryCommand);
        self::assertCount(1, $matching);
    }
}
