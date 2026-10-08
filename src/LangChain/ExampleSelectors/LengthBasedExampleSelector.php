<?php

declare(strict_types=1);

namespace LangChain\ExampleSelectors;

use LangChain\Prompts\PromptTemplate;

/**
 * Selects examples in order until their combined length reaches `maxLength`.
 *
 * Port of `LengthBasedExampleSelector` from `@langchain/core/example_selectors/length_based`.
 *
 * Length defaults to the number of words and lines (`text.split(/\n| /).length`).
 */
class LengthBasedExampleSelector extends BaseExampleSelector
{
    /** @var list<array<string, mixed>> */
    protected array $examples = [];

    public PromptTemplate $examplePrompt;

    /** @var callable(string): int */
    public $getTextLength;

    public int $maxLength = 2048;

    /** @var list<int> */
    public array $exampleTextLengths = [];

    /**
     * @param array{examplePrompt: PromptTemplate, maxLength?: int, getTextLength?: callable(string): int} $data
     */
    public function __construct(array $data)
    {
        $this->examplePrompt = $data['examplePrompt'];
        $this->maxLength = (int) ($data['maxLength'] ?? 2048);
        $this->getTextLength = $data['getTextLength'] ?? self::getLengthBased(...);
    }

    /**
     * Add an example and record its formatted length.
     *
     * @param array<string, mixed> $example
     */
    public function addExample(array $example): null
    {
        $this->examples[] = $example;
        $stringExample = $this->examplePrompt->format($example);
        $this->exampleTextLengths[] = ($this->getTextLength)($stringExample);

        return null;
    }

    /**
     * Lengths of the examples, computed when none were recorded.
     *
     * @param list<int> $v
     *
     * @return list<int>
     */
    public function calculateExampleTextLengths(array $v, self $values): array
    {
        if ($v !== []) {
            return $v;
        }

        return array_map(
            fn (array $eg): int => ($this->getTextLength)($values->examplePrompt->format($eg)),
            $values->examples,
        );
    }

    /**
     * @param array<string, mixed> $inputVariables
     *
     * @return list<array<string, mixed>>
     */
    public function selectExamples(array $inputVariables): array
    {
        $inputs = implode(' ', array_map(self::stringify(...), array_values($inputVariables)));
        $remainingLength = $this->maxLength - ($this->getTextLength)($inputs);
        $i = 0;
        $examples = [];

        while ($remainingLength > 0 && $i < count($this->examples)) {
            $newLength = $remainingLength - $this->exampleTextLengths[$i];
            if ($newLength < 0) {
                break;
            }
            $examples[] = $this->examples[$i];
            $remainingLength = $newLength;
            ++$i;
        }

        return $examples;
    }

    /**
     * @param list<array<string, mixed>> $examples
     * @param array{examplePrompt: PromptTemplate, maxLength?: int, getTextLength?: callable(string): int} $args
     */
    public static function fromExamples(array $examples, array $args): static
    {
        $selector = new static($args);
        foreach ($examples as $example) {
            $selector->addExample($example);
        }

        return $selector;
    }

    private static function getLengthBased(string $text): int
    {
        return count(preg_split('/\n| /', $text) ?: []);
    }

    /** JS `Array.join` stringification: null becomes the empty string. */
    private static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null => '',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) || $value instanceof \Stringable => (string) $value,
            default => (string) json_encode($value),
        };
    }
}
