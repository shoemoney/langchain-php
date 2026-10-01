<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Tools;

use LangChain\Runnables\RunnableConfig;
use LangChain\Tools\DynamicStructuredTool;
use LangChain\Tools\DynamicTool;
use LangChain\Tools\ToolRuntime;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * A tool function that declares a `ToolRuntime` parameter receives one.
 *
 * Upstream `tools/types.ts:472-486` documents this as automatic:
 *
 *     When a tool function has a parameter typed `ToolRuntime`, the tool execution system will
 *     automatically inject an instance containing state, toolCallId, config, context, store, writer.
 *     No `Annotated` wrapper is needed - just use `runtime: ToolRuntime` as a parameter.
 *
 * The port shipped the CLASS — with every field upstream lists — and the ledger marked it done, but nothing
 * in `src/` constructed one: 0 internal callers, 0 tests, and the two invocation sites forwarded the run
 * manager into the second slot. A tool written correctly against the documentation therefore died with
 *
 *     TypeError: Argument #2 ($rt) must be of type LangChain\Tools\ToolRuntime, LangChain\Tracers\...
 *
 * which names two unrelated port classes and gives no hint that a documented feature is missing.
 *
 * **The negative cases are the point.** `(input, runManager, config)` is the OLDER upstream tool signature
 * and the port implements it correctly, so injection must be conditional on the type hint — an
 * unconditional second-argument swap would break every tool already written against that shape.
 */
#[CoversNothing]
final class ToolRuntimeInjectionTest extends TestCase
{
    /** @return array<string, mixed> */
    private static function fields(): array
    {
        return [
            'name' => 'probe',
            'description' => 'probe',
            'parameters' => ['type' => 'object', 'properties' => ['x' => ['type' => 'integer']]],
        ];
    }

    public function testAStructuredToolDeclaringToolRuntimeReceivesOne(): void
    {
        $tool = new DynamicStructuredTool(self::fields(), static function (array $in, ToolRuntime $rt): string {
            return get_class($rt);
        });

        self::assertSame(ToolRuntime::class, $tool->invoke(['x' => 1]));
    }

    public function testAPlainToolDeclaringToolRuntimeReceivesOne(): void
    {
        $tool = new DynamicTool(self::fields(), static function (mixed $in, ToolRuntime $rt): string {
            return get_class($rt);
        });

        self::assertSame(ToolRuntime::class, $tool->invoke('hello'));
    }

    public function testTheInjectedRuntimeCarriesTheConfigItWasBuiltFrom(): void
    {
        $tool = new DynamicStructuredTool(self::fields(), static function (array $in, ToolRuntime $rt): string {
            return json_encode($rt->configurable);
        });

        // `ToolRuntime::fromConfig()` returns null unless the config carries a `toolCall` — it is a
        // pre-existing guard, not something 458 introduced. I had assumed it populated `configurable`
        // unconditionally and written a fixture that could not reach it, so the config here supplies the
        // tool call the real path needs.
        $out = $tool->invoke(['x' => 1], RunnableConfig::fromArray([
            'toolCall' => ['id' => 'call-1', 'name' => 'probe'],
            'configurable' => ['thread_id' => 'thread-abc'],
        ]));

        self::assertStringContainsString('thread-abc', (string) $out,
            'the injected runtime must be built from the config actually in force, not be an empty shell');
    }

    /**
     * With no `toolCall` in the config, `fromConfig()` yields null — the tool must still receive a
     * `ToolRuntime` rather than a TypeError, because upstream injects one unconditionally.
     */
    public function testAToolOutsideAToolCallContextStillReceivesARuntimeRatherThanFailing(): void
    {
        $tool = new DynamicStructuredTool(self::fields(), static function (array $in, ToolRuntime $rt): string {
            return $rt->toolCallId;
        });

        self::assertSame('', $tool->invoke(['x' => 1]),
            'no toolCall in config means fromConfig() returns null; the fallback must still be a ToolRuntime');
    }

    /** A tool on the OLDER `(input, runManager, config)` contract must be untouched. */
    public function testAToolDeclaringRunManagerStillReceivesTheRunManager(): void
    {
        $tool = new DynamicStructuredTool(self::fields(), static function (array $in, mixed $runManager): string {
            return get_debug_type($runManager);
        });

        $type = $tool->invoke(['x' => 1]);

        self::assertNotSame(ToolRuntime::class, $type,
            'injection must be conditional on the type hint — swapping the second argument unconditionally '
                . 'would break every tool written against the older upstream signature');
    }

    /** A single-argument tool must not grow a second argument. */
    public function testASingleArgumentToolIsUnaffected(): void
    {
        $tool = new DynamicStructuredTool(self::fields(), static fn (array $in): string => 'ok');

        self::assertSame('ok', $tool->invoke(['x' => 1]));
    }
}
