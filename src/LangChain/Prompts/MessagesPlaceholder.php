<?php

declare(strict_types=1);

namespace LangChain\Prompts;

use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\MessageUtils;
use LangChain\Utils\Js;

/**
 * A placeholder for messages supplied by the caller.
 *
 * Port of `MessagesPlaceholder` from `@langchain_core/prompts/chat`.
 *
 * This is how conversation history gets into a prompt: the template says *where*
 * the history goes ("after the system message, before the question") without
 * knowing anything about it. The value may be a `BaseMessage`, a message-like
 * value the coercion helpers understand, or a list of either.
 *
 * `optional` is the difference between "this prompt has no history yet" and
 * "this prompt is broken". Without it, the first turn of a conversation throws;
 * with it, the placeholder contributes nothing.
 */
class MessagesPlaceholder extends BaseMessagePromptTemplate
{
    /** The input variable this placeholder reads. */
    public string $variableName;

    /** When true, a missing value contributes no messages instead of throwing. */
    public bool $optional;

    /**
     * @param string $variableName
     * @param bool   $optional
     */
    public function __construct(string $variableName, bool $optional = false)
    {
        $this->variableName = $variableName;
        $this->optional = $optional;
        $this->inputVariables = [$variableName];

        $this->kwargs = ['variable_name' => $variableName];
        if ($optional) {
            $this->kwargs['optional'] = true;
        }
    }

    /**
     * @param array<string, mixed> $values
     * @return list<BaseMessage>
     * @throws InputFormatError
     */
    public function formatMessages(array $values): array
    {
        $input = $values[$this->variableName] ?? null;

        if ($this->optional && !$input) {
            return [];
        }

        if (!$input) {
            throw new InputFormatError(
                "Field \"{$this->variableName}\" in prompt uses a MessagesPlaceholder, which expects an array of BaseMessages as an input value. Received: undefined"
            );
        }

        try {
            if (is_array($input) && Js::isList($input)) {
                return array_map(self::coerceStrictly(...), $input);
            }

            return [self::coerceStrictly($input)];
        } catch (\Throwable $e) {
            $readable = is_string($input)
                ? $input
                : json_encode($input, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            $error = new InputFormatError(
                "Field \"{$this->variableName}\" in prompt uses a MessagesPlaceholder, which expects an array of BaseMessages or coerceable values as input.\n\n"
                . "Received value: {$readable}\n\n"
                . "Additional message: {$e->getMessage()}"
            );
            if ($e instanceof InputFormatError) {
                $error->lcErrorCode = $e->lcErrorCode;
            }

            throw $error;
        }
    }

    /**
     * Coerce one value to a message, rejecting anything that only *looks* coercible.
     *
     * `MessageUtils::coerceMessageLikeToMessage()` is deliberately permissive —
     * an arbitrary array becomes a `HumanMessage` carrying it as content. That is
     * right for a loose field and wrong here: silently turning a list of
     * documents into a user message would produce a prompt full of JSON with no
     * complaint.
     */
    private static function coerceStrictly(mixed $value): BaseMessage
    {
        if ($value instanceof BaseMessage) {
            return $value;
        }

        if (is_string($value)) {
            return new HumanMessage($value);
        }

        if (!is_array($value)) {
            throw new InputFormatError(
                'Unable to coerce message from array: only human, AI, system, developer, or tool message coercion is currently supported.'
            );
        }

        // A positional [type, content] pair or a field map with a role/type.
        $isPair = Js::isList($value) && count($value) === 2 && is_string($value[0]);
        if (!$isPair && !isset($value['role']) && !isset($value['type'])) {
            throw new InputFormatError(
                'Unable to coerce message from array: only human, AI, system, developer, or tool message coercion is currently supported.'
            );
        }

        return MessageUtils::coerceMessageLikeToMessage($value);
    }
}
