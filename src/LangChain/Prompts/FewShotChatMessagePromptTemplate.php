<?php

declare(strict_types=1);

namespace LangChain\Prompts;

use LangChain\ExampleSelectors\BaseExampleSelector;
use LangChain\Messages\BaseMessage;
use LangChain\OutputParsers\BaseOutputParser;

/**
 * Port of `FewShotChatMessagePromptTemplate` from core `prompts/few_shot.ts`.
 * formatMessages() contributes only example messages; format() additionally
 * joins prefix/suffix and interpolates the joined message contents.
 */
class FewShotChatMessagePromptTemplate extends BaseChatPromptTemplate
{
    public const PROMPT_TYPE = 'few_shot_chat';

    /**
     * @param list<array<string, mixed>>|null $examples null means not provided
     * @param list<string> $inputVariables
     * @param array<string, string|callable> $partialVariables
     */
    public function __construct(
        public BaseMessagePromptTemplate|BaseChatPromptTemplate $examplePrompt,
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

        $this->kwargs = [
            'input_variables' => $this->inputVariables,
            'example_prompt' => $this->examplePrompt,
            'examples' => $this->examples,
            'example_selector' => $this->exampleSelector,
            'prefix' => $this->prefix,
            'suffix' => $this->suffix,
            'example_separator' => $this->exampleSeparator,
            'template_format' => $this->templateFormat,
            'output_parser' => $this->outputParser,
        ];
    }

    public function getPromptType(): string
    {
        return 'few_shot_chat';
    }

    /**
     * Only the example prompt's input variables are supplied to each example.
     * Top-level values choose examples; they do not replace example fields.
     *
     * @param array<string, mixed> $values
     * @return list<BaseMessage>
     */
    public function formatMessages(array $values): array
    {
        $messages = [];
        foreach ($this->getExamples($this->mergePartialAndUserVariables($values)) as $example) {
            $inputs = [];
            foreach ($this->examplePrompt->inputVariables as $name) {
                if (array_key_exists($name, $example)) {
                    $inputs[$name] = $example[$name];
                }
                // JS stores undefined for an absent field. PHP represents that
                // missing value by omission so the existing prompt reports it.
            }
            array_push($messages, ...$this->examplePrompt->formatMessages($inputs));
        }

        return $messages;
    }

    /** @param array<string, mixed> $values */
    public function format(array $values): string
    {
        $allValues = $this->mergePartialAndUserVariables($values);
        $exampleStrings = [];
        foreach ($this->getExamples($allValues) as $example) {
            foreach ($this->examplePrompt->formatMessages($example) as $message) {
                $exampleStrings[] = self::joinContent($message->content);
            }
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

    /** Match JS Array.join coercion for message-content arrays. */
    private static function joinContent(mixed $content): string
    {
        if (is_array($content)) {
            return array_is_list($content)
                ? implode(',', array_map(self::joinContent(...), $content))
                : '[object Object]';
        }

        return match (true) {
            $content === null => '',
            is_bool($content) => $content ? 'true' : 'false',
            is_object($content) => '[object Object]',
            default => (string) $content,
        };
    }
}
