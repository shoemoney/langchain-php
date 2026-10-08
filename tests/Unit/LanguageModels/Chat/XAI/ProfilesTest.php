<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\XAI;

use LangChain\LanguageModels\Chat\XAI\ChatXAI;
use LangChain\LanguageModels\Chat\XAI\Profiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Profiles::class)]
#[CoversClass(ChatXAI::class)]
final class ProfilesTest extends TestCase
{
    public function testTheTableCarriesEveryUpstreamModel(): void
    {
        self::assertCount(19, Profiles::all());
        self::assertArrayHasKey('grok-3-fast-latest', Profiles::all());
        self::assertArrayHasKey('grok-2-vision', Profiles::all());
    }

    public function testKnownModelProfile(): void
    {
        $profile = Profiles::for('grok-3');

        self::assertSame(131072, $profile['maxInputTokens']);
        self::assertSame(8192, $profile['maxOutputTokens']);
        self::assertTrue($profile['toolCalling']);
        self::assertTrue($profile['structuredOutput']);
        self::assertFalse($profile['imageInputs']);
    }

    public function testTheVisionModelAcceptsImages(): void
    {
        self::assertTrue(Profiles::for('grok-2-vision')['imageInputs']);
        self::assertSame(8192, Profiles::for('grok-2-vision')['maxInputTokens']);
    }

    public function testUnknownModelHasAnEmptyProfile(): void
    {
        self::assertSame([], Profiles::for('grok-nonexistent'));
    }

    /** @return array<string, array{0: string}> */
    public static function models(): array
    {
        return array_map(static fn (string $m): array => [$m], array_combine(array_keys(Profiles::PROFILES), array_keys(Profiles::PROFILES)));
    }

    #[DataProvider('models')]
    public function testEveryProfileDeclaresTheTwelveUpstreamCapabilities(string $model): void
    {
        $profile = Profiles::for($model);

        self::assertCount(12, $profile, $model);
        foreach (['maxInputTokens', 'maxOutputTokens', 'toolCalling', 'imageInputs', 'reasoningOutput', 'structuredOutput'] as $key) {
            self::assertArrayHasKey($key, $profile, "$model lacks $key");
        }
    }

    public function testChatXaiExposesTheProfileOfItsModel(): void
    {
        $model = new ChatXAI(['apiKey' => 'k', 'model' => 'grok-3']);

        self::assertSame(Profiles::for('grok-3'), $model->profile());
        self::assertSame([], (new ChatXAI(['apiKey' => 'k', 'model' => 'grok-custom']))->profile());
    }
}
