<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Fireworks;

use LangChain\LanguageModels\Chat\Fireworks\FireworksResponseError;
use LangChain\Utils\AsyncCaller;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Port of `utils/tests/errors.test.ts` from `@langchain/fireworks`.
 */
#[CoversClass(FireworksResponseError::class)]
final class FireworksResponseErrorTest extends TestCase
{
    public function testExposesTheStatusAsAField(): void
    {
        self::assertSame(429, FireworksResponseError::create(429, 'rate limited')->status);
    }

    public function testKeepsTheOriginalMessageFormat(): void
    {
        self::assertSame('Error 401: unauthorized', FireworksResponseError::create(401, 'unauthorized')->getMessage());
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function nonRetryableStatuses(): iterable
    {
        foreach ([400, 401, 402, 403, 404, 413] as $status) {
            yield 'status ' . $status => [$status];
        }
    }

    /**
     * @return iterable<string, array{int}>
     */
    public static function retryableStatuses(): iterable
    {
        foreach ([408, 429, 500, 502, 503, 504] as $status) {
            yield 'status ' . $status => [$status];
        }
    }

    #[DataProvider('nonRetryableStatuses')]
    public function testMarksDeterministicStatusesNonRetryable(int $status): void
    {
        self::assertFalse(AsyncCaller::getRetryable(FireworksResponseError::create($status, 'nope')));
    }

    #[DataProvider('retryableStatuses')]
    public function testMarksTransientStatusesRetryable(int $status): void
    {
        self::assertTrue(AsyncCaller::getRetryable(FireworksResponseError::create($status, 'later')));
    }

    public function testLeavesAnUnmappedStatusUnclassified(): void
    {
        self::assertNull(AsyncCaller::getRetryable(FireworksResponseError::create(418, 'teapot')));
    }

    public function testFromBodyReadsAStringOrObjectDetail(): void
    {
        self::assertSame('Error 400: bad request', FireworksResponseError::fromBody(400, '{"error":"bad request"}')->getMessage());
        self::assertSame('Error 400: nope', FireworksResponseError::fromBody(400, '{"error":{"message":"nope"}}')->getMessage());
        self::assertSame('Error 502: Unspecified error', FireworksResponseError::fromBody(502, '<html>bad gateway</html>')->getMessage());
    }
}
