<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\Redis\RedisClientException;
use LangGraph\Checkpoint\Redis\RedisUtils;
use LangGraph\Checkpoint\Redis\TtlConfig;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The metadata-filter and TTL helpers the two savers share. Upstream keeps a private copy of each
 * in `index.ts` and `shallow.ts` and tests them only through a live server.
 */
#[CoversClass(RedisUtils::class)]
#[CoversClass(TtlConfig::class)]
final class RedisUtilsFilterTest extends TestCase
{
    public function testStringsBecomeTagClausesAndNumbersBecomeSinglePointRanges(): void
    {
        self::assertSame(
            ['(@source:{in\\-put})', '(@step:[2 2])', '(@score:[1.5 1.5])'],
            RedisUtils::filterQueryParts(['source' => 'in-put', 'step' => 2, 'score' => 1.5]),
        );
    }

    public function testNullBooleanAndContainerFiltersHaveNoQueryForm(): void
    {
        $filter = ['a' => null, 'b' => true, 'c' => [], 'd' => ['x' => 1]];

        self::assertSame([], RedisUtils::filterQueryParts($filter));
        self::assertTrue(RedisUtils::hasInexpressibleFilter($filter));
        self::assertFalse(RedisUtils::hasInexpressibleFilter(['source' => 'x', 'step' => 1]));
    }

    public function testFilterKeysCannotBreakOutOfTheirClause(): void
    {
        $parts = RedisUtils::filterQueryParts(['})|(@thread_id:{*' => 'x}) | (@thread_id:{*']);

        self::assertSame(['(@\\}\\)\\|\\(\\@thread_id\\:\\{\\*:{x\\}\\)\\ \\|\\ \\(\\@thread_id\\:\\{\\*})'], $parts);
    }

    public function testMetadataMatchingFollowsTheUpstreamRules(): void
    {
        $metadata = ['source' => 'loop', 'step' => 1, 'score' => null, 'parents' => ['b' => 2, 'a' => 1], 'ok' => true];

        self::assertTrue(RedisUtils::metadataMatches($metadata, []));
        self::assertTrue(RedisUtils::metadataMatches($metadata, ['source' => 'loop', 'step' => 1]));
        self::assertTrue(RedisUtils::metadataMatches($metadata, ['step' => 1.0]), 'numbers compare by value');
        self::assertFalse(RedisUtils::metadataMatches($metadata, ['step' => '1']), 'but a string is not a number');
        self::assertTrue(RedisUtils::metadataMatches($metadata, ['score' => null]), 'explicit null matches null');
        self::assertFalse(RedisUtils::metadataMatches($metadata, ['absent' => null]), 'a missing key is not null');
        self::assertTrue(RedisUtils::metadataMatches($metadata, ['parents' => ['a' => 1, 'b' => 2]]), 'key order is irrelevant');
        self::assertFalse(RedisUtils::metadataMatches($metadata, ['parents' => ['a' => 1]]));
        self::assertTrue(RedisUtils::metadataMatches($metadata, ['ok' => true]));
        self::assertFalse(RedisUtils::metadataMatches($metadata, ['ok' => false]));
    }

    public function testDeterministicOrdersObjectKeysButNotLists(): void
    {
        self::assertSame(
            RedisUtils::deterministic(['b' => [3, 1], 'a' => ['y' => 1, 'x' => 2]]),
            RedisUtils::deterministic(['a' => ['x' => 2, 'y' => 1], 'b' => [3, 1]]),
        );
        self::assertNotSame(RedisUtils::deterministic([1, 2]), RedisUtils::deterministic([2, 1]));
    }

    public function testWriteIndexesNeverOverlapBetweenCalls(): void
    {
        $first = RedisUtils::reserveWriteIndexes(3);
        $second = RedisUtils::reserveWriteIndexes(3);

        self::assertGreaterThanOrEqual($first + 3, $second);
    }

    public function testCheckpointTimestampsStrictlyIncrease(): void
    {
        $previous = RedisUtils::nextTimestamp();
        for ($i = 0; $i < 100; $i++) {
            $next = RedisUtils::nextTimestamp();
            self::assertGreaterThan($previous, $next);
            $previous = $next;
        }
    }

    public function testServerErrorWordingIsMatchedCaseInsensitively(): void
    {
        self::assertTrue(RedisUtils::isMissingIndex(new RedisClientException('No such index checkpoints')));
        self::assertTrue(RedisUtils::isMissingIndex(new RedisClientException('checkpoints: no such index')));
        self::assertTrue(RedisUtils::isMissingIndex(new RedisClientException('Unknown Index name')));
        self::assertFalse(RedisUtils::isMissingIndex(new RedisClientException('Syntax error at offset 3')));
        self::assertTrue(RedisUtils::isIndexExists(new RedisClientException('Index already exists')));
        self::assertTrue(RedisUtils::isIndexExists(new RedisClientException('Index Already Exists')));
    }

    public function testTtlConfigConvertsMinutesToWholeSeconds(): void
    {
        self::assertFalse((new TtlConfig())->enabled());
        self::assertFalse((new TtlConfig(defaultTtl: 0))->enabled());
        self::assertTrue((new TtlConfig(defaultTtl: 60))->enabled());
        self::assertSame(3600, (new TtlConfig(defaultTtl: 60))->seconds());
        self::assertSame(90, (new TtlConfig(defaultTtl: 1.5))->seconds());
        self::assertSame(0, (new TtlConfig(defaultTtl: 0.01))->seconds());
    }

    public function testEmbedKeepsBinaryPayloadsRoundTrippable(): void
    {
        $serde = new \LangGraph\Checkpoint\Serde\JsonPlusSerializer();
        $binary = "\xff\xfe\x00binary";

        [$type, $embedded] = RedisUtils::embed($serde, $binary);

        self::assertSame('bytes', $type);
        self::assertIsString($embedded);
        self::assertSame($binary, RedisUtils::unembed($serde, $type, $embedded));

        [$jsonType, $jsonValue] = RedisUtils::embed($serde, ['a' => ['b' => 1]]);
        self::assertSame('json', $jsonType);
        self::assertSame(['a' => ['b' => 1]], RedisUtils::unembed($serde, $jsonType, $jsonValue));
    }
}
