<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Prompts;

use LangChain\Prompts\PromptTemplate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A serialised template must come back carrying what it was given.
 *
 * `additionalContentFields` is the provider-specific extras a caller attaches to
 * a prompt — `cache_control`, image detail, a vendor marker — and it was dropped
 * on serialisation. Measured before the fix: a template built with
 * `['provider' => 'acme']` came back from `deserialize()` with `null`.
 *
 * The round-trip SUCCEEDED, which is what makes this worth a guard rather than a
 * shrug. Nothing errors; the rebuilt template simply no longer carries its
 * extras, and the loss surfaces much later as a provider that quietly stops
 * caching.
 *
 * Partial variable VALUES are deliberately absent from the wire form, and that is
 * upstream's choice rather than an omission here — `prompts/base.ts` returns
 * `{ partialVariables: undefined }` from `lc_attributes` with the comment
 * "python doesn't support this yet". Pinned below so the omission stays
 * deliberate and is not mistaken for this same bug again.
 */
#[CoversClass(PromptTemplate::class)]
final class SerializationRoundTripTest extends TestCase
{
    public function testAdditionalContentFieldsSurviveTheRoundTrip(): void
    {
        $extras = ['provider' => 'acme', 'cache_control' => ['type' => 'ephemeral']];
        $template = new PromptTemplate('Hello {name}', ['name'], [], null, null, null, $extras);

        $restored = PromptTemplate::deserialize($template->serialize());

        self::assertSame($extras, $restored->additionalContentFields);
    }

    public function testThePlainCaseIsUnchanged(): void
    {
        $template = new PromptTemplate('Hello {name}', ['name']);

        $restored = PromptTemplate::deserialize($template->serialize());

        self::assertNull($restored->additionalContentFields, 'unset must stay unset, not become []');
        self::assertSame('Hello World', $restored->format(['name' => 'World']));
    }

    public function testAnEmptyExtrasMapIsNotWrittenAtAll(): void
    {
        // Writing `additional_content_fields: []` on every template would change
        // the wire shape for callers who never set it, so it is emitted only when
        // there is something to emit.
        $serialized = (new PromptTemplate('Hi', []))->serialize();

        self::assertArrayNotHasKey('additional_content_fields', $serialized);
        self::assertSame(
            ['_type', 'input_variables', 'template', 'template_format'],
            array_keys($serialized),
        );
    }

    /**
     * Partial values are NOT carried — upstream omits them deliberately.
     * Asserted so this stays a decision rather than becoming indistinguishable
     * from the bug above.
     */
    public function testPartialValuesAreNotCarriedDeliberately(): void
    {
        $template = (new PromptTemplate('Hello {name}', ['name']))->partial(['name' => 'Ada']);

        self::assertArrayNotHasKey(
            'partial_variables',
            $template->serialize(),
            'upstream sets partialVariables: undefined in lc_attributes on purpose',
        );
    }
}
