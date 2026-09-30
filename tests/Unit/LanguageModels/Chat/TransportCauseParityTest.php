<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat;

use LangChain\LanguageModels\Chat\Anthropic\AnthropicException;
use LangChain\LanguageModels\Chat\Anthropic\ChatAnthropic;
use LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI;
use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Both clients attach the cause of a transport failure, on both paths.
 *
 * The eager path was fixed first and the stream path left behind, so a caller
 * debugging a network failure got "connection refused" for one provider and a
 * bare "status 0" for the other. "Status 0" does not say the connection was
 * *refused* — and a transport failure is exactly when the cause is wanted.
 */
#[CoversClass(ChatOpenAI::class)]
#[CoversClass(ChatAnthropic::class)]
final class TransportCauseParityTest extends TestCase
{
    /** @return iterable<string, array{class-string, class-string}> */
    public static function clients(): iterable
    {
        yield 'openai' => [ChatOpenAI::class, OpenAIException::class];
        yield 'anthropic' => [ChatAnthropic::class, AnthropicException::class];
    }

    #[DataProvider('clients')]
    public function testTheEagerPathAttachesTheCause(string $client, string $expected): void
    {
        $model = $client === ChatOpenAI::class
            ? new NoSleepOpenAI(['apiKey' => 'k', 'httpClient' => new AlwaysRefuses(), 'maxRetries' => 0])
            : new NoSleepAnthropic(['apiKey' => 'k', 'httpClient' => new AlwaysRefuses(), 'maxRetries' => 0]);

        try {
            $model->invoke('hi');
            self::fail('expected a failure');
        } catch (\Throwable $e) {
            self::assertInstanceOf($expected, $e);
            self::assertSame('connection refused', $e->getPrevious()?->getMessage());
        }
    }

    #[DataProvider('clients')]
    public function testTheStreamEstablishmentPathAttachesTheCause(string $client, string $expected): void
    {
        $model = $client === ChatOpenAI::class
            ? new NoSleepOpenAI(['apiKey' => 'k', 'httpClient' => new AlwaysRefuses(), 'maxRetries' => 0])
            : new NoSleepAnthropic(['apiKey' => 'k', 'httpClient' => new AlwaysRefuses(), 'maxRetries' => 0]);

        try {
            iterator_to_array($model->stream('hi'), false);
            self::fail('expected a failure');
        } catch (\Throwable $e) {
            self::assertInstanceOf($expected, $e);
            self::assertSame(
                'connection refused',
                $e->getPrevious()?->getMessage(),
                'a stream failure must name its cause too, not just "status 0"',
            );
        }
    }
}
