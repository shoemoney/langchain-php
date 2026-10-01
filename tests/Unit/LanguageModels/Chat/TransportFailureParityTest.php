<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat;

use LangChain\LanguageModels\Chat\Anthropic\AnthropicException;
use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Http\HttpResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A transport whose every call fails at connect time.
 */
final class AlwaysRefuses implements HttpClient
{
    public int $calls = 0;

    public function post(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): HttpResponse
    {
        $this->calls++;

        throw new HttpException('connection refused', 0, '');
    }

    public function postStream(string $url, array $headers, string $body, array $query = [], ?float $timeout = null): \Generator
    {
        $this->calls++;

        throw new HttpException('connection refused', 0, '');
    }
}

/** {@see ChatAnthropic} with the retry sleep removed. */
final class NoSleepAnthropic extends ChatAnthropic
{
    protected function backoff(int $attempt): void
    {
    }
}

/**
 * The two clients must fail the same way.
 *
 * The OpenAI client converted an exhausted transport retry into its own
 * exception and retried transport errors; the Anthropic one rethrew the raw
 * `HttpException` and did not retry at all. A caller writing one `catch` for
 * both providers would have silently missed the network-down case for one of
 * them — and the asymmetry was invisible because only OpenAI had a test for it.
 *
 * This asserts the symmetry directly, over the same transport, for both.
 */
#[CoversClass(ChatOpenAI::class)]
#[CoversClass(ChatAnthropic::class)]
final class TransportFailureParityTest extends TestCase
{
    /** @return iterable<string, array{class-string<ChatOpenAI|ChatAnthropic>, class-string}> */
    public static function clients(): iterable
    {
        yield 'openai' => [ChatOpenAI::class, OpenAIException::class];
        yield 'anthropic' => [ChatAnthropic::class, AnthropicException::class];
    }

    #[DataProvider('clients')]
    public function testAnExhaustedTransportRetryRaisesTheProviderException(
        string $client,
        string $expected,
    ): void {
        $transport = new AlwaysRefuses();
        $model = $client === ChatOpenAI::class
            ? new NoSleepOpenAI(['apiKey' => 'k', 'httpClient' => $transport, 'maxRetries' => 2])
            : new NoSleepAnthropic(['apiKey' => 'k', 'httpClient' => $transport, 'maxRetries' => 2]);

        try {
            $model->invoke('hi');
            self::fail('expected a failure');
        } catch (\Throwable $e) {
            self::assertInstanceOf(
                $expected,
                $e,
                'a transport failure must surface as the provider exception, not a raw HttpException',
            );
            self::assertInstanceOf(
                HttpException::class,
                $e->getPrevious(),
                'the original cause must be attached',
            );
            self::assertSame('connection refused', $e->getPrevious()->getMessage());
        }

        self::assertSame(3, $transport->calls, 'initial attempt plus two retries');
    }

    /**
     * A missing key is *our* exception already, and re-wrapping it would
     * replace an actionable message with a generic one.
     */
    #[DataProvider('clients')]
    public function testAMissingKeyKeepsItsActionableMessage(string $client, string $expected): void
    {
        $previous = getenv('OPENAI_API_KEY');
        $previousA = getenv('ANTHROPIC_API_KEY');
        putenv('OPENAI_API_KEY');
        putenv('ANTHROPIC_API_KEY');

        try {
            $model = new $client(['httpClient' => new AlwaysRefuses()]);
            $model->invoke('hi');
            self::fail('expected a failure');
        } catch (\Throwable $e) {
            self::assertInstanceOf($expected, $e);
            self::assertStringContainsString('_API_KEY', $e->getMessage());
        } finally {
            $previous === false ? putenv('OPENAI_API_KEY') : putenv('OPENAI_API_KEY=' . $previous);
            $previousA === false ? putenv('ANTHROPIC_API_KEY') : putenv('ANTHROPIC_API_KEY=' . $previousA);
        }
    }
}
