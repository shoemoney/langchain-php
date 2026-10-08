<?php

declare(strict_types=1);

namespace LangGraph\Utils;

/**
 * The 128-bit XXH3 digest LangGraph derives task and interrupt ids from.
 *
 * Port of `XXH3` / `isXXH3` from `langgraph-core/src/hash.ts`.
 *
 * Upstream hand-rolls XXH3-128 over `BigInt` because JavaScript has no native
 * one. PHP ships it as `hash('xxh128')`, so the algorithm is not re-implemented:
 * the digest is the same 32-character lowercase hex string, and every unseeded
 * vector in upstream's `hash.test.ts` — plus lengths 0 to 600, compared against
 * a Node run of upstream's own `hash.ts` — agree byte for byte.
 *
 * The seed is the one place the two diverge. Upstream threads the seed through
 * its own secret handling (and ignores it entirely above 240 bytes), which is
 * not what the reference algorithm does, so PHP's seeded `xxh128` produces
 * different digests for every input of four bytes or more. Nothing in LangGraph
 * ever passes a seed, so a non-zero seed is refused rather than silently
 * returning a digest upstream would not.
 */
final class Hash
{
    private function __construct()
    {
    }

    /**
     * The XXH3-128 digest of a string, as 32 lowercase hex characters.
     *
     * @throws \InvalidArgumentException for a non-zero seed (see the class docblock)
     */
    public static function xxh3(string $input, int $seed = 0): string
    {
        if ($seed !== 0) {
            throw new \InvalidArgumentException(
                'Seeded XXH3 is not supported: upstream\'s seeded digests differ from the reference algorithm.'
            );
        }

        return hash('xxh128', $input);
    }

    /** Whether a string has the shape of an XXH3-128 hex digest. */
    public static function isXXH3(string $value): bool
    {
        return preg_match('/^[0-9a-f]{32}\z/', $value) === 1;
    }
}
