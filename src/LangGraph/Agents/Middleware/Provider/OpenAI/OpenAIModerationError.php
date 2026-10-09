<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware\Provider\OpenAI;

/**
 * Raised when OpenAI flags content and `exitBehavior` is "error".
 *
 * Port of `OpenAIModerationError` from `langchain/src/agents/middleware/provider/openai/moderation.ts`.
 */
final class OpenAIModerationError extends \Exception
{
    public readonly string $originalMessage;

    /**
     * @param string               $content The flagged text.
     * @param string               $stage   Where it was flagged: "input", "output" or "tool".
     * @param array<string, mixed> $result  The moderation result that flagged it.
     * @param string               $message The formatted violation message.
     */
    public function __construct(
        public readonly string $content,
        public readonly string $stage,
        public readonly array $result,
        string $message,
    ) {
        parent::__construct($message);
        $this->originalMessage = $message;
    }
}
