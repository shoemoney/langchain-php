<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Tools;

use LangChain\Messages\ToolMessage;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tools\DynamicStructuredTool;
use LangChain\Tools\DynamicTool;
use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;
use LangChain\Tools\ToolException;
use LangChain\Tools\ToolOutput;
use LangChain\Tools\ToolUtils;
use LangChain\Tracers\CallbackHandler;
use LangChain\Utils\Testing\FakeTracer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * Port of `tools/tests/tools.test.ts`.
 *
 * The through-line of every test here is the same question asked seven ways:
 * did this tool call come *from a model*, and if so does the result carry the
 * right `tool_call_id`? That single attribute is what lets a tool-using loop
 * stitch a result back to the call that produced it.
 */
#[CoversClass(StructuredTool::class)]
#[CoversClass(DynamicTool::class)]
#[CoversClass(DynamicStructuredTool::class)]
#[CoversClass(Schema::class)]
#[CoversClass(ToolOutput::class)]
#[CoversClass(ToolException::class)]
#[CoversClass(ToolUtils::class)]
final class ToolTest extends TestCase
{
    // ---- responseFormat: content_and_artifact ----------------------------

    public function testContentAndArtifactThrowsWhenTheBodyReturnsNoTuple(): void
    {
        $weatherTool = tool(
            static fn (mixed $input): string => 'str',
            [
                'name' => 'weather',
                'schema' => Schema::object(['location' => ['type' => 'string']], ['location']),
                'responseFormat' => 'content_and_artifact',
            ],
        );

        $this->expectException(\RuntimeException::class);
        $weatherTool->invoke(['location' => 'San Francisco']);
    }

    public function testContentAndArtifactUnwrapsTheTupleWhenThereIsNoToolCall(): void
    {
        $weatherTool = tool(
            static fn (array $input): array => ['msg_content', $input],
            [
                'name' => 'weather',
                'schema' => Schema::object(['location' => ['type' => 'string']], ['location']),
                'responseFormat' => 'content_and_artifact',
            ],
        );

        // No tool_call_id means no ToolMessage — there is nothing to attribute
        // the result to, so the content half is returned bare.
        $result = $weatherTool->invoke(['location' => 'San Francisco']);

        $this->assertNotInstanceOf(ToolMessage::class, $result);
        $this->assertSame('msg_content', $result);
    }

    public function testContentAndArtifactReturnsAToolMessageWhenTheCallHasAnId(): void
    {
        $toolCall = [
            'id' => 'testid',
            'args' => ['location' => 'San Francisco'],
            'name' => 'weather',
            'type' => 'tool_call',
        ];

        $weatherTool = tool(
            static fn (array $input): array => ['msg_content', $input],
            [
                'name' => 'weather',
                'schema' => Schema::object(['location' => ['type' => 'string']], ['location']),
                'responseFormat' => 'content_and_artifact',
            ],
        );

        $result = $weatherTool->invoke($toolCall);

        $this->assertInstanceOf(ToolMessage::class, $result);
        $this->assertSame('msg_content', $result->content);
        $this->assertSame(['location' => 'San Francisco'], $result->artifact);
        $this->assertSame('weather', $result->toolName);
    }

    public function testContentAndArtifactPassesTheToolCallThroughTheConfig(): void
    {
        // A tool call with no id is a direct application call. It must reach the
        // body via config.toolCall — that is how a tool knows it is being
        // invoked on the model's behalf even though nothing was wrapped.
        $toolCall = [
            'args' => ['location' => 'San Francisco'],
            'name' => 'weather',
            'type' => 'tool_call',
        ];

        $seen = null;
        $weatherTool = tool(
            static function (array $input, mixed $runManager, ?RunnableConfig $config) use (&$seen): array {
                $seen = $config?->toolCall;

                return ['msg_content', $input];
            },
            [
                'name' => 'weather',
                'schema' => Schema::object(['location' => ['type' => 'string']], ['location']),
                'responseFormat' => 'content_and_artifact',
            ],
        );

        $result = $weatherTool->invoke($toolCall);

        $this->assertSame('msg_content', $result);
        $this->assertSame($toolCall, $seen);
    }

    // ---- ToolMessage content coercion -----------------------------------

    public function testToolMessageContentCoercesToEmptyStringForNull(): void
    {
        $toolCall = [
            'id' => 'testid',
            'args' => ['location' => 'San Francisco'],
            'name' => 'weather',
            'type' => 'tool_call',
        ];

        $weatherTool = tool(
            static fn (): mixed => null,
            [
                'name' => 'weather',
                'schema' => Schema::object(['location' => ['type' => 'string']], ['location']),
            ],
        );

        $result = $weatherTool->invoke($toolCall);

        $this->assertInstanceOf(ToolMessage::class, $result);
        // '' rather than 'null': an absent result is not the JSON value null,
        // and "null" would read to the model as if the tool had said so.
        $this->assertSame('', $result->content);
    }

    public function testToolMessageContentIsJsonStringifiedForAnArrayOfPlainObjects(): void
    {
        $toolCall = [
            'id' => 'testid',
            'args' => ['location' => 'San Francisco'],
            'name' => 'weather',
            'type' => 'tool_call',
        ];

        $forecast = [
            ['day' => 'Mon', 'highF' => 64, 'condition' => 'Foggy'],
            ['day' => 'Tue', 'highF' => 67, 'condition' => 'Sunny'],
        ];

        $weatherTool = tool(
            static fn (): array => $forecast,
            [
                'name' => 'weather',
                'schema' => Schema::object(['location' => ['type' => 'string']], ['location']),
            ],
        );

        $result = $weatherTool->invoke($toolCall);

        $this->assertInstanceOf(ToolMessage::class, $result);
        $this->assertSame(json_encode($forecast), $result->content);
    }

    public function testToolMessageContentPassesContentBlocksThroughUnchanged(): void
    {
        $toolCall = [
            'id' => 'testid',
            'args' => ['location' => 'San Francisco'],
            'name' => 'weather',
            'type' => 'tool_call',
        ];

        $blocks = [
            ['type' => 'text', 'text' => 'Sunny'],
            ['type' => 'image_url', 'image_url' => ['url' => 'https://example.com/sf.png']],
        ];

        $weatherTool = tool(
            static fn (): array => $blocks,
            [
                'name' => 'weather',
                'schema' => Schema::object(['location' => ['type' => 'string']], ['location']),
            ],
        );

        $result = $weatherTool->invoke($toolCall);

        $this->assertInstanceOf(ToolMessage::class, $result);
        // Encoding these would turn an image reference into a JSON string and
        // destroy the block, so a list of blocks passes through untouched.
        $this->assertSame($blocks, $result->content);
    }

    public function testADirectlyReturnedToolMessageIsNotDoubleWrapped(): void
    {
        $toolCall = [
            'id' => 'testid',
            'args' => ['location' => 'San Francisco'],
            'name' => 'weather',
            'type' => 'tool_call',
        ];

        $weatherTool = tool(
            static fn (): ToolMessage => new ToolMessage([
                'tool_call_id' => 'not_original',
                'content' => 'bar',
                'tool_name' => 'baz',
            ]),
            [
                'name' => 'weather',
                'schema' => Schema::object(['location' => ['type' => 'string']], ['location']),
            ],
        );

        $result = $weatherTool->invoke($toolCall);

        $this->assertInstanceOf(ToolMessage::class, $result);
        // Re-wrapping would overwrite the tool's own id with the call's, and the
        // model would then be replying to the wrong call.
        $this->assertSame('not_original', $result->toolCallId);
        $this->assertSame('bar', $result->content);
        $this->assertSame('baz', $result->toolName);
    }

    // ---- string-schema tools --------------------------------------------

    public function testToolAcceptsSingleStringInput(): void
    {
        $toolCall = [
            'id' => 'testid',
            'args' => ['input' => 'b'],
            'name' => 'string_tool',
            'type' => 'tool_call',
        ];

        $seenConfig = null;
        $stringTool = tool(
            static function (mixed $input, mixed $runManager, ?RunnableConfig $config) use (&$seenConfig): string {
                $seenConfig = $config;

                return $input . 'a';
            },
            [
                'name' => 'string_tool',
                'description' => "A tool that appends 'a' to the input string",
                'schema' => Schema::string(),
            ],
        );

        $result = $stringTool->invoke('b', new RunnableConfig(configurable: ['foo' => 'bar']));
        $this->assertSame('ba', $result);
        $this->assertSame(['foo' => 'bar'], $seenConfig?->configurable);

        $result2 = $stringTool->invoke($toolCall, new RunnableConfig(configurable: ['foo' => 'bar', 'usesToolCall' => true]));
        $this->assertInstanceOf(ToolMessage::class, $result2);
        $this->assertSame('ba', $result2->content);
        $this->assertSame('string_tool', $result2->toolName);
    }

    public function testToolFactoryReturnsDynamicToolForAStringSchema(): void
    {
        $stringTool = tool(static fn (string $i): string => $i, ['name' => 'test_tool', 'schema' => Schema::string()]);

        $this->assertInstanceOf(DynamicTool::class, $stringTool);
    }

    public function testToolFactoryReturnsDynamicStructuredToolForAnObjectSchema(): void
    {
        $greetTool = tool(
            static fn (array $i): string => 'Hello ' . $i['name'],
            [
                'name' => 'greet_tool',
                'schema' => Schema::object(['name' => ['type' => 'string'], 'age' => ['type' => 'number']], ['name', 'age']),
            ],
        );

        $this->assertInstanceOf(DynamicStructuredTool::class, $greetTool);
    }

    public function testToolFactoryDefaultsTheDescriptionToNamePlusTool(): void
    {
        $t = tool(static fn (string $i): string => $i, ['name' => 'my_tool']);

        $this->assertSame('my_tool tool', $t->description);
    }

    public function testToolFactoryRejectsAMissingName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        tool(static fn (string $i): string => $i, ['description' => 'no name']);
    }

    // ---- JSON-schema validation -----------------------------------------

    public function testJsonSchemaToolRejectsUnknownProperties(): void
    {
        $calls = 0;
        $weatherTool = tool(
            static function () use (&$calls): string {
                ++$calls;

                return 'Sunny';
            },
            [
                'name' => 'weather',
                'schema' => [
                    'type' => 'object',
                    'properties' => ['location' => ['type' => 'string']],
                    'required' => ['location'],
                ],
            ],
        );

        try {
            $weatherTool->invoke(['somethingSilly' => true]);
            $this->fail('Expected a ToolInputParsingException.');
        } catch (ToolException) {
            // Expected.
        }

        // The body must not have run: a tool with side effects must not act on
        // arguments that never validated.
        $this->assertSame(0, $calls);

        $this->assertSame('Sunny', $weatherTool->invoke(['location' => 'San Francisco']));
        $this->assertSame(1, $calls);
    }

    public function testDirectlyConstructedToolValidatesToo(): void
    {
        $calls = 0;
        $tool = new DynamicStructuredTool(
            [
                'name' => 'weather',
                'description' => 'get the weather',
                'schema' => [
                    'type' => 'object',
                    'properties' => ['location' => ['type' => 'string']],
                    'required' => ['location'],
                ],
            ],
            static function () use (&$calls): string {
                ++$calls;

                return 'Sunny';
            },
        );

        try {
            $tool->invoke(['somethingSilly' => true]);
            $this->fail('Expected a ToolInputParsingException.');
        } catch (ToolException) {
            // Expected.
        }
        $this->assertSame(0, $calls);

        $this->assertSame('Sunny', $tool->invoke(['location' => 'San Francisco']));
        $this->assertSame(1, $calls);
    }

    public function testValidationRejectsAMissingRequiredProperty(): void
    {
        $t = tool(static fn (array $i): string => 'Sunny', [
            'name' => 'weather',
            'schema' => Schema::object(['location' => ['type' => 'string']], ['location']),
        ]);

        $this->expectException(ToolException::class);
        $t->invoke(['badval' => 'someval']);
    }

    public function testValidationRejectsAWronglyTypedProperty(): void
    {
        $t = tool(static fn (array $i): string => 'Sunny', [
            'name' => 'weather',
            'schema' => Schema::object(['location' => ['type' => 'string']], ['location']),
        ]);

        $this->expectException(ToolException::class);
        $t->invoke(['location' => 8]);
    }

    public function testVerboseParsingErrorsIncludeTheDetails(): void
    {
        $stringTool = tool(
            static fn (array $i): string => (string) json_encode($i),
            [
                'name' => 'string_tool',
                'description' => "A tool that appends 'a' to the input string",
                'schema' => Schema::object(['location' => ['type' => 'string']], ['location']),
                'verboseParsingErrors' => true,
            ],
        );

        try {
            $stringTool->invoke(['location' => 8]);
            $this->fail('Expected a ToolInputParsingException.');
        } catch (ToolException $e) {
            $this->assertStringStartsWith('Received tool input did not match expected schema', $e->getMessage());
            $this->assertStringContainsString('Details:', $e->getMessage());
            // The raw input is attached so the retry message can show the model
            // what it actually sent.
            $this->assertSame('{"location":8}', $e->output);
        }
    }

    public function testParsingErrorsAreQuietByDefault(): void
    {
        $t = tool(static fn (array $i): string => 'Sunny', [
            'name' => 'weather',
            'schema' => Schema::object(['location' => ['type' => 'string']], ['location']),
        ]);

        try {
            $t->invoke(['location' => 8]);
            $this->fail('Expected a ToolInputParsingException.');
        } catch (ToolException $e) {
            // No "Details:" — the message goes back into the model's context on
            // a retry, and a wall of JSON about its own mistake is just noise.
            $this->assertSame('Received tool input did not match expected schema', $e->getMessage());
        }
    }

    // ---- metadata and extras --------------------------------------------

    public function testDynamicToolThreadsMetadataIntoTheResultingToolMessage(): void
    {
        $t = new DynamicTool(
            ['name' => 'test', 'description' => 'test', 'metadata' => ['foo' => 'bar']],
            static fn (mixed $i): string => 'test',
        );

        $result = $t->invoke([
            'id' => 'test_id',
            'name' => 'test',
            'args' => ['input' => 'test'],
            'type' => 'tool_call',
        ]);

        $this->assertInstanceOf(ToolMessage::class, $result);
        $this->assertSame(['foo' => 'bar'], $result->response_metadata);
    }

    public function testToolFactoryPropagatesExtras(): void
    {
        $testTool = tool(static fn (string $i): string => 'processed: ' . $i, [
            'name' => 'test_tool',
            'description' => 'A test tool',
            'extras' => ['foo' => 'test'],
        ]);

        $this->assertSame(['foo' => 'test'], $testTool->extras);
    }

    // ---- streaming tools -------------------------------------------------

    public function testGeneratorToolYieldsPartialResultsAndReturnsTheFinalOne(): void
    {
        $partials = [];

        $testTool = tool(
            static function (array $input) use (&$partials): \Generator {
                $partials[] = ['status' => 'searching', 'city' => $input['city']];
                $partials[] = ['status' => 'found', 'temperature' => 72];

                return "Weather in {$input['city']}: 72F";

                // Unreachable: PHP needs at least one yield for a function
                // containing one to compile as a generator at all.
                // phpcs:ignore
                yield;
            },
            [
                'name' => 'weather',
                'schema' => Schema::object(['city' => ['type' => 'string']], ['city']),
                'description' => 'Get weather',
            ],
        );

        $result = $testTool->invoke([
            'id' => 'call_123',
            'name' => 'weather',
            'args' => ['city' => 'SF'],
            'type' => 'tool_call',
        ]);

        $this->assertInstanceOf(ToolMessage::class, $result);
        $this->assertSame('Weather in SF: 72F', $result->content);

        $this->assertCount(2, $partials);
        $this->assertSame(['status' => 'searching', 'city' => 'SF'], $partials[0]);
        $this->assertSame(['status' => 'found', 'temperature' => 72], $partials[1]);
    }

    public function testStringSchemaGeneratorToolStreamsToo(): void
    {
        $partials = [];

        $stringSchemaTool = tool(
            static function (mixed $input) use (&$partials): \Generator {
                $partials[] = ['message' => 'step 1', 'input' => $input];
                $partials[] = ['message' => 'step 2'];

                return "Done: {$input}";

                // phpcs:ignore
                yield;
            },
            [
                'name' => 'string_stream_tool',
                'description' => 'A string-input tool that streams progress',
                'schema' => Schema::string(),
            ],
        );

        $result = $stringSchemaTool->invoke('hello');

        $this->assertSame('Done: hello', $result);
        $this->assertCount(2, $partials);
        $this->assertSame(['message' => 'step 1', 'input' => 'hello'], $partials[0]);
        $this->assertSame(['message' => 'step 2'], $partials[1]);
    }

    public function testNonGeneratorToolEmitsNoEvents(): void
    {
        $partials = [];

        $testTool = tool(
            static fn (array $input): string => "Weather in {$input['city']}: 72F",
            [
                'name' => 'weather',
                'schema' => Schema::object(['city' => ['type' => 'string']], ['city']),
                'description' => 'Get weather',
            ],
        );

        $result = $testTool->invoke(['city' => 'SF'], new RunnableConfig(callbacks: [
            CallbackHandler::fromMethods([
                'handleToolEvent' => static function (mixed $chunk) use (&$partials): void {
                    $partials[] = $chunk;
                },
            ]),
        ]));

        $this->assertSame('Weather in SF: 72F', $result);
        $this->assertCount(0, $partials);
    }

    public function testGeneratorToolWithNoYieldsStillReturns(): void
    {
        $testTool = tool(
            static function (array $input): \Generator {
                return $input['x'] * 2;

                // phpcs:ignore
                yield;
            },
            [
                'name' => 'double',
                'schema' => Schema::object(['x' => ['type' => 'number']], ['x']),
                'description' => 'Double a number',
            ],
        );

        $this->assertSame(10, $testTool->invoke(['x' => 5]));
    }

    public function testHandleToolStartAndEndFireAroundAStreamingTool(): void
    {
        $events = [];

        $testTool = tool(
            static function (array $input): \Generator {
                yield ['status' => 'searching', 'city' => $input['city']];
                yield ['status' => 'found', 'temperature' => 72];

                return "Weather in {$input['city']}: 72F";
            },
            [
                'name' => 'weather',
                'schema' => Schema::object(['city' => ['type' => 'string']], ['city']),
                'description' => 'Get weather',
            ],
        );

        $testTool->invoke(['city' => 'SF'], new RunnableConfig(callbacks: [
            CallbackHandler::fromMethods([
                'handleToolStart' => static function () use (&$events): void {
                    $events[] = 'start';
                },
                'handleToolEvent' => static function () use (&$events): void {
                    $events[] = 'stream';
                },
                'handleToolEnd' => static function () use (&$events): void {
                    $events[] = 'end';
                },
            ]),
        ]));

        $this->assertSame(['start', 'stream', 'stream', 'end'], $events);
    }

    public function testGeneratorErrorMidStreamReachesHandleToolError(): void
    {
        $handledError = null;

        $testTool = tool(
            static function (array $input): \Generator {
                yield $input['chunk'];

                throw new \RuntimeException('Mid-stream failure');
            },
            [
                'name' => 'failing',
                'schema' => Schema::object(['chunk' => ['type' => 'string']], ['chunk']),
                'description' => 'Generator that throws after yielding',
            ],
        );

        try {
            $testTool->invoke(['chunk' => 'chunk1'], new RunnableConfig(callbacks: [
                CallbackHandler::fromMethods([
                    'handleToolError' => static function (\Throwable $e) use (&$handledError): void {
                        $handledError = $e;
                    },
                ]),
            ]));
            $this->fail('Expected the mid-stream failure to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Mid-stream failure', $e->getMessage());
        }

        $this->assertInstanceOf(\RuntimeException::class, $handledError);
        $this->assertSame('Mid-stream failure', $handledError->getMessage());
    }

    public function testCallbackErrorsDuringStreamingAreResilient(): void
    {
        $handledError = null;

        $testTool = tool(
            static function (array $input): \Generator {
                yield $input['chunk'];

                return 'final';
            },
            [
                'name' => 'streaming',
                'schema' => Schema::object(['chunk' => ['type' => 'string']], ['chunk']),
                'description' => 'Generator that yields then returns',
            ],
        );

        $handler = CallbackHandler::fromMethods([
            'handleToolEvent' => static function (): void {
                throw new \RuntimeException('Callback failed');
            },
            'handleToolError' => static function (\Throwable $e) use (&$handledError): void {
                $handledError = $e;
            },
        ]);
        $handler->raiseError = true;

        $result = $testTool->invoke(['chunk' => 'chunk1'], new RunnableConfig(callbacks: [$handler]));

        // The observer broke; the tool's answer did not change. That is the
        // whole point of containing handler failures.
        $this->assertSame('final', $result);
        $this->assertInstanceOf(\RuntimeException::class, $handledError);
        $this->assertSame('Callback failed', $handledError->getMessage());
    }

    public function testAbandonedGeneratorStillRunsItsCleanup(): void
    {
        $cleanupRan = false;

        $throwingTool = tool(
            static function (array $input) use (&$cleanupRan): \Generator {
                try {
                    yield $input['chunk'];

                    throw new \RuntimeException('Generator fails');
                } finally {
                    // A generator abandoned mid-stream never runs its own
                    // cleanup, so a tool holding a handle or a transaction would
                    // leak it.
                    $cleanupRan = true;
                }
            },
            [
                'name' => 'throwing',
                'schema' => Schema::object(['chunk' => ['type' => 'string']], ['chunk']),
                'description' => 'Generator that throws after yield',
            ],
        );

        try {
            $throwingTool->invoke(['chunk' => 'chunk1']);
            $this->fail('Expected the generator failure to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Generator fails', $e->getMessage());
        }

        $this->assertTrue($cleanupRan);
    }

    // ---- tracing ---------------------------------------------------------

    public function testStructuredToolInputsStayStructuredInTracerRuns(): void
    {
        $tracer = new FakeTracer();
        $testTool = tool(
            static fn (array $input): string => $input['command'],
            [
                'name' => 'execute',
                'schema' => Schema::object(
                    ['command' => ['type' => 'string'], 'timeout' => ['type' => 'number']],
                    ['command', 'timeout'],
                ),
                'description' => 'Execute a command',
            ],
        );

        $testTool->invoke(
            ['command' => 'echo hello', 'timeout' => 1],
            new RunnableConfig(callbacks: [$tracer]),
        );

        // Stringifying here would make every structured tool's run record
        // unqueryable.
        $this->assertSame(['command' => 'echo hello', 'timeout' => 1], $tracer->runs[0]->inputs);
    }

    public function testHandleToolStartReceivesTheToolCallId(): void
    {
        $expectedToolCallId = 'call_abc123';
        $receivedToolCallId = null;

        $testTool = tool(
            static fn (array $input): string => (string) $input['x'],
            [
                'name' => 'adder',
                'schema' => Schema::object(['x' => ['type' => 'number']], ['x']),
                'description' => 'Echo x',
            ],
        );

        $testTool->invoke(
            ['id' => $expectedToolCallId, 'name' => 'adder', 'args' => ['x' => 42], 'type' => 'tool_call'],
            new RunnableConfig(callbacks: [
                CallbackHandler::fromMethods([
                    'handleToolStart' => static function (
                        mixed $tool,
                        mixed $input,
                        string $runId,
                        ?string $parentRunId,
                        array $tags,
                        array $metadata,
                        ?string $runName,
                        ?string $toolCallId,
                    ) use (&$receivedToolCallId): void {
                        $receivedToolCallId = $toolCallId;
                    },
                ]),
            ]),
        );

        $this->assertSame($expectedToolCallId, $receivedToolCallId);
    }

    // ---- tool-call detection --------------------------------------------

    public static function toolCallShapes(): array
    {
        return [
            'with id' => [['type' => 'tool_call', 'id' => 'x', 'name' => 't', 'args' => []], true],
            'without id' => [['type' => 'tool_call', 'name' => 't', 'args' => []], true],
            'wrong type' => [['type' => 'tool_call_chunk', 'id' => 'x'], false],
            'no type' => [['id' => 'x', 'name' => 't', 'args' => []], false],
            'a positional list' => [['tool_call'], false],
            'args that merely mention tool_call' => [['name' => 't', 'args' => ['type' => 'tool_call']], false],
            'a string' => ['tool_call', false],
            'null' => [null, false],
        ];
    }

    #[DataProvider('toolCallShapes')]
    public function testIsToolCallDistinguishesTheEnvelopeFromBareArguments(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, \LangChain\Tools\ToolUtils::isToolCall($value));
    }

    public function testAToolCallIdInTheConfigProducesAToolMessage(): void
    {
        $t = tool(static fn (array $i): string => 'Sunny', [
            'name' => 'weather',
            'schema' => Schema::object(['location' => ['type' => 'string']], ['location']),
        ]);

        // Bare arguments plus a tool call in the config is the same situation as
        // passing the envelope directly — an agent loop can hand over either.
        $result = $t->invoke(
            ['location' => 'San Francisco'],
            new RunnableConfig(toolCall: [
                'id' => '1',
                'name' => 'weather',
                'args' => ['location' => 'San Francisco'],
                'type' => 'tool_call',
            ]),
        );

        $this->assertInstanceOf(ToolMessage::class, $result);
    }

    // ---- schema behaviour ------------------------------------------------

    public function testAnEmptySchemaAcceptsAnything(): void
    {
        $this->assertSame([], Schema::any()->errors(['whatever' => 1]));
    }

    public function testASchemaFromNullAcceptsAnything(): void
    {
        $this->assertSame([], Schema::from(null)->errors('anything'));
    }

    public function testASchemaRejectsANonArrayNonSchema(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Schema::from('not a schema');
    }

    public function testAdditionalPropertiesFalseIsEnforced(): void
    {
        $schema = new Schema([
            'type' => 'object',
            'properties' => ['location' => ['type' => 'string']],
            'required' => ['location'],
            'additionalProperties' => false,
        ]);

        $errors = $schema->errors(['location' => 'SF', 'extra' => 1]);
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('extra', $errors[0]['message']);
    }

    public function testAnyOfAcceptsTheFirstMatchingOption(): void
    {
        $schema = new Schema(['anyOf' => [['type' => 'string'], ['type' => 'integer']]]);

        $this->assertSame([], $schema->errors('a string'));
        $this->assertSame([], $schema->errors(42));
        $this->assertNotSame([], $schema->errors(1.5));
    }

    public function testArrayItemsAreValidated(): void
    {
        $schema = new Schema(['type' => 'array', 'items' => ['type' => 'string']]);

        $this->assertSame([], $schema->errors(['a', 'b']));
        $this->assertNotSame([], $schema->errors(['a', 2]));
    }

    public static function stringOnlySchemas(): array
    {
        return [
            'type string' => [['type' => 'string'], true],
            'type string list' => [['type' => ['string']], true],
            'type object' => [['type' => 'object'], false],
            'string enum' => [['enum' => ['a', 'b']], true],
            'mixed enum' => [['enum' => ['a', 1]], false],
            'string const' => [['const' => 'a'], true],
            'integer const' => [['const' => 1], false],
            'empty' => [[], false],
        ];
    }

    #[DataProvider('stringOnlySchemas')]
    public function testValidatesOnlyStrings(array $schema, bool $expected): void
    {
        $this->assertSame($expected, (new Schema($schema))->validatesOnlyStrings());
    }

    public function testAnEmptySchemaIsReportedAsAnEmptyObject(): void
    {
        // A model asked to satisfy `{}` will invent fields; an empty properties
        // map is the honest way to say "takes no arguments".
        $this->assertSame(['type' => 'object', 'properties' => []], Schema::any()->toJsonSchema());
    }
}
