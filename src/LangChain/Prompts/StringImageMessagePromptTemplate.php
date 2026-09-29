<?php

declare(strict_types=1);

namespace LangChain\Prompts;

use LangChain\Messages\BaseMessage;
use LangChain\Messages\HumanMessage;

/**
 * Base class for the role-specific message templates.
 *
 * Port of `_StringImageMessagePromptTemplate` from `@langchain_core/prompts/chat`.
 *
 * A role message can be built from a plain string template or from a *list* of
 * content blocks — a text block and an image block, say, which a multimodal model
 * needs and a string cannot express. The two shapes are handled by the same
 * class, and `format()` returns a message whose content is either the rendered
 * string or the rendered block list.
 *
 * Subclasses declare the message class through {@see self::MESSAGE_CLASS}.
 */
abstract class StringImageMessagePromptTemplate extends BaseMessagePromptTemplate
{
    /** The message class this template produces. */
    public const MESSAGE_CLASS = HumanMessage::class;

    /**
     * Either one prompt, or a list of them rendered into content blocks.
     *
     * @var StringPromptTemplate|DictPromptTemplate|ImagePromptTemplate|list<StringPromptTemplate|DictPromptTemplate|ImagePromptTemplate>
     */
    public StringPromptTemplate|DictPromptTemplate|ImagePromptTemplate|array $prompt;

    /** @var array<string, mixed> Options carried from the `fromTemplate` call. */
    public array $additionalOptions = [];

    /**
     * @param StringPromptTemplate|DictPromptTemplate|ImagePromptTemplate|list<StringPromptTemplate|DictPromptTemplate|ImagePromptTemplate> $prompt
     * @param array<string, mixed>                                                                                                   $additionalOptions
     */
    public function __construct(
        StringPromptTemplate|DictPromptTemplate|ImagePromptTemplate|array $prompt,
        array $additionalOptions = [],
    ) {
        $this->prompt = $prompt;
        $this->additionalOptions = $additionalOptions;

        if (is_array($prompt)) {
            $inputVariables = [];
            foreach ($prompt as $item) {
                foreach ($item->inputVariables as $name) {
                    $inputVariables[] = $name;
                }
            }
            $this->inputVariables = $inputVariables;
            $this->kwargs = ['prompt' => $prompt, 'additional_options' => $additionalOptions];
        } else {
            $this->inputVariables = $prompt->inputVariables;
            $this->kwargs = ['prompt' => $prompt];
            if ($additionalOptions !== []) {
                $this->kwargs['additional_options'] = $additionalOptions;
            }
        }
    }

    /**
     * Render the message.
     *
     * @param array<string, mixed> $values
     */
    public function format(array $values): BaseMessage
    {
        if (!is_array($this->prompt)) {
            $text = $this->prompt->format($values);

            return $this->createMessage($text);
        }

        $content = [];
        foreach ($this->prompt as $prompt) {
            $inputs = [];
            foreach ($prompt->inputVariables as $name) {
                $inputs[$name] = $values[$name] ?? null;
            }

            $formatted = $prompt->format($inputs);
            $additional = $prompt->additionalContentFields();

            // `+` keeps the LEFT operand, so the rendered keys go first: the
            // extra fields are what a model expects to receive back, and the
            // rendered `type`/`text` are what this step owns.
            if ($prompt instanceof ImagePromptTemplate) {
                $content[] = ['type' => 'image_url', 'image_url' => $formatted] + $additional;

                continue;
            }

            if ($prompt instanceof DictPromptTemplate) {
                $content[] = $formatted + $additional;

                continue;
            }

            // An empty text block would leave a dangling gap in the message.
            if ($formatted !== '') {
                $content[] = ['type' => 'text', 'text' => $formatted] + $additional;
            }
        }

        return $this->createMessage($content);
    }

    /**
     * @param array<string, mixed> $values
     * @return list<BaseMessage>
     */
    public function formatMessages(array $values): array
    {
        return [$this->format($values)];
    }

    /**
     * Build a role message template from a string or a list of content blocks.
     *
     * A block is classified the way the TypeScript original classifies it, and
     * the classification is not what you would guess:
     *
     *  - a block whose ONLY key is `text` is a plain string template;
     *  - a block carrying an `image_url` (string, or a map with a `url`) is an
     *    image template;
     *  - everything else — including `{type: "text", text: …}` — is a nested
     *    dictionary template, because the extra keys are data the model expects
     *    to receive back untouched (`cache_control`, `mimeType`, and friends).
     *
     * @param string|list<mixed>   $template
     * @param array<string, mixed> $additionalOptions
     */
    public static function fromTemplate(string|array $template, array $additionalOptions = []): static
    {
        if (is_string($template)) {
            return new static(PromptTemplate::fromTemplate($template, $additionalOptions));
        }

        $prompts = [];
        foreach ($template as $item) {
            if ($item === null) {
                continue;
            }
            if (is_string($item)) {
                $prompts[] = PromptTemplate::fromTemplate($item, $additionalOptions);

                continue;
            }
            if (!is_array($item)) {
                continue;
            }

            if (count($item) === 1 && is_string($item['text'] ?? null)) {
                $prompts[] = PromptTemplate::fromTemplate(
                    $item['text'],
                    array_merge($additionalOptions, ['additionalContentFields' => $item])
                );

                continue;
            }

            if (self::isImageTemplateParam($item)) {
                $prompts[] = self::imagePromptFrom($item, $additionalOptions);

                continue;
            }

            $prompts[] = new DictPromptTemplate(
                template: $item,
                templateFormat: $additionalOptions['templateFormat'] ?? null,
            );
        }

        return new static($prompts, $additionalOptions);
    }

    /**
     * @param array<string, mixed> $item
     */
    private static function isImageTemplateParam(array $item): bool
    {
        if (!array_key_exists('image_url', $item)) {
            return false;
        }

        $imageUrl = $item['image_url'];
        if (is_string($imageUrl)) {
            return true;
        }

        return is_array($imageUrl) && is_string($imageUrl['url'] ?? null);
    }

    /**
     * Build the image template for one `image_url` content block.
     *
     * @param array<string, mixed> $item
     * @param array<string, mixed> $additionalOptions
     */
    private static function imagePromptFrom(array $item, array $additionalOptions): ImagePromptTemplate
    {
        $imageUrl = $item['image_url'] ?? '';
        $templateFormat = $additionalOptions['templateFormat'] ?? null;

        if (is_string($imageUrl)) {
            $variables = Template::templateVariables($imageUrl, (string) ($templateFormat ?? Template::F_STRING));
            if (count($variables) > 1) {
                throw new \RuntimeException(
                    "Only one format variable allowed per image template.\nGot: " . implode(',', $variables)
                    . "\nFrom: {$imageUrl}"
                );
            }

            return new ImagePromptTemplate(
                template: ['url' => $imageUrl],
                inputVariables: $variables,
                templateFormat: $templateFormat,
                additionalContentFields: $item,
            );
        }

        if (is_array($imageUrl)) {
            $url = is_string($imageUrl['url'] ?? null) ? $imageUrl['url'] : '';
            $variables = $url === ''
                ? []
                : Template::templateVariables($url, (string) ($templateFormat ?? Template::F_STRING));

            return new ImagePromptTemplate(
                template: $imageUrl,
                inputVariables: $variables,
                templateFormat: $templateFormat,
                additionalContentFields: $item,
            );
        }

        throw new \RuntimeException('Invalid image template');
    }

    /**
     * @param string|list<array<string, mixed>> $content
     */
    protected function createMessage(string|array $content): BaseMessage
    {
        $class = static::MESSAGE_CLASS;

        return new $class(['content' => $content]);
    }
}
