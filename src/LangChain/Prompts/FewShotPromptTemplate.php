<?php

declare(strict_types=1);

namespace LangChain\Prompts;

use LangChain\ExampleSelectors\BaseExampleSelector;
use LangChain\OutputParsers\BaseOutputParser;

/**
 * Port of core `prompts/few_shot.ts`: render examples, join all pieces, then
 * interpolate the resulting template with the caller and partial values.
 * Empty prefix/suffix pieces are retained, including their separators.
 */
class FewShotPromptTemplate extends StringPromptTemplate
{
    public const PROMPT_TYPE = 'few_shot';

    /**
     * @param list<array<string, mixed>>|null $examples null means not provided
     * @param list<string> $inputVariables
     * @param array<string, string|callable> $partialVariables
     */
    public function __construct(
        public PromptTemplate $examplePrompt,
        public ?array $examples = null,
        public ?BaseExampleSelector $exampleSelector = null,
        array $inputVariables = [],
        array $partialVariables = [],
        public string $prefix = '',
        public string $suffix = '',
        public string $exampleSeparator = "\n\n",
        public string $templateFormat = Template::F_STRING,
        public bool $validateTemplate = true,
        ?BaseOutputParser $outputParser = null,
        array $metadata = [],
        array $tags = [],
    ) {
        parent::__construct($inputVariables, $partialVariables, $outputParser, $metadata, $tags);

        if ($this->examples !== null && $this->exampleSelector !== null) {
            throw new \InvalidArgumentException("Only one of 'examples' and 'example_selector' should be provided");
        }
        if ($this->examples === null && $this->exampleSelector === null) {
            throw new \InvalidArgumentException("One of 'examples' and 'example_selector' should be provided");
        }
        if ($this->validateTemplate) {
            Template::checkValidTemplate(
                $this->prefix . $this->suffix,
                $this->templateFormat,
                array_merge($this->inputVariables, array_keys($this->partialVariables)),
            );
        }
    }

    public function getPromptType(): string
    {
        return 'few_shot';
    }

    /** @param array<string, mixed> $values */
    public function format(array $values): string
    {
        $allValues = $this->mergePartialAndUserVariables($values);
        $exampleStrings = [];
        foreach ($this->getExamples($allValues) as $example) {
            $exampleStrings[] = $this->examplePrompt->format($example);
        }

        return Template::renderTemplate(
            implode($this->exampleSeparator, [$this->prefix, ...$exampleStrings, $this->suffix]),
            $this->templateFormat,
            $allValues,
        );
    }

    /** @param array<string, string|callable> $values */
    public function partial(array $values): static
    {
        return new static(
            examplePrompt: $this->examplePrompt,
            examples: $this->examples,
            exampleSelector: $this->exampleSelector,
            inputVariables: array_values(array_filter($this->inputVariables, static fn (string $name): bool => !array_key_exists($name, $values))),
            partialVariables: array_merge($this->partialVariables, $values),
            prefix: $this->prefix,
            suffix: $this->suffix,
            exampleSeparator: $this->exampleSeparator,
            templateFormat: $this->templateFormat,
            validateTemplate: $this->validateTemplate,
            outputParser: $this->outputParser,
            metadata: $this->metadata,
            tags: $this->tags,
        );
    }

    /**
     * Legacy prompt serialization supports explicit examples only. As upstream,
     * callable/static partial values are not carried in this serialized shape.
     *
     * @return array<string, mixed>
     */
    public function serialize(): array
    {
        if ($this->exampleSelector !== null || $this->examples === null) {
            throw new \RuntimeException('Serializing an example selector is not currently supported');
        }
        if ($this->outputParser !== null) {
            throw new \RuntimeException('Serializing an output parser is not currently supported');
        }

        return [
            '_type' => $this->getPromptType(),
            'input_variables' => $this->inputVariables,
            'example_prompt' => $this->examplePrompt->serialize(),
            'example_separator' => $this->exampleSeparator,
            'suffix' => $this->suffix,
            'prefix' => $this->prefix,
            'template_format' => $this->templateFormat,
            'examples' => $this->examples,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function deserialize(array $data): self
    {
        if (!isset($data['example_prompt'])) {
            throw new \RuntimeException('Missing example prompt');
        }
        $examplePrompt = PromptTemplate::deserialize($data['example_prompt']);
        if (!is_array($data['examples'] ?? null) || !array_is_list($data['examples'])) {
            throw new \RuntimeException('Invalid examples format. Only list or string are supported.');
        }

        return new self(
            examplePrompt: $examplePrompt,
            examples: $data['examples'],
            inputVariables: $data['input_variables'] ?? [],
            prefix: $data['prefix'] ?? '',
            suffix: $data['suffix'] ?? '',
            exampleSeparator: $data['example_separator'] ?? "\n\n",
            templateFormat: $data['template_format'] ?? Template::F_STRING,
        );
    }

    /** Upstream disables constructor JSON serialization for the string form. */
    public function toJson(): array
    {
        return ['lc' => 1, 'type' => 'not_implemented', 'id' => static::lcId()];
    }

    /**
     * @param array<string, mixed> $values
     * @return list<array<string, mixed>>
     */
    private function getExamples(array $values): array
    {
        if ($this->examples !== null) {
            return $this->examples;
        }
        if ($this->exampleSelector !== null) {
            return $this->exampleSelector->selectExamples($values);
        }

        throw new \RuntimeException("One of 'examples' and 'example_selector' should be provided");
    }
}
