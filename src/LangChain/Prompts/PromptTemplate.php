<?php

declare(strict_types=1);

namespace LangChain\Prompts;

use LangChain\OutputParsers\BaseOutputParser;
use LangChain\Schema\PromptValue;

/**
 * A plain string prompt.
 *
 * Port of `PromptTemplate` from `@langchain_core/prompts/prompt`.
 *
 * The workhorse: `'{foo} and {bar}'` plus `['foo', 'bar']`. Two behaviours are
 * worth knowing before you rely on it.
 *
 * **Validation is a constructor-time check, not a runtime one.** The template is
 * rendered against dummy values built from the declared input variables, so a
 * typo in a variable name fails when you build the template rather than three
 * layers into a chain. Turning it off (`validateTemplate: false`) is what the
 * Mustache path does automatically, because Mustache tolerates a shape that
 * f-string validation rejects.
 *
 * **Non-string values are JSON-encoded.** Passing a `Document` to `{context}`
 * yields its JSON rather than a PHP notice about string concatenation, which is
 * what makes "here are the retrieved documents" prompts work.
 */
class PromptTemplate extends StringPromptTemplate
{
    public const PROMPT_TYPE = 'prompt';

    /**
     * The template itself: a string, or a list of message-content blocks.
     *
     * @var string|list<array<string, mixed>>
     */
    public string|array $template = '';

    /** @var self::F_STRING|self::MUSTACHE|non-empty-string */
    public string $templateFormat = Template::F_STRING;

    /** Whether the template is checked against the declared variables at construction. */
    public bool $validateTemplate = true;

    /**
     * Extra fields carried into a message-content block alongside the rendered text.
     *
     * @var array<string, mixed>|null
     */
    public ?array $additionalContentFields = null;

    /**
     * @param string|list<array<string, mixed>> $template
     * @param list<string>                      $inputVariables
     * @param array<string, string|callable>    $partialVariables
     * @param array<string, mixed>|null         $additionalContentFields
     * @throws \RuntimeException when `$validateTemplate` is on and the template is wrong
     */
    public function __construct(
        string|array $template,
        array $inputVariables = [],
        array $partialVariables = [],
        ?BaseOutputParser $outputParser = null,
        ?string $templateFormat = null,
        ?bool $validateTemplate = null,
        ?array $additionalContentFields = null,
        array $metadata = [],
        array $tags = [],
    ) {
        parent::__construct($inputVariables, $partialVariables, $outputParser, $metadata, $tags);

        $this->template = $template;

        // Mustache templates are validated by the mustache parser, not by the
        // f-string one, so validation defaults off for them.
        $this->validateTemplate = $templateFormat === Template::MUSTACHE && $validateTemplate === null
            ? false
            : ($validateTemplate ?? true);

        $this->templateFormat = $templateFormat ?? Template::F_STRING;
        $this->additionalContentFields = $additionalContentFields;

        $this->kwargs = [
            'input_variables' => $this->inputVariables,
            'template' => $this->template,
            'template_format' => $this->templateFormat,
        ];

        if ($this->validateTemplate) {
            if ($this->templateFormat === Template::MUSTACHE) {
                throw new \RuntimeException('Mustache templates cannot be validated.');
            }

            Template::checkValidTemplate(
                $this->template,
                $this->templateFormat,
                array_merge($this->inputVariables, array_keys($this->partialVariables))
            );
        }
    }

    public function getPromptType(): string
    {
        return 'prompt';
    }

    /**
     * @param array<string, mixed> $values
     */
    public function format(array $values): string
    {
        return Template::renderTemplate(
            $this->stringTemplate(),
            $this->templateFormat,
            $this->mergePartialAndUserVariables($values)
        );
    }

    /**
     * Build a template from its own text, inferring the input variables.
     *
     * This is the convenient constructor: it reads the `{name}` markers out of
     * the template instead of asking you to repeat them.
     *
     * @param array<string, mixed> $options
     */
    public static function fromTemplate(string $template, array $options = []): self
    {
        $templateFormat = $options['templateFormat'] ?? Template::F_STRING;

        return new self(
            template: $template,
            inputVariables: Template::templateVariables($template, (string) $templateFormat),
            templateFormat: $templateFormat,
            validateTemplate: $options['validateTemplate'] ?? null,
            outputParser: $options['outputParser'] ?? null,
            partialVariables: $options['partialVariables'] ?? [],
            additionalContentFields: $options['additionalContentFields'] ?? null,
        );
    }

    /**
     * Build a template from a list of examples plus a suffix.
     *
     * The classic few-shot layout: examples, a separator, then the question the
     * model is meant to answer.
     *
     * @param list<string> $examples
     * @param list<string> $inputVariables
     */
    public static function fromExamples(
        array $examples,
        string $suffix,
        array $inputVariables,
        string $exampleSeparator = "\n\n",
        string $prefix = '',
    ): self {
        return new self(
            template: implode($exampleSeparator, [$prefix, ...$examples, $suffix]),
            inputVariables: $inputVariables,
        );
    }

    /**
     * Bind values ahead of time, returning a new template.
     *
     * The original is untouched, which is what makes a partially-bound template
     * safe to store and reuse with different values.
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
            template: $this->template,
            inputVariables: $newInputVariables,
            partialVariables: array_merge($this->partialVariables, $values),
            outputParser: $this->outputParser,
            templateFormat: $this->templateFormat,
            validateTemplate: $this->validateTemplate,
            additionalContentFields: $this->additionalContentFields,
            metadata: $this->metadata,
            tags: $this->tags,
        );
    }

    /**
     * The serialized form of this template.
     *
     * @return array{_type: string, input_variables: list<string>, template: string|list<array<string, mixed>>, template_format: string}
     */
    public function serialize(): array
    {
        if ($this->outputParser !== null) {
            throw new \RuntimeException('Cannot serialize a prompt template with an output parser');
        }

        // `additional_content_fields` is carried, and only when set.
        //
        // These are the provider-specific extras a caller attaches to a prompt —
        // `cache_control`, image detail, a vendor marker — and they were dropped
        // on serialisation. Measured: a template built with
        // `['provider' => 'acme']` came back from `deserialize()` with `null`.
        // The round-trip SUCCEEDED, which is what makes it worth fixing: nothing
        // errors, the rebuilt template simply no longer carries the extras it was
        // given, and the loss surfaces much later as a provider that stops
        // caching or stops rendering a block.
        //
        // Partial variable VALUES are deliberately NOT carried, and that is
        // upstream's own choice rather than an omission here: prompts/base.ts
        // returns `{ partialVariables: undefined }` from `lc_attributes` with the
        // comment "python doesn't support this yet".
        $out = [
            '_type' => $this->getPromptType(),
            'input_variables' => $this->inputVariables,
            'template' => $this->template,
            'template_format' => $this->templateFormat,
        ];
        if ($this->additionalContentFields !== null && $this->additionalContentFields !== []) {
            $out['additional_content_fields'] = $this->additionalContentFields;
        }

        return $out;
    }

    /**
     * Rebuild a template from {@see self::serialize()} output.
     *
     * @param array{input_variables?: list<string>, template?: string|list<array<string, mixed>>, template_format?: string} $data
     */
    public static function deserialize(array $data): self
    {
        if (!isset($data['template']) || $data['template'] === '') {
            throw new \RuntimeException('Prompt template must have a template');
        }

        return new self(
            template: $data['template'],
            inputVariables: $data['input_variables'] ?? [],
            templateFormat: $data['template_format'] ?? null,
            additionalContentFields: $data['additional_content_fields'] ?? null,
        );
    }

    /** @return array<string, mixed> */
    public function additionalContentFields(): array
    {
        return $this->additionalContentFields ?? [];
    }

    /**
     * The text of the template.
     *
     * A content-block template renders each block's `text` field and joins them,
     * which is the only sensible flattening for a completion model.
     */
    private function stringTemplate(): string
    {
        if (is_string($this->template)) {
            return $this->template;
        }

        $parts = [];
        foreach ($this->template as $block) {
            if (is_array($block) && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        return implode('', $parts);
    }
}
