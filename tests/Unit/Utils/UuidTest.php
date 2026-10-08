<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Utils;

use LangChain\Utils\Uuid;
use LangGraph\Checkpoint\CheckpointId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `utils/tests/uuid.test.ts` (the v6 suite), extended to the other
 * generators the module exposes.
 */
#[CoversClass(Uuid::class)]
final class UuidTest extends TestCase
{
    private const V1_ID = 'f1207660-21d2-11ef-8c4f-419efbd44d48';
    private const V6_ID = '1ef21d2f-1207-6660-8c4f-419efbd44d48';

    /** @return array{msecs: int, nsecs: int, clockseq: int, node: string} */
    private static function fullOptions(): array
    {
        return [
            'msecs' => 0x133B891F705,
            'nsecs' => 0x1538,
            'clockseq' => 0x385C,
            'node' => "\x61\xcd\x3c\xbb\x32\x10",
        ];
    }

    private static function expectedBytes(): string
    {
        return "\x1e\x11\x22\xbd\x94\x28\x68\x88\xb8\x5c\x61\xcd\x3c\xbb\x32\x10";
    }

    public function testGeneratesAValidV6Uuid(): void
    {
        $id = Uuid::v6();

        self::assertTrue(Uuid::validate($id));
        self::assertSame(6, Uuid::version($id));
    }

    public function testSupportsAllOptionsDeterministically(): void
    {
        self::assertSame('1e1122bd-9428-6888-b85c-61cd3cbb3210', Uuid::v6(self::fullOptions()));
    }

    public function testSupportsWritingToAnOutputBuffer(): void
    {
        $buffer = str_repeat("\0", 16);

        $result = Uuid::v6(self::fullOptions(), $buffer);

        self::assertSame(self::expectedBytes(), $buffer);
        self::assertSame($buffer, $result);
    }

    public function testSupportsWritingAtAnOffset(): void
    {
        $buffer = str_repeat("\0", 32);
        Uuid::v6(self::fullOptions(), $buffer, 0);
        Uuid::v6(self::fullOptions(), $buffer, 16);

        self::assertSame(self::expectedBytes() . self::expectedBytes(), $buffer);
    }

    public function testThrowsForOutOfRangeBufferOffsets(): void
    {
        $buf15 = str_repeat("\0", 15);
        $buf30 = str_repeat("\0", 30);

        foreach ([[[], &$buf15, null], [[], &$buf30, -1], [[], &$buf30, 15]] as [$options, &$buf, $offset]) {
            try {
                Uuid::v6($options, $buf, $offset);
                self::fail('expected a RangeException');
            } catch (\RangeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testConvertsV1ToV6Correctly(): void
    {
        self::assertSame(self::V6_ID, Uuid::v1ToV6(self::V1_ID));
    }

    public function testConvertsRawV1BytesToRawV6Bytes(): void
    {
        self::assertSame(Uuid::parse(self::V6_ID), Uuid::v1ToV6(Uuid::parse(self::V1_ID)));
    }

    public function testSortsLexicographicallyByCreationTime(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; $i++) {
            $ids[] = Uuid::v6(['msecs' => $i * 1000]);
        }

        $sorted = $ids;
        sort($sorted, SORT_STRING);
        self::assertSame($ids, $sorted);
    }

    public function testStatefulV6IdsMintedInOneBurstSortInCreationOrder(): void
    {
        $ids = [];
        for ($i = 0; $i < 3000; $i++) {
            $ids[] = Uuid::v6();
        }

        $sorted = $ids;
        sort($sorted, SORT_STRING);
        self::assertSame($ids, $sorted);
        self::assertCount(3000, array_unique($ids));
    }

    public function testStatefulV6AgreesWithTheCheckpointIdGeneratorOnOrdering(): void
    {
        // Both generators must hand out ids that sort in creation order, or a saver
        // comparing ids as strings would pick the wrong "latest" checkpoint.
        $checkpointIds = [];
        for ($i = 0; $i < 500; $i++) {
            $checkpointIds[] = CheckpointId::uuid6();
        }
        $sorted = $checkpointIds;
        sort($sorted, SORT_STRING);

        self::assertSame($checkpointIds, $sorted);
        self::assertSame(6, Uuid::version($checkpointIds[0]));
        self::assertSame(6, Uuid::version(Uuid::v6()));
    }

    public function testV1WithOptionsIsDeterministic(): void
    {
        $options = self::fullOptions();

        self::assertSame(Uuid::v1($options), Uuid::v1($options));
        self::assertSame(1, Uuid::version(Uuid::v1($options)));
        self::assertSame('61cd3cbb3210', substr(Uuid::v1($options), 24));
    }

    public function testV6IsTheV1OfTheSameInstantWithFieldsReordered(): void
    {
        $options = self::fullOptions();

        self::assertSame(Uuid::v6($options), Uuid::v1ToV6(Uuid::v1($options)));
    }

    public function testStatefulV1BumpsTheSubMillisecondCounterWithinOneMillisecond(): void
    {
        $ids = [];
        for ($i = 0; $i < 200; $i++) {
            $ids[] = Uuid::v1();
        }

        self::assertCount(200, array_unique($ids));
        // Within a run the node and clock sequence are stable.
        self::assertCount(1, array_unique(array_map(static fn (string $id): string => substr($id, 19), $ids)));
    }

    public function testV4IsValidAndRandom(): void
    {
        $id = Uuid::v4();

        self::assertTrue(Uuid::validate($id));
        self::assertSame(4, Uuid::version($id));
        self::assertNotSame($id, Uuid::v4());
    }

    public function testV4FromProvidedRandomBytesIsDeterministic(): void
    {
        $random = "\x10\x91\x56\xbe\xc4\xfb\xc1\xea\x71\xb4\xef\xe1\x67\x1c\x58\x36";

        self::assertSame('109156be-c4fb-41ea-b1b4-efe1671c5836', Uuid::v4(['random' => $random]));
        self::assertSame('109156be-c4fb-41ea-b1b4-efe1671c5836', Uuid::v4(['rng' => static fn (): string => $random]));
    }

    public function testV4RejectsShortRandomBytes(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Uuid::v4(['random' => 'short']);
    }

    public function testV5MatchesTheRfcKnownAnswers(): void
    {
        self::assertSame('886313e1-3b8a-5372-9b90-0c9aee199e5d', Uuid::v5('python.org', Uuid::DNS));
        self::assertSame('0a300ee9-f9e4-5697-a51a-efc7fafaba67', Uuid::v5('http://example.com/', Uuid::URL));
    }

    public function testV5AcceptsRawNamespaceBytes(): void
    {
        self::assertSame(Uuid::v5('python.org', Uuid::DNS), Uuid::v5('python.org', Uuid::parse(Uuid::DNS)));
    }

    public function testV5RejectsABadNamespace(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Uuid::v5('x', 'not-a-uuid');
    }

    public function testV7IsValidAndSortsByTime(): void
    {
        $ids = [];
        for ($i = 0; $i < 2000; $i++) {
            $ids[] = Uuid::v7();
        }

        self::assertSame(7, Uuid::version($ids[0]));
        $sorted = $ids;
        sort($sorted, SORT_STRING);
        self::assertSame($ids, $sorted);
        self::assertCount(2000, array_unique($ids));
    }

    public function testV7WithOptionsIsDeterministicAndCarriesTheTimestamp(): void
    {
        $options = [
            'random' => "\x10\x91\x56\xbe\xc4\xfb\xc1\xea\x71\xb4\xef\xe1\x67\x1c\x58\x36",
            'msecs' => 0x17F22E279B0,
            'seq' => 0x1234567,
        ];

        $id = Uuid::v7($options);

        self::assertSame($id, Uuid::v7($options));
        self::assertSame('017f22e2-79b0-7', substr($id, 0, 15));
        self::assertSame(7, Uuid::version($id));
    }

    public function testV7WritesToABuffer(): void
    {
        $buffer = str_repeat("\xff", 20);

        Uuid::v7(['msecs' => 1000, 'seq' => 0], $buffer, 2);

        self::assertSame(20, strlen($buffer));
        self::assertSame("\xff\xff", substr($buffer, 0, 2));
        self::assertSame("\xff\xff", substr($buffer, 18));
        self::assertSame(7, ord($buffer[8]) >> 4);
    }

    public function testValidateAcceptsRealAndSpecialUuidsOnly(): void
    {
        self::assertTrue(Uuid::validate(self::V1_ID));
        self::assertTrue(Uuid::validate(Uuid::NIL));
        self::assertTrue(Uuid::validate(Uuid::MAX));
        self::assertTrue(Uuid::validate(strtoupper(self::V6_ID)));
        self::assertFalse(Uuid::validate('not-a-uuid'));
        self::assertFalse(Uuid::validate('f1207660-21d2-91ef-8c4f-419efbd44d48'));
        self::assertFalse(Uuid::validate(123));
        self::assertFalse(Uuid::validate(null));
    }

    public function testParseAndStringifyRoundTrip(): void
    {
        $bytes = Uuid::parse(self::V1_ID);

        self::assertSame(16, strlen($bytes));
        self::assertSame(self::V1_ID, Uuid::stringify($bytes));
        self::assertSame(self::V1_ID, Uuid::unsafeStringify("xx" . $bytes, 2));
    }

    public function testParseAndVersionRejectInvalidUuids(): void
    {
        foreach (['parse', 'version'] as $method) {
            try {
                Uuid::$method('nope');
                self::fail("$method should reject an invalid uuid");
            } catch (\InvalidArgumentException $e) {
                self::assertSame('Invalid UUID', $e->getMessage());
            }
        }
    }

    public function testStringifyRejectsBytesThatAreNotAValidUuid(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Uuid::stringify(str_repeat("\x01", 16));
    }
}
