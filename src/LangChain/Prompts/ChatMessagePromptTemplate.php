<?php

declare(strict_types=1);

namespace LangChain\Prompts;

use LangChain\Messages\BaseMessage;
use LangChain\Messages\ChatMessage;

/**
 * A message step with a caller-supplied role.
 *
 * Port of `ChatMessagePromptTemplate` from `@langchain_core/prompts/chat`.
 *
 * Use this when the role is not one of the three standard ones — a provider that
 * distinguishes `moderator` from `assistant`, or a chat log where a participant's
 * name is the role. Otherwise prefer {@see HumanMessagePromptTemplate},
 * {@see AIMessagePromptTemplate} or {@see SystemMessagePromptTemplate}: the
 * standard roles are what models and providers know how to handle.
 */
class ChatMessagePromptTemplate extends BaseMessageStringPromptTemplate
{
    public string $role;

    public function __construct(StringPromptTemplate $prompt, string $role = 'chat')
    {
        parent::__construct($prompt);
        $this->role = $role;
        $this->kwargs = ['prompt' => $prompt, 'role' => $role];
    }

    /**
     * @param array<string, mixed> $values
     */
    public function format(array $values): BaseMessage
    {
        return new ChatMessage(['role' => $this->role, 'content' => $this->prompt->format($values)]);
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromTemplate(string $template, string $role = 'chat', array $options = []): self
    {
        return new self(PromptTemplate::fromTemplate($template, $options), $role);
    }
}
