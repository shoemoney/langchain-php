<?php

declare(strict_types=1);

namespace LangChain\Prompts;

use LangChain\Messages\AIMessage;
use LangChain\Messages\BaseMessage;
use LangChain\Messages\ChatMessage;
use LangChain\Messages\HumanMessage;
use LangChain\Messages\MessageUtils;
use LangChain\Messages\SystemMessage;
use LangChain\OutputParsers\BaseOutputParser;

/**
 * A conversation-shaped prompt.
 *
 * Port of `ChatPromptTemplate` from `@langchain_core/prompts/chat`.
 *
 * Built with {@see self::fromMessages()}, which accepts everything a caller might
 * reasonably have on hand — a template object, a plain string, a `[role, text]`
 * pair, a ready-made message, a `['placeholder', '{history}']` marker — and
 * normalises it all into message templates. The declared input variables are then
 * derived from whatever survived, so the caller does not have to keep a list in
 * sync with the messages.
 *
 * ```php
 * $chat = ChatPromptTemplate::fromMessages([
 *     ['ai', 'You are a helpful assistant.'],
 *     new SystemMessagePromptTemplate(PromptTemplate::fromTemplate('{text}')),
 *     new MessagesPlaceholder('history'),
 *     ['human', '{question}'],
 * ]);
 * ```
 *
 * Constructing a template validates the variable list against the messages: an
 * input variable no message reads, or a variable a message reads that the
 * template never declares, is a construction-time error. That check is what
 * stops the silent failure where a placeholder is never filled and the model
 * answers a question nobody asked.
 */
class ChatPromptTemplate extends BaseChatPromptTemplate
{
    /** The prompt-type key, e.g. 'prompt' or 'chat'. Overridden by subclasses. */
    public const PROMPT_TYPE = 'chat';

    /**
     * The messages this prompt renders, in order.
     *
     * @var list<BaseMessagePromptTemplate|BaseMessage>
     */
    public array $promptMessages = [];

    /** @var bool */
    public bool $validateTemplate = true;

    /** @var string */
    public string $templateFormat = Template::F_STRING;

    /**
     * @param list<BaseMessagePromptTemplate|BaseMessage> $promptMessages
     * @param list<string>                                 $inputVariables
     * @param array<string, string|callable>               $partialVariables
     * @param array<string, mixed>|null                    $templateFormat
     * @throws \RuntimeException when the variable list does not match the messages
     */
    public function __construct(
        array $promptMessages = [],
        array $inputVariables = [],
        array $partialVariables = [],
        ?BaseOutputParser $outputParser = null,
        ?bool $validateTemplate = null,
        ?string $templateFormat = null,
        array $metadata = [],
        array $tags = [],
    ) {
        parent::__construct($inputVariables, $partialVariables, $outputParser, $metadata, $tags);

        $this->promptMessages = array_values($promptMessages);
        $this->validateTemplate = $templateFormat === Template::MUSTACHE && $validateTemplate === null
            ? false
            : ($validateTemplate ?? true);
        $this->templateFormat = $templateFormat ?? Template::F_STRING;

        $this->kwargs = [
            'input_variables' => $this->inputVariables,
            'messages' => $this->promptMessages,
            'template_format' => $this->templateFormat === Template::F_STRING ? null : $this->templateFormat,
        ];

        if ($this->validateTemplate) {
            $this->assertVariablesMatchMessages();
        }
    }

    /**
     * Build a chat prompt from a list of message-like values.
     *
     * A nested {@see ChatPromptTemplate} is flattened rather than nested, and its
     * partial variables are merged into this one — which is what makes a
     * partially-bound inner prompt composable into an outer one.
     *
     * @param list<mixed>         $promptMessages
     * @param array<string, mixed> $extra
     */
    public static function fromMessages(array $promptMessages, array $extra = []): static
    {
        $flattened = [];
        $flattenedPartialVariables = [];

        foreach ($promptMessages as $promptMessage) {
            if ($promptMessage instanceof self) {
                foreach ($promptMessage->promptMessages as $inner) {
                    $flattened[] = $inner;
                }
                foreach ($promptMessage->partialVariables as $key => $value) {
                    $flattenedPartialVariables[$key] = $value;
                }

                continue;
            }

            $flattened[] = self::coerceMessagePromptTemplateLike($promptMessage, $extra);
        }

        $inputVariables = [];
        foreach ($flattened as $promptMessage) {
            if ($promptMessage instanceof BaseMessage) {
                continue;
            }
            foreach ($promptMessage->inputVariables as $name) {
                if (array_key_exists($name, $flattenedPartialVariables)) {
                    continue;
                }
                $inputVariables[$name] = true;
            }
        }

        return new static(
            promptMessages: $flattened,
            inputVariables: array_keys($inputVariables),
            partialVariables: $flattenedPartialVariables,
            validateTemplate: $extra['validateTemplate'] ?? null,
            templateFormat: $extra['templateFormat'] ?? null,
            outputParser: $extra['outputParser'] ?? null,
        );
    }

    /**
     * Build a chat prompt from one human-message template.
     *
     * @param array<string, mixed> $options
     */
    public static function fromTemplate(string $template, array $options = []): static
    {
        $prompt = PromptTemplate::fromTemplate($template, $options);

        return static::fromMessages([new HumanMessagePromptTemplate($prompt)]);
    }

    /**
     * Bind values ahead of time, returning a new template.
     *
     * @param array<string, string|callable> $values
     */
    public function partial(array $values): static
    {
        $newInputVariables = array_values(array_filter(
            $this->inputVariables,
            static fn (string $name): bool => !array_key_exists($name, $values)
        ));

        return new static(
            promptMessages: $this->promptMessages,
            inputVariables: $newInputVariables,
            partialVariables: array_merge($this->partialVariables, $values),
            outputParser: $this->outputParser,
            validateTemplate: $this->validateTemplate,
            templateFormat: $this->templateFormat,
            metadata: $this->metadata,
            tags: $this->tags,
        );
    }

    /**
     * Render the conversation.
     *
     * @param array<string, mixed> $values
     * @return list<BaseMessage>
     * @throws PromptInputError
     */
    public function formatMessages(array $values): array
    {
        $allValues = $this->mergePartialAndUserVariables($values);
        $result = [];

        foreach ($this->promptMessages as $promptMessage) {
            if ($promptMessage instanceof BaseMessage) {
                $result[] = $this->parseImagePrompts($promptMessage, $allValues);

                continue;
            }

            if ($this->templateFormat === Template::MUSTACHE) {
                $inputValues = $allValues;
            } else {
                $inputValues = [];
                foreach ($promptMessage->inputVariables as $inputVariable) {
                    // An optional placeholder is allowed to be absent; anything
                    // else is a caller mistake and names itself in the error.
                    $isAbsentOptional = $promptMessage instanceof MessagesPlaceholder && $promptMessage->optional;
                    if (!array_key_exists($inputVariable, $allValues) && !$isAbsentOptional) {
                        throw new PromptInputError("Missing value for input variable `{$inputVariable}`");
                    }
                    $inputValues[$inputVariable] = $allValues[$inputVariable] ?? null;
                }
            }

            foreach ($promptMessage->formatMessages($inputValues) as $message) {
                $result[] = $message;
            }
        }

        return $result;
    }

    /**
     * Bind values ahead of time, returning a new template.
     *
     * @param array<string, mixed> $values
     */
    private function parseImagePrompts(BaseMessage $message, array $values): BaseMessage
    {
        if (is_string($message->content)) {
            return $message;
        }

        $blocks = [];
        foreach ($message->content as $block) {
            if (!is_array($block) || ($block['type'] ?? null) !== 'image_url') {
                $blocks[] = $block;

                continue;
            }

            $imageUrl = $block['image_url'] ?? null;
            $url = is_string($imageUrl)
                ? $imageUrl
                : (is_array($imageUrl) && is_string($imageUrl['url'] ?? null) ? $imageUrl['url'] : '');

            $formatted = PromptTemplate::fromTemplate($url, [
                'templateFormat' => $this->templateFormat,
            ])->format($values);

            if (is_array($imageUrl) && array_key_exists('url', $imageUrl)) {
                $imageUrl['url'] = $formatted;
                $block['image_url'] = $imageUrl;
            } else {
                $block['image_url'] = $formatted;
            }

            $blocks[] = $block;
        }

        $message->content = $blocks;

        return $message;
    }

    /**
     * Reject a variable list that does not match the messages.
     *
     * @throws \RuntimeException
     */
    private function assertVariablesMatchMessages(): void
    {
        $messageVariables = [];
        foreach ($this->promptMessages as $promptMessage) {
            if ($promptMessage instanceof BaseMessage) {
                continue;
            }
            foreach ($promptMessage->inputVariables as $name) {
                $messageVariables[$name] = true;
            }
        }

        $declared = [];
        foreach ($this->inputVariables as $name) {
            $declared[$name] = true;
        }
        foreach (array_keys($this->partialVariables) as $name) {
            $declared[$name] = true;
        }

        $unused = array_values(array_diff(array_keys($declared), array_keys($messageVariables)));
        if ($unused !== []) {
            throw new \RuntimeException(
                'Input variables `' . implode(',', $unused) . '` are not used in any of the prompt messages.'
            );
        }

        $unread = array_values(array_diff(array_keys($messageVariables), array_keys($declared)));
        if ($unread !== []) {
            throw new \RuntimeException(
                'Input variables `' . implode(',', $unread) . '` are used in prompt messages but not in the prompt template.'
            );
        }
    }

    /**
     * Normalise one caller-supplied message into a template or a message.
     *
     * @param array<string, mixed> $extra
     */
    private static function coerceMessagePromptTemplateLike(mixed $like, array $extra): BaseMessagePromptTemplate|BaseMessage
    {
        if ($like instanceof BaseMessagePromptTemplate || $like instanceof BaseMessage) {
            return $like;
        }

        if (is_array($like) && ($like[0] ?? null) === 'placeholder') {
            $content = $like[1] ?? null;
            $templateFormat = $extra['templateFormat'] ?? null;

            if ($templateFormat === Template::MUSTACHE
                && is_string($content)
                && str_starts_with($content, '{{')
                && str_ends_with($content, '}}')
            ) {
                return new MessagesPlaceholder(substr($content, 2, -2), true);
            }

            if (is_string($content) && str_starts_with($content, '{') && str_ends_with($content, '}')) {
                return new MessagesPlaceholder(substr($content, 1, -1), true);
            }

            $double = $templateFormat === Template::MUSTACHE ? 'double' : 'single';
            $shownFormat = $templateFormat ?? '"f-string"';
            $shown = is_string($content) ? $content : json_encode($content);

            throw new \RuntimeException(
                "Invalid placeholder template for format {$shownFormat}: \"{$shown}\". Expected a variable name surrounded by {$double} curly braces."
            );
        }

        $message = MessageUtils::coerceMessageLikeToMessage($like);

        $templateData = $message->content;
        $type = $message->getType();

        if ($type === BaseMessage::ROLE_HUMAN) {
            return HumanMessagePromptTemplate::fromTemplate($templateData, $extra);
        }
        if ($type === BaseMessage::ROLE_AI) {
            return AIMessagePromptTemplate::fromTemplate($templateData, $extra);
        }
        if ($type === BaseMessage::ROLE_SYSTEM) {
            return SystemMessagePromptTemplate::fromTemplate($templateData, $extra);
        }
        if ($message instanceof ChatMessage) {
            return ChatMessagePromptTemplate::fromTemplate(
                is_string($templateData) ? $templateData : '',
                $type,
                $extra
            );
        }

        throw new \RuntimeException(
            "Could not coerce message prompt template from input. Received message type: \"{$type}\"."
        );
    }
}
