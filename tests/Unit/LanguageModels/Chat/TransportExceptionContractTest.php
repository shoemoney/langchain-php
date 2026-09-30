<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat;

use LangChain\LanguageModels\Chat\Anthropic\AnthropicException;
use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** A transport that violates the `HttpClient` contract by raising its own type. */
final class RudeTransport implements HttpClient
{
    public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
    {
        throw new \LogicException('I am not an HttpException');
    }

    public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
    {
        throw new \LogicException('I am not an HttpException');

        yield '';
    }
}

/**
 * Every failure leaving a provider client is that client's exception type.
 *
 * `HttpClient` is a public interface, so a third-party transport may raise
 * anything. A bare `LogicException` escaping means a caller catching
 * `OpenAIException` — the only type these clients document — silently misses it,
 * and the failure is never attributable to the provider.
 */
#[CoversClass(OpenAIException::class)]
#[CoversClass(AnthropicException::class)]
final class TransportExceptionContractTest extends TestCase
{
    public function testOpenAiWrapsATransportThatRaisesItsOwnType(): void
    {
        $model = new ChatOpenAI(['apiKey' => 'k', 'httpClient' => new RudeTransport(), 'maxRetries' => 0]);

        try {
            iterator_to_array($model->stream('hi'), false);
            self::fail('expected an OpenAIException');
        } catch (OpenAIException $e) {
            self::assertStringContainsString('LogicException', $e->getMessage());
            self::assertInstanceOf(\LogicException::class, $e->getPrevious());
        }
    }

    public function testAnthropicWrapsATransportThatRaisesItsOwnType(): void
    {
        $model = new ChatAnthropic(['apiKey' => 'k', 'httpClient' => new RudeTransport(), 'maxRetries' => 0]);

        try {
            iterator_to_array($model->stream('hi'), false);
            self::fail('expected an AnthropicException');
        } catch (AnthropicException $e) {
            self::assertStringContainsString('LogicException', $e->getMessage());
            self::assertInstanceOf(\LogicException::class, $e->getPrevious());
        }
    }

    /**
     * The eager path must behave alike — and it originally did not: only the
     * `HttpException` branch was guarded, so a transport raising anything else
     * escaped raw from `invoke()` while the streaming path was fixed.
     */
    public function testTheEagerPathWrapsToo(): void
    {
        foreach ([
            'openai' => [ChatOpenAI::class, OpenAIException::class],
            'anthropic' => [ChatAnthropic::class, AnthropicException::class],
        ] as $label => [$client, $expected]) {
            $model = new $client(['apiKey' => 'k', 'httpClient' => new RudeTransport(), 'maxRetries' => 0]);

            try {
                $model->invoke('hi');
                self::fail($label . ': expected a provider exception');
            } catch (\Throwable $e) {
                self::assertInstanceOf($expected, $e, $label . ' eager path must wrap');
                self::assertInstanceOf(\LogicException::class, $e->getPrevious(), $label);
            }
        }
    }
}
