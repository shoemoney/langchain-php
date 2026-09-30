<?php

declare(strict_types=1);

namespace LangChain\LanguageModels;

use LangChain\OutputParsers\JsonOutputParser;
use LangChain\OutputParsers\OpenAITools\JsonOutputKeyToolsParser;
use LangChain\Runnables\Runnable;
use LangChain\Runnables\RunnablePassthrough;
use LangChain\Runnables\RunnableSequence;

/**
 * The three pieces every provider's `withStructuredOutput` is assembled from.
 *
 * Port of `language_models/structured_output.ts` from `@langchain/core`.
 *
 * `withStructuredOutput` is not one mechanism but three, and providers differ
 * only in which they wire together:
 *
 *  - **content** — the model is told to answer in JSON; the text is parsed.
 *  - **functionCalling** — the schema is offered as a tool; the *tool call's
 *    arguments* are the answer.
 *
 * The second is the interesting one. The model never "returns JSON" here: it
 * decides to call a tool whose parameters happen to be the schema, and the
 * arguments it produced are read out. That is why the parser is a
 * `JsonOutputKeyToolsParser` keyed by the tool's name, and why the tool's name
 * must match on both sides — a rename on either end yields
 * "no tool call found", not an empty result.
 *
 * The TypeScript original picks between Zod and a plain JSON Schema at runtime.
 * This port has only the latter (see the "Tool input schemas" note in
 * `PORT_STATUS.md`), so both helpers collapse to their unvalidated form rather
 * than carrying a branch that can never be taken.
 */
final class StructuredOutput
{
    /**
     * A parser for a model answering in JSON text.
     *
     * @return JsonOutputParser<array<string, mixed>>
     */
    public static function createContentParser(): JsonOutputParser
    {
        return new JsonOutputParser();
    }

    /**
     * A parser for the arguments of one named tool call.
     *
     * @return JsonOutputKeyToolsParser<array<string, mixed>>
     */
    public static function createFunctionCallingParser(string $keyName): JsonOutputKeyToolsParser
    {
        return new JsonOutputKeyToolsParser(['keyName' => $keyName, 'returnSingle' => true]);
    }

    /**
     * Pipe a model through a parser, optionally keeping the raw message.
     *
     * With `$includeRaw`, the result is `{raw: <message>, parsed: <value>}` and
     * a parse failure yields `parsed: null` rather than throwing — the point of
     * the flag is that a caller who cares about the raw response can still see
     * it when parsing did not work.
     *
     * Without the flag a "no tool call" outcome is *also* a null, not an
     * exception: upstream's `JsonOutputKeyToolsParser.parseResult` returns
     * `undefined` for the same case, and inventing a throw here would make this
     * port diverge from the behaviour every other LangChain caller is written
     * against. Test for `null`; do not rely on a throw.
     *
     * @param Runnable<mixed, mixed>           $llm
     * @param Runnable<mixed, mixed>           $outputParser
     *
     * @return Runnable<mixed, mixed>
     */
    public static function assembleStructuredOutputPipeline(
        Runnable $llm,
        Runnable $outputParser,
        bool $includeRaw = false,
        ?string $runName = null,
    ): Runnable {
        $result = $includeRaw
            ? self::withRaw($llm, $outputParser)
            : $llm->pipe($outputParser);

        if ($runName !== null) {
            $result = $result->bind([], ['run_name' => $runName]);
        }

        return $result;
    }

    /**
     * `{raw: llm_output, parsed: parsed_or_null}`.
     *
     * The `parsed` branch reads `$input['raw']` — the value the preceding step
     * produced — which is why this is two chained steps and not one
     * {@see \LangChain\Runnables\RunnableParallel}. A parallel's branches all
     * see the *input*; only a sequential assign lets one read another's output.
     *
     * @param Runnable<mixed, mixed> $llm
     * @param Runnable<mixed, mixed> $outputParser
     */
    private static function withRaw(Runnable $llm, Runnable $outputParser): RunnableSequence
    {
        $parse = RunnablePassthrough::assign([
            'parsed' => static fn (mixed $input): mixed => $outputParser->invoke(
                is_array($input) ? ($input['raw'] ?? null) : null,
            ),
        ]);

        return new RunnableSequence([
            new \LangChain\Runnables\RunnableParallel(['raw' => $llm]),
            $parse->withFallbacks([
                RunnablePassthrough::assign(['parsed' => static fn (mixed $input): mixed => null]),
            ]),
        ]);
    }
}
