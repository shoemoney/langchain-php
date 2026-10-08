<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Indexing;

use LangChain\Indexing\HashedDocument;
use LangChain\Schema\Document;
use LangChain\Utils\Uuid;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Own tests: upstream has none for `_HashedDocument`.
 */
#[CoversClass(HashedDocument::class)]
final class HashedDocumentTest extends TestCase
{
    private static function hashOf(string $content, array $metadata = []): HashedDocument
    {
        return HashedDocument::fromDocument(new Document($content, $metadata));
    }

    public function testHashesAreDeterministicV5UuidsOverContentAndMetadata(): void
    {
        $a = self::hashOf('hello', ['source' => 'x']);
        $b = self::hashOf('hello', ['source' => 'x']);

        $this->assertSame($a->hash_, $b->hash_);
        $this->assertSame($a->uid, $a->hash_, 'the uid defaults to the combined hash');
        $this->assertSame(5, Uuid::version((string) $a->hash_));
        $this->assertSame(5, Uuid::version((string) $a->contentHash));
        $this->assertSame(5, Uuid::version((string) $a->metadataHash));
    }

    public function testContentHashIsSha256ThenUuidV5(): void
    {
        $doc = self::hashOf('hello');

        $this->assertSame(
            Uuid::v5(hash('sha256', 'hello'), '10f90ea3-90a4-4962-bf75-83a0f3c1c62a'),
            $doc->contentHash,
        );
    }

    public function testChangingContentOrMetadataChangesTheHash(): void
    {
        $base = self::hashOf('hello', ['a' => 1]);

        $this->assertNotSame($base->hash_, self::hashOf('hello!', ['a' => 1])->hash_);
        $this->assertNotSame($base->hash_, self::hashOf('hello', ['a' => 2])->hash_);
        $this->assertSame(self::hashOf('hello', ['a' => 1])->contentHash, $base->contentHash);
    }

    public function testMetadataKeyOrderDoesNotChangeTheHash(): void
    {
        $this->assertSame(
            self::hashOf('x', ['a' => 1, 'b' => 2])->hash_,
            self::hashOf('x', ['b' => 2, 'a' => 1])->hash_,
        );
    }

    public function testNestedKeysNotNamedAtTheTopLevelDoNotAffectTheHash(): void
    {
        // Upstream hashes JSON.stringify(data, Object.keys(data).sort()): an array replacer is a
        // whitelist at EVERY depth, so nested keys absent from the top level are dropped.
        $this->assertSame(
            self::hashOf('x', ['outer' => ['inner' => 1]])->metadataHash,
            self::hashOf('x', ['outer' => ['inner' => 999]])->metadataHash,
        );
        $this->assertNotSame(
            self::hashOf('x', ['outer' => ['outer' => 1]])->metadataHash,
            self::hashOf('x', ['outer' => ['outer' => 2]])->metadataHash,
        );
    }

    public function testEmptyMetadataHashesAsAnEmptyObject(): void
    {
        $this->assertSame(
            Uuid::v5(hash('sha256', '{}'), '10f90ea3-90a4-4962-bf75-83a0f3c1c62a'),
            self::hashOf('x')->metadataHash,
        );
    }

    public function testAnExplicitUidIsKept(): void
    {
        $doc = HashedDocument::fromDocument(new Document('x'), 'my-uid');

        $this->assertSame('my-uid', $doc->uid);
        $this->assertNotSame('my-uid', $doc->hash_);
    }

    /** @return iterable<string, array{string}> */
    public static function reservedKeys(): iterable
    {
        yield 'hash_' => ['hash_'];
        yield 'content_hash' => ['content_hash'];
        yield 'metadata_hash' => ['metadata_hash'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('reservedKeys')]
    public function testReservedMetadataKeysAreRejected(string $key): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Metadata cannot contain key $key as it is reserved for internal use");

        self::hashOf('x', [$key => 1]);
    }

    public function testUnserializableMetadataIsReportedAsAHashingFailure(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Failed to hash metadata');

        self::hashOf('x', ['bad' => NAN]);
    }

    public function testToDocumentDropsTheHashes(): void
    {
        $document = self::hashOf('x', ['a' => 1])->toDocument();

        $this->assertEquals(new Document('x', ['a' => 1]), $document);
    }

    public function testTheKeyEncoderIsReplaceable(): void
    {
        $doc = new HashedDocument(['pageContent' => 'x', 'metadata' => []]);
        $doc->makeDefaultKeyEncoder(static fn (string $s): string => 'fixed');
        $doc->calculateHashes();

        $this->assertSame(Uuid::v5('fixed', '10f90ea3-90a4-4962-bf75-83a0f3c1c62a'), $doc->contentHash);
    }
}
