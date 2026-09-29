<?php

declare(strict_types=1);

namespace LangGraph\Pregel\Checkpoint;

use Ramsey\Uuid\Uuid;

/**
 * Checkpoint identity and version arithmetic.
 *
 * Port of the free functions in `@langchain/langgraph-checkpoint`'s
 * `base.ts` and `id.ts` that the Pregel engine depends on.
 */
final class CheckpointFunctions
{
    /** The last millisecond handed out, and the 100ns counter within it. */
    private static int $lastMsecs = 0;
    private static int $lastNsecs = 0;

    private function __construct()
    {
    }

    /**
     * The version representing "this channel has never been written".
     *
     * Port of `getNullChannelVersion`. A channel map is homogeneous in practice
     * (all counters or all strings), so the first key decides the flavour: `0`
     * for numbers, `''` for strings, and `null` when the map is empty.
     *
     * Returning `null` matters — it means "no baseline exists", and the
     * scheduler treats that as "not resumable from here".
     */
    public static function getNullChannelVersion(array $currentVersions): int|string|null
    {
        // Short circuit for `__start__`, the channel nearly every graph has.
        if (isset($currentVersions['__start__'])) {
            $start = $currentVersions['__start__'];

            return is_int($start) ? 0 : '';
        }

        foreach ($currentVersions as $version) {
            return is_int($version) ? 0 : '';
        }

        return null;
    }

    /**
     * Compare two channel versions.
     *
     * Port of `compareChannelVersions`. Numeric versions compare numerically;
     * string versions compare as strings.
     */
    public static function compareChannelVersions(int|string $a, int|string $b): int
    {
        if (is_int($a) && is_int($b)) {
            return $a <=> $b;
        }

        return strcmp((string) $a, (string) $b);
    }

    /**
     * The later of two channel versions.
     *
     * Port of `maxChannelVersion`.
     */
    public static function maxChannelVersion(int|string $a, int|string $b): int|string
    {
        return self::compareChannelVersions($a, $b) >= 0 ? $a : $b;
    }

    /**
     * The highest version across a whole channel map.
     *
     * Port of `maxChannelMapVersion` in `algo.ts`. Returns `null` for an empty
     * map, which the caller treats as "nothing has happened yet".
     */
    public static function maxChannelMapVersion(array $channelVersions): int|string|null
    {
        $max = null;
        foreach ($channelVersions as $version) {
            $max = $max === null
                ? $version
                : self::maxChannelVersion($max, $version);
        }

        return $max;
    }

    /**
     * Which channel versions advanced since the previous checkpoint.
     *
     * Port of `getNewChannelVersions`. Only these are written to the saver's
     * `versions` column, so a checkpoint row stores what changed rather than the
     * whole map.
     */
    public static function getNewChannelVersions(array $previousVersions, array $currentVersions): array
    {
        if ($previousVersions === []) {
            return $currentVersions;
        }

        $nullVersion = self::getNullChannelVersion($currentVersions);
        $new = [];
        foreach ($currentVersions as $key => $version) {
            $previous = $previousVersions[$key] ?? $nullVersion;
            if ($previous === null) {
                // No baseline: nothing to be newer than, so treat as unchanged.
                continue;
            }
            if (self::compareChannelVersions($version, $previous) > 0) {
                $new[$key] = $version;
            }
        }

        return $new;
    }

    /** An empty checkpoint, as a fresh run would start from. */
    public static function emptyCheckpoint(): Checkpoint
    {
        return new Checkpoint(
            v: 4,
            id: self::uuid6(0),
            ts: gmdate('Y-m-d\TH:i:s.v\Z'),
            channelValues: [],
            channelVersions: [],
            versionsSeen: [],
        );
    }

    /**
     * A deterministic id derived from a name and a namespace checkpoint id.
     *
     * Port of `uuid5`. Determinism is the entire point: re-preparing the same
     * task from the same checkpoint must produce the same id, or a resume would
     * schedule a duplicate task rather than continue the original one.
     *
     * @param string $name      The varying input (a JSON-serialised task descriptor).
     * @param string $namespace A checkpoint id.
     */
    public static function uuid5(string $name, string $namespace): string
    {
        $hex = str_replace('-', '', $namespace);
        if (strlen($hex) !== 32 || !ctype_xdigit($hex)) {
            $hex = str_pad(substr($hex, 0, 32), 32, '0');
        }

        $bytes = hex2bin($hex);
        if ($bytes === false) {
            $bytes = str_repeat("\0", 16);
        }

        return Uuid::uuid5(Uuid::fromBytes($bytes), $name)->toString();
    }

    /**
     * A time-ordered id, so checkpoint ids sort chronologically as strings.
     *
     * Port of `uuid6`. The clock is kept strictly monotonic: if the wall clock
     * has not advanced since the previous call, the sub-millisecond counter is
     * bumped (rolling into the next millisecond at 10,000) so the emitted time
     * bits still increase.
     *
     * The monotonicity is load-bearing, not a nicety. Savers order a thread's
     * history by comparing ids as *strings*, so two checkpoints minted inside one
     * millisecond have to come out in the order they were written. A random
     * sub-millisecond value would order them arbitrarily — which is exactly what
     * the upstream `id.ts` comment is about: it keeps this counter for the same
     * reason, because a saver that picked the "latest" checkpoint by string
     * comparison would then read back the wrong one.
     *
     * @param int $clockSeq Disambiguates concurrent generators; folded into the
     *                      id but never allowed to affect time ordering.
     */
    public static function uuid6(int $clockSeq = 0): string
    {
        $msecs = (int) (microtime(true) * 1000);
        if ($msecs <= self::$lastMsecs) {
            self::$lastNsecs++;
            if (self::$lastNsecs >= 10000) {
                self::$lastNsecs = 0;
                $msecs = self::$lastMsecs + 1;
            }
        } else {
            self::$lastNsecs = 0;
        }
        self::$lastMsecs = $msecs;

        $timeHigh = ($msecs >> 20) & 0xFFF;
        $timeMid = ($msecs >> 4) & 0xFFFF;
        $timeLow = (($msecs & 0xF) << 12) | (self::$lastNsecs & 0xFFF);
        $seq = $clockSeq & 0x3FFF;

        $bytes = chr(($timeHigh >> 8) & 0xFF) . chr($timeHigh & 0xFF)
            . chr(($timeMid >> 8) & 0xFF) . chr($timeMid & 0xFF)
            . chr(($timeLow >> 8) & 0xFF) . chr($timeLow & 0xFF)
            . chr(($seq >> 8) & 0xFF) . chr($seq & 0xFF)
            . random_bytes(8);

        // Version 6 in the high nibble of byte 6, RFC 4122 variant in byte 8.
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x60);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}
