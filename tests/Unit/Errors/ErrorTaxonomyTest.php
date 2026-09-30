<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Errors;

use LangGraph\Errors as E;
use LangGraph\Pregel\Command;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The `LangGraph\Errors` taxonomy, pinned.
 *
 * Thirteen classes, and before this file NOTHING in the suite referenced the
 * namespace at all — `grep -rl 'LangGraph\Errors' tests/` returned zero files.
 * Every caller catches these by type, so a re-parenting is invisible until a
 * `catch` stops matching.
 *
 * The hierarchy mirrors upstream `langgraph-core/src/errors.ts`:
 *
 *     GraphBubbleUp            the control-flow family root
 *       GraphDrained           extends GraphBubbleUp      :65
 *       GraphInterrupt         extends GraphBubbleUp      :85
 *         NodeInterrupt         extends GraphInterrupt    :100
 *       ParentCommand          extends GraphBubbleUp      :164
 *
 * `NodeError` extends NOTHING, and that is deliberate: upstream declares it a
 * plain class (errors.ts:134) because it names a node rather than describing a
 * failure. `Guard` is a type guard, likewise not an exception. So a
 * `catch (BaseLangGraphError)` misses both — upstream's shape, pinned here so
 * it stays a decision rather than becoming an accident.
 *
 * The constructors are NOT uniform, which is why each instance below is built
 * by hand: `GraphInterrupt` takes `?array $interrupts`, `ParentCommand` takes a
 * `Command`, `GraphValueError` takes `($message, array $fields)`, and
 * `GraphDrained`/`NodeInterrupt` compose their own message. A uniform
 * `new $class('boom')` was tried first and produced four TypeErrors.
 */
#[CoversClass(E\BaseLangGraphError::class)]
final class ErrorTaxonomyTest extends TestCase
{
    /**
     * One ready instance per class in the family.
     *
     * @return array<string, array{0: class-string, 1: \Throwable}>
     */
    public static function familyInstances(): array
    {
        return [
            'BaseLangGraphError' => [E\BaseLangGraphError::class, new E\BaseLangGraphError('boom')],
            'EmptyChannelError' => [E\EmptyChannelError::class, new E\EmptyChannelError('boom')],
            'EmptyInputError' => [E\EmptyInputError::class, new E\EmptyInputError('boom')],
            'GraphBubbleUp' => [E\GraphBubbleUp::class, new E\GraphBubbleUp('boom')],
            'GraphRecursionError' => [E\GraphRecursionError::class, new E\GraphRecursionError('boom')],
            'GraphValueError' => [E\GraphValueError::class, new E\GraphValueError('boom', [])],
            'InvalidUpdateError' => [E\InvalidUpdateError::class, new E\InvalidUpdateError('boom')],
            'GraphDrained' => [E\GraphDrained::class, new E\GraphDrained('shutdown')],
            'GraphInterrupt' => [E\GraphInterrupt::class, new E\GraphInterrupt(['node-1'])],
            'NodeInterrupt' => [E\NodeInterrupt::class, new E\NodeInterrupt('node-7')],
            'ParentCommand' => [E\ParentCommand::class, new E\ParentCommand(new Command())],
        ];
    }

    /**
     * Everything a caller might catch by the root type IS caught by it —
     * including the control-flow subclasses, because an interrupt IS an
     * exception and `catch (BaseLangGraphError)` must see it.
     */
    #[DataProvider('familyInstances')]
    public function testTheRootTypeCatchesTheWholeFamily(string $class, \Throwable $error): void
    {
        self::assertInstanceOf(E\BaseLangGraphError::class, $error, $class);
        self::assertInstanceOf(\Throwable::class, $error, $class);
        self::assertNotSame('', $error->getMessage(), $class . ' must carry a message');
    }

    /**
     * The control-flow family keeps its own shape, because callers branch on
     * it: `GraphDrained` means finished, `ParentCommand` means resume, and both
     * are distinguished from a plain `GraphInterrupt` by TYPE.
     */
    public function testTheControlFlowFamilyIsDistinguishableByType(): void
    {
        self::assertInstanceOf(
            E\GraphBubbleUp::class,
            new E\GraphDrained('shutdown'),
            'errors.ts:65 — GraphDrained extends GraphBubbleUp',
        );
        self::assertInstanceOf(
            E\GraphInterrupt::class,
            new E\GraphInterrupt(['node-7']),
            'errors.ts:100 — NodeInterrupt extends GraphInterrupt',
        );
        self::assertInstanceOf(
            E\GraphBubbleUp::class,
            new E\ParentCommand(new Command()),
            'errors.ts:164 — ParentCommand extends GraphBubbleUp',
        );

        // And the three are NOT interchangeable, which is what makes catching
        // by type safe in the first place.
        self::assertNotInstanceOf(E\GraphInterrupt::class, new E\GraphDrained('x'));
        self::assertNotInstanceOf(E\GraphDrained::class, new E\GraphInterrupt());
        self::assertNotInstanceOf(E\NodeInterrupt::class, new E\GraphInterrupt());
    }

    /**
     * `NodeError` and `Guard` sit outside the base ON PURPOSE, matching upstream.
     *
     * Pinned because it reads like an oversight. Re-parenting them would make a
     * `catch` clause match a node failure as though it were a graph failure.
     */
    public function testTheTwoNonExceptionsAreDeliberatelyOutsideTheBase(): void
    {
        self::assertNotInstanceOf(
            E\BaseLangGraphError::class,
            new E\NodeError('node-7', new \RuntimeException('inner')),
            'upstream declares NodeError a plain class (errors.ts:134), so it is not an exception',
        );
        self::assertNotInstanceOf(
            E\BaseLangGraphError::class,
            E\Guard::class,
            'Guard is a type guard, not an exception',
        );
    }

    /**
     * The cause reaches a `NodeError` as its `error` property, which is upstream's
     * mechanism (errors.ts:134).
     *
     * Note what is NOT asserted: that a cause can be chained onto the base
     * classes. It cannot — none of the thirteen constructors accept a
     * `$previous`, and upstream's do not either. Asserting it would have been
     * asserting an API that does not exist, which is how a test ends up pinning
     * a wish.
     */
    public function testANodeErrorCarriesTheFailureThatCausedIt(): void
    {
        $inner = new \RuntimeException('the real failure');
        $nodeError = new E\NodeError('node-7', $inner);

        self::assertSame('node-7', $nodeError->node);
        self::assertSame($inner, $nodeError->error);
    }

    public function testTheRootIsAnException(): void
    {
        self::assertTrue(
            (new \ReflectionClass(E\BaseLangGraphError::class))->isSubclassOf(\Exception::class),
        );
    }
}
