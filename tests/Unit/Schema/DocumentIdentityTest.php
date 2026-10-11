<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Schema;

use LangChain\Schema\Document;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(Document::class)]
final class DocumentIdentityTest extends TestCase
{
    public static function identifiers(): iterable
    {
        yield 'absent' => [null];
        yield 'empty' => [''];
        yield 'zero' => ['0'];
        yield 'non UUID' => ['source/document 123'];
    }

    #[DataProvider('identifiers')]
    public function testOptionalIdentifiersArePreservedWithoutValidation(?string $id): void
    {
        $document = new Document('original', ['source' => 'book'], $id);

        self::assertSame($id, $document->id);
        self::assertSame($id, $document->withPageContent('replacement')->id);
        self::assertSame($id, $document->withMetadata('page', 2)->id);
        self::assertSame('original', $document->pageContent);
        self::assertSame(['source' => 'book'], $document->metadata);
        self::assertSame($id, (clone $document)->id);
        self::assertSame($id, unserialize(serialize($document))->id);
        $wire = json_decode(json_encode($document, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['langchain_core', 'documents', 'Document'], $wire['id']);
        if ($id === null) {
            self::assertArrayNotHasKey('id', $wire['kwargs']);
        } else {
            self::assertSame($id, $wire['kwargs']['id']);
        }
    }

    public function testExistingConstructorAndUnnamedSerializedShapeRemainCompatible(): void
    {
        $empty = new Document();
        self::assertSame('', $empty->pageContent);
        self::assertSame([], $empty->metadata);
        self::assertNull($empty->id);

        $document = new Document(pageContent: 'text', metadata: ['a' => 1]);
        self::assertSame(['page_content' => 'text', 'metadata' => ['a' => 1]], $document->kwargs());
        self::assertSame($document->kwargs(), $document->toJson()['kwargs']);
    }

    public function testPublicIdUpdatesRemainVisibleInSerializedDocuments(): void
    {
        $document = new Document('text', ['a' => 1], 'before');
        $document->id = 'after';

        self::assertSame(['page_content' => 'text', 'metadata' => ['a' => 1], 'id' => 'after'], $document->toJson()['kwargs']);

        $document->id = null;
        self::assertArrayNotHasKey('id', $document->toJson()['kwargs']);
    }
}
