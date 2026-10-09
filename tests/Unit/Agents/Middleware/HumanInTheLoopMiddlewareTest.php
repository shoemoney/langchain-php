<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Middleware;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\ToolMessage;
use LangChain\Tests\Unit\Agents\Support\AgentAssertions;
use LangChain\Tests\Unit\Agents\Support\FakeToolCallingModel;
use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;
use LangGraph\Agents\Agent;
use LangGraph\Agents\Middleware\ClearToolUsesEdit;
use LangGraph\Agents\Middleware\ContextEditingMiddleware;
use LangGraph\Agents\Middleware\HumanInTheLoopMiddleware;
use LangGraph\Agents\Middleware\SummarizationMiddleware;
use LangGraph\Agents\ReactAgent;
use LangGraph\Agents\Runtime;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Pregel\Command;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * `langchain/src/agents/middleware/tests/hitl.test.ts`.
 *
 * The `vi.fn` tool mocks are call logs on the test case, reset for every test. `hitl.int.test.ts` (5 cases)
 * drives a live model and is skipped, see {@see self::testLiveApiTestsAreNotConverted()}.
 */
#[CoversClass(HumanInTheLoopMiddleware::class)]
final class HumanInTheLoopMiddlewareTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $writeFileCalls = [];

    /** @var list<array<string, mixed>> */
    private array $calculatorCalls = [];

    private StructuredTool $writeFileTool;

    private StructuredTool $calculateTool;

    protected function setUp(): void
    {
        $this->writeFileCalls = [];
        $this->calculatorCalls = [];

        $this->calculateTool = tool(
            function (array $in): string {
                $this->calculatorCalls[] = $in;

                return match ($in['operation']) {
                    'add' => "{$in['a']} + {$in['b']} = " . ($in['a'] + $in['b']),
                    'multiply' => "{$in['a']} * {$in['b']} = " . ($in['a'] * $in['b']),
                    default => 'Unknown operation',
                };
            },
            [
                'name' => 'calculator',
                'description' => 'Perform basic math operations',
                'schema' => Schema::object([
                    'a' => ['type' => 'number', 'description' => 'First number'],
                    'b' => ['type' => 'number', 'description' => 'Second number'],
                    'operation' => ['type' => 'string', 'enum' => ['add', 'multiply'], 'description' => 'Math operation'],
                ], ['a', 'b', 'operation']),
            ],
        );

        $this->writeFileTool = tool(
            function (array $in): string {
                $this->writeFileCalls[] = $in;

                return 'Successfully wrote ' . \strlen($in['content']) . " characters to {$in['filename']}";
            },
            [
                'name' => 'write_file',
                'description' => 'Write content to a file',
                'schema' => Schema::object([
                    'filename' => ['type' => 'string', 'description' => 'Name of the file'],
                    'content' => ['type' => 'string', 'description' => 'Content to write'],
                ], ['filename', 'content']),
            ],
        );
    }

    /**
     * @param array<string, mixed>                $interruptOn
     * @param list<list<array<string, mixed>>>    $toolCalls
     * @param list<StructuredTool>                $tools
     * @return array{0: ReactAgent, 1: FakeToolCallingModel, 2: array<string, mixed>}
     */
    private function agent(array $interruptOn, array $toolCalls, array $tools, string $threadId, ?string $systemPrompt = null): array
    {
        $model = new FakeToolCallingModel(['toolCalls' => $toolCalls]);
        $options = [
            'model' => $model,
            'checkpointer' => new MemorySaver(),
            'tools' => $tools,
            'middleware' => [HumanInTheLoopMiddleware::create(['interruptOn' => $interruptOn])],
        ];
        if ($systemPrompt !== null) {
            $options['systemPrompt'] = $systemPrompt;
        }

        return [Agent::create($options), $model, ['configurable' => ['thread_id' => $threadId]]];
    }

    /** @return array{id: string, name: string, args: array<string, mixed>} */
    private static function call(string $id, string $name, array $args): array
    {
        return ['id' => $id, 'name' => $name, 'args' => $args];
    }

    /** @return array{id: string, name: string, args: array<string, mixed>} */
    private static function add(string $id, int $a, int $b): array
    {
        return self::call($id, 'calculator', ['a' => $a, 'b' => $b, 'operation' => 'add']);
    }

    /** @return array{id: string, name: string, args: array<string, mixed>} */
    private static function write(string $id, string $filename, string $content): array
    {
        return self::call($id, 'write_file', ['filename' => $filename, 'content' => $content]);
    }

    /** @param list<array<string, mixed>> $decisions */
    private static function resume(array $decisions): Command
    {
        return new Command(resume: ['decisions' => $decisions]);
    }

    /** @return array<string, mixed> the HITLRequest the thread is paused on */
    private static function pendingRequest(ReactAgent $agent, array $config): array
    {
        $task = $agent->getState($config)->tasks[0] ?? null;
        self::assertNotNull($task);
        self::assertCount(1, $task->interrupts);

        return $task->interrupts[0]['value'];
    }

    private static function lastOfType(array $messages, string $class): BaseMessage
    {
        foreach (array_reverse($messages) as $message) {
            if ($message instanceof $class) {
                return $message;
            }
        }

        self::fail("No {$class} in the messages.");
    }

    private static function toolStatus(ToolMessage $message): mixed
    {
        return $message->additional_kwargs['status'] ?? null;
    }

    /** @return list<array{id: string, name: string}> */
    private static function callIds(AIMessage $message): array
    {
        return array_map(static fn (array $c): array => ['id' => $c['id'], 'name' => $c['name']], $message->toolCalls);
    }

    // ---- humanInTheLoopMiddleware ----------------------------------------------------------------

    public function testShouldAutoApproveSafeToolsAndInterruptForToolsRequiringApproval(): void
    {
        [$agent, $model, $config] = $this->agent(
            [
                'write_file' => ['allowedDecisions' => ['approve'], 'description' => '⚠️ File write operation requires approval'],
                'calculator' => false,
            ],
            [
                // First call: calculator tool (auto-approved)
                [self::call('call_1', 'calculator', ['a' => 42, 'b' => 17, 'operation' => 'multiply'])],
                // Second call: write_file tool (requires approval)
                [self::write('call_2', 'greeting.txt', 'Hello World')],
                [],
            ],
            [$this->calculateTool, $this->writeFileTool],
            'test-123',
            'You are a helpful assistant. Use the tools provided to help the user.',
        );

        // Test 1: Calculator tool (auto-approved)
        $mathResult = $agent->invoke(['messages' => [new HumanMessage('Calculate 42 * 17')]], $config);

        self::assertSame([], $this->writeFileCalls);
        self::assertSame([['a' => 42, 'b' => 17, 'operation' => 'multiply']], $this->calculatorCalls);

        $mathMessages = $mathResult['messages'];
        self::assertCount(4, $mathMessages);
        // 1st message: the human prompt; 2nd: the AI message calling the tool; 3rd: the tool response; 4th: the AI response
        self::assertInstanceOf(HumanMessage::class, $mathMessages[0]);
        self::assertSame('Calculate 42 * 17', $mathMessages[0]->content);
        self::assertInstanceOf(AIMessage::class, $mathMessages[1]);
        self::assertStringContainsString('You are a helpful assistant.', $mathMessages[1]->content);
        self::assertInstanceOf(ToolMessage::class, $mathMessages[2]);
        self::assertStringContainsString('42 * 17 = 714', $mathMessages[2]->content);
        self::assertInstanceOf(AIMessage::class, $mathMessages[3]);
        self::assertStringContainsString('42 * 17 = 714', $mathMessages[3]->content);

        // Test 2: Write file tool (requires approval)
        $model->indexRef->current = 1;
        $agent->invoke(['messages' => [new HumanMessage("Write 'Hello World' to greeting.txt")]], $config);

        // write_file was NOT called yet
        self::assertSame([], $this->writeFileCalls);

        // The agent is paused for approval
        $state = $agent->getState($config);
        self::assertCount(1, $state->next);

        self::assertEquals([
            'actionRequests' => [[
                'name' => 'write_file',
                'args' => ['filename' => 'greeting.txt', 'content' => 'Hello World'],
                'description' => '⚠️ File write operation requires approval',
            ]],
            'reviewConfigs' => [['actionName' => 'write_file', 'allowedDecisions' => ['approve']]],
        ], self::pendingRequest($agent, $config));

        // Resume with approval
        $model->indexRef->current = 1;
        $resumed = $agent->invoke(self::resume([['type' => 'approve']]), $config);

        // write_file was called after approval
        self::assertSame([['filename' => 'greeting.txt', 'content' => 'Hello World']], $this->writeFileCalls);

        $finalMessages = $resumed['messages'];
        self::assertSame('Successfully wrote 11 characters to greeting.txt', $finalMessages[array_key_last($finalMessages)]->content);
    }

    public function testShouldHandleEditResponseType(): void
    {
        [$agent, , $config] = $this->agent(
            ['write_file' => true],
            [[self::write('call_1', 'dangerous.txt', 'Dangerous content')]],
            [$this->writeFileTool],
            'test-edit',
        );

        $agent->invoke(['messages' => [new HumanMessage('Write dangerous content')]], $config);

        $agent->invoke(self::resume([[
            'type' => 'edit',
            'editedAction' => ['name' => 'write_file', 'args' => ['filename' => 'safe.txt', 'content' => 'Safe content']],
        ]]), $config);

        // The tool was called with the edited args
        self::assertSame([['filename' => 'safe.txt', 'content' => 'Safe content']], $this->writeFileCalls);
    }

    public function testShouldReturnToModelWithoutExecutingApprovedToolsWhenAnyToolIsRejected(): void
    {
        [$agent, , $config] = $this->agent(
            [
                'calculator' => ['allowedDecisions' => ['approve', 'reject']],
                'write_file' => ['allowedDecisions' => ['approve', 'reject']],
            ],
            [[self::add('call_1', 5, 5), self::write('call_2', 'approved.txt', 'approved')]],
            [$this->calculateTool, $this->writeFileTool],
            'test-partial-reject',
        );

        $agent->invoke(['messages' => [new HumanMessage('Calculate 5+5 and write to file')]], $config);

        // Approve first, reject second
        $result = $agent->invoke(self::resume([
            ['type' => 'approve'],
            ['type' => 'reject', 'message' => 'File write not allowed'],
        ]), $config);

        // Only the rejected tool call appears in the tool messages
        $toolMessages = AgentAssertions::ofType($result['messages'], ToolMessage::class);
        self::assertCount(1, $toolMessages);
        self::assertSame('File write not allowed', $toolMessages[0]->content);
        self::assertSame('call_2', $toolMessages[0]->toolCallId);
        self::assertSame('error', self::toolStatus($toolMessages[0]));
        self::assertSame('write_file', $toolMessages[0]->name);

        // When there are rejections, all tool calls remain in the AI message
        $aiMessage = self::lastOfType($result['messages'], AIMessage::class);
        self::assertCount(2, $aiMessage->toolCalls);
        self::assertSame(['id' => 'call_1', 'name' => 'calculator', 'args' => ['a' => 5, 'b' => 5, 'operation' => 'add']], array_intersect_key($aiMessage->toolCalls[0], array_flip(['id', 'name', 'args'])));
        self::assertSame(['id' => 'call_2', 'name' => 'write_file', 'args' => ['filename' => 'approved.txt', 'content' => 'approved']], array_intersect_key($aiMessage->toolCalls[1], array_flip(['id', 'name', 'args'])));

        // We go back to the model without executing approved tools, so neither tool is executed
        self::assertSame([], $this->calculatorCalls);
        self::assertSame([], $this->writeFileCalls);

        // The agent is ready to continue (the model needs to process the rejection)
        self::assertNotSame([], $agent->getState($config)->next);
    }

    public function testShouldHandleManualResponseType(): void
    {
        [$agent, , $config] = $this->agent(
            ['write_file' => ['allowedDecisions' => ['reject']]],
            [[self::write('call_1', 'manual.txt', 'Manual content')]],
            [$this->writeFileTool],
            'test-manual',
        );

        $agent->invoke(['messages' => [new HumanMessage('Write to manual file')]], $config);

        $resumed = $agent->invoke(self::resume([['type' => 'reject', 'message' => 'File operation not allowed in demo mode']]), $config);

        // The tool was NOT called and the manual response was added
        self::assertSame([], $this->writeFileCalls);
        $messages = $resumed['messages'];
        $last = $messages[array_key_last($messages)];
        self::assertSame('File operation not allowed in demo mode', $last->content);
        self::assertInstanceOf(ToolMessage::class, $last);
        self::assertSame('call_1', $last->toolCallId);
    }

    public function testShouldGenerateDefaultRejectionMessageWhenMessageIsNotProvided(): void
    {
        [$agent, , $config] = $this->agent(
            ['calculator' => ['allowedDecisions' => ['reject']]],
            [[self::add('call_123', 3, 4)]],
            [$this->calculateTool],
            'test-default-reject-msg',
        );

        $agent->invoke(['messages' => [new HumanMessage('Calculate 3 + 4')]], $config);

        // Reject without a message
        $result = $agent->invoke(self::resume([['type' => 'reject']]), $config);

        $toolMessage = self::lastOfType($result['messages'], ToolMessage::class);
        self::assertSame('User rejected the tool call for `calculator` with id call_123', $toolMessage->content);
        self::assertSame('call_123', $toolMessage->toolCallId);
    }

    public function testShouldThrowIfResponseIsNotAString(): void
    {
        [$agent, , $config] = $this->agent(
            ['write_file' => ['allowedDecisions' => ['reject']]],
            [[self::write('call_1', 'manual.txt', 'Manual content')]],
            [$this->writeFileTool],
            'test-manual',
        );

        $agent->invoke(['messages' => [new HumanMessage('Write to manual file')]], $config);

        // The message must be a string, but an object is passed
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Tool call response for "write_file" must be a string, got object');

        $agent->invoke(self::resume([[
            'type' => 'reject',
            'message' => ['action' => 'write_file', 'args' => 'File operation not allowed in demo mode'],
        ]]), $config);
    }

    public function testShouldAllowToInterruptMultipleToolsAtTheSameTime(): void
    {
        [$agent, , $config] = $this->agent(
            [
                'write_file' => ['allowedDecisions' => ['edit'], 'description' => '⚠️ File write operation requires approval'],
                'calculator' => true,
            ],
            [[
                self::call('call_1', 'calculator', ['a' => 42, 'b' => 17, 'operation' => 'multiply']),
                self::write('call_2', 'greeting.txt', 'Hello World'),
            ]],
            [$this->calculateTool, $this->writeFileTool],
            'test-123',
            'You are a helpful assistant. Use the tools provided to help the user.',
        );

        $agent->invoke(['messages' => [new HumanMessage('Calculate 42 * 17 and write to greeting.txt')]], $config);

        // not called due to the interrupt
        self::assertSame([], $this->calculatorCalls);
        self::assertSame([], $this->writeFileCalls);

        $hitlRequest = self::pendingRequest($agent, $config);
        $decisions = array_map(
            static fn (array $action): array => match ($action['name']) {
                'calculator' => ['type' => 'approve'],
                'write_file' => ['type' => 'edit', 'editedAction' => ['name' => 'write_file', 'args' => ['filename' => 'safe.txt', 'content' => 'Safe content']]],
                default => throw new \Exception("Unknown action: {$action['name']}"),
            },
            $hitlRequest['actionRequests'],
        );

        $agent->invoke(self::resume($decisions), $config);

        self::assertSame([['a' => 42, 'b' => 17, 'operation' => 'multiply']], $this->calculatorCalls);
        self::assertSame([['filename' => 'safe.txt', 'content' => 'Safe content']], $this->writeFileCalls);
    }

    public function testShouldThrowIfNotAllToolCallsHaveAResponse(): void
    {
        [$agent, , $config] = $this->agent(
            [
                'write_file' => ['allowedDecisions' => ['edit'], 'description' => '⚠️ File write operation requires approval'],
                'calculator' => true,
            ],
            [[
                self::call('call_1', 'calculator', ['a' => 42, 'b' => 17, 'operation' => 'multiply']),
                self::write('call_2', 'greeting.txt', 'Hello World'),
            ]],
            [$this->calculateTool, $this->writeFileTool],
            'test-123',
            'You are a helpful assistant. Use the tools provided to help the user.',
        );

        $agent->invoke(['messages' => [new HumanMessage('Calculate 42 * 17 and write to greeting.txt')]], $config);

        // Resume with only one decision when two are needed
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Number of human decisions (1) does not match number of hanging tool calls (2).');

        $agent->invoke(self::resume([['type' => 'approve']]), $config);
    }

    public function testShouldNotAllowMeToApproveIfIDontHaveApproveInAllowedDecisions(): void
    {
        [$agent, , $config] = $this->agent(
            ['write_file' => ['allowedDecisions' => ['edit'], 'description' => '⚠️ File write operation requires approval']],
            [[self::add('call_1', 42, 17), self::write('call_2', 'greeting.txt', 'Hello World')]],
            [$this->writeFileTool],
            'test-123',
            'You are a helpful assistant. Use the tools provided to help the user.',
        );

        $agent->invoke(['messages' => [new HumanMessage('Calculate 42 * 17 and write to greeting.txt')]], $config);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unexpected human decision: {"type":"approve"}. Decision type \'approve\' is not allowed for tool \'write_file\'. Expected one of ["edit"] based on the tool\'s configuration.');

        $agent->invoke(self::resume([['type' => 'approve']]), $config);
    }

    public function testShouldSupportDynamicDescriptionFactoryFunctions(): void
    {
        $factoryCalls = [];
        $descriptionFactory = static function (array $toolCall, array $state, Runtime $runtime) use (&$factoryCalls): string {
            $factoryCalls[] = [$toolCall, $state, $runtime];

            return "Dynamic description for tool: {$toolCall['name']}\nFile: {$toolCall['args']['filename']}\nContent length: " . \strlen($toolCall['args']['content']) . ' characters';
        };

        [$agent, , $config] = $this->agent(
            ['write_file' => ['allowedDecisions' => ['approve', 'edit'], 'description' => $descriptionFactory]],
            [[self::write('call_1', 'dynamic.txt', 'Hello Dynamic World')]],
            [$this->writeFileTool],
            'test-dynamic',
        );

        $agent->invoke(['messages' => [new HumanMessage('Write dynamic content')]], $config);

        // The description factory was called with the tool call, the state and the runtime
        self::assertCount(1, $factoryCalls);
        [$toolCall, $state, $runtime] = $factoryCalls[0];
        self::assertSame('call_1', $toolCall['id']);
        self::assertSame('write_file', $toolCall['name']);
        self::assertSame(['filename' => 'dynamic.txt', 'content' => 'Hello Dynamic World'], $toolCall['args']);
        self::assertIsArray($state['messages']);
        self::assertNotNull($runtime->context);

        // The generated description is in the interrupt
        $hitlRequest = self::pendingRequest($agent, $config);
        self::assertSame(
            "Dynamic description for tool: write_file\nFile: dynamic.txt\nContent length: 19 characters",
            $hitlRequest['actionRequests'][0]['description'],
        );

        $agent->invoke(self::resume([['type' => 'approve']]), $config);

        self::assertSame([['filename' => 'dynamic.txt', 'content' => 'Hello Dynamic World']], $this->writeFileCalls);
    }

    public function testShouldPropagateArgsSchemaToReviewConfig(): void
    {
        $testSchema = [
            'type' => 'object',
            'properties' => ['filename' => ['type' => 'string'], 'content' => ['type' => 'string']],
        ];
        [$agent, , $config] = $this->agent(
            ['write_file' => ['allowedDecisions' => ['edit'], 'argsSchema' => $testSchema]],
            [[self::write('call_1', 'test.txt', 'test')]],
            [$this->writeFileTool],
            'test-args-schema',
        );

        $agent->invoke(['messages' => [new HumanMessage('Write to file')]], $config);

        $hitlRequest = self::pendingRequest($agent, $config);
        self::assertCount(1, $hitlRequest['reviewConfigs']);
        self::assertEquals($testSchema, $hitlRequest['reviewConfigs'][0]['argsSchema']);
    }

    /** @param array<string, mixed> $decision */
    private function assertEditDecisionIsRejected(array $decision, string $threadId, string $message): void
    {
        [$agent, , $config] = $this->agent(
            ['write_file' => ['allowedDecisions' => ['edit']]],
            [[self::write('call_1', 'test.txt', 'test')]],
            [$this->writeFileTool],
            $threadId,
        );

        $agent->invoke(['messages' => [new HumanMessage('Write test file')]], $config);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage($message);

        $agent->invoke(self::resume([$decision]), $config);
    }

    public function testShouldThrowErrorWhenEditedActionHasInvalidName(): void
    {
        $this->assertEditDecisionIsRejected(
            ['type' => 'edit', 'editedAction' => ['name' => 123, 'args' => ['filename' => 'test.txt', 'content' => 'test']]],
            'test-invalid-name',
            'Invalid edited action for tool "write_file": name must be a string',
        );
    }

    public function testShouldThrowErrorWhenEditedActionHasInvalidArguments(): void
    {
        $this->assertEditDecisionIsRejected(
            ['type' => 'edit', 'editedAction' => ['name' => 'write_file', 'args' => 'not an object']],
            'test-invalid-arguments',
            'Invalid edited action for tool "write_file": args must be an object',
        );
    }

    public function testShouldThrowErrorWhenEditedActionIsMissing(): void
    {
        $this->assertEditDecisionIsRejected(
            ['type' => 'edit'],
            'test-missing-edited-action',
            'Invalid edited action for tool "write_file": name must be a string',
        );
    }

    /** @param mixed $resume */
    private function assertResumeIsRejected(mixed $resume, string $threadId): void
    {
        [$agent, , $config] = $this->agent(
            ['write_file' => ['allowedDecisions' => ['approve']]],
            [[self::write('call_1', 'test.txt', 'test')]],
            [$this->writeFileTool],
            $threadId,
        );

        $agent->invoke(['messages' => [new HumanMessage('Write test file')]], $config);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid HITLResponse: decisions must be a non-empty array');

        $agent->invoke(new Command(resume: $resume), $config);
    }

    public function testShouldThrowErrorWhenDecisionsArrayIsNotProvided(): void
    {
        // decisions is missing
        $this->assertResumeIsRejected(['unrelated' => true], 'test-no-decisions');
    }

    public function testShouldThrowErrorWhenDecisionsIsNotAnArray(): void
    {
        $this->assertResumeIsRejected(['decisions' => 'not an array'], 'test-decisions-not-array');
    }

    // ---- tool call ordering ----------------------------------------------------------------------

    /** @return list<array{id: string, name: string, args: array<string, mixed>}> */
    private static function interleavedCalls(string $file1 = 'file1.txt', string $file2 = 'file2.txt'): array
    {
        return [
            self::add('call_1', 1, 2),
            self::write('call_2', $file1, 'Content 1'),
            self::add('call_3', 3, 4),
            self::write('call_4', $file2, 'Content 2'),
        ];
    }

    public function testShouldReproduceOrderingBugWithHitlMiddleware(): void
    {
        [$agent, , $config] = $this->agent(
            ['write_file' => ['allowedDecisions' => ['approve']], 'calculator' => false],
            [self::interleavedCalls()],
            [$this->calculateTool, $this->writeFileTool],
            'test-order-bug-reproduction',
        );

        $agent->invoke(['messages' => [new HumanMessage('Calculate 1+2, write file1, calculate 3+4, write file2')]], $config);

        // The run is paused on the interrupt
        self::assertNotSame([], $agent->getState($config)->next);

        // The original order is intact in the state before the resume
        $before = self::lastOfType($agent->getState($config)->values['messages'], AIMessage::class);
        self::assertSame(['call_1', 'call_2', 'call_3', 'call_4'], array_column($before->toolCalls, 'id'));

        $resumeResult = $agent->invoke(self::resume([['type' => 'approve'], ['type' => 'approve']]), $config);

        // The AI message of the resume result carries the four calls in the original order
        $modified = null;
        foreach ($resumeResult['messages'] as $message) {
            if ($message instanceof AIMessage && \count($message->toolCalls) === 4) {
                $modified = $message;
                break;
            }
        }
        self::assertNotNull($modified);
        self::assertSame(['call_1', 'call_2', 'call_3', 'call_4'], array_column($modified->toolCalls, 'id'));
        self::assertSame(['calculator', 'write_file', 'calculator', 'write_file'], array_column($modified->toolCalls, 'name'));
    }

    public function testShouldPreserveOriginalOrderWhenMixingAutoApprovedAndInterruptToolCalls(): void
    {
        [$agent, , $config] = $this->agent(
            ['write_file' => ['allowedDecisions' => ['approve'], 'description' => '⚠️ File write operation requires approval'], 'calculator' => false],
            [self::interleavedCalls()],
            [$this->calculateTool, $this->writeFileTool],
            'test-order-1',
        );

        $agent->invoke(['messages' => [new HumanMessage('Calculate 1+2, write file1, calculate 3+4, write file2')]], $config);

        // The interrupt covers only the calls that need approval, in order
        $hitlRequest = self::pendingRequest($agent, $config);
        self::assertCount(2, $hitlRequest['actionRequests']);
        self::assertSame('write_file', $hitlRequest['actionRequests'][0]['name']);
        self::assertSame('file1.txt', $hitlRequest['actionRequests'][0]['args']['filename']);
        self::assertSame('write_file', $hitlRequest['actionRequests'][1]['name']);
        self::assertSame('file2.txt', $hitlRequest['actionRequests'][1]['args']['filename']);

        $lastMessage = self::lastOfType($agent->getState($config)->values['messages'], AIMessage::class);
        self::assertSame(
            [['id' => 'call_1', 'name' => 'calculator'], ['id' => 'call_2', 'name' => 'write_file'], ['id' => 'call_3', 'name' => 'calculator'], ['id' => 'call_4', 'name' => 'write_file']],
            self::callIds($lastMessage),
        );

        $agent->invoke(self::resume([['type' => 'approve'], ['type' => 'approve']]), $config);

        self::assertCount(2, $this->calculatorCalls);
        self::assertCount(2, $this->writeFileCalls);

        // The tool_calls array keeps the original interleaved order
        $finalAI = self::lastOfType($agent->getState($config)->values['messages'], AIMessage::class);
        self::assertSame(
            [['id' => 'call_1', 'name' => 'calculator'], ['id' => 'call_2', 'name' => 'write_file'], ['id' => 'call_3', 'name' => 'calculator'], ['id' => 'call_4', 'name' => 'write_file']],
            self::callIds($finalAI),
        );

        // Each tool saw its calls in order
        self::assertSame(['a' => 1, 'b' => 2, 'operation' => 'add'], $this->calculatorCalls[0]);
        self::assertSame(['filename' => 'file1.txt', 'content' => 'Content 1'], $this->writeFileCalls[0]);
        self::assertSame(['a' => 3, 'b' => 4, 'operation' => 'add'], $this->calculatorCalls[1]);
        self::assertSame(['filename' => 'file2.txt', 'content' => 'Content 2'], $this->writeFileCalls[1]);
    }

    public function testShouldPreserveOrderWhenSomeInterruptCallsAreRejected(): void
    {
        [$agent, , $config] = $this->agent(
            ['write_file' => ['allowedDecisions' => ['approve', 'reject']], 'calculator' => false],
            [self::interleavedCalls()],
            [$this->calculateTool, $this->writeFileTool],
            'test-order-reject',
        );

        $agent->invoke(['messages' => [new HumanMessage('Calculate 1+2, write file1, calculate 3+4, write file2')]], $config);

        // First approved, second rejected
        $agent->invoke(self::resume([['type' => 'approve'], ['type' => 'reject', 'message' => 'File 2 not allowed']]), $config);

        // Nothing ran: the run went back to the model
        self::assertSame([], $this->calculatorCalls);
        self::assertSame([], $this->writeFileCalls);

        // The state has the rejected tool message
        $toolMessages = AgentAssertions::ofType($agent->getState($config)->values['messages'], ToolMessage::class);
        self::assertNotSame([], $toolMessages);
        $rejected = array_values(array_filter($toolMessages, static fn (ToolMessage $m): bool => $m->toolCallId === 'call_4'));
        self::assertCount(1, $rejected);
        self::assertSame('File 2 not allowed', $rejected[0]->content);
    }

    public function testShouldPreserveOrderWithMultipleAutoApprovedToolsBetweenInterrupts(): void
    {
        [$agent, , $config] = $this->agent(
            ['write_file' => ['allowedDecisions' => ['approve']], 'calculator' => false],
            [[
                self::write('call_1', 'file1.txt', 'Content 1'),
                self::add('call_2', 1, 2),
                self::add('call_3', 3, 4),
                self::write('call_4', 'file2.txt', 'Content 2'),
                self::add('call_5', 5, 6),
            ]],
            [$this->calculateTool, $this->writeFileTool],
            'test-order-multiple-auto',
        );

        $agent->invoke(['messages' => [new HumanMessage('Write file1, calculate 1+2, calculate 3+4, write file2, calculate 5+6')]], $config);

        self::assertNotSame([], $agent->getState($config)->next);

        $lastMessage = self::lastOfType($agent->getState($config)->values['messages'], AIMessage::class);
        self::assertSame(
            [['id' => 'call_1', 'name' => 'write_file'], ['id' => 'call_2', 'name' => 'calculator'], ['id' => 'call_3', 'name' => 'calculator'], ['id' => 'call_4', 'name' => 'write_file'], ['id' => 'call_5', 'name' => 'calculator']],
            self::callIds($lastMessage),
        );

        $agent->invoke(self::resume([['type' => 'approve'], ['type' => 'approve']]), $config);

        self::assertCount(3, $this->calculatorCalls);
        self::assertCount(2, $this->writeFileCalls);
        self::assertSame('file1.txt', $this->writeFileCalls[0]['filename']);
        self::assertSame(['a' => 1, 'b' => 2, 'operation' => 'add'], $this->calculatorCalls[0]);
        self::assertSame(['a' => 3, 'b' => 4, 'operation' => 'add'], $this->calculatorCalls[1]);
        self::assertSame('file2.txt', $this->writeFileCalls[1]['filename']);
        self::assertSame(['a' => 5, 'b' => 6, 'operation' => 'add'], $this->calculatorCalls[2]);
    }

    public function testShouldPreserveOrderWhenEditingInterruptToolCalls(): void
    {
        [$agent, , $config] = $this->agent(
            ['write_file' => ['allowedDecisions' => ['approve', 'edit']], 'calculator' => false],
            [[
                self::add('call_1', 1, 2),
                self::write('call_2', 'original1.txt', 'Original 1'),
                self::add('call_3', 3, 4),
                self::write('call_4', 'original2.txt', 'Original 2'),
            ]],
            [$this->calculateTool, $this->writeFileTool],
            'test-order-edit',
        );

        $agent->invoke(['messages' => [new HumanMessage('Calculate 1+2, write file1, calculate 3+4, write file2')]], $config);

        // Edit the first file, approve the second
        $agent->invoke(self::resume([
            ['type' => 'edit', 'editedAction' => ['name' => 'write_file', 'args' => ['filename' => 'edited1.txt', 'content' => 'Edited 1']]],
            ['type' => 'approve'],
        ]), $config);

        self::assertCount(2, $this->calculatorCalls);
        self::assertCount(2, $this->writeFileCalls);
        self::assertSame(['a' => 1, 'b' => 2, 'operation' => 'add'], $this->calculatorCalls[0]);
        self::assertSame(['filename' => 'edited1.txt', 'content' => 'Edited 1'], $this->writeFileCalls[0]);
        self::assertSame(['a' => 3, 'b' => 4, 'operation' => 'add'], $this->calculatorCalls[1]);
        self::assertSame(['filename' => 'original2.txt', 'content' => 'Original 2'], $this->writeFileCalls[1]);
    }

    // ---- when predicate --------------------------------------------------------------------------

    public function testAutoApprovesTheToolCallWhenWhenReturnsFalse(): void
    {
        [$agent, , $config] = $this->agent(
            ['write_file' => [
                'allowedDecisions' => ['approve'],
                'when' => static fn (array $request): bool => str_starts_with((string) ($request['toolCall']['args']['filename'] ?? ''), 'danger'),
            ]],
            [[self::write('call_1', 'safe.txt', 'Safe content')], []],
            [$this->writeFileTool],
            'test-when-false',
        );

        $agent->invoke(['messages' => [new HumanMessage('Write to safe.txt')]], $config);

        // The agent ran to completion without interrupting
        $state = $agent->getState($config);
        self::assertCount(0, $state->next);
        self::assertSame([], $state->tasks[0]->interrupts ?? []);

        // The tool executed because the `when` predicate auto-approved it
        self::assertSame([['filename' => 'safe.txt', 'content' => 'Safe content']], $this->writeFileCalls);
    }

    public function testInterruptsForTheToolCallWhenWhenReturnsTrue(): void
    {
        [$agent, , $config] = $this->agent(
            ['write_file' => [
                'allowedDecisions' => ['approve'],
                'when' => static fn (array $request): bool => str_starts_with((string) ($request['toolCall']['args']['filename'] ?? ''), 'danger'),
            ]],
            [[self::write('call_1', 'danger.txt', 'Dangerous content')]],
            [$this->writeFileTool],
            'test-when-true',
        );

        $agent->invoke(['messages' => [new HumanMessage('Write to danger.txt')]], $config);

        // The tool must not run until the human approves
        self::assertSame([], $this->writeFileCalls);

        $state = $agent->getState($config);
        self::assertCount(1, $state->next);

        $hitlRequest = self::pendingRequest($agent, $config);
        self::assertCount(1, $hitlRequest['actionRequests']);
        self::assertSame('write_file', $hitlRequest['actionRequests'][0]['name']);
    }

    public function testPassesAToolCallRequestWithTheCorrectValuesToWhen(): void
    {
        $captured = [];
        [$agent, , $config] = $this->agent(
            ['write_file' => [
                'allowedDecisions' => ['approve'],
                'when' => static function (array $request) use (&$captured): bool {
                    $captured[] = $request;

                    return true;
                },
            ]],
            [[self::write('tc-1', 'report.txt', 'data')]],
            [$this->writeFileTool],
            'test-when-args',
        );

        $agent->invoke(['messages' => [new HumanMessage('Write report')]], $config);

        self::assertCount(1, $captured);
        $request = $captured[0];

        // The captured tool call matches the one emitted by the model
        self::assertEquals([
            'id' => 'tc-1',
            'name' => 'write_file',
            'args' => ['filename' => 'report.txt', 'content' => 'data'],
            'type' => 'tool_call',
        ], $request['toolCall']);

        // In batch mode the request is constructed without a concrete tool
        self::assertNull($request['tool']);

        // The request carries the live agent state: the human prompt followed by the AI message whose tool call is evaluated
        $messages = $request['state']['messages'];
        self::assertCount(2, $messages);
        self::assertInstanceOf(HumanMessage::class, $messages[0]);
        self::assertSame('Write report', $messages[0]->content);
        self::assertInstanceOf(AIMessage::class, $messages[1]);
        self::assertSame('tc-1', $messages[1]->toolCalls[0]['id']);
        self::assertSame('write_file', $messages[1]->toolCalls[0]['name']);

        // The request exposes the node-level runtime
        self::assertInstanceOf(Runtime::class, $request['runtime']);
        self::assertSame('test-when-args', $request['runtime']->configurable['thread_id']);
    }

    // ---- not in upstream's unit file -------------------------------------------------------------

    public function testTheDescriptionPrefixAndTheDefaultDescription(): void
    {
        $middleware = HumanInTheLoopMiddleware::create([
            'interruptOn' => ['write_file' => true],
            'descriptionPrefix' => 'Database operation pending approval',
        ]);
        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[self::write('call_1', 'a.txt', 'x')]]]),
            'checkpointer' => new MemorySaver(),
            'tools' => [$this->writeFileTool],
            'middleware' => [$middleware],
        ]);
        $config = ['configurable' => ['thread_id' => 'prefix']];

        $agent->invoke(['messages' => [new HumanMessage('go')]], $config);

        $request = self::pendingRequest($agent, $config);
        self::assertSame(
            "Database operation pending approval\n\nTool: write_file\nArgs: {\n  \"filename\": \"a.txt\",\n  \"content\": \"x\"\n}",
            $request['actionRequests'][0]['description'],
        );
        self::assertEquals(['approve', 'edit', 'reject'], $request['reviewConfigs'][0]['allowedDecisions']);
    }

    public function testInterruptOnCanComeFromTheRunContext(): void
    {
        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[self::write('call_1', 'a.txt', 'x')]]]),
            'checkpointer' => new MemorySaver(),
            'tools' => [$this->writeFileTool],
            'middleware' => [HumanInTheLoopMiddleware::create()],
        ]);
        $config = ['configurable' => ['thread_id' => 'ctx'], 'context' => ['interruptOn' => ['write_file' => true]]];

        $agent->invoke(['messages' => [new HumanMessage('go')]], $config);

        self::assertCount(1, self::pendingRequest($agent, $config)['actionRequests']);
        self::assertSame([], $this->writeFileCalls);
    }

    public function testNoInterruptOnConfigMeansNothingPauses(): void
    {
        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[self::write('call_1', 'a.txt', 'x')], []]]),
            'checkpointer' => new MemorySaver(),
            'tools' => [$this->writeFileTool],
            'middleware' => [HumanInTheLoopMiddleware::create()],
        ]);

        $agent->invoke(['messages' => [new HumanMessage('go')]], ['configurable' => ['thread_id' => 'none']]);

        self::assertCount(1, $this->writeFileCalls);
    }

    public function testComposesWithSummarizationAndContextEditingEndToEnd(): void
    {
        $summarizer = new class () {
            /** @var list<string> */
            public array $calls = [];

            public function invoke(string $prompt, mixed $config = null): array
            {
                $this->calls[] = $prompt;

                return ['content' => 'The user asked to write hello to a.txt.'];
            }
        };
        $agent = Agent::create([
            'model' => new FakeToolCallingModel(['toolCalls' => [[self::write('call_1', 'a.txt', 'hello')], []]]),
            'checkpointer' => new MemorySaver(),
            'tools' => [$this->writeFileTool],
            'middleware' => [
                SummarizationMiddleware::create(['model' => $summarizer, 'trigger' => ['messages' => 3], 'keep' => ['messages' => 1]]),
                ContextEditingMiddleware::create(['edits' => [new ClearToolUsesEdit(['trigger' => ['tokens' => 10], 'keep' => ['messages' => 0]])]]),
                HumanInTheLoopMiddleware::create(['interruptOn' => ['write_file' => true]]),
            ],
        ]);
        $config = ['configurable' => ['thread_id' => 'compose']];

        $agent->invoke(['messages' => [new HumanMessage('Write hello to a.txt')]], $config);

        // Paused on the write, nothing summarized or written yet
        self::assertSame('write_file', self::pendingRequest($agent, $config)['actionRequests'][0]['name']);
        self::assertSame([], $this->writeFileCalls);
        self::assertSame([], $summarizer->calls);

        $result = $agent->invoke(self::resume([['type' => 'approve']]), $config);

        // The approved call ran once; the history was summarized before the next model call, then the tool
        // result was cleared from it
        self::assertSame([['filename' => 'a.txt', 'content' => 'hello']], $this->writeFileCalls);
        self::assertCount(1, $summarizer->calls);
        $messages = $result['messages'];
        self::assertInstanceOf(HumanMessage::class, $messages[0]);
        self::assertStringContainsString('Here is a summary of the conversation to date', $messages[0]->content);
        $toolMessages = AgentAssertions::ofType($messages, ToolMessage::class);
        self::assertCount(1, $toolMessages);
        self::assertSame('[cleared]', $toolMessages[0]->content);
        self::assertInstanceOf(AIMessage::class, $messages[array_key_last($messages)]);
        self::assertSame([], $agent->getState($config)->next);
    }

    public function testLiveApiTestsAreNotConverted(): void
    {
        self::markTestSkipped('hitl.int.test.ts (5 cases) drives a live model through createAgent with a real API key; this port has no live-API suites.');
    }
}
