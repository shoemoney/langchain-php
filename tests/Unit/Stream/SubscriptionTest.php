<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Stream;

use LangChain\Tests\Unit\Stream\Support\StreamHelpers;
use LangGraph\Stream\Subscription;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * `langgraph-core/src/stream/subscription.test.ts`.
 */
#[CoversClass(Subscription::class)]
final class SubscriptionTest extends TestCase
{
    use StreamHelpers;

    /**
     * @param list<string> $namespace
     * @return array<string, mixed>
     */
    private static function event(string $method, array $namespace = [], mixed $data = null, int $seq = 1): array
    {
        return [
            'type' => 'event',
            'seq' => $seq,
            'method' => $method,
            'params' => ['namespace' => $namespace, 'timestamp' => 0, 'data' => $data],
        ];
    }

    // ---- inferChannel ------------------------------------------------------------------------

    public function testInferChannelMapsKnownMethodsToTheirChannel(): void
    {
        foreach (['values', 'updates', 'messages', 'tools', 'checkpoints', 'lifecycle', 'tasks'] as $method) {
            $this->assertSame($method, Subscription::inferChannel(self::event($method)), $method);
        }
    }

    public function testInferChannelMapsBothInputAndInputRequestedToTheInputChannel(): void
    {
        $this->assertSame('input', Subscription::inferChannel(self::event('input')));
        $this->assertSame('input', Subscription::inferChannel(self::event('input.requested')));
    }

    public function testInferChannelResolvesTheNamedCustomChannelWhenThePayloadCarriesAName(): void
    {
        $this->assertSame('custom:a2a', Subscription::inferChannel(self::event('custom', [], ['name' => 'a2a'])));
        $this->assertSame('custom', Subscription::inferChannel(self::event('custom')));
    }

    public function testInferChannelReturnsNullForUnknownMethods(): void
    {
        $this->assertNull(Subscription::inferChannel(self::event('future-method')));
    }

    // ---- normalizeNamespaceSegment -----------------------------------------------------------

    public function testNormalizeNamespaceSegmentStripsDynamicSuffixesAfterTheFirstColon(): void
    {
        $this->assertSame('fetcher', Subscription::normalizeNamespaceSegment('fetcher:abc-uuid'));
        $this->assertSame('fetcher', Subscription::normalizeNamespaceSegment('fetcher'));
        $this->assertSame('a', Subscription::normalizeNamespaceSegment('a:b:c'));
    }

    // ---- isPrefixMatch -----------------------------------------------------------------------

    public function testIsPrefixMatchMatchesAnExactPrefix(): void
    {
        $this->assertTrue(Subscription::isPrefixMatch(['agent', 'inner'], ['agent']));
        $this->assertFalse(Subscription::isPrefixMatch(['agent'], ['agent', 'inner']));
    }

    public function testIsPrefixMatchNormalizesDynamicSuffixesWhenThePrefixSegmentIsStatic(): void
    {
        $this->assertTrue(Subscription::isPrefixMatch(['fetcher:abc-uuid'], ['fetcher']));
    }

    public function testIsPrefixMatchRequiresExactMatchWhenThePrefixSegmentCarriesASuffix(): void
    {
        $this->assertFalse(Subscription::isPrefixMatch(['fetcher:abc'], ['fetcher:xyz']));
        $this->assertTrue(Subscription::isPrefixMatch(['fetcher:abc'], ['fetcher:abc']));
    }

    // ---- isSupportedChannel / SUPPORTED_CHANNELS ----------------------------------------------

    public function testRecognizesEveryBaseChannel(): void
    {
        foreach (Subscription::SUPPORTED_CHANNELS as $channel) {
            $this->assertTrue(Subscription::isSupportedChannel($channel), $channel);
        }
    }

    public function testRecognizesNamedCustomChannels(): void
    {
        $this->assertTrue(Subscription::isSupportedChannel('custom:a2a'));
    }

    public function testRejectsUnknownChannels(): void
    {
        $this->assertFalse(Subscription::isSupportedChannel('nope'));
        $this->assertFalse(Subscription::isSupportedChannel(''));
    }

    // ---- matchesSubscription -----------------------------------------------------------------

    public function testMatchesWhenTheChannelIsSubscribed(): void
    {
        $def = ['channels' => ['messages']];

        $this->assertTrue(Subscription::matchesSubscription(self::event('messages'), $def));
        $this->assertFalse(Subscription::matchesSubscription(self::event('values'), $def));
    }

    public function testRoutesNamedCustomChannelsThroughTheBaseCustomSubscription(): void
    {
        $def = ['channels' => ['custom']];

        $this->assertTrue(Subscription::matchesSubscription(self::event('custom', [], ['name' => 'a2a']), $def));
    }

    public function testFiltersByNamespacePrefixAndDepth(): void
    {
        $def = ['channels' => ['messages'], 'namespaces' => [['agent']], 'depth' => 0];

        $this->assertTrue(Subscription::matchesSubscription(self::event('messages', ['agent']), $def));
        $this->assertFalse(Subscription::matchesSubscription(self::event('messages', ['agent', 'inner']), $def));
    }

    public function testNormalizesDynamicNamespaceSuffixesWhenMatchingAStaticPrefix(): void
    {
        $def = ['channels' => ['messages'], 'namespaces' => [['fetcher']]];

        $this->assertTrue(Subscription::matchesSubscription(self::event('messages', ['fetcher:abc-uuid']), $def));
    }

    public function testSinceCursorExcludesEventsAtOrBeforeTheCursor(): void
    {
        $def = ['channels' => ['messages'], 'since' => 5];

        $this->assertFalse(Subscription::matchesSubscription(self::event('messages', [], null, 5), $def));
        $this->assertFalse(Subscription::matchesSubscription(self::event('messages', [], null, 4), $def));
    }

    public function testSinceCursorIncludesEventsAfterTheCursor(): void
    {
        $def = ['channels' => ['messages'], 'since' => 5];

        $this->assertTrue(Subscription::matchesSubscription(self::event('messages', [], null, 6), $def));
    }

    public function testSinceCursorIgnoresANonNumericSince(): void
    {
        $this->assertTrue(Subscription::matchesSubscription(self::event('messages', [], null, 1), ['channels' => ['messages'], 'since' => null]));
    }

    // ---- beyond the upstream cases -----------------------------------------------------------

    public function testAnEventWithAnUnrecognisedMethodNeverMatches(): void
    {
        $this->assertFalse(Subscription::matchesSubscription(self::event('future-method'), ['channels' => ['messages', 'custom']]));
    }

    public function testDepthCountsSegmentsBelowTheMatchedPrefix(): void
    {
        $def = ['channels' => ['values'], 'namespaces' => [['agent']], 'depth' => 1];

        $this->assertTrue(Subscription::matchesSubscription(self::event('values', ['agent', 'a']), $def));
        $this->assertFalse(Subscription::matchesSubscription(self::event('values', ['agent', 'a', 'b']), $def));
    }
}
