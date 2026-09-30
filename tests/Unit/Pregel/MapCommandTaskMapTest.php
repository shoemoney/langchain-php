<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Pregel;

use LangGraph\Pregel\Constants;
use LangGraph\Pregel\IO;
use LangGraph\Pregel\Command;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Task-map resume detection, and what a task's queued resumes accumulate to.
 *
 * The detection guard used to carry a comparison of `array_keys($resume)`
 * against ITSELF — true for every input, guarding nothing. Upstream's guard is
 * exactly "non-empty AND every key is a task hash" (`io.ts:100-102`), which is
 * what these pin, so a future simplification of the hash check has something to
 * fail against.
 *
 * The accumulation is also pinned because it looks like a bug and is not:
 * upstream truncates a task's prior resumes with `.slice(0, 1)` (`io.ts:108`),
 * so the port's `array_slice($existing, 0, 1)` is deliberate fidelity. Its
 * docblock previously said the opposite, which is what made it look wrong.
 */
#[CoversClass(IO::class)]
final class MapCommandTaskMapTest extends TestCase
{
    private const TASK_A = '123e4567e89b12d3a456426614174000';
    private const TASK_B = '123e4567e89b12d3a456426614174001';

    public function testANonEmptyMapOfTaskHashesIsTreatedAsATaskMap(): void
    {
        $writes = iterator_to_array(IO::mapCommand(new Command(resume: [self::TASK_A => 'r1'])), false);

        self::assertSame([[self::TASK_A, Constants::RESUME, ['r1']]], $writes);
    }

    public function testSeveralTaskHashesEachGetTheirOwnWrite(): void
    {
        $writes = iterator_to_array(IO::mapCommand(new Command(resume: [self::TASK_A => 'r1', self::TASK_B => 'r2'])), false);

        self::assertSame([
            [self::TASK_A, Constants::RESUME, ['r1']],
            [self::TASK_B, Constants::RESUME, ['r2']],
        ], $writes);
    }

    /**
     * Only the FIRST prior resume survives — matching upstream exactly.
     *
     * `io.ts:104-110` maps the pending RESUME writes and applies
     * `.slice(0, 1)`, which keeps index 0 of the collected list. The port's
     * `array_slice($existing, 0, 1)` is that line. The docblock above the method
     * claimed resume values ACCUMULATE, which is what made a reviewer read the
     * faithful code as a bug; it now describes the truncation.
     */
    public function testOnlyTheFirstPriorResumeSurvives(): void
    {
        $pending = [
            [self::TASK_A, Constants::RESUME, 'first'],
            [self::TASK_A, Constants::RESUME, 'second'],
        ];

        $writes = iterator_to_array(IO::mapCommand(
            new Command(resume: [self::TASK_A => 'incoming']),
            $pending,
        ), false);

        self::assertSame(
            [[self::TASK_A, Constants::RESUME, ['first', 'incoming']]],
            $writes,
            'upstream truncates with slice(0,1), keeping the first (io.ts:108)',
        );
    }

    /**
     * A prior resume belonging to ANOTHER task must not leak in.
     */
    public function testAnotherTasksPriorResumeIsNotCollected(): void
    {
        $pending = [[self::TASK_B, Constants::RESUME, 'other-task']];

        $writes = iterator_to_array(IO::mapCommand(
            new Command(resume: [self::TASK_A => 'incoming']),
            $pending,
        ), false);

        self::assertSame([[self::TASK_A, Constants::RESUME, ['incoming']]], $writes);
    }

    /**
     * A non-hash key means this is NOT a task map — it is a bare resume value,
     * which upstream routes to the null task id.
     */
    public function testANonHashKeyRoutesToTheNullTask(): void
    {
        $writes = iterator_to_array(IO::mapCommand(new Command(resume: ['not-a-hash' => 'v'])), false);

        self::assertSame([[Constants::NULL_TASK_ID, Constants::RESUME, ['not-a-hash' => 'v']]], $writes);
    }

    public function testAScalarResumeGoesToTheNullTask(): void
    {
        $writes = iterator_to_array(IO::mapCommand(new Command(resume: 'plain')), false);

        self::assertSame([[Constants::NULL_TASK_ID, Constants::RESUME, 'plain']], $writes);
    }
}
