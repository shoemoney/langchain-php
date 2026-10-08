<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Retrievers;

use LangChain\Retrievers\BaseDocumentCompressor;
use LangChain\Retrievers\BaseRetriever;
use LangChain\Runnables\RunnableConfig;
use LangChain\Schema\Document;
use LangChain\Tracers\CallbackHandler;
use LangChain\Tracers\CallbackManager;
use LangChain\Tracers\Serialized;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Own tests: upstream has none for `BaseRetriever` callbacks.
 */
#[CoversClass(BaseRetriever::class)]
#[CoversClass(BaseDocumentCompressor::class)]
final class BaseRetrieverTest extends TestCase
{
    /**
     * @param list<array{0: string, 1: array<int, mixed>}> $log
     */
    private static function recorder(array &$log): CallbackHandler
    {
        return new CallbackHandler([
            'handleRetrieverStart' => static function (...$args) use (&$log): void {
                $log[] = ['start', $args];
            },
            'handleRetrieverEnd' => static function (...$args) use (&$log): void {
                $log[] = ['end', $args];
            },
            'handleRetrieverError' => static function (...$args) use (&$log): void {
                $log[] = ['error', $args];
            },
        ]);
    }

    public function testInvokeWithoutObserversJustReturnsTheDocuments(): void
    {
        $retriever = new FakeRetriever();

        $result = $retriever->invoke('anything');

        $this->assertEquals([new Document('foo'), new Document('bar')], $result);
        $this->assertSame([], $retriever->lastRunManager?->handlers, 'nobody is listening');
    }

    public function testStartAndEndBracketTheCallWithQueryAndDocuments(): void
    {
        $log = [];
        $retriever = new FakeRetriever(['callbacks' => [self::recorder($log)]]);

        $retriever->invoke('why?');

        $this->assertSame(['start', 'end'], array_column($log, 0));
        [$retrieverSerialized, $query] = $log[0][1];
        $this->assertInstanceOf(Serialized::class, $retrieverSerialized);
        $this->assertSame(['test', 'fake', 'FakeRetriever'], $retrieverSerialized->id);
        $this->assertSame('why?', $query);
        $this->assertEquals([new Document('foo'), new Document('bar')], $log[1][1][0]);
        $this->assertSame($log[0][1][2], $log[1][1][1], 'start and end share one run id');
    }

    public function testAFailureReportsTheErrorAndRethrowsTheOriginal(): void
    {
        $log = [];
        $boom = new \DomainException('index offline');
        $retriever = new FakeRetriever(['callbacks' => [self::recorder($log)]]);
        $retriever->failWith = $boom;

        try {
            $retriever->invoke('q');
            $this->fail('the retrieval error must propagate');
        } catch (\DomainException $e) {
            $this->assertSame($boom, $e);
        }

        $this->assertSame(['start', 'error'], array_column($log, 0));
        $this->assertSame($boom, $log[1][1][0]);
    }

    public function testCallbacksCanArriveOnTheConfigInstead(): void
    {
        $log = [];
        $retriever = new FakeRetriever();

        $retriever->invoke('q', new RunnableConfig(callbacks: [self::recorder($log)]));

        $this->assertSame(['start', 'end'], array_column($log, 0));
    }

    public function testTagsMetadataRunIdAndRunNameReachTheHandler(): void
    {
        $log = [];
        $retriever = new FakeRetriever(['callbacks' => [self::recorder($log)], 'tags' => ['mine'], 'metadata' => ['m' => 1]]);

        $retriever->invoke('q', new RunnableConfig(
            tags: ['call'],
            metadata: ['c' => 2],
            runId: ['0190b3a2-0000-7000-8000-000000000001'],
            runName: 'named',
        ));

        // handleRetrieverStart($retriever, $query, $runId, $parentRunId, $tags, $metadata, $name)
        [, , $runId, , $tags, $metadata, $name] = $log[0][1];
        $this->assertSame('0190b3a2-0000-7000-8000-000000000001', $runId);
        $this->assertEqualsCanonicalizing(['call', 'mine'], $tags);
        $this->assertSame(['c' => 2, 'm' => 1], $metadata);
        $this->assertSame('named', $name);
    }

    public function testTheParentRunIdFromTheConfigIsForwarded(): void
    {
        $log = [];
        $retriever = new FakeRetriever(['callbacks' => [self::recorder($log)]]);

        $retriever->invoke('q', new RunnableConfig(runIdParent: 'parent-run'));

        $this->assertSame('parent-run', $log[0][1][3]);
    }

    public function testTheRunManagerHandedToTheSubclassCreatesInheritingChildren(): void
    {
        $log = [];
        $retriever = new FakeRetriever(['callbacks' => [self::recorder($log)]]);

        $retriever->invoke('q');

        $child = $retriever->lastRunManager?->getChild('vectorstore');
        $this->assertInstanceOf(CallbackManager::class, $child);
        $this->assertContains('vectorstore', $child->tags);
        $this->assertSame($retriever->lastRunManager->runId, $child->parentRunId);
    }

    public function testASubclassThatImplementsNothingThrowsNotImplemented(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Not implemented!');

        (new BareRetriever())->invoke('q');
    }

    public function testErrorsFromANotImplementedRetrieverStillReachTheHandlers(): void
    {
        $log = [];
        $retriever = new BareRetriever(['callbacks' => [self::recorder($log)]]);

        try {
            $retriever->invoke('q');
        } catch (\RuntimeException) {
        }

        $this->assertSame(['start', 'error'], array_column($log, 0));
    }

    public function testBatchRunsEachQueryThroughInvoke(): void
    {
        $log = [];
        $retriever = new FakeRetriever(['callbacks' => [self::recorder($log)]]);

        $results = $retriever->batch(['a', 'b']);

        $this->assertCount(2, $results);
        $this->assertSame(['start', 'end', 'start', 'end'], array_column($log, 0));
    }

    public function testDocumentCompressorDuckTypeCheck(): void
    {
        $compressor = new class extends BaseDocumentCompressor {
            public function compressDocuments(array $documents, string $query, array|CallbackManager|null $callbacks = null): array
            {
                return array_slice($documents, 0, 1);
            }
        };

        $this->assertTrue(BaseDocumentCompressor::isBaseDocumentCompressor($compressor));
        $this->assertFalse(BaseDocumentCompressor::isBaseDocumentCompressor(new \stdClass()));
        $this->assertFalse(BaseDocumentCompressor::isBaseDocumentCompressor('compressDocuments'));
        $this->assertEquals([new Document('foo')], $compressor->compressDocuments([new Document('foo'), new Document('bar')], 'q'));
    }
}
