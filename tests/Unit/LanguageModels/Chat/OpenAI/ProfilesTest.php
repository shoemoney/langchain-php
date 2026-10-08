<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\OpenAI;

use LangChain\LanguageModels\Chat\OpenAI\BaseChatOpenAI;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAICompletions;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAIResponses;
use LangChain\LanguageModels\Chat\OpenAI\Profiles;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `chat_models/profiles.ts` has no upstream tests; these pin the generated table
 * and the `profile` getter on `BaseChatOpenAI`.
 */
#[CoversClass(Profiles::class)]
#[CoversClass(BaseChatOpenAI::class)]
final class ProfilesTest extends TestCase
{
    private const KEYS = [
        'maxInputTokens', 'imageInputs', 'audioInputs', 'pdfInputs', 'videoInputs', 'maxOutputTokens',
        'reasoningOutput', 'imageOutputs', 'audioOutputs', 'videoOutputs', 'toolCalling', 'structuredOutput',
        'imageUrlInputs', 'pdfToolMessage', 'imageToolMessage', 'toolChoice',
    ];

    public function testTheTableCarriesEveryUpstreamModel(): void
    {
        self::assertCount(48, Profiles::all());
        foreach (['gpt-5-nano', 'gpt-4.1-nano', 'gpt-4o-2024-05-13', 'gpt-5-pro', 'gpt-4o-2024-11-20', 'o1'] as $model) {
            self::assertArrayHasKey($model, Profiles::all());
        }
    }

    public function testKnownModelProfileKeepsUpstreamValues(): void
    {
        self::assertSame([
            'maxInputTokens' => 128000, 'imageInputs' => true, 'audioInputs' => false, 'pdfInputs' => true,
            'videoInputs' => false, 'maxOutputTokens' => 4096, 'reasoningOutput' => false, 'imageOutputs' => false,
            'audioOutputs' => false, 'videoOutputs' => false, 'toolCalling' => true, 'structuredOutput' => true,
            'imageUrlInputs' => true, 'pdfToolMessage' => true, 'imageToolMessage' => true, 'toolChoice' => true,
        ], Profiles::for('gpt-4o-2024-05-13'));
    }

    public function testEntriesOnlyCarryWhatUpstreamListed(): void
    {
        $profile = Profiles::for('gpt-5-pro');

        self::assertSame(400000, $profile['maxInputTokens']);
        self::assertSame(272000, $profile['maxOutputTokens']);
        self::assertCount(16, $profile);
    }

    public function testUnknownModelHasAnEmptyProfile(): void
    {
        self::assertSame([], Profiles::for('not-a-model'));
    }

    /** @return array<string, array{0: string}> */
    public static function models(): array
    {
        return array_map(static fn (string $m): array => [$m], array_combine(array_keys(Profiles::PROFILES), array_keys(Profiles::PROFILES)));
    }

    #[DataProvider('models')]
    public function testEveryEntryUsesOnlyKnownKeysWithTypedValues(string $model): void
    {
        foreach (Profiles::for($model) as $key => $value) {
            self::assertContains($key, self::KEYS, "$model has unexpected key $key");
            self::assertTrue(is_int($value) || is_bool($value), "$model.$key must be int or bool");
        }
    }

    public function testEveryChatClientReadsTheProfileOfItsModel(): void
    {
        foreach ([ChatOpenAI::class, ChatOpenAICompletions::class, ChatOpenAIResponses::class] as $class) {
            $model = new $class(['model' => 'gpt-4o-2024-11-20', 'apiKey' => 'k']);
            self::assertSame(16384, $model->profile()['maxOutputTokens'], $class);
            self::assertSame([], (new $class(['model' => 'mystery', 'apiKey' => 'k']))->profile(), $class);
        }
    }
}
