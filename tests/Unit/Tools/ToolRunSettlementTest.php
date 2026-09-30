<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Tools;

use LangChain\Runnables\RunnableConfig;
use LangChain\Tools\Schema;
use LangChain\Tools\StructuredTool;
use LangChain\Tracers\Run;
use LangChain\Tracers\RunCollectorCallbackHandler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

use function LangChain\Tools\tool;

/**
 * A tool run must settle even when the failure happens AFTER `execute()`.
 *
 * `callToolWithValidation()` guarded only `execute()`. `splitResult()` throws
 * when a tool does not return a `[content, artifact]` tuple, and
 * `ToolOutput::format()` can throw too — both sat after the try block, so either
 * escaped with no `handleToolError` and no `handleToolEnd`, leaving the tool's
 * span open forever in any trace UI.
 *
 * That is the same shape as the batch-run leak fixed earlier: a run that starts
 * and never ends, which reads as "hanging" and is invisible in a green suite.
 */
#[CoversClass(StructuredTool::class)]
final class ToolRunSettlementTest extends TestCase
{
    /**
     * @param callable $func
     */
    private function toolWith(callable $func, string $responseFormat = 'content'): StructuredTool
    {
        return tool($func, [
            'name' => 't',
            'description' => 'a tool under test',
            'schema' => Schema::object([], []),
            'responseFormat' => $responseFormat,
        ]);
    }

    /** @return list<Run> */
    private function toolRuns(RunCollectorCallbackHandler $collector): array
    {
        return array_values(array_filter(
            $collector->tracedRuns,
            static fn (Run $r): bool => $r->runType === 'tool',
        ));
    }

    /**
     * The regression: the failure happens after `execute()` returned, so nothing
     * was guarding it and the run never settled.
     */
    public function testAFailureAfterExecuteStillErrorsTheRun(): void
    {
        $collector = new RunCollectorCallbackHandler();

        try {
            // A bare string is not the [content, artifact] tuple
            // `splitResult()` expects, so this throws AFTER execute() succeeded.
            // `splitResult()` only unpacks — and so only throws — under the
            // content_and_artifact response format. With the default format a
            // bare string is the content and nothing fails, which is why the
            // first version of this fixture asserted nothing.
            $this->toolWith(static fn (): string => 'not a tuple', 'content_and_artifact')
                ->invoke([], new RunnableConfig(callbacks: [$collector]));
            self::fail('an unpackable tool result must not pass silently');
        } catch (\Throwable) {
            // expected
        }

        $runs = $this->toolRuns($collector);
        self::assertNotEmpty($runs, 'the tool run must be recorded');

        foreach ($runs as $run) {
            self::assertNotNull(
                $run->error,
                'a failure after execute() must still reach handleToolError, or the span hangs forever',
            );
        }
    }

    public function testASuccessfulToolRunStillEndsWithoutAnError(): void
    {
        $collector = new RunCollectorCallbackHandler();

        $this->toolWith(static fn (): string => 'fine')->invoke([], new RunnableConfig(callbacks: [$collector]));

        $runs = $this->toolRuns($collector);
        self::assertNotEmpty($runs);
        foreach ($runs as $run) {
            self::assertNull($run->error, 'a successful tool must not record an error');
        }
    }
}
