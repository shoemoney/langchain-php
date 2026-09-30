<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Runnables;

use LangChain\Runnables\RunnableAssign;
use LangChain\Runnables\RunnableLambda;
use LangChain\Runnables\RunnableParallel;
use LangChain\Runnables\RunnablePassthrough;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(RunnableAssign::class)]
#[CoversClass(RunnablePassthrough::class)]
final class RunnablePassthroughTest extends TestCase
{
    /**
     * Port of "RunnablePassthrough can call .assign and pass prev result through".
     *
     * The load-bearing assertion is that the *other* input keys survive. An
     * `assign` that returned only its own mapping would drop them, and a chain
     * ending in one would silently lose the record it was built from.
     */
    public function testAssignPassesPreviousResultThrough(): void
    {
        $chain = RunnablePassthrough::assign([
            'outputValue' => static fn (mixed $i): mixed => $i['outputValue'],
        ]);

        $result = $chain->invoke(['input' => 'hello', 'outputValue' => 'testing']);

        self::assertSame(['input' => 'hello', 'outputValue' => 'testing'], $result);
    }

    /**
     * The `assign` in the middle of a real chain, as upstream builds it.
     *
     * `FakeChatModel` echoes its input, so a lambda stands in for the prompt
     * template upstream uses to inject the JSON — the model path itself is
     * still exercised end to end.
     */
    public function testAssignAsFinalStepOfChain(): void
    {
        $llm = new \LangChain\Utils\Testing\FakeChatModel();

        $chain = RunnablePassthrough::assign([
            'input' => static fn (mixed $i): mixed => $i['otherProp'],
        ])->pipe(new RunnableLambda(static fn (mixed $i): string => $i['input']))
            ->pipe($llm)
            ->pipe(new \LangChain\OutputParsers\JsonOutputParser())
            ->pipe(RunnablePassthrough::assign([
                'outputValue' => static fn (mixed $i): mixed => $i['outputValue'],
            ]));

        $result = $chain->invoke(['otherProp' => '{"outputValue": "testing"}']);

        self::assertSame(['outputValue' => 'testing'], $result);
    }

    /**
     * A mapping key overrides the input value of the same name.
     *
     * This is the `{...input, ...mapperResult}` ordering. Getting it backwards
     * would make `assign` unable to *correct* a field, which is the main reason
     * to reach for it over a plain map.
     */
    public function testMappingOverridesInputOfSameName(): void
    {
        $result = RunnablePassthrough::assign([
            'value' => static fn (): string => 'from mapping',
        ])->invoke(['value' => 'from input']);

        self::assertSame(['value' => 'from mapping'], $result);
    }

    /**
     * The distinction from {@see RunnableParallel}: a map *replaces*, an
     * assign *layers*. If these ever converged, `assembleStructuredOutputPipeline`
     * would lose the raw message it is supposed to preserve.
     */
    public function testAssignLayersWhereParallelReplaces(): void
    {
        $input = ['keep' => 'me', 'shared' => 'input'];

        $assigned = RunnablePassthrough::assign([
            'shared' => static fn (): string => 'mapping',
        ])->invoke($input);

        $paralleled = RunnableParallel::from([
            'shared' => static fn (): string => 'mapping',
        ])->invoke($input);

        self::assertSame(['keep' => 'me', 'shared' => 'mapping'], $assigned);
        self::assertSame(['shared' => 'mapping'], $paralleled);
    }

    /**
     * Every branch sees the *whole* input, which is what makes a map of
     * `{question: passthrough, context: retriever}` expressible.
     */
    public function testEveryBranchReceivesWholeInput(): void
    {
        $result = RunnablePassthrough::assign([
            'question' => static fn (mixed $i): mixed => $i['question'],
            'length' => static fn (mixed $i): int => strlen($i['question']),
        ])->invoke(['question' => 'why?']);

        self::assertSame(['question' => 'why?', 'length' => 4], $result);
    }

    /**
     * Port of "can invoke a function without modifying passthrough value".
     *
     * The function is an observer. Its return value is discarded, so a caller
     * that expects a transformed value gets the input back unchanged.
     */
    public function testFuncIsAnObserverNotATransform(): void
    {
        $called = false;
        $passthrough = new RunnablePassthrough([
            'func' => static function (mixed $input) use (&$called): void {
                $called = true;
            },
        ]);

        self::assertSame('unchanged', $passthrough->invoke('unchanged'));
        self::assertTrue($called, 'the observer func must run');
    }

    public function testPassthroughIsIdentity(): void
    {
        self::assertSame('x', (new RunnablePassthrough())->invoke('x'));
    }

    /**
     * Runnable coercion: a bare closure is a valid branch.
     */
    public function testAssignAcceptsBareClosures(): void
    {
        $result = RunnablePassthrough::assign([
            'doubled' => new RunnableLambda(static fn (mixed $i): int => $i['n'] * 2),
        ])->invoke(['n' => 21]);

        self::assertSame(['n' => 21, 'doubled' => 42], $result);
    }

    /**
     * A non-record input has no keys to layer onto, so the mapping stands alone.
     *
     * This is what makes `{raw: llm}` usable with a plain string — the shape
     * every `includeRaw` structured-output pipeline starts from.
     */
    public function testNonArrayInputYieldsTheMappingAlone(): void
    {
        $result = RunnablePassthrough::assign([
            'raw' => static fn (mixed $i): mixed => $i,
        ])->invoke('a string');

        self::assertSame(['raw' => 'a string'], $result);
    }

    public function testGetName(): void
    {
        self::assertSame('RunnablePassthrough', (new RunnablePassthrough())->getName());
        self::assertSame(
            'RunnableAssign',
            RunnablePassthrough::assign(['a' => static fn (): int => 1])->getName(),
        );
    }
}
