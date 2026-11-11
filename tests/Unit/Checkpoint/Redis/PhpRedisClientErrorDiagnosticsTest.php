<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint\Redis;

use LangGraph\Checkpoint\Redis\PhpRedisClient;
use LangGraph\Checkpoint\Redis\RedisClientException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpRedisClient::class)]
#[RequiresPhpExtension('redis')]
final class PhpRedisClientErrorDiagnosticsTest extends TestCase
{
    /**
     * Socket-free standalone driver seam. phpredis 6.3 library.c:310-311 does not
     * throw for ordinary ERR replies; :4508-4511 sets lastError and returns false.
     */
    private static function fake(?string $error): \Redis
    {
        return new class($error) extends \Redis {
            public ?string $lastError = null;
            public bool $literal = false;
            public mixed $reply = false;

            public function __construct(public ?string $replyError)
            {
            }

            public function rawCommand(string $command, mixed ...$args): mixed
            {
                $this->lastError = $this->replyError;

                return $this->reply;
            }

            public function getLastError(): ?string
            {
                return $this->lastError;
            }

            public function clearLastError(): bool
            {
                $this->lastError = null;

                return true;
            }

            public function getOption(int $option): mixed
            {
                return $this->literal;
            }

            public function setOption(int $option, mixed $value): bool
            {
                $this->literal = $value;

                return true;
            }
        };
    }

    public static function falseSearchReplies(): iterable
    {
        foreach (['FT.CREATE', 'FT.SEARCH', 'FT.INFO'] as $command) {
            yield $command . '-server-ERR' => [$command, "ERR unknown command '{$command}'"];
            yield $command . '-no-error-null' => [$command, null];
            yield $command . '-no-error-empty' => [$command, ''];
        }
    }

    #[DataProvider('falseSearchReplies')]
    public function testStandaloneFalseRepliesPreserveActualErrorOrAccurateFallback(string $command, ?string $error): void
    {
        $fake = self::fake($error);
        $client = new PhpRedisClient($fake);
        try {
            match ($command) {
                'FT.CREATE' => $client->ftCreate('store', [], 'store:'),
                'FT.SEARCH' => $client->ftSearch('store', '*', 0, 10, '', false),
                'FT.INFO' => $client->ftInfo('store'),
            };
            self::fail('A failed Search command must throw.');
        } catch (RedisClientException $exception) {
            self::assertSame($error !== null && $error !== '' ? $error : $command . ' returned false without a server error.', $exception->getMessage());
        }
        self::assertNull($fake->lastError);
        self::assertFalse($fake->literal);
    }

    public function testStandaloneNilJsonRepliesRemainMissingOrDeclined(): void
    {
        $fake = self::fake(null);
        $client = new PhpRedisClient($fake);
        self::assertNull($client->jsonGet('missing'));
        self::assertFalse($client->jsonSet('exists', '$', '{}', true));
    }
}
