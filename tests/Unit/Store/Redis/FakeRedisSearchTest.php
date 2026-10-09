<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store\Redis;

use LangChain\Tests\Unit\Checkpoint\FakeRedisClient;
use LangGraph\Checkpoint\Redis\RedisClientException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The query forms {@see \LangGraph\Store\Redis\RedisStore} sends, as {@see FakeRedisClient} models
 * them: TEXT, KNN with each distance metric, RETURN, PARAMS and FT.INFO. The distances are the ones a
 * live Redis 8 server returned for the same vectors.
 */
#[CoversClass(FakeRedisClient::class)]
final class FakeRedisSearchTest extends TestCase
{
    private function client(string $metric = 'COSINE'): FakeRedisClient
    {
        $client = new FakeRedisClient();
        $client->ftCreate('ix', [
            '$.prefix' => ['type' => 'TEXT', 'as' => 'prefix'],
            '$.key' => ['type' => 'TAG', 'as' => 'key'],
            '$.n' => ['type' => 'NUMERIC', 'as' => 'n'],
            '$.embedding' => ['type' => 'VECTOR', 'as' => 'embedding', 'algorithm' => 'FLAT', 'attributes' => ['TYPE' => 'FLOAT32', 'DIM' => 2, 'DISTANCE_METRIC' => $metric]],
        ], 'p:');
        $client->seedJson('p:a', ['prefix' => 'users.alpha-beta', 'key' => 'ka', 'n' => 1, 'embedding' => [1.0, 0.0]]);
        $client->seedJson('p:b', ['prefix' => 'users.gamma', 'key' => 'kb', 'n' => 2, 'embedding' => [0.0, 2.0]]);
        $client->seedJson('p:c', ['prefix' => 'x.y.z', 'key' => 'kc', 'n' => 3, 'embedding' => [1.0, 1.0]]);

        return $client;
    }

    /**
     * @param  list<array{id: string, value: string}> $hits
     * @return list<string>
     */
    private static function ids(array $hits): array
    {
        return array_column($hits, 'id');
    }

    public function testTextClausesMatchEveryTokenCaseInsensitively(): void
    {
        $client = $this->client();

        self::assertSame(['p:a'], self::ids($client->ftSearch('ix', '@prefix:(users alpha beta)', 0, 10, '', false)));
        self::assertSame(['p:a', 'p:b'], self::ids($client->ftSearch('ix', '@prefix:(USERS)', 0, 10, '', false)));
        self::assertSame(['p:c'], self::ids($client->ftSearch('ix', '@prefix:(y z)', 0, 10, '', false)));
        self::assertSame([], self::ids($client->ftSearch('ix', '@prefix:(users z)', 0, 10, '', false)));
        self::assertSame([], self::ids($client->ftSearch('ix', '@prefix:(use)', 0, 10, '', false)), 'a word is not a prefix of itself');
    }

    public function testTextPrefixWildcardAndGroupingParentheses(): void
    {
        $client = $this->client();

        self::assertSame(['p:a', 'p:b'], self::ids($client->ftSearch('ix', '(@prefix:use*)', 0, 10, '', false)));
        self::assertSame(['p:b'], self::ids($client->ftSearch('ix', '(@prefix:(users)) (@key:{kb})', 0, 10, '', false)));
        self::assertSame(['p:b'], self::ids($client->ftSearch('ix', '@prefix:(users) (@n:[2 2])', 0, 10, '', false)));
    }

    public function testTextOnANonTextFieldMatchesNothing(): void
    {
        self::assertSame([], $this->client()->ftSearch('ix', '@key:(ka)', 0, 10, '', false));
    }

    public function testReturnProjectsOnlyTheNamedFieldsAsStrings(): void
    {
        $hits = $this->client()->ftSearch('ix', '@prefix:(gamma)', 0, 10, '', false, ['prefix', 'n']);

        self::assertSame([['id' => 'p:b', 'value' => '{"prefix":"users.gamma","n":"2"}']], $hits);
    }

    public function testWithoutSortByHitsComeBackInIdOrder(): void
    {
        self::assertSame(['p:a', 'p:b', 'p:c'], self::ids($this->client()->ftSearch('ix', '*', 0, 10, '', false)));
        self::assertSame(['p:c', 'p:b', 'p:a'], self::ids($this->client()->ftSearch('ix', '*', 0, 10, 'n', true)));
    }

    /** @return array<string, float|string> */
    private function knn(FakeRedisClient $client, array $vector, string $sortBy = '', int $k = 3, int $dialect = 2): array
    {
        $hits = $client->ftSearch('ix', "(*)=>[KNN {$k} @embedding \$BLOB]", 0, $k, $sortBy, false, ['key', '__embedding_score'], ['BLOB' => pack('g*', ...$vector)], $dialect);

        $scores = [];
        foreach ($hits as $hit) {
            $fields = json_decode($hit['value'], true);
            $scores[$fields['key']] = (float) $fields['__embedding_score'];
        }

        return $scores;
    }

    public function testCosineDistanceIsOneMinusSimilarity(): void
    {
        $scores = $this->knn($this->client('COSINE'), [0.0, 1.0]);

        self::assertEqualsWithDelta(1.0, $scores['ka'], 1e-6);
        self::assertEqualsWithDelta(0.0, $scores['kb'], 1e-6);
        self::assertEqualsWithDelta(1 - 1 / sqrt(2), $scores['kc'], 1e-6);
    }

    public function testL2DistanceIsSquared(): void
    {
        $scores = $this->knn($this->client('L2'), [1.0, 1.0]);

        self::assertEqualsWithDelta(1.0, $scores['ka'], 1e-6);
        self::assertEqualsWithDelta(2.0, $scores['kb'], 1e-6);
        self::assertEqualsWithDelta(0.0, $scores['kc'], 1e-6);
    }

    public function testInnerProductDistanceIsOneMinusTheDotProduct(): void
    {
        $scores = $this->knn($this->client('IP'), [1.0, 1.0]);

        self::assertEqualsWithDelta(0.0, $scores['ka'], 1e-6);
        self::assertEqualsWithDelta(-1.0, $scores['kb'], 1e-6);
        self::assertEqualsWithDelta(-1.0, $scores['kc'], 1e-6);
    }

    public function testKnnReturnsTheKNearestInIndexOrderUnlessSortedByScore(): void
    {
        $client = $this->client('L2');

        self::assertSame(['ka', 'kc'], array_keys($this->knn($client, [1.0, 1.0], '', 2)), 'index order, not best-first');
        self::assertSame(['kc', 'ka'], array_keys($this->knn($client, [1.0, 1.0], '__embedding_score', 2)), 'best-first, k=2');
    }

    public function testKnnHonoursThePrefilterAndSkipsDocumentsWithoutAVector(): void
    {
        $client = $this->client();
        $client->seedJson('p:d', ['prefix' => 'users.delta', 'key' => 'kd', 'n' => 4]);

        $hits = $client->ftSearch('ix', '(@prefix:users*)=>[KNN 5 @embedding $BLOB]', 0, 5, '', false, ['key'], ['BLOB' => pack('g*', 1.0, 0.0)], 2);

        self::assertSame(['p:a', 'p:b'], self::ids($hits));
    }

    public function testKnnPagesWithLimit(): void
    {
        $client = $this->client('L2');

        $hits = $client->ftSearch('ix', '(*)=>[KNN 3 @embedding $BLOB]', 1, 1, '__embedding_score', false, ['key'], ['BLOB' => pack('g*', 1.0, 0.0)], 2);

        self::assertSame(['p:c'], self::ids($hits));
    }

    public function testKnnErrors(): void
    {
        $client = $this->client();

        foreach ([
            ['(*)=>[KNN 3 @key $BLOB]', ['BLOB' => pack('g*', 1.0, 0.0)], 'is not a vector field'],
            ['(*)=>[KNN 3 @embedding $BLOB]', [], 'No such parameter'],
            ['(*)=>[KNN 3 @embedding $BLOB]', ['BLOB' => pack('g*', 1.0)], 'does not match'],
        ] as [$query, $params, $message]) {
            try {
                $client->ftSearch('ix', $query, 0, 3, '', false, null, $params, 2);
                self::fail("expected an error containing {$message}");
            } catch (RedisClientException $e) {
                self::assertStringContainsString($message, $e->getMessage());
            }
        }
    }

    public function testFtInfoDescribesTheIndexAndCountsItsDocuments(): void
    {
        $client = $this->client();
        $client->seedJson('other:1', ['prefix' => 'not indexed']);

        $info = $client->ftInfo('ix');

        self::assertSame('ix', $info['index_name']);
        self::assertSame(3, $info['num_docs']);
        self::assertEquals(['prefixes' => ['p:'], 'key_type' => 'JSON'], $info['index_definition']);
        self::assertSame(['prefix', 'key', 'n', 'embedding'], array_column($info['attributes'], 'attribute'));

        $client->del(['p:a']);
        self::assertSame(2, $client->ftInfo('ix')['num_docs']);
    }

    public function testFtInfoOnAMissingIndexUsesTheServersWording(): void
    {
        $this->expectException(RedisClientException::class);
        $this->expectExceptionMessage('No such index nope');
        (new FakeRedisClient())->ftInfo('nope');
    }
}
