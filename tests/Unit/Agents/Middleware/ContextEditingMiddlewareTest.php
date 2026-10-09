<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Middleware;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware\ClearToolUsesEdit;
use LangGraph\Agents\Middleware\ContextEdit;
use LangGraph\Agents\Middleware\ContextEditingMiddleware;
use LangGraph\Agents\Middleware\Utils;
use LangGraph\Agents\ReactAgent;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `langchain/src/agents/middleware/tests/contextEditing.test.ts`.
 *
 * The edits are visible in `result.messages` upstream because JavaScript edits the thread's own message
 * array; here the middleware writes them back as a state update, so these tests read the result the same way.
 */
#[CoversClass(ContextEditingMiddleware::class)]
#[CoversClass(ClearToolUsesEdit::class)]
final class ContextEditingMiddlewareTest extends TestCase
{
    /** @var list<string> */
    private array $deprecations = [];

    private bool $capturing = false;

    /** Collect the E_USER_DEPRECATED notices the deprecated options raise (upstream: `console.warn`). */
    private function captureDeprecations(): void
    {
        $this->capturing = true;
        set_error_handler(function (int $level, string $message): bool {
            $this->deprecations[] = $message;

            return true;
        }, \E_USER_DEPRECATED);
    }

    protected function tearDown(): void
    {
        if ($this->capturing) {
            restore_error_handler();
            $this->capturing = false;
        }
        $this->deprecations = [];
    }

    /** @return list<BaseMessage> */
    private static function createToolCallConversation(): array
    {
        return [
            new HumanMessage("Search for 'React'"),
            new AIMessage(['content' => "I'll search for that.", 'tool_calls' => [['id' => 'call_1', 'name' => 'search', 'args' => ['query' => 'React']]]]),
            new ToolMessage(['content' => str_repeat('x', 1000), 'tool_call_id' => 'call_1']), // Large result
            new AIMessage('Found React information.'),
            new HumanMessage("Now search for 'TypeScript'"),
            new AIMessage(['content' => "I'll search for TypeScript.", 'tool_calls' => [['id' => 'call_2', 'name' => 'search', 'args' => ['query' => 'TypeScript']]]]),
            new ToolMessage(['content' => str_repeat('y', 1000), 'tool_call_id' => 'call_2']), // Large result
            new AIMessage('Found TypeScript information.'),
            new HumanMessage("Search for 'JavaScript'"),
        ];
    }

    private static function isCleared(BaseMessage $message): bool
    {
        return (bool) ($message->response_metadata['context_editing']['cleared'] ?? false);
    }

    /**
     * @param list<BaseMessage> $messages
     * @return list<ToolMessage>
     */
    private static function filterClearedMessages(array $messages): array
    {
        return array_values(array_filter($messages, static fn (BaseMessage $m): bool => $m instanceof ToolMessage && self::isCleared($m)));
    }

    /**
     * @param list<BaseMessage> $messages
     * @return list<ToolMessage>
     */
    private static function filterUnclearedMessages(array $messages): array
    {
        return array_values(array_filter($messages, static fn (BaseMessage $m): bool => $m instanceof ToolMessage && !self::isCleared($m)));
    }

    /** @param list<ContextEdit>|null $edits */
    private static function agent(string $response, ?array $edits = null): ReactAgent
    {
        return Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage($response)]),
            'middleware' => [$edits === null ? ContextEditingMiddleware::create() : ContextEditingMiddleware::create(['edits' => $edits])],
        ]);
    }

    // ---- default behavior -------------------------------------------------------------------------

    public function testShouldUseNotClearAnythingIfTheConversationIsBelowTheDefaultThreshold(): void
    {
        $agent = self::agent('Final response');

        $result = $agent->invoke(['messages' => [
            new HumanMessage('Hello'),
            new AIMessage(['content' => 'Let me search.', 'tool_calls' => [['id' => 'call_1', 'name' => 'search', 'args' => ['query' => 'test']]]]),
            new ToolMessage(['content' => 'Results', 'tool_call_id' => 'call_1']),
            new AIMessage('Done.'),
            new HumanMessage('Thanks'),
        ]]);

        // With the default 100K token threshold, nothing is cleared
        self::assertCount(0, self::filterClearedMessages($result['messages']));
    }

    public function testShouldClearToolResultsWhenExceedingDefaultTriggerThreshold(): void
    {
        $agent = self::agent('Final response', [
            new ClearToolUsesEdit([
                'trigger' => ['tokens' => 100], // Very low threshold
                'keep' => ['messages' => 1], // Keep only 1 most recent
            ]),
        ]);

        $result = $agent->invoke(['messages' => self::createToolCallConversation()]);

        // The older tool message is cleared
        $clearedMessages = self::filterClearedMessages($result['messages']);
        self::assertCount(1, $clearedMessages);

        // The cleared message has the placeholder
        self::assertSame('[cleared]', $clearedMessages[0]->content);
        self::assertTrue($clearedMessages[0]->response_metadata['context_editing']['cleared']);
        self::assertSame('clear_tool_uses', $clearedMessages[0]->response_metadata['context_editing']['strategy']);
    }

    // ---- custom ClearToolUsesEdit configuration ---------------------------------------------------

    public function testShouldRespectCustomTriggerThreshold(): void
    {
        $agent = self::agent('Response', [new ClearToolUsesEdit(['trigger' => ['tokens' => 50], 'keep' => ['messages' => 0]])]);

        $result = $agent->invoke(['messages' => self::createToolCallConversation()]);

        self::assertCount(10, $result['messages']);
        self::assertCount(2, self::filterClearedMessages($result['messages']));
    }

    public function testShouldKeepTheSpecifiedNumberOfMostRecentToolResults(): void
    {
        $keepCount = 1;
        $agent = self::agent('Response', [new ClearToolUsesEdit(['trigger' => ['tokens' => 50], 'keep' => ['messages' => $keepCount]])]);

        $result = $agent->invoke(['messages' => self::createToolCallConversation()]);

        // At least `keepCount` tool messages stay uncleared
        self::assertGreaterThanOrEqual($keepCount, \count(self::filterUnclearedMessages($result['messages'])));
    }

    public function testShouldExcludeSpecifiedToolsFromClearing(): void
    {
        $agent = self::agent('Response', [new ClearToolUsesEdit(['trigger' => ['tokens' => 50], 'keep' => ['messages' => 2], 'excludeTools' => ['important_search']])]);

        $result = $agent->invoke(['messages' => [
            new HumanMessage('Search'),
            new AIMessage(['content' => 'Searching...', 'tool_calls' => [['id' => 'call_1', 'name' => 'important_search', 'args' => ['query' => 'test']]]]),
            new ToolMessage(['content' => str_repeat('x', 1000), 'tool_call_id' => 'call_1', 'name' => 'important_search']),
            new AIMessage('Found it.'),
            new HumanMessage('Search again'),
            new AIMessage(['content' => 'Searching...', 'tool_calls' => [['id' => 'call_2', 'name' => 'regular_search', 'args' => ['query' => 'test']]]]),
            new ToolMessage(['content' => str_repeat('y', 1000), 'tool_call_id' => 'call_2', 'name' => 'regular_search']),
            new AIMessage('Done.'),
            new HumanMessage('One more'),
        ]]);

        $excluded = null;
        foreach ($result['messages'] as $message) {
            if ($message instanceof ToolMessage && $message->toolCallId === 'call_1') {
                $excluded = $message;
            }
        }

        // Excluded tools are never cleared
        self::assertNotNull($excluded);
        self::assertFalse(self::isCleared($excluded));
        self::assertNotSame('[cleared]', $excluded->content);
    }

    public function testShouldClearToolInputsWhenClearToolInputsIsTrue(): void
    {
        $agent = self::agent('Response', [new ClearToolUsesEdit(['trigger' => ['tokens' => 50], 'keep' => ['messages' => 1], 'clearToolInputs' => true])]);

        $result = $agent->invoke(['messages' => self::createToolCallConversation()]);

        // An AI message whose tool output was cleared
        $clearedToolMsg = self::filterClearedMessages($result['messages'])[0] ?? null;
        self::assertNotNull($clearedToolMsg);
        $aiMsg = null;
        foreach ($result['messages'] as $message) {
            if ($message instanceof AIMessage && \in_array($clearedToolMsg->toolCallId, array_column($message->toolCalls, 'id'), true)) {
                $aiMsg = $message;
            }
        }
        self::assertNotNull($aiMsg);

        $toolCall = null;
        foreach ($aiMsg->toolCalls as $call) {
            if ($call['id'] === $clearedToolMsg->toolCallId) {
                $toolCall = $call;
            }
        }

        // The tool call args are cleared
        self::assertSame([], $toolCall['args']);
        self::assertContains($clearedToolMsg->toolCallId, $aiMsg->response_metadata['context_editing']['cleared_tool_inputs']);
    }

    public function testShouldUseCustomPlaceholderText(): void
    {
        $customPlaceholder = '[REDACTED]';
        $agent = self::agent('Response', [new ClearToolUsesEdit(['trigger' => ['tokens' => 50], 'keep' => ['messages' => 1], 'placeholder' => $customPlaceholder])]);

        $result = $agent->invoke(['messages' => self::createToolCallConversation()]);

        $clearedMessages = self::filterClearedMessages($result['messages']);
        self::assertCount(1, $clearedMessages);
        self::assertSame($customPlaceholder, $clearedMessages[0]->content);
    }

    // ---- deprecated properties --------------------------------------------------------------------

    public function testShouldSupportDeprecatedTriggerTokensProperty(): void
    {
        $this->captureDeprecations();

        $agent = self::agent('Response', [new ClearToolUsesEdit(['triggerTokens' => 100, 'keep' => ['messages' => 1]])]);

        $result = $agent->invoke(['messages' => self::createToolCallConversation()]);
        self::assertCount(10, $result['messages']);

        // Still works and clears messages
        self::assertCount(1, self::filterClearedMessages($result['messages']));

        self::assertSame(['triggerTokens is deprecated. Use `trigger: { tokens: value }` instead.'], $this->deprecations);
    }

    public function testShouldSupportDeprecatedKeepMessagesProperty(): void
    {
        $this->captureDeprecations();

        $agent = self::agent('Response', [new ClearToolUsesEdit(['trigger' => ['tokens' => 100], 'keepMessages' => 1])]);

        $result = $agent->invoke(['messages' => self::createToolCallConversation()]);
        self::assertCount(10, $result['messages']);

        // Still works and keeps the specified number of messages
        self::assertCount(1, self::filterUnclearedMessages($result['messages']));

        self::assertSame(['keepMessages is deprecated. Use `keep: { messages: value }` instead.'], $this->deprecations);
    }

    public function testShouldSupportDeprecatedClearAtLeastProperty(): void
    {
        $this->captureDeprecations();

        // Multiple large tool results
        $messages = [new HumanMessage("Search for 'React'")];
        foreach ([['React', 'x', 1], ['TypeScript', 'y', 2], ['JavaScript', 'z', 3]] as [$topic, $fill, $n]) {
            $messages[] = new AIMessage(['content' => "I'll search for {$topic}.", 'tool_calls' => [['id' => "call_{$n}", 'name' => 'search', 'args' => ['query' => $topic]]]]);
            $messages[] = new ToolMessage(['content' => str_repeat($fill, 500), 'tool_call_id' => "call_{$n}"]); // ~500 chars
            $messages[] = new AIMessage("Found {$topic} information.");
            $messages[] = new HumanMessage($n < 3 ? "Search for the next topic {$n}" : 'One more search');
        }

        $agent = self::agent('Response', [new ClearToolUsesEdit([
            'trigger' => ['tokens' => 100], // Low threshold to trigger
            'keep' => ['messages' => 1], // Keep only 1 most recent tool result
            'clearAtLeast' => 200, // Deprecated: require at least 200 tokens cleared
        ])]);

        $result = $agent->invoke(['messages' => $messages]);

        // With keep 1 we would normally keep one result, but clearAtLeast needs more cleared to meet the budget
        self::assertGreaterThanOrEqual(2, \count(self::filterClearedMessages($result['messages'])));
        self::assertLessThanOrEqual(1, \count(self::filterUnclearedMessages($result['messages'])));

        self::assertSame(['clearAtLeast is deprecated and will be removed in a future version. It conflicts with the `keep` property. Use `keep: { tokens: value }` or `keep: { messages: value }` instead to control retention.'], $this->deprecations);
    }

    // ---- custom editing strategies ----------------------------------------------------------------

    public function testShouldSupportCustomContextEditImplementation(): void
    {
        // Removes all human messages but the last one when over the threshold
        $edit = new class () implements ContextEdit {
            public bool $called = false;

            public function apply(array &$messages, callable $countTokens, ?object $model = null): void
            {
                $this->called = true;
                $tokens = $countTokens($messages);

                if ($tokens > 100) {
                    $humanIndices = [];
                    foreach ($messages as $idx => $msg) {
                        if ($msg instanceof HumanMessage) {
                            $humanIndices[] = $idx;
                        }
                    }

                    // Keep only the last human message; remove from the end backwards to keep the indices valid
                    if (\count($humanIndices) > 1) {
                        for ($i = \count($humanIndices) - 2; $i >= 0; --$i) {
                            array_splice($messages, $humanIndices[$i], 1);
                        }
                    }
                }
            }
        };

        $agent = self::agent('Response', [$edit]);

        $messages = self::createToolCallConversation();
        $humanCountBefore = \count(array_filter($messages, static fn (BaseMessage $m): bool => $m instanceof HumanMessage));

        $result = $agent->invoke(['messages' => $messages]);
        self::assertTrue($edit->called);

        $humanCountAfter = \count(array_filter($result['messages'], static fn (BaseMessage $m): bool => $m instanceof HumanMessage));

        // Fewer human messages
        self::assertLessThan($humanCountBefore, $humanCountAfter);
    }

    public function testShouldChainMultipleEditingStrategies(): void
    {
        $strategy = static fn (): ContextEdit => new class () implements ContextEdit {
            public bool $called = false;

            public function apply(array &$messages, callable $countTokens, ?object $model = null): void
            {
                $this->called = true;
            }
        };
        $strategy1 = $strategy();
        $strategy2 = $strategy();

        $agent = self::agent('Response', [$strategy1, $strategy2]);

        $agent->invoke(['messages' => [new HumanMessage('Hello'), new AIMessage('Hi there!')]]);

        // Both strategies are called in sequence
        self::assertTrue($strategy1->called);
        self::assertTrue($strategy2->called);
    }

    // ---- token counting methods -------------------------------------------------------------------

    public function testShouldUseApproximateTokenCountingByDefault(): void
    {
        $agent = Agent::create([
            'model' => AgentAssertions::fakeChat([new AIMessage('Response')]),
            'middleware' => [ContextEditingMiddleware::create(['tokenCountMethod' => 'approx'])],
        ]);

        // Does not throw even without model token counting support
        $result = $agent->invoke(['messages' => [new HumanMessage('Test message'), new AIMessage('Response')]]);

        self::assertNotNull($result);
    }

    public function testShouldHandleMessagesWithEmptyContent(): void
    {
        $agent = self::agent('Response', [new ClearToolUsesEdit(['trigger' => ['tokens' => 50], 'keep' => ['messages' => 0]])]);

        $result = $agent->invoke(['messages' => [
            new HumanMessage(''),
            new AIMessage(['content' => '', 'tool_calls' => [['id' => 'call_1', 'name' => 'tool', 'args' => []]]]),
            new ToolMessage(['content' => '', 'tool_call_id' => 'call_1']),
        ]]);

        self::assertCount(0, self::filterClearedMessages($result['messages']));
    }

    // ---- edge cases -------------------------------------------------------------------------------

    public function testShouldNotClearAlreadyClearedMessages(): void
    {
        $agent = self::agent('Response', [new ClearToolUsesEdit(['trigger' => ['tokens' => 50], 'keep' => ['messages' => 2]])]);

        // A message that is already marked as cleared
        $result = $agent->invoke(['messages' => [
            new HumanMessage('Test'),
            new AIMessage(['content' => 'Testing', 'tool_calls' => [['id' => 'call_1', 'name' => 'test', 'args' => []]]]),
            new ToolMessage([
                'content' => '[cleared]',
                'tool_call_id' => 'call_1',
                'response_metadata' => ['context_editing' => ['cleared' => true, 'strategy' => 'clear_tool_uses']],
            ]),
            new AIMessage('Done'),
            new HumanMessage('More'),
        ]]);

        $clearedMsg = null;
        foreach ($result['messages'] as $message) {
            if ($message instanceof ToolMessage && $message->toolCallId === 'call_1') {
                $clearedMsg = $message;
            }
        }

        self::assertNotNull($clearedMsg);
        self::assertSame('[cleared]', $clearedMsg->content);
        self::assertTrue($clearedMsg->response_metadata['context_editing']['cleared']);
    }

    public function testShouldHandleMessagesWithNoCorrespondingAIMessage(): void
    {
        $agent = self::agent('Response', [new ClearToolUsesEdit(['trigger' => ['tokens' => 50], 'keep' => ['messages' => 0]])]);

        // A tool message without a corresponding AI message (a malformed conversation)
        $result = $agent->invoke(['messages' => [
            new HumanMessage('Test'),
            new ToolMessage(['content' => 'Result', 'tool_call_id' => 'orphan_call']),
            new AIMessage('Done'),
        ]]);

        // Handled gracefully: the orphan is dropped and nothing is cleared
        $orphans = array_filter($result['messages'], static fn (BaseMessage $m): bool => $m instanceof ToolMessage && $m->toolCallId === 'orphan_call');
        self::assertSame([], $orphans);
        self::assertCount(0, self::filterClearedMessages($result['messages']));
    }

    // ---- not in upstream's unit file --------------------------------------------------------------

    public function testTheModelTokenCountMethodRequiresAModelThatCanCount(): void
    {
        $middleware = ContextEditingMiddleware::create([
            'edits' => [new ClearToolUsesEdit(['trigger' => ['tokens' => 50]])],
            'tokenCountMethod' => 'model',
        ]);
        $model = AgentAssertions::fakeChat([new AIMessage('Response')]);

        $this->expectExceptionMessage('does not support token counting');

        $middleware['wrapModelCall'](
            ['messages' => [new HumanMessage('hi')], 'model' => $model, 'systemPrompt' => ''],
            static fn (array $request): AIMessage => new AIMessage('unused'),
        );
    }

    public function testTheModelTokenCountMethodUsesGetNumTokensFromMessages(): void
    {
        $model = new class () {
            /** @var list<int> */
            public array $counted = [];

            public function getNumTokensFromMessages(array $messages): array
            {
                $this->counted[] = \count($messages);

                return ['totalCount' => 7, 'countPerMessage' => array_fill(0, \count($messages), 1)];
            }
        };
        $middleware = ContextEditingMiddleware::create([
            'edits' => [new ClearToolUsesEdit(['trigger' => ['tokens' => 50]])],
            'tokenCountMethod' => 'model',
        ]);

        $middleware['wrapModelCall'](
            ['messages' => [new HumanMessage('hi')], 'model' => $model, 'systemPrompt' => 'be brief'],
            static fn (array $request): AIMessage => new AIMessage('ok'),
        );

        // The system prompt counts along with the messages
        self::assertSame([2], $model->counted);
    }

    public function testAnEditThatChangesNothingLeavesTheModelReplyAlone(): void
    {
        $middleware = ContextEditingMiddleware::create(['edits' => [new ClearToolUsesEdit()]]);
        $reply = new AIMessage('ok');

        $response = $middleware['wrapModelCall'](
            ['messages' => [new HumanMessage('hi')], 'model' => null, 'systemPrompt' => ''],
            static fn (array $request): AIMessage => $reply,
        );

        self::assertSame($reply, $response);
    }

    public function testTheTokenCounterSeesTheMessagesTheEditsLeave(): void
    {
        // Direct use of the approximate counter the middleware defaults to.
        self::assertSame(3, Utils::countTokensApproximately([new HumanMessage('hello world!')]));
    }
}
