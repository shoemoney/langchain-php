<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels;

use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `canonicalise()` must keep working on a SUBCLASS that does not redeclare the alias table.
 *
 * Iteration 469 moved `canonicalise()` and `pickOption()` out of `ChatAnthropic` and `ChatOpenAI` into
 * `NormalisesProviderOptions`, and wrote `static::KEY_ALIASES` first — the natural-looking choice, since the
 * two alias TABLES genuinely differ. It broke 81 tests with
 * `Undefined constant …\NoSleepChatAnthropic::KEY_ALIASES`: a test subclass inherits the method without
 * redeclaring the private constant, and `static::` resolves to the RUNTIME class.
 *
 * **The suite caught that only by accident**, because `NoSleepChatAnthropic` happened to exist. Delete or
 * rename that fixture and the same mistake becomes silent — `static::` would resolve correctly for the two
 * concrete providers and nothing would fail. So the property is pinned here directly, on subclasses defined
 * for the purpose and owning no constant of their own.
 *
 * A trait's `self::` resolves to the COMPOSING class, which is what the original per-class code did;
 * `static::` resolves to the runtime class and is wrong for any subclass that inherits the method without the
 * table. This test is the difference between a guard and a coincidence.
 */
#[CoversNothing]
final class ProviderOptionCanonicalisationSubclassTest extends TestCase
{
    /** @return iterable<string, array{class-string}> */
    public static function providers(): iterable
    {
        yield 'ChatAnthropic' => [BareSubclassOfChatAnthropic::class];
        yield 'ChatOpenAI' => [BareSubclassOfChatOpenAI::class];
    }

    /** @param class-string $provider */
    #[DataProvider('providers')]
    public function testCanonicaliseWorksOnASubclassThatInheritsTheAliasTable(string $provider): void
    {
        // Named, not anonymous: PHP forbids `new class extends <expression>`, so the parent has to be
        // fixed at parse time. Neither provider is `final`, and these declare NO constant and override
        // nothing — the minimal shape that broke 469.
        $bag = $provider::canonicalise(['max_tokens' => 512, 'tool_choice' => 'auto']);

        self::assertSame(512, $bag['maxTokens'] ?? null, 'the wire key must alias to its camelCase form');
        self::assertSame('auto', $bag['toolChoice'] ?? null, 'a key present in BOTH tables must alias too');
    }

    /** @param class-string $provider */
    #[DataProvider('providers')]
    public function testTheSubclassDoesNotSilentlyLoseTheTable(string $provider): void
    {
        $bag = $provider::canonicalise(['top_p' => 0.5]);

        self::assertArrayHasKey('topP', $bag,
            'a subclass that inherits canonicalise() must still see the composing class\'s alias table — '
                . 'this is what `static::` breaks and `self::` preserves');
    }
}

/** Inherits `canonicalise()` without redeclaring `KEY_ALIASES` — the shape 469's `static::` broke. */
final class BareSubclassOfChatAnthropic extends ChatAnthropic
{
}

/** As above, for the OpenAI provider and its larger alias table. */
final class BareSubclassOfChatOpenAI extends ChatOpenAI
{
}
