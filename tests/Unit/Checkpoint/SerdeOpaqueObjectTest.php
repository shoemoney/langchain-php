<?php

declare(strict_types=1);

namespace LangGraph\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\Serde\JsonPlusSerializer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A channel value that cannot be written must fail, not vanish.
 *
 * `get_object_vars()` is called from outside the value's class, so it sees only
 * PUBLIC properties. An object whose state is private therefore walked to an
 * empty array and was stored as `{}` — and the checkpoint resumed with that
 * channel's state gone, with no error at write time and nothing in the trace to
 * say so. The damage surfaces several graph runs later as an unrelated symptom.
 */
#[CoversClass(JsonPlusSerializer::class)]
final class SerdeOpaqueObjectTest extends TestCase
{
    public function testAnObjectWithPrivateStateIsRefusedRatherThanSilentlyDropped(): void
    {
        $opaque = new class {
            private int $count = 7;

            public function count(): int
            {
                return $this->count;
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Refusing to serialise');

        (new JsonPlusSerializer())->dumpsTyped($opaque);
    }

    /**
     * The message names the class and the lost properties, so the failure says
     * what to fix rather than just that something is wrong.
     */
    public function testTheRefusalNamesTheClassAndItsState(): void
    {
        $opaque = new class {
            private int $count = 7;
            private string $label = 'x';
        };

        try {
            (new JsonPlusSerializer())->dumpsTyped($opaque);
            self::fail('expected a refusal');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('count', $e->getMessage());
            self::assertStringContainsString('label', $e->getMessage());
        }
    }

    /**
     * The guard must not touch objects that serialise fine.
     */
    public function testAPublicPropertyObjectStillRoundTrips(): void
    {
        $plain = new \stdClass();
        $plain->a = 1;

        [$type, $bytes] = (new JsonPlusSerializer())->dumpsTyped($plain);
        $back = (new JsonPlusSerializer())->loadsTyped($type, $bytes);

        self::assertSame(['a' => 1], $back);
    }

    public function testJsonSerializableStillRoundTrips(): void
    {
        $value = new class implements \JsonSerializable {
            public function jsonSerialize(): mixed
            {
                return ['a' => 1];
            }
        };

        [$type, $bytes] = (new JsonPlusSerializer())->dumpsTyped($value);
        $back = (new JsonPlusSerializer())->loadsTyped($type, $bytes);

        self::assertSame(['a' => 1], $back);
    }

    /**
     * An object with no state at all has nothing to lose and is not refused.
     */
    public function testAStatelessObjectIsStillAllowed(): void
    {
        $type = (new JsonPlusSerializer())->dumpsTyped(new \stdClass())[0];

        self::assertSame('json', $type);
    }

    /**
     * Arrays — the shape a channel value is meant to have — are untouched.
     */
    public function testNestedArraysAreUnaffected(): void
    {
        [$type, $bytes] = (new JsonPlusSerializer())->dumpsTyped(['a' => ['b' => [1, 2]]]);
        $back = (new JsonPlusSerializer())->loadsTyped($type, $bytes);

        self::assertSame(['a' => ['b' => [1, 2]]], $back);
    }
}
