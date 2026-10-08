<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Caches;

use LangChain\Caches\BaseCache;
use LangChain\Caches\InMemoryCache;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\Generation;
use LangChain\Messages\AIMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Converted from `caches/tests/in_memory_cache.test.ts` and `tests/caches.test.ts`.
 */
#[CoversClass(InMemoryCache::class)]
#[CoversClass(BaseCache::class)]
final class InMemoryCacheTest extends TestCase
{
    public function testInMemoryCacheWorks(): void
    {
        $cache = new InMemoryCache();
        $cache->update('prompt', 'key1', [new Generation('text1')]);

        $result = $cache->lookup('prompt', 'key1');

        self::assertNotNull($result);
        self::assertSame('text1', $result[0]->text);
    }

    public function testInMemoryCacheWorksWithComplexMessageTypes(): void
    {
        $cache = new InMemoryCache();
        $cache->update('prompt', 'key1', [['type' => 'text', 'text' => 'text1']]);

        self::assertSame([['type' => 'text', 'text' => 'text1']], $cache->lookup('prompt', 'key1'));
    }

    public function testInMemoryCacheHandlesDefaultKeyEncoder(): void
    {
        $cache = new InMemoryCache();
        $cache->update('prompt1', 'key1', [new Generation('text1')]);

        self::assertNotNull($cache->lookup('prompt1', 'key1'));
    }

    public function testInMemoryCacheHandlesCustomKeyEncoder(): void
    {
        $cache = new InMemoryCache();
        $seen = [];
        $cache->makeDefaultKeyEncoder(static function (string ...$parts) use (&$seen): string {
            $seen[] = $parts;

            return $parts[0] . '###' . $parts[1];
        });

        $cache->update('prompt1', 'key1', [new Generation('text1')]);
        $result = $cache->lookup('prompt1', 'key1');

        self::assertNotNull($result);
        self::assertSame('text1', $result[0]->text);
        self::assertSame([['prompt1', 'key1'], ['prompt1', 'key1']], $seen);
    }

    public function testCachesTestInMemoryCache(): void
    {
        $cache = new InMemoryCache();
        $cache->update('foo', 'bar', [new Generation('baz')]);

        $result = $cache->lookup('foo', 'bar');

        self::assertEquals([new Generation('baz')], $result);
    }

    public function testMissReturnsNull(): void
    {
        self::assertNull((new InMemoryCache())->lookup('nothing', 'here'));
    }

    public function testPromptAndLlmKeyBothSelectTheEntry(): void
    {
        $cache = new InMemoryCache();
        $cache->update('p', 'model-a', [new Generation('A')]);

        self::assertNull($cache->lookup('p', 'model-b'), 'a different llm key must not hit');
        self::assertNull($cache->lookup('q', 'model-a'), 'a different prompt must not hit');
    }

    public function testGetCacheKeyIsSha256OfPartsJoinedWithUnderscore(): void
    {
        self::assertSame(hash('sha256', 'a_b'), BaseCache::getCacheKey('a', 'b'));
        self::assertNotSame(BaseCache::getCacheKey('a', 'b'), BaseCache::getCacheKey('b', 'a'));
    }

    public function testGlobalInstancesShareOneMap(): void
    {
        InMemoryCache::global()->update('global-prompt', 'global-key', [new Generation('shared')]);

        $other = InMemoryCache::global();

        self::assertNotNull($other->lookup('global-prompt', 'global-key'));
        self::assertNull((new InMemoryCache())->lookup('global-prompt', 'global-key'));
    }

    public function testInstancesBuiltOverTheSameMapShareEntries(): void
    {
        $map = new \ArrayObject();
        $a = new InMemoryCache($map);
        $b = new InMemoryCache($map);

        $a->update('p', 'k', [new Generation('x')]);

        self::assertNotNull($b->lookup('p', 'k'));
    }

    public function testSerializeAndDeserializePlainGeneration(): void
    {
        $stored = BaseCache::serializeGeneration(new Generation('hello'));

        self::assertSame(['text' => 'hello'], $stored);
        $restored = BaseCache::deserializeStoredGeneration($stored);
        self::assertSame(Generation::class, $restored::class);
        self::assertSame('hello', $restored->text);
    }

    public function testSerializeAndDeserializeChatGenerationRoundTripsTheMessage(): void
    {
        $generation = new ChatGeneration(new AIMessage('hi there'), 'hi there');

        $stored = BaseCache::serializeGeneration($generation);

        self::assertSame('ai', $stored['message']['type']);
        $restored = BaseCache::deserializeStoredGeneration(json_decode((string) json_encode($stored), true));
        self::assertInstanceOf(ChatGeneration::class, $restored);
        self::assertInstanceOf(AIMessage::class, $restored->message);
        self::assertSame('hi there', $restored->message->content);
        self::assertSame('hi there', $restored->text);
    }
}
