<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Anthropic;

use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\Anthropic\Profiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Profiles::class)]
#[CoversClass(ChatAnthropic::class)]
final class AnthropicProfilesTest extends TestCase
{
    public function testTheTableCarriesEveryUpstreamModel(): void
    {
        self::assertCount(16, Profiles::all());
        self::assertArrayHasKey('claude-sonnet-4-6', Profiles::all());
        self::assertArrayHasKey('claude-opus-4-1-20250805', Profiles::all());
    }

    public function testKnownModelProfile(): void
    {
        $profile = Profiles::for('claude-haiku-4-5');

        self::assertSame(200000, $profile['maxInputTokens']);
        self::assertSame(64000, $profile['maxOutputTokens']);
        self::assertTrue($profile['toolCalling']);
        self::assertFalse($profile['structuredOutput']);
    }

    public function testUnknownModelHasAnEmptyProfile(): void
    {
        self::assertSame([], Profiles::for('claude-nonexistent'));
    }

    public function testLargeContextModelsReportAMillionInputTokens(): void
    {
        foreach (['claude-fable-5', 'claude-fable-5-1', 'claude-sonnet-4-6', 'claude-opus-4-6'] as $model) {
            self::assertSame(1000000, Profiles::for($model)['maxInputTokens'], $model);
        }
    }

    /** @return array<string, array{0: string}> */
    public static function models(): array
    {
        return array_map(static fn (string $m): array => [$m], array_combine(array_keys(Profiles::PROFILES), array_keys(Profiles::PROFILES)));
    }

    #[DataProvider('models')]
    public function testEveryProfileDeclaresTheCoreCapabilities(string $model): void
    {
        $profile = Profiles::for($model);

        foreach (['maxInputTokens', 'maxOutputTokens', 'toolCalling', 'imageInputs', 'reasoningOutput'] as $key) {
            self::assertArrayHasKey($key, $profile, "$model lacks $key");
        }
    }

    public function testChatAnthropicExposesTheProfileOfItsModel(): void
    {
        $model = new ChatAnthropic(['apiKey' => 'k', 'model' => 'claude-opus-4-6']);

        self::assertSame(Profiles::for('claude-opus-4-6'), $model->profile());
        self::assertSame([], (new ChatAnthropic(['apiKey' => 'k', 'model' => 'claude-custom']))->profile());
    }
}
