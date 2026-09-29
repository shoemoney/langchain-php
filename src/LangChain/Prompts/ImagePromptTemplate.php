<?php

declare(strict_types=1);

namespace LangChain\Prompts;

use LangChain\OutputParsers\BaseOutputParser;
use LangChain\Schema\PromptValue;
use LangChain\Schema\StringPromptValue;

/**
 * An image reference for a multimodal model.
 *
 * Port of `ImagePromptTemplate` from `@langchain_core/prompts/image`.
 *
 * The template is a map — `{url, detail}` — and every *string* leaf of it is
 * interpolated, which is what lets a data URL or a signed URL carry a variable
 * (`{myUrl}`) while `detail: 'high'` passes through untouched.
 *
 * A note on `formatPromptValue()`: the TypeScript original returns an
 * `ImagePromptValue`, which has no counterpart in this port's
 * {@see PromptValue} hierarchy. Since an image template is only ever used as one
 * step of a message's content, this returns the URL as a
 * {@see StringPromptValue} instead.
 */
class ImagePromptTemplate extends BasePromptTemplate
{
    public const PROMPT_TYPE = 'prompt';

    /** @var array<string, mixed> */
    public array $template;

    /** @var string */
    public string $templateFormat = Template::F_STRING;

    /** @var bool */
    public bool $validateTemplate = true;

    /** @var array<string, mixed>|null */
    public ?array $additionalContentFields = null;

    /**
     * @param array<string, mixed> $template
     * @param list<string>          $inputVariables
     */
    public function __construct(
        array $template,
        array $inputVariables = [],
        array $partialVariables = [],
        ?BaseOutputParser $outputParser = null,
        ?string $templateFormat = null,
        ?bool $validateTemplate = null,
        ?array $additionalContentFields = null,
    ) {
        parent::__construct($inputVariables, $partialVariables, $outputParser);

        $this->template = $template;
        $this->templateFormat = $templateFormat ?? $this->templateFormat;
        $this->validateTemplate = $validateTemplate ?? $this->validateTemplate;
        $this->additionalContentFields = $additionalContentFields;

        $this->kwargs = [
            'input_variables' => $this->inputVariables,
            'template' => $this->template,
            'template_format' => $this->templateFormat,
        ];

        if ($this->validateTemplate) {
            Template::checkValidTemplate(
                [['type' => 'image_url', 'image_url' => $this->template]],
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
     * Render the image reference.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function format(array $values): array
    {
        $formatted = [];
        foreach ($this->template as $key => $value) {
            $formatted[$key] = is_string($value)
                ? Template::renderTemplate($value, $this->templateFormat, $values)
                : $value;
        }

        $url = $values['url'] ?? $formatted['url'] ?? null;
        $detail = $values['detail'] ?? $formatted['detail'] ?? null;

        if (!$url) {
            throw new \RuntimeException('Must provide either an image URL.');
        }
        if (!is_string($url)) {
            throw new \RuntimeException('url must be a string.');
        }

        $output = ['url' => $url];
        if ($detail) {
            $output['detail'] = $detail;
        }

        return $output;
    }

    /**
     * @param array<string, mixed> $values
     */
    public function formatPromptValue(array $values): PromptValue
    {
        $formatted = $this->format($values);

        return new StringPromptValue((string) ($formatted['url'] ?? ''));
    }

    /** @return array<string, mixed> */
    public function additionalContentFields(): array
    {
        return $this->additionalContentFields ?? [];
    }

    /**
     * @param array<string, string|callable> $values
     */
    public function partial(array $values): static
    {
        return new static(
            template: $this->template,
            inputVariables: array_values(array_filter(
                $this->inputVariables,
                static fn (string $name): bool => !array_key_exists($name, $values)
            )),
            partialVariables: array_merge($this->partialVariables, $values),
            outputParser: $this->outputParser,
            templateFormat: $this->templateFormat,
            validateTemplate: $this->validateTemplate,
            additionalContentFields: $this->additionalContentFields,
        );
    }
}
