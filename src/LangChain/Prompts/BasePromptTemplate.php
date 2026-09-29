<?php

declare(strict_types=1);

namespace LangChain\Prompts;

use LangChain\OutputParsers\BaseOutputParser;
use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnableConfig;
use LangChain\Schema\PromptValue;

/**
 * Base class for every prompt template.
 *
 * Port of `BasePromptTemplate` from `@langchain_core/prompts/base`.
 *
 * A prompt template is a {@see Runnable} whose input is a bag of values and
 * whose output is a {@see PromptValue} — a prompt that has been rendered but
 * not yet sent anywhere. Three renderings of the same template exist and they
 * are not interchangeable:
 *
 *  - `format()` → a string, for a completion model;
 *  - `formatMessages()` → a message list, for a chat model;
 *  - `formatPromptValue()` → a `PromptValue`, which a model then decides how to
 *    read. This is the one `invoke()` returns, and the reason one chain can
 *    serve both kinds of model.
 *
 * `partialVariables` are values bound ahead of time — a system prompt baked into
 * a template that otherwise takes user input. They are merged *under* the
 * caller's values, so a caller can always override a partial, and a value may be
 * a callable for the ones that are expensive to compute.
 *
 * @template TPartialVariableName of string
 * @extends Runnable<array<string, mixed>, PromptValue>
 */
abstract class BasePromptTemplate extends Runnable implements \JsonSerializable
{
    /** The prompt-type key, e.g. 'prompt' or 'chat'. Overridden by subclasses. */
    public const PROMPT_TYPE = '';

    /**
     * The variable names this template expects the caller to supply.
     *
     * @var list<string>
     */
    public array $inputVariables = [];

    /**
     * Values bound ahead of time.
     *
     * A string is used as-is; a callable is invoked at merge time, which is how
     * a partial can carry something expensive (a retrieved context, a rendered
     * few-shot block) without paying for it on every construction.
     *
     * @var array<string, string|callable>
     */
    public array $partialVariables = [];

    /**
     * How to parse this prompt's output.
     *
     * Nothing runs it automatically — it exists so a chain can reach the parser
     * the caller already configured, rather than declaring a second one.
     */
    public ?BaseOutputParser $outputParser = null;

    /** @var array<string, mixed> Metadata surfaced in traces. */
    public array $metadata = [];

    /** @var list<string> Trace labels. */
    public array $tags = [];

    /**
     * @param list<string>                    $inputVariables
     * @param array<string, string|callable>  $partialVariables
     */
    public function __construct(
        array $inputVariables = [],
        array $partialVariables = [],
        ?BaseOutputParser $outputParser = null,
        array $metadata = [],
        array $tags = [],
    ) {
        foreach ($inputVariables as $name) {
            if ($name === 'stop') {
                throw new \InvalidArgumentException(
                    "Cannot have an input variable named 'stop', as it is used internally, please rename."
                );
            }
        }

        $this->inputVariables = array_values($inputVariables);
        $this->partialVariables = $partialVariables;
        $this->outputParser = $outputParser;
        $this->metadata = $metadata;
        $this->tags = $tags;
    }

    /** @return list<string> */
    public static function lcId(): array
    {
        $parts = explode('\\', static::class);

        return ['langchain_core', 'prompts', static::PROMPT_TYPE, end($parts)];
    }

    /**
     * Bind values ahead of time, returning a new template.
     *
     * @param array<string, string|callable> $values
     */
    abstract public function partial(array $values): static;

    /**
     * Render the template.
     *
     * Declared as `mixed` because the three families genuinely differ: a string
     * template renders to a string, a chat template flattens to one, and an
     * image template renders to a `{url, detail}` map. Subclasses narrow the
     * return type.
     *
     * @param array<string, mixed> $values
     */
    abstract public function format(array $values): mixed;

    /**
     * Render the template to a prompt value.
     *
     * @param array<string, mixed> $values
     */
    abstract public function formatPromptValue(array $values): PromptValue;

    /**
     * The string key uniquely identifying this class of prompt template.
     */
    abstract public function getPromptType(): string;

    /**
     * Merge the bound values with the caller's.
     *
     * The caller's values win: a partial is a default, not a constraint.
     *
     * @param array<string, mixed> $userVariables
     * @return array<string, mixed>
     */
    public function mergePartialAndUserVariables(array $userVariables): array
    {
        $partialValues = [];

        foreach ($this->partialVariables as $key => $value) {
            $partialValues[$key] = is_string($value)
                ? $value
                : (is_callable($value) ? (string) $value() : $value);
        }

        return array_merge($partialValues, $userVariables);
    }

    /**
     * @param array<string, mixed>|mixed $input
     * @return PromptValue
     */
    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        return $this->formatPromptValue(is_array($input) ? $input : []);
    }

    /**
     * The kwargs this template was constructed with, in serialized form.
     *
     * `partial_variables` is dropped: the Python runtime has no equivalent, and
     * including it would make a PHP-written prompt unloadable there.
     *
     * @return array<string, mixed>
     */
    public function kwargs(): array
    {
        return $this->lcMapKeys($this->kwargs);
    }

    /** @var array<string, mixed> Constructor fields, already snake_cased. */
    protected array $kwargs = [];

    /**
     * Extra fields carried into a message-content block alongside the rendered text.
     *
     * Only the templates that build content blocks override this; for a plain
     * string template there is nothing extra to carry.
     *
     * @return array<string, mixed>
     */
    public function additionalContentFields(): array
    {
        return [];
    }

    /**
     * Drop nulls and apply any class-specific renames.
     *
     * @param array<string, mixed> $kwargs
     * @return array<string, mixed>
     */
    protected function lcMapKeys(array $kwargs): array
    {
        unset($kwargs['partial_variables']);

        return array_filter($kwargs, static fn (mixed $v): bool => $v !== null);
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
            'kwargs' => $this->kwargs(),
        ];
    }

    public function jsonSerialize(): mixed
    {
        return $this->toJson();
    }
}
