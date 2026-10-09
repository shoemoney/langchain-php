<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\Postgres;

use LangGraph\Store\Postgres\Modules\DatabaseCore;
use LangGraph\Store\Postgres\Modules\IndexConfig;
use LangGraph\Store\Postgres\Modules\VectorOperations;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `extractTextAtPath` from `vector-operations.ts`, which decides what gets embedded.
 * Needs no server: the PDO is never touched.
 */
#[CoversClass(VectorOperations::class)]
final class PostgresStoreTextExtractionTest extends TestCase
{
    private VectorOperations $ops;

    protected function setUp(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $this->ops = new VectorOperations(new DatabaseCore($pdo, 'public', null, new IndexConfig(2, static fn (array $t): array => [])));
    }

    public function testTheWholeDocumentIsOneJsonText(): void
    {
        self::assertSame(['{"a":"é/x"}'], $this->ops->extractTextAtPath(['a' => 'é/x'], '$'));
    }

    public function testDottedPathsWalkNestedObjects(): void
    {
        $doc = ['meta' => ['title' => 'Hello', 'n' => 3, 'on' => true, 'obj' => ['k' => 'v']]];

        self::assertSame(['Hello'], $this->ops->extractTextAtPath($doc, 'meta.title'));
        self::assertSame(['3'], $this->ops->extractTextAtPath($doc, 'meta.n'));
        self::assertSame(['true'], $this->ops->extractTextAtPath($doc, 'meta.on'));
        self::assertSame(['{"k":"v"}'], $this->ops->extractTextAtPath($doc, 'meta.obj'));
        self::assertSame([], $this->ops->extractTextAtPath($doc, 'meta.missing'));
        self::assertSame([], $this->ops->extractTextAtPath($doc, 'nope.deeper'));
    }

    public function testArrayIndexesFirstLastAndWildcard(): void
    {
        $doc = [
            'tags' => ['a', 'b', 'c'],
            'sections' => [['text' => 'one'], ['text' => 'two'], null],
        ];

        self::assertSame(['a'], $this->ops->extractTextAtPath($doc, 'tags[0]'));
        self::assertSame(['c'], $this->ops->extractTextAtPath($doc, 'tags[-1]'));
        self::assertSame(['a', 'b', 'c'], $this->ops->extractTextAtPath($doc, 'tags[*]'));
        self::assertSame(['one', 'two'], $this->ops->extractTextAtPath($doc, 'sections[*].text'));
    }
}
