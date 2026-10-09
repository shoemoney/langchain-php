<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware;

/**
 * Error thrown when the configuration for a retry middleware is invalid.
 *
 * Port of `InvalidRetryConfigError` from `langchain/src/agents/middleware/error.ts`.
 *
 * Upstream carries the `ZodError` as `cause` and builds the message with `z4.prettifyError(error).slice(2)`.
 * There is no Zod here, so the validation issues travel as `issues()` (each `['path' => list<string|int>,
 * 'code' => string, 'message' => string]`, with Zod's codes such as `too_small` and `invalid_type`), and the
 * message is laid out the way `prettifyError` lays it out: the first message, then a `→ at <path>` line, one
 * block per issue.
 */
final class InvalidRetryConfigError extends \Exception
{
    /** @var list<array{path: list<string|int>, code: string, message: string}> */
    private array $issues;

    /**
     * @param list<array{path: list<string|int>, code: string, message: string}> $issues
     */
    public function __construct(array $issues)
    {
        $this->issues = $issues;
        parent::__construct(self::pretty($issues));
    }

    /** @return list<array{path: list<string|int>, code: string, message: string}> */
    public function issues(): array
    {
        return $this->issues;
    }

    /** Upstream's `name`. */
    public function errorName(): string
    {
        return 'InvalidRetryConfigError';
    }

    /**
     * @param list<array{path: list<string|int>, code: string, message: string}> $issues
     */
    private static function pretty(array $issues): string
    {
        $blocks = [];
        foreach ($issues as $issue) {
            $block = $issue['message'];
            if ($issue['path'] !== []) {
                $block .= "\n  → at " . implode('.', array_map('strval', $issue['path']));
            }
            $blocks[] = $block;
        }

        return implode("\n✖ ", $blocks);
    }
}
