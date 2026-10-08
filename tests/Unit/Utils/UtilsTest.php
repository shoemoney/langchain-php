<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Utils;

use LangChain\Runnables\RunnableConfig;
use LangGraph\Utils\Utils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The generator and config helpers from `langgraph-core/src/utils.ts`.
 *
 * Upstream has no test file of its own for these (`tests/utils.test.ts` exercises
 * `getCurrentTaskInput`); the cases below pin the behaviour of the functions as
 * written upstream.
 */
#[CoversClass(Utils::class)]
final class UtilsTest extends TestCase
{
    private static function numbers(): \Generator
    {
        yield 1;
        yield 2;
        yield 3;
    }

    // ---- prefixGenerator ------------------------------------------------

    public function testPrefixGeneratorTagsEveryValueWithThePrefix(): void
    {
        self::assertSame(
            [['updates', 1], ['updates', 2], ['updates', 3]],
            iterator_to_array(Utils::prefixGenerator(self::numbers(), 'updates'), false),
        );
    }

    public function testPrefixGeneratorWithoutAPrefixPassesValuesThrough(): void
    {
        self::assertSame([1, 2, 3], iterator_to_array(Utils::prefixGenerator(self::numbers()), false));
    }

    public function testPrefixGeneratorIsLazy(): void
    {
        $pulled = 0;
        $source = (static function () use (&$pulled): \Generator {
            foreach ([1, 2, 3] as $n) {
                $pulled++;
                yield $n;
            }
        })();

        $prefixed = Utils::prefixGenerator($source, 'p');
        self::assertSame(0, $pulled);

        self::assertSame(['p', 1], $prefixed->current());
        self::assertSame(1, $pulled);
    }

    public function testPrefixGeneratorAcceptsAnyIterable(): void
    {
        self::assertSame([['k', 'a'], ['k', 'b']], iterator_to_array(Utils::prefixGenerator(['a', 'b'], 'k'), false));
    }

    // ---- gatherIterator -------------------------------------------------

    public function testGatherIteratorDrainsIntoAList(): void
    {
        self::assertSame([1, 2, 3], Utils::gatherIterator(self::numbers()));
    }

    public function testGatherIteratorDiscardsKeys(): void
    {
        $keyed = (static function (): \Generator {
            yield 'x' => 1;
            yield 'x' => 2;
        })();

        self::assertSame([1, 2], Utils::gatherIterator($keyed));
        self::assertSame([10, 20], Utils::gatherIterator(['a' => 10, 'b' => 20]));
    }

    public function testGatherIteratorOfAnEmptyIterableIsAnEmptyList(): void
    {
        self::assertSame([], Utils::gatherIterator([]));
        self::assertSame([], Utils::gatherIteratorSync(new \EmptyIterator()));
    }

    public function testGatherIteratorSyncMatchesGatherIterator(): void
    {
        self::assertSame(Utils::gatherIterator(self::numbers()), Utils::gatherIteratorSync(self::numbers()));
    }

    // ---- patchConfigurable ----------------------------------------------

    public function testPatchConfigurableOnNoConfigBuildsOne(): void
    {
        $patched = Utils::patchConfigurable(null, ['thread_id' => 't']);

        self::assertSame(['thread_id' => 't'], $patched->configurable);
    }

    public function testPatchConfigurableAddsToAnEmptyConfigurable(): void
    {
        $config = new RunnableConfig(tags: ['x']);

        $patched = Utils::patchConfigurable($config, ['a' => 1]);

        self::assertSame(['a' => 1], $patched->configurable);
        self::assertSame(['x'], $patched->tags, 'every other field is carried over');
    }

    public function testPatchConfigurableOverlaysOntoTheExistingKeys(): void
    {
        $config = new RunnableConfig(configurable: ['a' => 1, 'b' => 2]);

        $patched = Utils::patchConfigurable($config, ['b' => 3, 'c' => 4]);

        self::assertSame(['a' => 1, 'b' => 3, 'c' => 4], $patched->configurable);
    }

    public function testPatchConfigurableNeverMutatesTheInput(): void
    {
        $config = new RunnableConfig(configurable: ['a' => 1]);

        $patched = Utils::patchConfigurable($config, ['a' => 2]);

        self::assertNotSame($config, $patched);
        self::assertSame(['a' => 1], $config->configurable);
    }

    // ---- isGeneratorFunction --------------------------------------------

    public static function generatorMethod(): \Generator
    {
        yield 1;
    }

    public static function plainMethod(): int
    {
        return 1;
    }

    public function testIsGeneratorFunctionRecognisesAClosureThatYields(): void
    {
        self::assertTrue(Utils::isGeneratorFunction(static function (): \Generator {
            yield 1;
        }));
        self::assertFalse(Utils::isGeneratorFunction(static fn (): int => 1));
    }

    public function testIsGeneratorFunctionRecognisesMethods(): void
    {
        self::assertTrue(Utils::isGeneratorFunction([self::class, 'generatorMethod']));
        self::assertTrue(Utils::isGeneratorFunction(self::class . '::generatorMethod'));
        self::assertFalse(Utils::isGeneratorFunction([self::class, 'plainMethod']));
    }

    public function testIsGeneratorFunctionIsFalseForANonCallable(): void
    {
        self::assertFalse(Utils::isGeneratorFunction(null));
        self::assertFalse(Utils::isGeneratorFunction('no_such_function_here'));
        self::assertFalse(Utils::isGeneratorFunction(42));
    }

    public function testIsGeneratorFunctionIsFalseForAFunctionReturningAGeneratorWithoutYielding(): void
    {
        // A generator FUNCTION is one whose body yields; returning an existing generator is not that.
        self::assertFalse(Utils::isGeneratorFunction(static fn (): \Generator => self::numbers()));
    }
}
