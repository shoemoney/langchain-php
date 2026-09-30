<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use LangGraph\Checkpoint\BaseCheckpointSaver;
use LangGraph\Checkpoint\Checkpoint;
use LangGraph\Checkpoint\CheckpointId;
use LangGraph\Checkpoint\MemorySaver;
use LangGraph\Checkpoint\SqliteSaver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A malformed namespace must not become a valid-looking one.
 *
 * The savers read the namespace with `(string) $configurable['checkpoint_ns']`.
 * That does not fail on a non-string — an array becomes the literal `"Array"`,
 * an object `"Object"`. Either collides with a real namespace of that name, so a
 * checkpoint lands where a resume will not look for it, and one saved under the
 * colliding name overwrites it. It also emits an "Array to string conversion"
 * warning, which fails this suite outright under `failOnWarning`.
 */
#[CoversClass(BaseCheckpointSaver::class)]
#[CoversClass(MemorySaver::class)]
#[CoversClass(SqliteSaver::class)]
final class CheckpointNamespaceTest extends TestCase
{
    private const META = ['source' => 'update', 'step' => -1, 'parents' => []];

    private static function checkpoint(mixed $value): Checkpoint
    {
        return new Checkpoint(
            v: 4,
            id: CheckpointId::uuid6(0),
            ts: '2024-04-19T17:19:07.952Z',
            channelValues: ['k' => $value],
            channelVersions: ['k' => 1],
            versionsSeen: ['k' => ['k' => 1]],
        );
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedNamespaces(): iterable
    {
        yield 'array' => [['a', 'b']];
        yield 'int' => [42];
        yield 'bool' => [true];
        yield 'float' => [1.5];
        yield 'object' => [new \stdClass()];
    }

    #[DataProvider('malformedNamespaces')]
    public function testMemorySaverTreatsANonStringNamespaceAsRoot(mixed $ns): void
    {
        $saver = new MemorySaver();
        $config = ['thread_id' => '1', 'checkpoint_ns' => $ns];

        $saver->put($config, self::checkpoint('v'), self::META);

        self::assertSame('v', $saver->getTuple(['thread_id' => '1', 'checkpoint_ns' => ''])?->checkpoint->toArray()['channel_values']['k'] ?? null);
    }

    #[DataProvider('malformedNamespaces')]
    public function testSqliteSaverTreatsANonStringNamespaceAsRoot(mixed $ns): void
    {
        $saver = SqliteSaver::fromConnString(':memory:');
        $config = ['thread_id' => '1', 'checkpoint_ns' => $ns];

        $saver->put($config, self::checkpoint('v'), self::META);

        self::assertSame('v', $saver->getTuple(['thread_id' => '1', 'checkpoint_ns' => ''])?->checkpoint->toArray()['channel_values']['k'] ?? null);
    }

    /**
     * The collision, which is the actual defect.
     *
     * A malformed namespace reads as the ROOT here, which is the intended
     * conservative behaviour. The harm the raw cast caused was different: an
     * array became the literal string `"Array"`, so a checkpoint written under a
     * namespace genuinely called "Array" was reachable — and overwritable —
     * through a config holding an array. This pins that the two stay distinct.
     */
    public function testAMalformedNamespaceCannotCollideWithOneNamedArray(): void
    {
        $saver = new MemorySaver();

        // A real namespace literally called "Array".
        $saver->put(['thread_id' => '1', 'checkpoint_ns' => 'Array'], self::checkpoint('legitimate'), self::META);
        // A malformed one, which the cast used to render as the same string.
        $saver->put(['thread_id' => '1', 'checkpoint_ns' => ['a']], self::checkpoint('malformed'), self::META);

        self::assertSame(
            'legitimate',
            $saver->getTuple(['thread_id' => '1', 'checkpoint_ns' => 'Array'])?->checkpoint->toArray()['channel_values']['k'] ?? null,
            'a namespace called "Array" must not be reachable via a malformed one',
        );
    }

    public function testAValidStringNamespaceIsStillHonoured(): void
    {
        $saver = new MemorySaver();
        $config = ['thread_id' => '1', 'checkpoint_ns' => 'parent:child'];

        $saver->put($config, self::checkpoint('nested'), self::META);

        self::assertNotNull($saver->getTuple($config), 'the nested namespace holds it');
        self::assertNull(
            $saver->getTuple(['thread_id' => '1', 'checkpoint_ns' => '']),
            'and the root is still empty, so a string namespace is not collapsed onto it',
        );
    }
}
