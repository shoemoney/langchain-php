<?php

declare(strict_types=1);

namespace LangChain\Tools;

use LangChain\Runnables\RunnableConfig;

/**
 * A tool whose arguments are a single string.
 *
 * Port of `Tool` from `@langchain/core/tools`.
 *
 * The base class declares an object schema `{input?: string}` and then unwraps
 * it, so a string-schema tool written as `fn(string)` and one written as
 * `fn({input: string})` are the same tool. The unwrapping happens here rather
 * than in each subclass because a model emits `{input: "..."}` regardless of
 * how the tool's body is written, and every single-string tool would otherwise
 * have to remember to unwrap.
 *
 * A bare string passed to {@see StructuredTool::invoke()} is wrapped into that
 * envelope before validation, so `invoke('b')` and `invoke({input: 'b'})` are
 * equivalent.
 */
abstract class Tool extends StructuredTool
{
    /**
     * A string-schema tool always declares the `{input: …}` envelope.
     *
     * Note this *overrides* any schema passed in, exactly as the TypeScript
     * original does with its `schema = z.object({input}).transform(…)` property
     * initialiser. That is not an oversight: the caller's string schema was only
     * ever used to decide that this tool takes a string, and keeping it would
     * make the tool validate `{input: "x"}` against `type: "string"` and reject
     * every call the model makes.
     *
     * @param array<string, mixed> $fields
     */
    public function __construct(array $fields = [])
    {
        parent::__construct($fields);
        $this->schema = Schema::stringInput();
    }

    /**
     * Accept a bare string as shorthand for the `{input: …}` envelope.
     *
     * `null` is wrapped too, and that is not a convenience: a model that calls
     * a no-argument tool sends `{input: null}`, and treating that as "no
     * arguments" rather than as an invalid call is what lets a no-arg tool work
     * at all.
     */
    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        if (is_string($input) || $input === null) {
            $input = ['input' => $input];
        }

        return parent::invoke($input, $config);
    }
}
