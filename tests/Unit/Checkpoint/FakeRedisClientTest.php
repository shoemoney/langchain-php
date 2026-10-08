<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\Redis\RedisClientException;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * Pins what {@see FakeRedisClient} claims to model.
 *
 * A fake that drifts from Redis makes every test built on it vouch for behaviour Redis does not
 * have. Each expectation below was first observed against a live Redis Stack 7.4; the live run of
 * `RedisSaverIntegrationTest` is the standing check on the rest.
 */
#[CoversNothing]
final class FakeRedisClientTest extends TestCase
{
    public function testJsonSetNxDeclinesToOverwriteAndReportsIt(): void
    {
        $client = new FakeRedisClient();

        self::assertTrue($client->jsonSet('k', '$', '{"a":1}', onlyIfAbsent: true));
        self::assertFalse($client->jsonSet('k', '$', '{"a":2}', onlyIfAbsent: true));
        self::assertSame('{"a":1}', $client->jsonGet('k'));
        self::assertTrue($client->jsonSet('k', '$', '{"a":3}'));
        self::assertSame('{"a":3}', $client->jsonGet('k'));
        self::assertNull($client->jsonGet('missing'));
    }

    public function testJsonSetRejectsInvalidJson(): void
    {
        $this->expectException(\JsonException::class);

        (new FakeRedisClient())->jsonSet('k', '$', '{not json');
    }

    public function testKeysUseRedisGlobSyntax(): void
    {
        $client = new FakeRedisClient();
        foreach (['checkpoint:t1::a', 'checkpoint:t1:ns:b', 'checkpoint:t2::c', 'checkpoint_blob:t1::x:1'] as $key) {
            $client->jsonSet($key, '$', '{}');
        }

        self::assertSame(['checkpoint:t1::a', 'checkpoint:t1:ns:b'], $client->keys('checkpoint:t1:*'));
        self::assertSame(['checkpoint:t1::a'], $client->keys('checkpoint:t1::?'));
        self::assertSame(['checkpoint:t1::a', 'checkpoint:t2::c'], $client->keys('checkpoint:t[12]::*'));
        self::assertSame(['checkpoint:t1::a', 'checkpoint:t1:ns:b', 'checkpoint:t2::c'], $client->keys('checkpoint:*'));
        self::assertSame([], $client->keys('checkpoint:t1'), 'a glob matches the whole key');
    }

    public function testExpiryHidesAKeyAndAnOverwriteKeepsTheTtl(): void
    {
        $client = new FakeRedisClient();
        $client->jsonSet('k', '$', '{"v":1}');
        self::assertTrue($client->expire('k', 60));
        $client->jsonSet('k', '$', '{"v":2}');

        self::assertSame(60, $client->ttl('k'), 'JSON.SET keeps the TTL');

        $client->advance(61);
        self::assertSame(0, $client->exists('k'));
        self::assertNull($client->jsonGet('k'));
        self::assertFalse($client->expire('k', 60), 'EXPIRE on a missing key is false');
    }

    public function testZRangeOrdersByScoreThenMember(): void
    {
        $client = new FakeRedisClient();
        $client->zAdd('z', [
            ['score' => 1, 'value' => 'b'],
            ['score' => 0, 'value' => 'z'],
            ['score' => 1, 'value' => 'a'],
        ]);

        self::assertSame(['z', 'a', 'b'], $client->zRange('z', 0, -1));
        self::assertSame(['a'], $client->zRange('z', 1, 1));
        self::assertSame([], $client->zRange('z', 5, 9));
        self::assertSame(1, $client->exists('z'));
        self::assertSame(1, $client->del(['z']));
    }

    public function testIndexLifecycleErrorsUseTheServersWording(): void
    {
        $client = new FakeRedisClient();

        try {
            $client->ftSearch('nope', '*', 0, 10, 'ts', true);
            self::fail('Expected a missing-index error');
        } catch (RedisClientException $e) {
            self::assertSame('No such index nope', $e->getMessage());
        }

        $client->ftCreate('ix', ['$.a' => ['type' => 'TAG', 'as' => 'a']], 'p:');
        $this->expectException(RedisClientException::class);
        $this->expectExceptionMessage('Index already exists');
        $client->ftCreate('ix', ['$.a' => ['type' => 'TAG', 'as' => 'a']], 'p:');
    }

    public function testSearchMatchesTagsCaseInsensitivelyAndAnUnindexedFieldMatchesNothing(): void
    {
        $client = new FakeRedisClient();
        $client->ftCreate('ix', [
            '$.name' => ['type' => 'TAG', 'as' => 'name'],
            '$.n' => ['type' => 'NUMERIC', 'as' => 'n'],
        ], 'p:');
        $client->jsonSet('p:1', '$', '{"name":"x-y","n":1,"extra":"hit"}');
        $client->jsonSet('p:2', '$', '{"name":"X-Y","n":2}');
        $client->jsonSet('p:3', '$', '{"name":"other","n":3}');
        $client->jsonSet('q:4', '$', '{"name":"x-y","n":4}');

        $ids = static fn (array $hits): array => array_column($hits, 'id');

        self::assertSame(['p:2', 'p:1'], $ids($client->ftSearch('ix', '(@name:{x\\-y})', 0, 10, 'n', true)), 'case-insensitive; prefix-scoped; sorted');
        self::assertSame(['p:2'], $ids($client->ftSearch('ix', '(@name:{x\\-y}) (@n:[2 2])', 0, 10, 'n', true)), 'clauses AND together');
        self::assertSame(['p:1', 'p:2', 'p:3'], $ids($client->ftSearch('ix', '*', 0, 10, 'n', false)));
        self::assertSame(['p:2'], $ids($client->ftSearch('ix', '*', 1, 1, 'n', false)), 'LIMIT offset size');
        self::assertSame([], $client->ftSearch('ix', '(@extra:{hit})', 0, 10, 'n', true), 'not in the schema, so no match and no error');
        self::assertSame([], $client->ftSearch('ix', '(@name:{absent})', 0, 10, 'n', true));
    }

    public function testSearchRejectsQueriesOutsideTheModelledGrammar(): void
    {
        $client = new FakeRedisClient();
        $client->ftCreate('ix', ['$.a' => ['type' => 'TAG', 'as' => 'a']], 'p:');

        $this->expectException(RedisClientException::class);
        $client->ftSearch('ix', '@a:{x} | @a:{y}', 0, 10, 'a', true);
    }
}
