<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Storage;

use LangChain\Schema\Document;
use LangChain\Storage\EncoderBackedStore;
use LangChain\Storage\InMemoryStore;
use LangChain\Storage\LocalFileStore;
use LangChain\Stores\InMemoryStore as CoreInMemoryStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Own tests for `storage/encoder_backed.ts` and `storage/in_memory.ts` (upstream has none).
 */
#[CoversClass(EncoderBackedStore::class)]
#[CoversClass(InMemoryStore::class)]
final class EncoderBackedStoreTest extends TestCase
{
    private function jsonStore(CoreInMemoryStore $inner): EncoderBackedStore
    {
        return new EncoderBackedStore([
            'store' => $inner,
            'keyEncoder' => static fn (int $id): string => "user:{$id}",
            'valueSerializer' => static fn (array $v): string => json_encode($v, \JSON_THROW_ON_ERROR),
            'valueDeserializer' => static fn (string $s): array => json_decode($s, true, 512, \JSON_THROW_ON_ERROR),
        ]);
    }

    public function testEncodesKeysAndSerializesValuesIntoTheBackingStore(): void
    {
        $inner = new InMemoryStore();
        $store = $this->jsonStore($inner);

        $store->mset([[1, ['name' => 'ada']], [2, ['name' => 'lin']]]);

        self::assertSame(['{"name":"ada"}', '{"name":"lin"}'], $inner->mget(['user:1', 'user:2']));
        self::assertSame([['name' => 'lin'], ['name' => 'ada']], $store->mget([2, 1]));
    }

    public function testMissingKeysStayNullWithoutCallingTheDeserializer(): void
    {
        $calls = 0;
        $store = new EncoderBackedStore([
            'store' => new InMemoryStore(),
            'keyEncoder' => static fn (string $k): string => $k,
            'valueSerializer' => static fn (string $v): string => $v,
            'valueDeserializer' => static function (string $v) use (&$calls): string {
                $calls++;

                return $v;
            },
        ]);

        self::assertSame([null], $store->mget(['nope']));
        self::assertSame(0, $calls);
    }

    public function testMdeleteEncodesKeys(): void
    {
        $inner = new InMemoryStore();
        $store = $this->jsonStore($inner);
        $store->mset([[1, ['a' => 1]], [2, ['a' => 2]]]);

        $store->mdelete([1]);

        self::assertSame([null, '{"a":2}'], $inner->mget(['user:1', 'user:2']));
    }

    public function testYieldKeysPassesThroughTheBackingStoresEncodedKeys(): void
    {
        $inner = new InMemoryStore();
        $store = $this->jsonStore($inner);
        $store->mset([[1, ['a' => 1]], [2, ['a' => 2]]]);

        self::assertSame(['user:1', 'user:2'], iterator_to_array($store->yieldKeys('user:'), false));
        self::assertSame(['user:2'], iterator_to_array($store->yieldKeys('user:2'), false));
    }

    public function testDocumentStoreFromByteStoreRoundTripsDocumentsOverAFileStore(): void
    {
        $dir = sys_get_temp_dir() . '/encoder_backed_' . bin2hex(random_bytes(6));
        try {
            $docs = EncoderBackedStore::createDocumentStoreFromByteStore(LocalFileStore::fromPath($dir));
            $docs->mset([['doc1', new Document('hello world', ['source' => 'a.txt', 'page' => 3])]]);

            [$found, $missing] = $docs->mget(['doc1', 'doc2']);

            self::assertNull($missing);
            self::assertInstanceOf(Document::class, $found);
            self::assertSame('hello world', $found->pageContent);
            self::assertSame(['source' => 'a.txt', 'page' => 3], $found->metadata);
            self::assertSame(
                '{"pageContent":"hello world","metadata":{"source":"a.txt","page":3}}',
                file_get_contents($dir . '/doc1.txt'),
            );
        } finally {
            foreach (glob($dir . '/*') ?: [] as $f) {
                unlink($f);
            }
            @rmdir($dir);
        }
    }

    public function testEmptyMetadataSerializesAsAnObjectNotAList(): void
    {
        $inner = new InMemoryStore();
        $docs = EncoderBackedStore::createDocumentStoreFromByteStore($inner);

        $docs->mset([['d', new Document('x')]]);

        self::assertSame(['{"pageContent":"x","metadata":{}}'], $inner->mget(['d']));
    }

    public function testStorageInMemoryStoreIsAThinReExportOfTheCoreStore(): void
    {
        self::assertInstanceOf(CoreInMemoryStore::class, new InMemoryStore());
        self::assertSame(CoreInMemoryStore::lcId(), InMemoryStore::lcId());
    }
}
