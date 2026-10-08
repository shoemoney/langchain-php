<?php

declare(strict_types=1);

namespace LangGraph\Errors;

/**
 * A node attempt exceeded its {@see \LangGraph\Pregel\TimeoutPolicy}.
 *
 * Port of `NodeTimeoutError` from `langgraph-core/src/errors.ts`.
 *
 * Deliberately NOT a {@see GraphBubbleUp}: a timeout is an ordinary node failure, so the
 * default retry policy retries it and a configured `retryOn` can decide on it.
 *
 * `kind` says which cap fired (`run`, the hard wall-clock one, or `idle`, the one progress
 * resets); `timeout` is that cap's value; `runTimeout` / `idleTimeout` reflect the whole
 * configured policy, each null when not configured.
 */
class NodeTimeoutError extends BaseLangGraphError
{
    public const UNMINIFIABLE_NAME = 'NodeTimeoutError';

    /** The value (ms) of the timeout that fired. */
    public readonly int|float $timeout;

    /**
     * @param string         $node        Name of the node or task that timed out.
     * @param int|float      $elapsed     Milliseconds since the attempt started.
     * @param 'run'|'idle'   $kind        Which timeout fired.
     * @param int|float|null $runTimeout  Configured run timeout (ms).
     * @param int|float|null $idleTimeout Configured idle timeout (ms).
     * @param array<string, mixed> $errorFields
     */
    public function __construct(
        public readonly string $node,
        public readonly int|float $elapsed,
        public readonly string $kind,
        public readonly int|float|null $runTimeout = null,
        public readonly int|float|null $idleTimeout = null,
        array $errorFields = [],
    ) {
        if ($kind === 'idle') {
            if ($idleTimeout === null) {
                throw new \InvalidArgumentException("idleTimeout is required when kind='idle'");
            }
            $this->timeout = $idleTimeout;
            $message = "Node \"{$node}\" exceeded its idle timeout of {$idleTimeout}ms "
                . "without making progress (elapsed: {$elapsed}ms).";
        } else {
            if ($runTimeout === null) {
                throw new \InvalidArgumentException("runTimeout is required when kind='run'");
            }
            $this->timeout = $runTimeout;
            $message = "Node \"{$node}\" exceeded its run timeout of {$runTimeout}ms (elapsed: {$elapsed}ms).";
        }

        parent::__construct($message, $errorFields);
    }

    /** Port of `isNodeTimeoutError`. */
    public static function is(mixed $e): bool
    {
        return $e instanceof self;
    }
}
