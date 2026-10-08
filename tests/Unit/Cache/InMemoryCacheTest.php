<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Cache;

use LangGraph\Cache\BaseCache;
use LangGraph\Cache\InMemoryCache;
use LangGraph\Checkpoint\Serde\JsonPlusSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `checkpoint/src/tests/cache.test.ts`.
 */
#[CoversClass(InMemoryCache::class)]
#[CoversClass(BaseCache::class)]
final class InMemoryCacheTest extends TestCase
{
    /** @var InMemoryCache<string> */
    private InMemoryCache $cache;

    protected function setUp(): void
    {
        $this->cache = new InMemoryCache();
    }

    public function testSetsAndGetsASingleValue(): void
    {
        $key = [['test'], 'key1'];
        $this->cache->set([['key' => $key, 'value' => 'value1', 'ttl' => 1000]]);

        self::assertSame([['key' => $key, 'value' => 'value1']], $this->cache->get([$key]));
    }

    public function testHandlesMultipleValues(): void
    {
        $pairs = [
            ['key' => [['test'], 'key1'], 'value' => 'value1', 'ttl' => 1000],
            ['key' => [['test'], 'key2'], 'value' => 'value2', 'ttl' => 1000],
        ];

        $this->cache->set($pairs);

        self::assertSame(
            [
                ['key' => [['test'], 'key1'], 'value' => 'value1'],
                ['key' => [['test'], 'key2'], 'value' => 'value2'],
            ],
            $this->cache->get(array_map(static fn (array $p): array => $p['key'], $pairs)),
        );
    }

    public function testReturnsNothingForNonExistentKeys(): void
    {
        self::assertSame([], $this->cache->get([[['test'], 'nonexistent']]));
    }

    public function testExpiresValuesAfterTheirTtl(): void
    {
        $key = [['test'], 'key1'];
        $this->cache->set([['key' => $key, 'value' => 'value1', 'ttl' => 0.05]]);

        usleep(80_000);

        self::assertSame([], $this->cache->get([$key]));
    }

    public function testDoesNotExpireValuesBeforeTheirTtl(): void
    {
        $key = [['test'], 'key1'];
        $this->cache->set([['key' => $key, 'value' => 'value1', 'ttl' => 5]]);

        usleep(1000);

        $result = $this->cache->get([$key]);
        self::assertCount(1, $result);
        self::assertSame('value1', $result[0]['value']);
    }

    public function testAnEntryWithoutATtlNeverExpires(): void
    {
        $key = [['test'], 'forever'];
        $this->cache->set([['key' => $key, 'value' => 'v']]);

        self::assertCount(1, $this->cache->get([$key]));
    }

    public function testHandlesDifferentNamespacesSeparately(): void
    {
        $pairs = [
            ['key' => [['ns1'], 'key1'], 'value' => 'value1', 'ttl' => 1000],
            ['key' => [['ns2'], 'key1'], 'value' => 'value2', 'ttl' => 1000],
        ];

        $this->cache->set($pairs);

        self::assertSame(
            [
                ['key' => [['ns1'], 'key1'], 'value' => 'value1'],
                ['key' => [['ns2'], 'key1'], 'value' => 'value2'],
            ],
            $this->cache->get(array_map(static fn (array $p): array => $p['key'], $pairs)),
        );
    }

    public function testHandlesNestedNamespaces(): void
    {
        $key = [['ns1', 'subns'], 'key1'];
        $this->cache->set([['key' => $key, 'value' => 'value1', 'ttl' => 1.0]]);

        self::assertSame([['key' => $key, 'value' => 'value1']], $this->cache->get([$key]));
    }

    public function testClearsASpecificNamespace(): void
    {
        $pairs = [
            ['key' => [['ns1'], 'key1'], 'value' => 'value1', 'ttl' => 1.0],
            ['key' => [['ns2'], 'key1'], 'value' => 'value2', 'ttl' => 1.0],
        ];
        $this->cache->set($pairs);

        $this->cache->clear([['ns1']]);

        self::assertSame(
            [['key' => [['ns2'], 'key1'], 'value' => 'value2']],
            $this->cache->get(array_map(static fn (array $p): array => $p['key'], $pairs)),
        );
    }

    public function testClearsEverythingWhenNoNamespaceIsGiven(): void
    {
        $pairs = [
            ['key' => [['ns1'], 'key1'], 'value' => 'value1', 'ttl' => 1.0],
            ['key' => [['ns2'], 'key1'], 'value' => 'value2', 'ttl' => 1.0],
        ];
        $this->cache->set($pairs);

        $this->cache->clear([]);

        self::assertSame([], $this->cache->get(array_map(static fn (array $p): array => $p['key'], $pairs)));
    }

    public function testEdgeCasesWithEmptyInputs(): void
    {
        self::assertSame([], $this->cache->get([]));
        $this->cache->clear([]);
        $this->cache->set([]);
        self::assertSame([], $this->cache->get([]));
    }

    public function testStructuredValuesRoundTripThroughTheSerializer(): void
    {
        $key = [['writes'], 'k'];
        $writes = [['a', ['n' => 1, 'xs' => [1, 2]]], ['b', 'text']];
        $this->cache->set([['key' => $key, 'value' => $writes]]);

        self::assertSame($writes, $this->cache->get([$key])[0]['value']);
    }

    public function testNumericLookingNamespacesAndKeysAreStillDistinct(): void
    {
        $this->cache->set([
            ['key' => [['1'], '2'], 'value' => 'a'],
            ['key' => [['1', '2'], '3'], 'value' => 'b'],
        ]);

        self::assertSame('a', $this->cache->get([[['1'], '2']])[0]['value']);
        self::assertSame('b', $this->cache->get([[['1', '2'], '3']])[0]['value']);
    }

    public function testTheSerializerDefaultsToJsonPlusAndCanBeReplaced(): void
    {
        self::assertInstanceOf(JsonPlusSerializer::class, $this->cache->serde);

        $custom = new JsonPlusSerializer();
        self::assertSame($custom, (new InMemoryCache($custom))->serde);
    }
}
