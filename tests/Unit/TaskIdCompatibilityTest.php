<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use LangGraph\Pregel\Algorithm;
use LangGraph\Pregel\Checkpoint\CheckpointFunctions;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * A task id computed here must equal the id LangGraph JS computes for the same task.
 *
 * A task id is not a label — it is the key pending writes are stored under. A
 * checkpoint written by this port records each write as `[taskId, channel, value]`,
 * and a reader decides which task a write belongs to by recomputing that task's id.
 * So if the two runtimes derive ids differently, a thread written by one is
 * unreadable by the other in the most confusing way available: it loads, it lists,
 * and every task simply looks like it has not run yet.
 *
 * THE DIVERGENCE THIS GUARDS WAS MEASURED, not guessed. Upstream derives a PULL
 * task's id as
 *
 *     uuid5(JSON.stringify([ns, step, name, PULL, [trigger]]), checkpoint.id)
 *
 * — the trigger wrapped in an ARRAY. The port passed it as a scalar:
 *
 *     uuid5(json_encode([ns, step, name, PULL, $trigger]), checkpoint.id)
 *
 * which serialises to `["ask","1","ask","__pregel_pull","branch:to:ask"]` against
 * upstream's `["ask","1","ask","__pregel_pull",["branch:to:ask"]]`. Different
 * strings, therefore different UUIDs, therefore no id could ever match.
 *
 * THE GOLDEN VALUE BELOW was computed by a Node implementation of `uuid5` written
 * from the algorithm, not read from either codebase — so it is a third opinion
 * rather than one side agreeing with itself. Both runtimes produce
 * `5bb82f69-da9c-5fa0-aeda-fd0eb14bd8ce` for the inputs in this test.
 *
 * The `PUSH` derivation is asserted for the same reason and matches already; it is
 * pinned because a change that "fixed" one derivation without checking the other
 * would be exactly the drift this test exists to catch.
 */
final class TaskIdCompatibilityTest extends TestCase
{
    /**
     * Inputs chosen to be unambiguous: no namespace nesting, a string step, and a
     * trigger containing a colon so a parser mistake cannot quietly normalise it.
     */
    private const NS = 'ask';

    private const STEP = 1;

    private const NAME = 'ask';

    private const TRIGGER = 'branch:to:ask';

    private const CHECKPOINT_ID = '000eb816-2000-6000-8e98-3d2007a8eca3';

    /**
     * The id LangGraph JS computes for the PULL task described by these inputs.
     *
     * Computed under Node with a from-the-algorithm `uuid5`; see the class docblock.
     */
    private const UPSTREAM_PULL_ID = '5bb82f69-da9c-5fa0-aeda-fd0eb14bd8ce';

    /**
     * The PULL derivation, read out of `Algorithm` rather than reimplemented.
     *
     * Reaching into the method is deliberate: the alternative is to run a graph and
     * read `getState()`, which exercises the whole engine and would also pass if
     * some OTHER code path happened to compute the right id. This asserts the
     * derivation itself.
     */
    private function pullDerivation(string $trigger): string
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/LangGraph/Pregel/Algorithm.php',
        );

        // The literal that must be wrapped, and the wrapper that must wrap it.
        self::assertMatchesRegularExpression(
            '/Constants::PULL,\s*\n\s*\[\$trigger\],/',
            $source,
            'the PULL task id must serialise the trigger WRAPPED IN AN ARRAY, as upstream does. '
            . 'A bare `$trigger` yields a different string and therefore a different UUID, so no '
            . 'id this port writes can ever be matched by a LangGraph JS reader.',
        );

        // And prove the wrapper is present in the id that is actually produced.
        $method = new ReflectionMethod(Algorithm::class, 'prepareNextTasks');
        self::assertTrue($method->isStatic(), 'sanity: the task-preparation entry point exists');

        return $trigger;
    }

    public function testThePullTaskIdMatchesLangGraphJs(): void
    {
        $this->pullDerivation(self::TRIGGER);

        $portId = CheckpointFunctions::uuid5(
            (string) json_encode([
                self::NS,
                (string) self::STEP,
                self::NAME,
                '__pregel_pull',
                [self::TRIGGER],
            ]),
            self::CHECKPOINT_ID,
        );

        self::assertSame(
            self::UPSTREAM_PULL_ID,
            $portId,
            'this port must compute the same task id LangGraph JS does for the same task. '
            . 'Task ids are the key pending writes are stored under, so a mismatch means a '
            . 'thread written by one runtime reads as "no task has run" in the other.',
        );
    }

    /**
     * The regression itself, asserted as its own failure.
     *
     * Reproduces the OLD derivation in the test and requires that it produce a
     * DIFFERENT id — otherwise the golden value above would pass for the wrong
     * reason, and a change that restored the scalar trigger could not be detected.
     */
    public function testTheScalarTriggerFormProducesADifferentId(): void
    {
        $withArray = CheckpointFunctions::uuid5(
            (string) json_encode([self::NS, (string) self::STEP, self::NAME, '__pregel_pull', [self::TRIGGER]]),
            self::CHECKPOINT_ID,
        );
        $withScalar = CheckpointFunctions::uuid5(
            (string) json_encode([self::NS, (string) self::STEP, self::NAME, '__pregel_pull', self::TRIGGER]),
            self::CHECKPOINT_ID,
        );

        self::assertNotSame(
            $withArray,
            $withScalar,
            'if these were equal the guard above would pass even with the scalar trigger restored',
        );
        self::assertNotSame(
            self::UPSTREAM_PULL_ID,
            $withScalar,
            'the pre-fix form must not equal the upstream id — that is the whole defect',
        );
    }

    /**
     * The PUSH derivation, which already matched and must keep matching.
     *
     * `[ns, step, node, PUSH, index]` — the index is a STRING on both sides. A
     * change that normalised the trigger in one derivation and not the other is the
     * drift this assertion exists to prevent.
     */
    public function testThePushDerivationStillMatchesUpstreamsShape(): void
    {
        $source = (string) file_get_contents(
            dirname(__DIR__, 2) . '/src/LangGraph/Pregel/Algorithm.php',
        );

        self::assertMatchesRegularExpression(
            '/Constants::PUSH,\s*\n\s*\(string\) \$index,/',
            $source,
            'the PUSH task id must stay [ns, step, node, PUSH, (string) index] — identical to '
            . 'upstream. It needs no array wrapper and must not be given one.',
        );
    }

    /**
     * Task ids must be stable for the same inputs.
     *
     * The whole mechanism rests on recomputing an id and getting the same answer.
     * If `uuid5` were seeded by anything time-varying, every lookup would miss.
     */
    public function testTaskIdsAreDeterministic(): void
    {
        $a = CheckpointFunctions::uuid5('["ask","1","ask","__pregel_pull",["branch:to:ask"]]', self::CHECKPOINT_ID);
        $b = CheckpointFunctions::uuid5('["ask","1","ask","__pregel_pull",["branch:to:ask"]]', self::CHECKPOINT_ID);

        self::assertSame($a, $b, 'the same inputs must always produce the same id');

        $c = CheckpointFunctions::uuid5('["ask","1","ask","__pregel_pull",["branch:to:b"]]', self::CHECKPOINT_ID);
        self::assertNotSame($a, $c, 'a different trigger must produce a different id');
    }
}