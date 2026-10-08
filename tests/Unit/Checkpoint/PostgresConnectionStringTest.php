<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\Postgres\PostgresSaver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** `fromConnString()` must turn what a caller writes into a PDO DSN. */
#[CoversClass(PostgresSaver::class)]
final class PostgresConnectionStringTest extends TestCase
{
    public function testAUrlBecomesADsnWithCredentialsSplitOut(): void
    {
        self::assertSame(
            ['pgsql:host=db.example;port=5433;dbname=app', 'user', 'p@ss'],
            PostgresSaver::parseConnString('postgresql://user:p%40ss@db.example:5433/app'),
        );
    }

    public function testTheHostQueryParameterSelectsAUnixSocket(): void
    {
        self::assertSame(
            ['pgsql:host=/tmp;port=54329;dbname=x', null, null],
            PostgresSaver::parseConnString('postgres://localhost/x?host=/tmp&port=54329'),
        );
    }

    public function testAPdoDsnPassesThroughUntouched(): void
    {
        self::assertSame(['pgsql:host=h;dbname=d', null, null], PostgresSaver::parseConnString('pgsql:host=h;dbname=d'));
    }

    public function testALibpqKeyValueStringBecomesADsn(): void
    {
        self::assertSame(['pgsql:host=h;dbname=d', null, null], PostgresSaver::parseConnString('host=h dbname=d'));
    }
}
