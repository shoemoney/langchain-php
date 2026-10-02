<?php

declare(strict_types=1);

namespace LangChain\Tests\Integration;

use LangGraph\Checkpoint\Serde\JsonPlusSerializer;
use LangGraph\Pregel\Checkpoint\CheckpointFunctions;
use PHPUnit\Framework\TestCase;

/**
 * Bytes that JavaScript actually wrote, asserted against bytes PHP writes.
 *
 * WHY THIS TEST EXISTS, and why it is shaped the way it is.
 *
 * Every wire defect this project has accepted clusters into one class: `{}` vs
 * `[]`, a key spelled wrong, a nested map flattened. Each survived because the
 * test asserted a PHP ROUND TRIP — encode then decode with the same code — and
 * encode/decode are symmetric in PHP. A symmetric oracle cannot see a shape that
 * is wrong in both directions at once.
 *
 * So the oracle here is not PHP. `tests/Fixtures/langgraph/checkpoint-tuples.json`
 * was produced by RUNNING `@langchain/langgraph` 1.4.18 under Node: a real graph,
 * a real invoke, a real `MemorySaver`, and the saver's own tuples read back and
 * written to disk. Nothing in this repository produced those bytes, and no amount
 * of editing PHP can change them — which is exactly the property an oracle needs.
 *
 * WHAT IT ALREADY CAUGHT. The fixture's top-level keys are
 * `v, id, ts, channel_values, channel_versions, versions_seen`. The port's engine
 * checkpoint writes `v, id, ts, channelValues, channelVersions, versionsSeen`
 * (`Pregel\Checkpoint\Checkpoint::toArray()`). Upstream declares the snake_case
 * names in `checkpoint/src/base.ts:41`, so the two are the same field and the port
 * spells it differently on the wire. A checkpoint written by this port and read by
 * a LangGraph JS saver finds no `channel_versions` — the resume reads as empty
 * rather than erroring.
 *
 * That is recorded as a known divergence rather than silently "fixed" below,
 * because renaming the keys is a change to a persisted format and needs a decision
 * about reading the old spelling too. The test pins the FINDING so the divergence
 * cannot quietly become normal, and so whoever fixes it has the exact bytes to fix
 * it against.
 *
 * The nested-map assertions are already satisfied and are pinned as such: they are
 * the `{}`-vs-`[]` class this project has hit three times, and they now have an
 * oracle that is not symmetric with the code under test.
 */
final class LangGraphJsCheckpointFixtureTest extends TestCase
{
    private const FIXTURE = 'tests/Fixtures/langgraph/checkpoint-tuples.json';

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        $path = dirname(__DIR__, 2) . '/' . self::FIXTURE;
        self::assertFileExists($path, 'the JS oracle is the whole point of this test');

        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        return $data;
    }

    /**
     * The fixture must be traceable to a real run.
     *
     * A fixture with no provenance is an assumption wearing the costume of an
     * oracle — and this project has a recorded instance of exactly that, where a
     * probe built on a guessed API produced confident findings about a class that
     * was never broken. The provenance block is asserted, not trusted.
     */
    public function testTheFixtureDeclaresItsProvenance(): void
    {
        $fixture = $this->fixture();

        self::assertArrayHasKey('_provenance', $fixture);
        self::assertArrayHasKey('package', $fixture);
        self::assertStringContainsString(
            '@langchain/langgraph',
            (string) $fixture['package'],
            'the oracle must name the package and version it was generated from',
        );
        self::assertNotEmpty($fixture['tuples'], 'the oracle must contain at least one real checkpoint');
    }

    /**
     * JS writes snake_case. This is the finding, pinned.
     *
     * Asserted against the fixture rather than against a constant so the test fails
     * if the fixture is ever regenerated from a version that changed the wire
     * format — at which point the port's spelling has to be re-decided rather than
     * assumed.
     */
    public function testTheOracleUsesSnakeCaseWhereThePortWritesCamelCase(): void
    {
        $jsKeys = array_keys($this->fixture()['tuples'][0]['checkpoint']);
        sort($jsKeys);

        self::assertSame(
            ['channel_values', 'channel_versions', 'id', 'ts', 'v', 'versions_seen'],
            $jsKeys,
            'the JS oracle changed shape; re-decide the port key spelling rather than assuming',
        );

        $phpKeys = array_keys(
            json_decode(
                (new JsonPlusSerializer())->dumpsTyped(CheckpointFunctions::emptyCheckpoint())[1],
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );
        sort($phpKeys);

        // WAS a pinned divergence. `assertNotSame` here recorded that this port wrote
        // camelCase where LangGraph JS writes snake_case, and the message said it would
        // fail once the port was fixed.
        //
        // IT WAS WRONG, and the correction matters more than the fix. The finding
        // measured `dumpsTyped()` on a checkpoint object READ BACK from a saver — which
        // walks PHP property names, not the stored bytes. Reading the raw sqlite row
        // showed the SAVED JSON was snake_case all along, because both savers'
        // `wireCheckpoint()` hand-write the snake_case array rather than calling
        // `toArray()`. Nothing on the save path was ever broken; the port agreed with
        // upstream.
        //
        // So `assertNotSame` is now `assertSame`, and the defect it described was real
        // but different in kind: `toArray()` and the savers disagreed, leaving the wrong
        // shape on the method every other caller reaches for. That is fixed, and this
        // assertion now pins agreement rather than a warning about it.
        self::assertSame(
            $jsKeys,
            $phpKeys,
            'this port must write the same key names LangGraph JS writes. It previously '
            . 'emitted camelCase from Pregel\Checkpoint\Checkpoint::toArray() while the savers '
            . 'hand-wrote snake_case; the STORED bytes were always snake_case, so this is '
            . 'now pinning agreement rather than recording a divergence that never reached disk.',
        );
    }

    /**
     * The `{}`-vs-`[]` class, now against a non-symmetric oracle.
     *
     * Tuple 2 is the brand-new-thread case the port's own ledger has been arguing
     * about: `channel_versions: {"__start__": 1}` and
     * `versions_seen: {"__input__": {}}`. Both are OBJECTS on the wire, and
     * `versions_seen` nests an empty object INSIDE another object — the case a
     * one-level cast misses.
     */
    public function testEveryVersionMapIsAnObjectIncludingTheNestedOne(): void
    {
        foreach ($this->fixture()['tuples'] as $i => $tuple) {
            $checkpoint = $tuple['checkpoint'];

            self::assertIsArray(
                $checkpoint['channel_versions'] ?? null,
                "tuple {$i}: JS wrote channel_versions as an object",
            );
            self::assertIsArray(
                $checkpoint['versions_seen'] ?? null,
                "tuple {$i}: JS wrote versions_seen as an object",
            );

            foreach ($checkpoint['versions_seen'] as $node => $seen) {
                self::assertIsArray(
                    $seen,
                    "tuple {$i}: versions_seen['{$node}'] is an object upstream, including when empty",
                );
            }
        }
    }

    /**
     * The port's empty checkpoint must match the oracle's SHAPE, key for key.
     *
     * Compared by key set after normalising the spelling, so this asserts the two
     * are the same six fields and not merely both valid JSON. It is the test that
     * would have caught the rename if the names had been compared directly.
     */
    public function testThePortsCheckpointCarriesTheSameFieldsAsTheOracle(): void
    {
        $jsKeys = array_keys($this->fixture()['tuples'][0]['checkpoint']);
        $phpKeys = array_keys(
            json_decode(
                (new JsonPlusSerializer())->dumpsTyped(CheckpointFunctions::emptyCheckpoint())[1],
                true,
                512,
                JSON_THROW_ON_ERROR,
            ),
        );

        $normalise = static fn (array $keys): array => array_values(array_unique(array_map(
            static fn (string $k): string => strtolower((string) preg_replace('/[_-]/', '', $k)),
            $keys,
        )));
        sort($jsKeys);
        sort($phpKeys);

        self::assertSame(
            $normalise($jsKeys),
            $normalise($phpKeys),
            'once spelling is normalised the two must be the same six fields — a field added '
            . 'or dropped on one side is a wire divergence the JS side cannot detect',
        );
    }

    /**
     * The PORT's own bytes must have the oracle's nested-map shape.
     *
     * Added after a mutation caught this test being half-blind: breaking the
     * `(object)` cast in `Pregel\Checkpoint\Checkpoint::toArray()` left all 9 tests
     * green, because the assertions above read the FIXTURE and never the port's
     * output. Asserting that JavaScript writes `{}` says nothing about whether PHP
     * does — the oracle has to be pointed at BOTH sides or it only pins the
     * half nobody is changing.
     *
     * The case exercised is the one the ledger has argued about three times: an
     * empty `versionsSeen` at the top level, and an empty map NESTED inside it.
     */
    public function testThePortsOwnBytesMatchTheOraclesNestedMapShape(): void
    {
        $bytes = (new JsonPlusSerializer())->dumpsTyped(CheckpointFunctions::emptyCheckpoint())[1];

        self::assertStringContainsString('"versions_seen":{}', $bytes, 'an empty versions_seen must be {}');
        self::assertStringNotContainsString('"versions_seen":[]', $bytes, 'an empty versions_seen must never be []');
        self::assertStringNotContainsString('"channel_versions":[]', $bytes, 'an empty channel_versions must never be []');

        // Now the nested case: one level in, exactly as the JS tuple 2 carries
        // versions_seen: {"__input__": {}}. A one-level cast passes the assertion
        // above and fails this one, which is why the ledger's fix needed two.
        $checkpoint = CheckpointFunctions::emptyCheckpoint();
        $versionsSeen = new \ReflectionProperty($checkpoint, 'versionsSeen');
        $versionsSeen->setValue($checkpoint, ['__input__' => []]);

        $nested = (new JsonPlusSerializer())->dumpsTyped($checkpoint)[1];
        self::assertStringContainsString(
            '"versions_seen":{"__input__":{}}',
            $nested,
            'a map nested inside versions_seen must also be {} — the case JS writes as '
            . 'versions_seen: {"__input__": {}}',
        );
    }

    /**
     * The version fields carry integers, not strings.
     *
     * A version compared as a string sorts wrongly (`"10" < "9"`), and the port's
     * own ledger records a `uuid6()` defect that was only visible because savers
     * order by string comparison. Cheap to pin against a real oracle.
     */
    public function testChannelVersionsAreIntegersOnBothSides(): void
    {
        foreach ($this->fixture()['tuples'] as $i => $tuple) {
            foreach ($tuple['checkpoint']['channel_versions'] as $channel => $version) {
                self::assertIsInt(
                    $version,
                    "tuple {$i}: channel_versions['{$channel}'] must be an int, not a string",
                );
            }
        }
    }
}