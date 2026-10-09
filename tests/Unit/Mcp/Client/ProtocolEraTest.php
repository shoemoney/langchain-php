<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp\Client;

use LangGraph\Mcp\Client\ProtocolEra;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProtocolEra::class)]
final class ProtocolEraTest extends TestCase
{
    /** @return iterable<string, array{string, string|null}> */
    public static function versions(): iterable
    {
        yield 'modern' => ['2026-07-28', 'modern'];
        yield 'legacy newest' => ['2025-11-25', 'legacy'];
        yield 'legacy oldest' => ['2024-11-05', 'legacy'];
        yield 'unknown' => ['2030-01-01', null];
        yield 'empty' => ['', null];
    }

    #[DataProvider('versions')]
    public function testVersionsMapToEras(string $version, ?string $era): void
    {
        self::assertSame($era, ProtocolEra::forVersion($version));
    }
}
