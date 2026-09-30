<?php

declare(strict_types=1);

namespace LangChain\Utils;

/**
 * Records the notices the TypeScript originals write with `console.warn`.
 *
 * ## Why this exists
 *
 * Upstream reports recoverable conditions by warning and continuing:
 * `algo.ts:860-867` drops an invalid pending-send packet with `console.warn`,
 * and `PregelRunner` logs a retry before attempting it again. Neither stops
 * anything.
 *
 * The port translated those to `trigger_error(..., E_USER_WARNING)`, and this
 * package's own phpunit.xml sets `failOnWarning="true"`. The consequence is
 * that a NOTICE is a TEST FAILURE: measured, `TextSplitter` with an oversized
 * chunk printed its notice and exited 1. The library could not be tested for its
 * own documented behaviour.
 *
 * Two tests had already been contorted around it — `TextSplitterTest` grew an
 * `ignoringUserWarnings()` helper whose docblock documented the wrong decision,
 * and `AlgorithmTest` installs a `set_error_handler` purely to capture a warning
 * the engine emits on purpose. A test that has to catch the thing under test to
 * let the suite pass is a test documenting a defect.
 *
 * ## Why a recorder rather than a logger
 *
 * `psr/log` is already a dependency, but no logger is wired anywhere in this
 * port, so adding one would mean threading a parameter through five constructors
 * to reproduce something the rest of the project already solves with a static
 * record: see {@see \LangChain\Tracers\BaseRunManager::handlerErrors()} and
 * {@see \LangChain\TextSplitters\TextSplitter::oversizedChunkWarnings()}.
 *
 * Nothing is lost. {@see notices()} returns everything, and a caller who wants
 * these on stderr can log them.
 */
final class Notice
{
    /** @var list<string> */
    private static array $notices = [];

    private function __construct()
    {
    }

    /**
     * Record a notice. Returns the message so a caller can log it too.
     */
    public static function record(string $message): string
    {
        self::$notices[] = $message;

        return $message;
    }

    /** @return list<string> */
    public static function notices(): array
    {
        return self::$notices;
    }

    /** Notices recorded since the last {@see self::clear()}, keyed by nothing. */
    public static function count(): int
    {
        return count(self::$notices);
    }

    public static function clear(): void
    {
        self::$notices = [];
    }
}
