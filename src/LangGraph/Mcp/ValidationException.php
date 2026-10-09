<?php

declare(strict_types=1);

namespace LangGraph\Mcp;

/**
 * A value did not satisfy the schema it was parsed against.
 *
 * The PHP stand-in for the `ZodError` the TypeScript adapter attaches as the `cause` of a
 * {@see ToolException}: the port is JSON-Schema-native, so there is no Zod, but callers still
 * need the structured issues (path + message), not just a string.
 */
class ValidationException extends \InvalidArgumentException
{
    /**
     * @param list<array{path: list<int|string>, message: string}> $issues
     */
    public function __construct(public readonly array $issues, ?string $message = null)
    {
        parent::__construct($message ?? implode("\n", array_map(
            static fn (array $issue): string => $issue['message'],
            $issues,
        )));
    }

    /** One-issue convenience. */
    public static function of(string $message, array $path = []): self
    {
        return new self([['path' => $path, 'message' => $message]]);
    }

    /**
     * A human-readable rendering, one block per issue (`z.prettifyError`).
     */
    public function prettify(): string
    {
        $lines = [];
        foreach ($this->issues as $issue) {
            $lines[] = '✖ ' . $issue['message'];
            if ($issue['path'] !== []) {
                $lines[] = '  → at ' . implode('.', array_map('strval', $issue['path']));
            }
        }

        return implode("\n", $lines);
    }
}
