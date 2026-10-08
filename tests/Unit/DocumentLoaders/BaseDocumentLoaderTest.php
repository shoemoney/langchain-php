<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\DocumentLoaders;

use LangChain\DocumentLoaders\BaseDocumentLoader;
use LangChain\DocumentLoaders\DocumentLoader;
use LangChain\Schema\Document;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Own tests: upstream has none for `BaseDocumentLoader`.
 */
#[CoversClass(BaseDocumentLoader::class)]
final class BaseDocumentLoaderTest extends TestCase
{
    public function testASubclassImplementingLoadIsADocumentLoader(): void
    {
        $loader = new class extends BaseDocumentLoader {
            public function load(): array
            {
                return [new Document('page', ['source' => 'inline'])];
            }
        };

        $this->assertInstanceOf(DocumentLoader::class, $loader);
        $this->assertEquals([new Document('page', ['source' => 'inline'])], $loader->load());
    }

    public function testBaseDocumentLoaderIsAbstractAndOffersNoLoadAndSplit(): void
    {
        $reflection = new \ReflectionClass(BaseDocumentLoader::class);

        $this->assertTrue($reflection->isAbstract());
        $this->assertTrue($reflection->getMethod('load')->isAbstract());
        $this->assertFalse($reflection->hasMethod('loadAndSplit'), 'upstream core dropped loadAndSplit; so has the port');
    }
}
