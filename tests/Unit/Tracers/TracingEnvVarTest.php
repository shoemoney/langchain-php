<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Tracers;

use LangChain\Tracers\CallbackManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An environment variable means what it says.
 *
 * `getenv()` returns `false` only when a variable is UNSET, so
 * `getenv('LANGCHAIN_TRACING') !== false` means "set to ANYTHING". Measured
 * before the fix: `LANGCHAIN_TRACING=false`, `=0`, `=no` and `=""` all enabled
 * tracing — the exact opposite of what the user asked for — and attached a
 * LangChainTracer to every run, collecting runs nobody requested.
 *
 * The line directly above got it right (`getenv('LANGCHAIN_VERBOSE') === 'true'`),
 * which is what makes this worth a guard: two adjacent lines reading the same
 * kind of variable and disagreeing about what "set" means is how the wrong one
 * survives.
 *
 * Upstream compares the STRING — `langsmith_interop.test.ts` sets
 * `LANGCHAIN_TRACING_V2 = "true"` — so `=== 'true'` is the faithful form.
 */
#[CoversClass(CallbackManager::class)]
final class TracingEnvVarTest extends TestCase
{
    /**
     * Values that must NOT turn tracing on.
     *
     * @return array<string, array{0: string}>
     */
    public static function falsyValues(): array
    {
        return [
            'the word false' => ['false'],
            'zero' => ['0'],
            'no' => ['no'],
            'empty string' => [''],
            'mixed case false' => ['FALSE'],
        ];
    }

    #[DataProvider('falsyValues')]
    public function testAFalsyValueDoesNotEnableTracing(string $value): void
    {
        putenv('LANGCHAIN_TRACING=' . $value);
        putenv('LANGCHAIN_TRACING_V2=' . $value);

        try {
            self::assertNull(
                CallbackManager::configure(),
                sprintf('LANGCHAIN_TRACING=%s must not enable tracing', var_export($value, true)),
            );
        } finally {
            putenv('LANGCHAIN_TRACING');
            putenv('LANGCHAIN_TRACING_V2');
        }
    }

    public function testTheStringTrueStillEnablesTracing(): void
    {
        putenv('LANGCHAIN_TRACING_V2=true');

        try {
            self::assertNotNull(
                CallbackManager::configure(),
                'the one value that must enable tracing',
            );
        } finally {
            putenv('LANGCHAIN_TRACING_V2');
        }
    }

    public function testTheExplicitOptionStillWins(): void
    {
        self::assertNotNull(
            CallbackManager::configure(null, null, null, null, null, null, ['tracing' => true]),
            'the option is independent of the environment',
        );
    }
}
