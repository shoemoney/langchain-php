<?php

declare(strict_types=1);

namespace LangChain\Prompts;

use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableConfig;

/**
 * A prompt whose template is a nested structure rather than a string.
 *
 * Port of `DictPromptTemplate` from `@langchain_core/prompts/dict`.
 *
 * Content blocks are not always `{type, text}` — they carry provider-specific
 * extras (`cache_control`, `mimeType`, an audio source) that must survive
 * interpolation untouched. So this walks the structure and renders only the
 * string leaves, leaving everything else exactly as it was. The input variables
 * it reports are the union of the names found anywhere in that walk.
 */
class DictPromptTemplate extends Runnable implements \JsonSerializable
{
    /** @var array<string, mixed> */
    public array $template;

    /** @var string */
    public string $templateFormat;

    /** @var list<string> */
    public array $inputVariables;

    /**
     * @param array<string, mixed> $template
     * @param string|null          $templateFormat
     */
    public function __construct(array $template, ?string $templateFormat = null)
    {
        $this->templateFormat = $templateFormat ?? Template::F_STRING;
        $this->template = $template;
        $this->inputVariables = self::extractInputVariables($template, $this->templateFormat);
    }

    /** @return list<string> */
    public static function lcId(): array
    {
        return ['langchain_core', 'prompts', 'dict', 'DictPromptTemplate'];
    }

    /**
     * A dict template has no extra fields of its own: everything it carries is
     * already inside the template map it renders.
     *
     * @return array<string, mixed>
     */
    public function additionalContentFields(): array
    {
        return [];
    }

    /**
     * Render every string leaf of the template.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function format(array $values): array
    {
        return self::insertInputVariables($this->template, $values, $this->templateFormat);
    }

    /**
     * @param array<string, mixed>|mixed $input
     * @return array<string, mixed>
     */
    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        return $this->format(is_array($input) ? $input : []);
    }

    /**
     * Every variable the template reads, walking nested arrays.
     *
     * @param array<string, mixed> $template
     * @return list<string>
     */
    private static function extractInputVariables(array $template, string $templateFormat): array
    {
        $names = [];

        foreach ($template as $value) {
            if (is_string($value)) {
                foreach (Template::templateVariables($value, $templateFormat) as $name) {
                    $names[$name] = true;
                }
            } elseif (is_array($value)) {
                if (array_is_list($value)) {
                    foreach ($value as $item) {
                        if (is_string($item)) {
                            foreach (Template::templateVariables($item, $templateFormat) as $name) {
                                $names[$name] = true;
                            }
                        } elseif (is_array($item)) {
                            foreach (self::extractInputVariables($item, $templateFormat) as $name) {
                                $names[$name] = true;
                            }
                        }
                    }

                    continue;
                }

                foreach (self::extractInputVariables($value, $templateFormat) as $name) {
                    $names[$name] = true;
                }
            }
        }

        return array_keys($names);
    }

    /**
     * @param array<string, mixed> $template
     * @param array<string, mixed> $inputs
     * @return array<string, mixed>
     */
    private static function insertInputVariables(array $template, array $inputs, string $templateFormat): array
    {
        $formatted = [];

        foreach ($template as $key => $value) {
            if (is_string($value)) {
                $formatted[$key] = Template::renderTemplate($value, $templateFormat, $inputs);

                continue;
            }

            if (!is_array($value)) {
                $formatted[$key] = $value;

                continue;
            }

            if (array_is_list($value)) {
                $items = [];
                foreach ($value as $item) {
                    if (is_string($item)) {
                        $items[] = Template::renderTemplate($item, $templateFormat, $inputs);
                    } elseif (is_array($item)) {
                        $items[] = self::insertInputVariables($item, $inputs, $templateFormat);
                    }
                }
                $formatted[$key] = $items;

                continue;
            }

            $formatted[$key] = self::insertInputVariables($value, $inputs, $templateFormat);
        }

        return $formatted;
    }

    /**
     * @return array{lc: int, type: string, id: list<string>, kwargs: array<string, mixed>}
     */
    public function toJson(): array
    {
        return [
            'lc' => 1,
            'type' => 'constructor',
            'id' => static::lcId(),
            'kwargs' => [
                'input_variables' => $this->inputVariables,
                'template' => $this->template,
                'template_format' => $this->templateFormat,
            ],
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toJson();
    }
}
