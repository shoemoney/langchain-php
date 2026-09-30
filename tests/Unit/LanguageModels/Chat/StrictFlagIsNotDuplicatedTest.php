<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat;

use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\Tools\DynamicStructuredTool;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `bindTools()` must not leave a `strict` key in `kwargs` for the OpenAI client.
 *
 * The decision is already stored where it is read: `$supportsStrictToolCalling`.
 * The copy loop nevertheless carried every `$kwargs` key except `tools` into
 * `$next->kwargs`, so a second copy of a flag that nothing reads was serialised
 * into every trace. Same class as the already-fixed dead `streamUsage` /
 * `user` / `seed` flags.
 *
 * `ChatAnthropic` is the opposite case and its copy IS load-bearing — it reads
 * `$this->kwargs['strict']` on a later bind so a chained bind inherits the
 * decision. So the test pins the asymmetry rather than deleting both.
 */
#[CoversClass(ChatOpenAI::class)]
final class StrictFlagIsNotDuplicatedTest extends TestCase
{
    public function testBindToolsLeavesNoStrictKeyInKwargs(): void
    {
        $model = (new ChatOpenAI(['apiKey' => 'k']))->bindTools(
            [new DynamicStructuredTool(
                ['name' => 't', 'description' => 'd', 'schema' => \LangChain\Tools\Schema::any()],
                static fn (): string => 'x',
            )],
            ['strict' => true],
        );

        self::assertArrayNotHasKey(
            'strict',
            $model->kwargs(),
            'strict is carried in $supportsStrictToolCalling; a copy in kwargs is read by nothing',
        );
    }

    public function testTheDecisionItselfStillSurvivesOnItsOwnProperty(): void
    {
        $model = (new ChatOpenAI(['apiKey' => 'k']))->bindTools(
            [new DynamicStructuredTool(
                ['name' => 't', 'description' => 'd', 'schema' => \LangChain\Tools\Schema::any()],
                static fn (): string => 'x',
            )],
            ['strict' => true],
        );

        // Dropping the kwargs copy must NOT drop the behaviour — that is the
        // difference between removing a duplicate and removing the flag.
        self::assertTrue($model->supportsStrictToolCalling);
    }

    public function testOtherKwargsAreStillCopied(): void
    {
        $model = (new ChatOpenAI(['apiKey' => 'k']))->bindTools(
            [new DynamicStructuredTool(
                ['name' => 't', 'description' => 'd', 'schema' => \LangChain\Tools\Schema::any()],
                static fn (): string => 'x',
            )],
            ['temperature' => 0.25],
        );

        self::assertArrayHasKey('temperature', $model->kwargs(), 'the exclusion must not swallow every kwarg');
        self::assertSame(0.25, $model->kwargs()['temperature']);
    }
}
