<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Stream;

use LangChain\Tests\Unit\Stream\Support\RecordingHandle;
use LangChain\Tests\Unit\Stream\Support\StreamHelpers;
use LangGraph\Stream\Mux;
use LangGraph\Stream\Transformers\SubgraphDiscoveryTransformer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `langgraph-core/src/stream/transformers/subgraphs.test.ts`.
 */
#[CoversClass(SubgraphDiscoveryTransformer::class)]
final class SubgraphDiscoveryTransformerTest extends TestCase
{
    use StreamHelpers;

    /** @var array<string, array{discoveryStart: int, eventStart: int}> */
    private array $offsets = [];

    private function install(Mux $mux): SubgraphDiscoveryTransformer
    {
        $transformer = new SubgraphDiscoveryTransformer($mux, function (array $path, int $discoveryStart, int $eventStart): RecordingHandle {
            $this->offsets[implode('/', $path)] = ['discoveryStart' => $discoveryStart, 'eventStart' => $eventStart];

            return new RecordingHandle($path);
        });
        $mux->addTransformer($transformer);

        return $transformer;
    }

    public function testAnnouncesASingleDiscoveryForEachUnseenTopLevelNamespace(): void
    {
        $mux = new Mux();
        $this->install($mux);

        $mux->push(['agent'], self::makeEvent('messages', ['agent']));
        $mux->discoveries->close();

        $items = self::collect($mux->discoveries->iterate());
        $this->assertCount(1, $items);
        $this->assertSame(['agent'], $items[0]['ns']);
        $this->assertInstanceOf(RecordingHandle::class, $items[0]['stream']);
    }

    public function testUsesOnlyTheTopLevelNamespaceSegmentAsTheDiscoveryKey(): void
    {
        $mux = new Mux();
        $this->install($mux);

        $mux->push(['parent', 'child'], self::makeEvent('messages', ['parent', 'child']));
        $mux->discoveries->close();

        $items = self::collect($mux->discoveries->iterate());
        $this->assertCount(1, $items);
        $this->assertSame(['parent'], $items[0]['ns']);
    }

    public function testDoesNotCreateDuplicateDiscoveriesForRepeatedEventsOnTheSameNamespace(): void
    {
        $mux = new Mux();
        $this->install($mux);

        $mux->push(['agent'], self::makeEvent('messages', ['agent'], [], null, 0));
        $mux->push(['agent'], self::makeEvent('messages', ['agent'], [], null, 1));
        $mux->discoveries->close();

        $this->assertCount(1, self::collect($mux->discoveries->iterate()));
    }

    public function testDoesNotAnnounceTheRootNamespace(): void
    {
        $mux = new Mux();
        $this->install($mux);

        $mux->push([], self::makeEvent('values', [], [], null, 0));
        $mux->discoveries->close();

        $this->assertCount(0, self::collect($mux->discoveries->iterate()));
    }

    public function testRegistersEachNewStreamOnTheMuxSoValuesResolveOnClose(): void
    {
        $mux = new Mux();
        $handles = [];
        $mux->addTransformer(new SubgraphDiscoveryTransformer($mux, static function (array $path) use (&$handles): RecordingHandle {
            return $handles[] = new RecordingHandle($path);
        }));

        $mux->push(['agent'], self::makeEvent('messages', ['agent']));
        $mux->push(['agent'], self::makeEvent('values', ['agent'], ['step' => 1]));
        $mux->close();

        $this->assertCount(1, $handles);
        // The first resolve carries the real payload; the mux's trailing "resolve with null" is ignored by
        // a real handle (a promise settles once).
        $this->assertSame(['step' => 1], $handles[0]->resolved[0]);
    }

    public function testRootProjectionYieldsOnlyDirectChildrenOfTheRootNamespace(): void
    {
        $mux = new Mux();
        $transformer = $this->install($mux);
        $projection = $transformer->init();

        // Deep namespace: only the top-level ["parent"] is announced.
        $mux->push(['parent', 'child', 'grandchild'], self::makeEvent('messages', ['parent', 'child', 'grandchild']));
        $mux->push(['sibling'], self::makeEvent('messages', ['sibling']));
        $mux->discoveries->close();

        $rootChildren = self::collect($projection['subgraphs']);
        $this->assertCount(2, $rootChildren);
        $this->assertSame(['parent'], $rootChildren[0]->path);
        $this->assertSame(['sibling'], $rootChildren[1]->path);
    }

    public function testFilterHandlesScopesTheSharedDiscoveryLogToAnArbitraryNamespace(): void
    {
        $mux = new Mux();
        $this->install($mux);

        $mux->push(['a'], self::makeEvent('messages', ['a']));
        $mux->push(['b'], self::makeEvent('messages', ['b']));
        $mux->discoveries->close();

        $seen = self::collect(SubgraphDiscoveryTransformer::filterHandles($mux->discoveries, [], 1));
        // startAt=1 skips the first discovery entry.
        $this->assertCount(1, $seen);
        $this->assertSame(['b'], $seen[0]->path);
    }

    public function testTracksDiscoveryStartAndEventStartOffsetsForEachNewStream(): void
    {
        $mux = new Mux();
        $this->install($mux);

        $mux->push([], self::makeEvent('values', [], [], null, 0));
        $mux->push([], self::makeEvent('values', [], [], null, 1));
        $mux->push(['late'], self::makeEvent('messages', ['late']));
        $mux->discoveries->close();

        $this->assertCount(1, self::collect($mux->discoveries->iterate()));
        // The discovery log starts empty, so the first discovery sits at index 0.
        $this->assertSame(0, $this->offsets['late']['discoveryStart']);
        // The event log already holds the two root `values` events before this discovery.
        $this->assertSame(2, $this->offsets['late']['eventStart']);
    }
}
