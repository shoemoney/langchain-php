<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents;

use LangGraph\Agents\Errors\MiddlewareError;
use LangGraph\Agents\Errors\MultipleStructuredOutputsError;
use LangGraph\Agents\Errors\MultipleToolsBoundError;
use LangGraph\Agents\Errors\StructuredOutputParsingError;
use LangGraph\Agents\Errors\ToolInvocationError;
use LangGraph\Errors\GraphInterrupt;
use LangGraph\Errors\NodeInterrupt;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for `langchain/src/agents/errors.ts`, which has no upstream test file of its own (its behaviour is
 * exercised through `ToolNode` and `createAgent`).
 */
#[CoversClass(MultipleToolsBoundError::class)]
#[CoversClass(MultipleStructuredOutputsError::class)]
#[CoversClass(StructuredOutputParsingError::class)]
#[CoversClass(ToolInvocationError::class)]
#[CoversClass(MiddlewareError::class)]
final class ErrorsTest extends TestCase
{
    public function testMultipleToolsBoundMessage(): void
    {
        self::assertSame(
            "The provided LLM already has bound tools. Please provide an LLM without bound tools to createAgent. The agent will bind the tools provided in the 'tools' parameter.",
            (new MultipleToolsBoundError())->getMessage(),
        );
    }

    public function testMultipleStructuredOutputsNamesTheTools(): void
    {
        $error = new MultipleStructuredOutputsError(['a', 'b']);

        self::assertSame(['a', 'b'], $error->toolNames);
        self::assertSame(
            'The model has called multiple tools: a, b to return a structured output. This is not supported. Please provide a single structured output.',
            $error->getMessage(),
        );
    }

    public function testStructuredOutputParsingErrorListsEveryProblem(): void
    {
        $error = new StructuredOutputParsingError('person', ['name is required', 'age must be a number']);

        self::assertSame('person', $error->toolName);
        self::assertSame(['name is required', 'age must be a number'], $error->errors);
        self::assertSame(
            "Failed to parse structured output for tool 'person':\n  - name is required\n  - age must be a number.",
            $error->getMessage(),
        );
    }

    public function testToolInvocationErrorCarriesTheCallAndTheCause(): void
    {
        $cause = new \RuntimeException('bad value');
        $call = ['id' => '1', 'name' => 'strict_tool', 'args' => ['value' => '123']];

        $error = new ToolInvocationError($cause, $call);

        self::assertSame($call, $error->toolCall);
        self::assertSame($cause, $error->toolError);
        self::assertSame($cause, $error->getPrevious());
        self::assertStringStartsWith("Error invoking tool 'strict_tool' with kwargs {\"value\":\"123\"} with error: ", $error->getMessage());
        self::assertStringContainsString('bad value', $error->getMessage());
        self::assertStringEndsWith("\n Please fix the error and try again.", $error->getMessage());
    }

    public function testToolInvocationErrorEncodesEmptyArgsAsAnObject(): void
    {
        $error = new ToolInvocationError(new \Exception('x'), ['id' => '1', 'name' => 't', 'args' => []]);

        self::assertStringContainsString("with kwargs {} with error", $error->getMessage());
    }

    public function testToolInvocationErrorWrapsNonErrorValues(): void
    {
        $error = new ToolInvocationError('just a string', ['id' => '1', 'name' => 't', 'args' => ['a' => 1]]);

        self::assertInstanceOf(\Throwable::class, $error->toolError);
        self::assertStringContainsString('just a string', $error->getMessage());
    }

    public function testToolInvocationErrorIsInstance(): void
    {
        $error = new ToolInvocationError(new \Exception('x'), ['name' => 't', 'args' => []]);

        self::assertTrue(ToolInvocationError::isInstance($error));
        self::assertFalse(ToolInvocationError::isInstance(new \Exception('x')));
        self::assertFalse(ToolInvocationError::isInstance('ToolInvocationError'));
        self::assertSame('ToolInvocationError', $error->brand);
    }

    public function testMiddlewareErrorWrapKeepsTheMessageAndTheCause(): void
    {
        $cause = new \InvalidArgumentException('boom');

        $wrapped = MiddlewareError::wrap($cause, 'guard');

        self::assertInstanceOf(MiddlewareError::class, $wrapped);
        self::assertSame('boom', $wrapped->getMessage());
        self::assertSame('InvalidArgumentException', $wrapped->errorName);
        self::assertSame($cause, $wrapped->getPrevious());
        self::assertSame('MiddlewareError', $wrapped->brand);
    }

    public function testMiddlewareErrorWrapOfANonErrorNamesTheMiddleware(): void
    {
        $wrapped = MiddlewareError::wrap('plain failure', 'guard');

        self::assertInstanceOf(MiddlewareError::class, $wrapped);
        self::assertSame('plain failure', $wrapped->getMessage());
        self::assertSame('GuardError', $wrapped->errorName);
        self::assertNull($wrapped->getPrevious());
    }

    public function testMiddlewareErrorNeverWrapsControlFlow(): void
    {
        $interrupt = new GraphInterrupt();
        $nodeInterrupt = new NodeInterrupt('stop');

        self::assertSame($interrupt, MiddlewareError::wrap($interrupt, 'guard'));
        self::assertSame($nodeInterrupt, MiddlewareError::wrap($nodeInterrupt, 'guard'));
    }

    public function testMiddlewareErrorCannotBeConstructedDirectly(): void
    {
        $constructor = (new \ReflectionClass(MiddlewareError::class))->getConstructor();

        self::assertNotNull($constructor);
        self::assertTrue($constructor->isPrivate());
    }

    public function testMiddlewareErrorIsInstance(): void
    {
        self::assertTrue(MiddlewareError::isInstance(MiddlewareError::wrap(new \Exception('x'), 'm')));
        self::assertFalse(MiddlewareError::isInstance(new \Exception('x')));
    }

    public function testWrappingTwiceNestsAndTheCauseChainReachesTheRoot(): void
    {
        $root = new ToolInvocationError(new \Exception('x'), ['name' => 't', 'args' => []]);

        $nested = MiddlewareError::wrap(MiddlewareError::wrap($root, 'inner'), 'outer');

        $walker = $nested;
        while (MiddlewareError::isInstance($walker)) {
            $walker = $walker->getPrevious();
        }
        self::assertSame($root, $walker);
    }
}
