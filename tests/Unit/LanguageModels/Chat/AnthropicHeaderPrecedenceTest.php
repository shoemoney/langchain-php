<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat;

use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The pinned API version and key cannot be overridden by `defaultHeaders`.
 *
 * `headers()` returned `$this->defaultHeaders + [pinned]`, and PHP's `+` keeps
 * the LEFT on a key collision — so the caller's map won. Measured before the
 * fix: constructing with `['anthropic-version' => '1999-01-01']` sent
 * `1999-01-01`, while the pinned constant is `2023-06-01`.
 *
 * That contradicts the constant's own docblock — "Pinned, not
 * configurable-by-default … a silently negotiated version would make the same
 * code behave differently on two days" — and the harm is real rather than
 * theoretical: the tool-use wire format and the event stream both changed across
 * versions, so a caller who sets the header by accident gets different parsing
 * on a different day, with no error anywhere.
 *
 * Caller extras still merge. Only the pinned keys are protected.
 */
#[CoversClass(ChatAnthropic::class)]
final class AnthropicHeaderPrecedenceTest extends TestCase
{
    private function headers(array $defaultHeaders): array
    {
        $model = new ChatAnthropic([
            'apiKey' => 'sk-test',
            'defaultHeaders' => $defaultHeaders,
        ]);
        $m = new \ReflectionMethod($model, 'headers');
        $m->setAccessible(true);

        return $m->invoke($model);
    }

    public function testThePinnedVersionWins(): void
    {
        self::assertSame(
            ChatAnthropic::API_VERSION,
            $this->headers(['anthropic-version' => '1999-01-01'])['anthropic-version'],
        );
    }

    public function testTheApiKeyWins(): void
    {
        self::assertSame(
            'sk-test',
            $this->headers(['x-api-key' => 'someone-elses-key'])['x-api-key'],
        );
    }

    public function testCallerExtrasAreStillSent(): void
    {
        $headers = $this->headers([
            'anthropic-version' => '1999-01-01',
            'X-Trace-Id' => 'abc123',
        ]);

        self::assertSame(
            'abc123',
            $headers['X-Trace-Id'] ?? null,
            'protecting the pinned keys must not swallow the caller\'s own headers',
        );
    }

    public function testTheDefaultHeadersAreUnchangedWhenTheyDoNotCollide(): void
    {
        $headers = $this->headers(['anthropic-beta' => 'tools-2024']);

        self::assertSame('tools-2024', $headers['anthropic-beta'] ?? null);
        self::assertSame('application/json', $headers['content-type'] ?? null);
    }
}
