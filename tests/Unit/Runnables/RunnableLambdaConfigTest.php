<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables;

use LangChain\Runnables\RunnableConfig;
use LangChain\Runnables\RunnableLambda;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A lambda that declares a config parameter must receive it.
 *
 * Upstream's signature is `(input, config?, ...rest)`. Without the config, a
 * pipeline built from lambdas silently dropped every call-time override:
 * `config->options` is where a `temperature` or `max_tokens` override lives, and
 * nothing else in the chain reads it back out.
 */
#[CoversClass(RunnableLambda::class)]
final class RunnableLambdaConfigTest extends TestCase
{
    public function testALambdaCanReadCallOptionsFromTheConfig(): void
    {
        $lambda = RunnableLambda::from(
            static fn (mixed $x, ?RunnableConfig $c = null): string => (string) $x . ':' . ($c->options['temperature'] ?? '-'),
        );

        self::assertSame(
            'a:0.25',
            $lambda->invoke('a', new RunnableConfig(options: ['temperature' => 0.25])),
        );
    }

    /**
     * A single-parameter lambda keeps receiving exactly one argument.
     *
     * Handing a config to a callable whose second parameter means something else
     * would be a silent behaviour change for a case that is currently
     * unambiguous, so the arity is checked before the config is passed.
     */
    public function testASingleParameterLambdaIsUnaffected(): void
    {
        $lambda = RunnableLambda::from(static fn (mixed $x): string => 'got:' . $x);

        self::assertSame('got:a', $lambda->invoke('a', new RunnableConfig(options: ['temperature' => 0.25])));
    }

    public function testAVariadicLambdaReceivesIt(): void
    {
        $lambda = RunnableLambda::from(
            static function (mixed $x, mixed ...$rest): string {
                return $x . ':' . count($rest) . ':' . (($rest[0] ?? null) instanceof RunnableConfig ? 'config' : 'no');
            },
        );

        self::assertSame('a:1:config', $lambda->invoke('a', new RunnableConfig()));
    }
}
