<?php

declare(strict_types=1);

namespace LangGraph\Errors;

/**
 * Base class for every LangGraph error.
 *
 * Port of `BaseLangGraphError` from `langgraph-core/src/errors.ts`. Carries an
 * `lc_error_code` so callers can branch on a stable identifier rather than
 * parsing a message string.
 */
class BaseLangGraphError extends \Exception
{
    /** @var array<string, mixed> */
    public array $fields = [];

    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(string $message = '', array $fields = [])
    {
        parent::__construct($message);
        $this->fields = $fields;
    }

    public function getLcErrorCode(): ?string
    {
        $code = $this->fields['lc_error_code'] ?? null;

        return is_string($code) ? $code : null;
    }
}
