<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Guards the `autoload-dev` psr-4 prefix by USING it.
 *
 * `composer.json` mapped `"LangChain\Tests\" => "tests/Unit/"`. That prefix is 16 characters, so
 * resolving `LangChain\Tests\Unit\Channels\ChannelTest` stripped 16 and appended
 * `Unit/Channels/ChannelTest.php` to `tests/Unit/`, yielding `tests/Unit/Unit/Channels/ChannelTest.php`
 * — a doubled segment that does not exist. The test tree had been addressed through a wrong path since
 * it was created.
 *
 * Nothing caught it. PHPUnit includes test files by path, so the suite stayed green; the only symptom
 * was `composer dump-autoload -o` printing 132 "does not comply with psr-4 ... Skipping" lines that
 * this loop ran, and ignored, for most of its history.
 *
 * So this test asserts RESOLUTION rather than inspecting the config: a doubled or truncated prefix
 * cannot resolve a class no matter what the JSON says.
 */
#[CoversNothing]
final class AutoloadDevPrefixTest extends TestCase
{
    /**
     * A sample of test classes across the subdirectories, including the non-TestCase fixture that
     * previously needed an explicit `require_once` because it could not be autoloaded at all.
     *
     * Named `devAutoloadableClasses`, NOT `testClasses`: a data provider whose name begins with
     * `test` is itself collected as a test method, which produced a risky "did not perform any
     * assertions" and a runner warning — and this suite runs `failOnRisky`/`failOnWarning`.
     *
     * @return iterable<string, array{string}>
     */
    public static function devAutoloadableClasses(): iterable
    {
        yield 'ChannelTest' => ['LangChain\Tests\Unit\Channels\ChannelTest'];
        yield 'InternalsTest' => ['LangChain\Tests\Unit\Channels\InternalsTest'];
        yield 'StateGraphTest' => ['LangChain\Tests\Unit\State\StateGraphTest'];
        yield 'PregelLoopTest' => ['LangChain\Tests\Unit\Pregel\PregelLoopTest'];
        yield 'MemorySaverTest' => ['LangChain\Tests\Unit\Checkpoint\MemorySaverTest'];
        yield 'CheckpointerFixture (not a TestCase)' => ['LangChain\Tests\Unit\Checkpoint\CheckpointerFixture'];
    }

    /**
     * @param class-string $class
     */
    #[DataProvider('devAutoloadableClasses')]
    public function testTheTestTreeResolvesThroughTheDevAutoloader(string $class): void
    {
        self::assertTrue(
            class_exists($class),
            $class . ' is not autoloadable. Either autoload-dev\'s psr-4 prefix does not match the '
            . 'namespace-to-directory mapping, or this file has no class of that name. PHPUnit running '
            . 'green does NOT cover this: it includes test files by path.'
        );
    }

    /** The fixture specifically: 325 deleted a `require_once` that existed only because this failed. */
    public function testASecondTestClassCanReferenceTheFixtureWithoutAnExplicitInclude(): void
    {
        self::assertTrue(
            class_exists('LangChain\Tests\Unit\Checkpoint\CheckpointerFixture'),
            'CheckpointerFixture must resolve through the autoloader; a require_once in a consumer '
            . 'is a workaround for a broken prefix and will come back.'
        );
    }
}
