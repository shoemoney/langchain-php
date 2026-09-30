<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat;

use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * One spelling table, three layers.
 *
 * These existed as three hand-written lists that drifted: the constructor read
 * only camelCase while `invocationParams()` accepted the wire spelling, so
 * `new ChatOpenAI(['max_tokens' => 99])` was accepted, silently ignored, and
 * reported nowhere.
 */
#[CoversClass(ChatOpenAI::class)]
#[CoversClass(ChatAnthropic::class)]
final class CallOptionSpellingTest extends TestCase
{
    /** @return iterable<string, array{string, string, int|float}> */
    public static function optionSpellings(): iterable
    {
        yield 'maxTokens' => ['maxTokens', 'max_tokens', 99];
        yield 'max_tokens' => ['max_tokens', 'max_tokens', 99];
        yield 'topP' => ['topP', 'top_p', 0.5];
        yield 'top_p' => ['top_p', 'top_p', 0.5];
    }

    #[DataProvider('optionSpellings')]
    public function testOpenAiAcceptsEverySpellingInTheConstructor(string $key, string $wire, int|float $value): void
    {
        $model = new ChatOpenAI(['apiKey' => 'k', 'model' => 'gpt-4o', $key => $value]);

        self::assertSame($value, $model->invocationParams()[$wire] ?? null, "constructor key $key");
        self::assertSame($value, $model->kwargs()[$key === 'max_tokens' ? 'maxTokens' : $key] ?? $value);
    }

    #[DataProvider('optionSpellings')]
    public function testOpenAiAcceptsEverySpellingBound(string $key, string $wire, int|float $value): void
    {
        $bound = (new ChatOpenAI(['apiKey' => 'k']))->bindTools([], [$key => $value]);

        self::assertSame($value, $bound->invocationParams()[$wire] ?? null, "bound key $key");
    }

    #[DataProvider('optionSpellings')]
    public function testOpenAiAcceptsEverySpellingPerCall(string $key, string $wire, int|float $value): void
    {
        $params = (new ChatOpenAI(['apiKey' => 'k']))->invocationParams([$key => $value]);

        self::assertSame($value, $params[$wire] ?? null, "per-call key $key");
    }

    #[DataProvider('optionSpellings')]
    public function testAnthropicAcceptsEverySpellingInTheConstructor(string $key, string $wire, int|float $value): void
    {
        $model = new ChatAnthropic(['apiKey' => 'k', $key => $value]);

        self::assertSame($value, $model->invocationParams()[$wire] ?? null, "constructor key $key");
    }

    /**
     * The three layers must agree, or a value set one way is honoured while the
     * same value set another way is not.
     */
    #[DataProvider('optionSpellings')]
    public function testAllThreeLayersResolveToTheSameValue(string $key, string $wire, int|float $value): void
    {
        $fromCtor = (new ChatOpenAI(['apiKey' => 'k', $key => $value]))->invocationParams()[$wire] ?? null;
        $fromBound = (new ChatOpenAI(['apiKey' => 'k']))->bindTools([], [$key => $value])->invocationParams()[$wire] ?? null;
        $fromCall = (new ChatOpenAI(['apiKey' => 'k']))->invocationParams([$key => $value])[$wire] ?? null;

        self::assertSame($fromCtor, $fromBound, "bound disagrees with constructor for $key");
        self::assertSame($fromCtor, $fromCall, "per-call disagrees with constructor for $key");
    }

    /** @return iterable<string, array{string, string, int|float}> */
    public static function anthropicOnlySpellings(): iterable
    {
        yield 'topK' => ['topK', 'top_k', 0.25];
        yield 'top_k' => ['top_k', 'top_k', 0.25];
    }

    /**
     * `top_k` is an Anthropic parameter and not a Chat Completions one.
     *
     * Upstream's ChatOpenAI sends no such key, so accepting it and dropping it
     * silently would tell a caller their sampling was configured when it was
     * not. It is not in ChatOpenAI's alias table at all.
     */
    #[DataProvider('anthropicOnlySpellings')]
    public function testAnthropicAcceptsTopK(string $key, string $wire, int|float $value): void
    {
        self::assertSame($value, (new ChatAnthropic(['apiKey' => 'k', $key => $value]))->invocationParams()[$wire] ?? null);
        self::assertSame($value, (new ChatAnthropic(['apiKey' => 'k']))->bindTools([], [$key => $value])->invocationParams()[$wire] ?? null);
    }

    #[DataProvider('anthropicOnlySpellings')]
    public function testOpenAiRefusesTopKUnderEitherSpelling(string $key): void
    {
        // Refused, not ignored: a setting that is accepted and then dropped
        // looks applied and is not, with nothing anywhere to report it. Both
        // spellings must be refused — mapping the wire form to the canonical
        // one is what lets the same check catch it.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no topK parameter');

        new ChatOpenAI(['apiKey' => 'k', $key => 0.25]);
    }

    public function testAValidOptionIsStillAccepted(): void
    {
        $model = new ChatOpenAI(['apiKey' => 'k', 'topP' => 0.5]);

        self::assertSame(0.5, $model->invocationParams()['top_p'] ?? null);
    }

    /**
     * The option really reaches the socket, not just the parameter array.
     */
    public function testAWireSpelledConstructorOptionReachesTheRequest(): void
    {
        $http = new \LangChain\Utils\Testing\FakeHttpClient([
            \LangChain\Utils\Testing\FakeHttpClient::json(200, [
                'id' => 'x', 'model' => 'm',
                'choices' => [['index' => 0, 'message' => ['role' => 'assistant', 'content' => 'ok']]],
            ]),
        ]);

        $model = new ChatOpenAI(['apiKey' => 'k', 'model' => 'gpt-4o', 'max_tokens' => 99, 'httpClient' => $http]);
        $model->invoke('hi');

        self::assertSame(99, $http->lastRequestBody()['max_tokens'] ?? null);
    }
}
