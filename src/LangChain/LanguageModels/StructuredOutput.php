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
            // camelCase, because `RunnableConfig` reads `$runName` and upstream's
            // `RunnableBinding.kwargs` is typed `Partial<CallOptions>` — a typed
            // object merged into the config, where `runName` is the canonical
            // key. `run_name` is not a synonym here: it lands in
            // `config->options['run_name']` and `config->runName` stays null, so
            // the tracer never sees the pipeline name and the run shows up
            // unnamed. A snake_case key that is quietly accepted and quietly
            // ignored is worse than one that is rejected.
            // Upstream is `runName ? result.withConfig({ runName }) : result`
            // (chat_models/structured_output.ts:114) — so the CONFIG slot, not
            // the kwargs slot.
            //
            // `RunnableBinding::mergeConfig()` ends with
            //   $merged->options = $this->kwargs + (...);
            // so anything in the kwargs slot becomes an OPTION and `runName` is
            // read from `$this->config`. Measured through mergeConfig:
            //
            //   bind(['runName' => 'x'], [])  -> runName=NULL, options={"runName":"x"}
            //   bind([], ['runName' => 'x'])  -> runName='x',   options=[]
            //
            // This call previously used `run_name` in the kwargs slot, which was
            // wrong twice: the key was snake_case, and the slot was the kwargs
            // one. Correcting only the key changed nothing observable, because a
            // correctly-spelled key in the kwargs slot is still just an option.
            //
            // RESIDUAL GAP — FIXED at iteration 375. It WAS here, and it was
            // `BaseLanguageModel::invoke()`, which destructured the config down
            // to `options` and `callbacks`. `generatePrompt()` now takes the
            // config as a fourth argument and merges it, so a bound `runName`
            // reaches `Run::name()`, which reads `$extra['__name']`
            // written only by `BaseTracer.php:282` from the `$runName` argument
            // of `handleChatModelStart`. Bound directly on the model with no pipe
            // in the way, `Run::name()` still falls back to the component id. So
            // the propagation from a bound config into the traced run is broken
            // somewhere in `RunnableBinding`/`BaseChatModel`, and the loss is
            // silent because `name()` falls back to the serialized id rather
            // than reporting nothing. Tracked, not fixed here.
            // The SECOND argument. `Runnable::bind(array $kwargs = [], ?array
            // $config = null)` (Runnable.php:101) — the name goes in $config.
            //
            // This had been wrong twice in opposite directions, and the reason is
            // worth recording: the diagnosis was done by constructing
            // `new RunnableBinding($bound, $kwargs, $config)` directly, which
            // confirmed that $config is where runName is read from — and then the
            // CALL was written with the arguments the other way round. Verifying
            // a constructor and then applying the conclusion to a wrapper method
            // is not the same act, and the wrapper's signature is the one that
            // ships.
            //
            // Measured through bind() itself:
            //   bind(['runName'=>'x'], [])  -> runName=NULL, options={"runName":"x"}
            //   bind([], ['runName'=>'x'])  -> runName='x',   options=[]
            $result = $result->bind([], ['runName' => $runName]);
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
