<?php

declare(strict_types=1);

namespace LangChain\OutputParsers\OpenAITools;

use LangChain\OutputParsers\OutputParserException;
use LangChain\Runnables\RunnableConfig;

/**
 * Pull one named tool call's arguments out of a tool-calling response.
 *
 * Port of `JsonOutputKeyToolsParser` from
 * `@langchain_core/output_parsers/openai_tools`.
 *
 * The base {@see JsonOutputToolsParser} returns *every* call the model made. This
 * narrows to the one named tool, which is the shape a caller wants when it asked
 * for a specific capability — the model may also narrate, or call something else,
 * and neither is an error here.
 *
 * The asymmetry between {@see self::parsePartialResult()} and
 * {@see self::parseResult()} is deliberate and inherited: while a call is still
 * streaming its arguments are a fragment, so validating them against anything
 * would reject a perfectly good in-progress call. Only the final, complete
 * result is validated.
 *
 * @template T
 * @extends JsonOutputToolsParser<T>
 */
class JsonOutputKeyToolsParser extends JsonOutputToolsParser
{
    /** The tool whose calls are returned. */
    public string $keyName = '';

    /** Return only the first matching call rather than a list. */
    public bool $returnSingle = false;

    /**
     * Optional schema validating the final result.
     *
     * PHP has no Zod, so this takes the same JSON Schema the rest of the port
     * uses. Absent, the arguments are returned unvalidated — the same contract
     * the TypeScript original offers for a plain-object schema.
     *
     * @var array<string, mixed>|null
     */
    public ?array $jsonSchema = null;

    /** @param array<string, mixed> $fields */
    public function __construct(array $fields = [])
    {
        parent::__construct($fields);

        $this->keyName = (string) ($fields['keyName'] ?? $this->keyName);
        $this->returnSingle = (bool) ($fields['returnSingle'] ?? $this->returnSingle);
        $this->jsonSchema = isset($fields['jsonSchema']) ? (array) $fields['jsonSchema'] : null;
    }

    /**
     * @param list<array{text: string, message?: \LangChain\Messages\BaseMessage}> $generations
     */
    public function parsePartialResult(array $generations, bool $partial = true): mixed
    {
        $results = parent::parsePartialResult($generations, $partial);
        $matching = is_array($results)
            ? array_values(array_filter($results, fn (array $r): bool => ($r['type'] ?? null) === $this->keyName))
            : [];

        if ($matching === []) {
            return null;
        }

        $values = $this->returnId ? $matching : array_map(static fn (array $r): array => $r['args'], $matching);

        return $this->returnSingle ? $values[0] : $values;
    }

    /**
     * @param list<array{text: string, message?: \LangChain\Messages\BaseMessage}> $generations
     */
    public function parseResult(array $generations, ?RunnableConfig $config = null): mixed
    {
        $results = parent::parsePartialResult($generations, false);
        $matching = is_array($results)
            ? array_values(array_filter($results, fn (array $r): bool => ($r['type'] ?? null) === $this->keyName))
            : [];

        if ($matching === []) {
            return null;
        }

        $values = $this->returnId ? $matching : array_map(static fn (array $r): array => $r['args'], $matching);

        if ($this->returnSingle) {
            return $this->validateResult($values[0]);
        }

        return array_map(fn (array $value): array => $this->validateResult($value), $values);
    }

    /**
     * Check the finished arguments against the schema, if one was given.
     *
     * Only the final parse validates — see the class docblock.
     *
     * @return array<string, mixed>
     */
    private function validateResult(array $result): array
    {
        if ($this->jsonSchema === null) {
            return $result;
        }

        $errors = \LangChain\OutputParsers\JsonSchemaValidator::validate($result, $this->jsonSchema);
        if ($errors !== []) {
            $rendered = json_encode($result, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES);

            throw new OutputParserException(
                'Failed to parse. Text: "' . $rendered . '". Error: ' . json_encode($errors),
                $rendered,
            );
        }

        return $result;
    }
}
