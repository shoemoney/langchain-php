<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint\Redis;

use LangGraph\Checkpoint\Redis\PhpRedisClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpRedisClient::class)]
#[RequiresPhpExtension('redis')]
final class PhpRedisClientReplyLiteralTest extends TestCase
{
    private static function fakeRedis(bool $initial, bool $fail = false): \Redis
    {
        return new class($initial, $fail) extends \Redis {
            /** @var list<bool> */
            public array $sets = [];

            public function __construct(private bool $literal, private readonly bool $fail)
            {
            }

            public function getOption(int $option): mixed
            {
                return $this->literal;
            }

            public function setOption(int $option, mixed $value): bool
            {
                $this->sets[] = $value;
                $this->literal = $value;

                return true;
            }

            public function rawCommand(string $command, mixed ...$args): mixed
            {
                if ($this->fail) {
                    throw new \RedisException('boom');
                }

                return ['index_name', 'idx', 'num_docs', 3];
            }

            public function clearLastError(): bool
            {
                return true;
            }
        };
    }

    public function testFtInfoRestoresALiteralRepliesOptionThatWasAlreadyOn(): void
    {
        $redis = self::fakeRedis(true);

        $info = (new PhpRedisClient($redis))->ftInfo('idx');

        self::assertSame(3, $info['num_docs']);
        self::assertSame([true, true], $redis->sets);
        self::assertTrue($redis->getOption(\Redis::OPT_REPLY_LITERAL));
    }

    public function testFtInfoRestoresAnOffOptionToOff(): void
    {
        $redis = self::fakeRedis(false);

        (new PhpRedisClient($redis))->ftInfo('idx');

        self::assertSame([true, false], $redis->sets);
    }

    public function testFtInfoRestoresTheOptionWhenTheCommandFails(): void
    {
        $redis = self::fakeRedis(true, true);

        try {
            (new PhpRedisClient($redis))->ftInfo('idx');
            self::fail('expected a failure');
        } catch (\Throwable) {
        }

        self::assertTrue($redis->getOption(\Redis::OPT_REPLY_LITERAL));
    }
}
