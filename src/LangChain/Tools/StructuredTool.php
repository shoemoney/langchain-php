<?php

declare(strict_types=1);

namespace LangChain\Tools;

use LangChain\LanguageModels\BaseLangChain;
use LangChain\Runnables\RunnableConfig;
use LangChain\Tracers\CallbackManagerForToolRun;
use LangChain\Tracers\Serialized;

/**
 * The base for every tool.
 *
 * Port of `StructuredTool` from `@langchain/core/tools`.
 *
 * A tool is a {@see \LangChain\Runnables\RunnableInterface} whose input is a
 * model's tool-call arguments and whose output is that call's result. What makes
 * it more than a lambda is the three things this class owns:
 *
 *  1. **Validation.** Arguments are checked against {@see self::$schema} before
 *     the body runs, and a mismatch throws {@see ToolException}. A tool whose
 *     body receives unchecked arguments has to re-derive the model's intent
 *     from whatever arrived.
 *
 *  2. **Result attribution.** If the call came from a model, the output is
 *     wrapped in a `ToolMessage` carrying the originating `tool_call_id`. If it
 *     did not, the raw value passes through. See {@see ToolOutput} for why that
 *     branch matters.
 *
 *  3. **Observability.** Every call is bracketed by `handleToolStart` /
 *     `handleToolEnd` / `handleToolError`, including the streaming case where
 *     intermediate values arrive via `handleToolEvent`.
 *
 * A subclass implements only {@see self::call()} — the actual work — and may
 * return a `\Generator` to stream intermediate progress.
 *
 * @template TOutput What the tool's body returns.
 * @extends BaseLangChain<mixed, TOutput|mixed>
 */
abstract class StructuredTool extends BaseLangChain
{
    /**
     * The tool's name, as the model sees it.
     *
     * Must be stable: it is the key a model uses to select this tool, and a name
     * that changes between the binding call and the invocation call produces a
     * call no tool will match.
     */
    public string $name = '';

    /**
     * What the tool does, in the model's terms.
     *
     * This is the tool's entire justification for being chosen. Vague
     * descriptions are the most common cause of a model calling the wrong tool.
     */
    public string $description = '';

    /** The declared shape of this tool's arguments. */
    public Schema $schema;

    /**
     * Return the tool's output directly to the caller, unwrapped.
     *
     * Set when the tool's output IS the answer — a search, a lookup — rather
     * than material an agent must reason over before continuing. An agent loop
     * reads this to decide whether to stop looping.
     */
    public bool $returnDirect = false;

    /**
     * Include schema-violation details in the thrown message.
     *
     * Off by default: the detail is useful when developing a tool and noise when
     * a model is in the loop and the message goes back into its context.
     */
    public bool $verboseParsingErrors = false;

    /**
     * `'content'` or `'content_and_artifact'`.
     *
     * The second form exists so a tool can hand the agent a small summary while
     * keeping the full result for the caller — an artifact is never sent to the
     * model, so it can be arbitrarily large.
     */
    public string $responseFormat = 'content';

    /**
     * Config applied to every call, overridable per-call.
     *
     * @var array<string, mixed>|null
     */
    public ?array $defaultConfig = null;

    /**
     * Provider-specific extras, passed through untouched.
     *
     * @var array<string, mixed>
     */
    public array $extras = [];

    /** @param array<string, mixed> $fields */
    public function __construct(array $fields = [])
    {
        parent::__construct($fields);

        $this->schema = Schema::from($fields['schema'] ?? null);
        $this->verboseParsingErrors = (bool) ($fields['verboseParsingErrors'] ?? $this->verboseParsingErrors);
        $this->responseFormat = (string) ($fields['responseFormat'] ?? $this->responseFormat);
        $this->defaultConfig = $fields['defaultConfig'] ?? $this->defaultConfig;
        $this->extras = (array) ($fields['extras'] ?? []);
        $this->returnDirect = (bool) ($fields['returnDirect'] ?? $this->returnDirect);
        $this->name = (string) ($fields['name'] ?? $this->name);
        $this->description = (string) ($fields['description'] ?? $this->description);
    }

    /** @return list<string> */
    public static function lcNamespace(): array
    {
        return ['langchain', 'tools'];
    }

    /**
     * Do the tool's actual work.
     *
     * Return a value for an ordinary tool, or a `\Generator` that yields
     * intermediate values and `return`s the final one to stream progress.
     *
     * @param CallbackManagerForToolRun|null $runManager
     * @param RunnableConfig|null            $parentConfig
     */
    abstract protected function callTool(mixed $arg, ?CallbackManagerForToolRun $runManager = null, ?RunnableConfig $parentConfig = null): mixed;

    /**
     * Run the tool, validating arguments and wrapping the result.
     *
     * The order here is load-bearing and is the same as the original's:
     *
     *  1. unwrap a tool-call envelope to its arguments;
     *  2. validate against the schema — **before** the body runs, so a
     *     mismatched call cannot have side effects;
     *  3. start the trace run;
     *  4. execute, draining a generator if one was returned;
     *  5. wrap and end the run.
     *
     * Validation precedes execution specifically so a malformed call cannot
     * half-run: a tool that deletes a file should not delete it on arguments
     * that never validated.
     */
    public function invoke(mixed $input, ?RunnableConfig $config = null): mixed
    {
        $enrichedConfig = $this->mergeConfig($config);

        if (ToolUtils::isToolCall($input)) {
            $toolInput = $input['args'] ?? [];
            $enrichedConfig->toolCall = $input;
        } else {
            $toolInput = $input;
        }

        return $this->callToolWithValidation($toolInput, $input, $enrichedConfig);
    }

    /**
     * The `call()` alias from the TypeScript original, kept because agent loops
     * written against 0.2 call this.
     *
     * @param list<string>|null $tags
     */
    public function call(mixed $arg, ?RunnableConfig $config = null, ?array $tags = null): mixed
    {
        if ($tags !== null) {
            $config = ($config ?? new RunnableConfig())->with(['tags' => $tags]);
        }

        return $this->invoke($arg, $config);
    }

    /**
     * Validate, execute, and wrap.
     *
     * `$rawArg` is threaded separately from `$toolInput` because the exception
     * must report the *original* argument — including the tool-call envelope —
     * not the unwrapped arguments, so the failure message shows the model what
     * it actually sent.
     */
    private function callToolWithValidation(mixed $toolInput, mixed $rawArg, RunnableConfig $config): mixed
    {
        $this->schema->validate($toolInput, $rawArg, $this->verboseParsingErrors);

        $callbackManager = $this->callbackManagerFor($config);

        $toolCallId = null;
        if (ToolUtils::isToolCall($rawArg) && isset($rawArg['id']) && is_string($rawArg['id'])) {
            $toolCallId = $rawArg['id'];
        }
        if ($toolCallId === null && ToolUtils::configHasToolCallId(['toolCall' => $config->toolCall])) {
            /** @var array{id: string} $toolCall */
            $toolCall = $config->toolCall;
            $toolCallId = $toolCall['id'];
        }

        $runManager = $callbackManager?->handleToolStart(
            new Serialized(static::lcId(), ['name' => $this->name, 'description' => $this->description]),
            $this->callbackInput($toolInput, $rawArg),
            $config->runId[0] ?? null,
            $config->tags,
            $config->metadata,
            $config->runName,
            $toolCallId,
        );

        try {
            $result = $this->execute($toolInput, $runManager, $config);
        } catch (\Throwable $e) {
            $runManager?->handleToolError($e);

            throw $e;
        }

        [$content, $artifact] = $this->splitResult($result);

        $formatted = ToolOutput::format($content, $artifact, $toolCallId, $this->name, $this->metadata);
        $runManager?->handleToolEnd($formatted);

        return $formatted;
    }

    /**
     * Run the body, draining a generator if one came back.
     *
     * The generator's yields become `handleToolEvent` calls. A failure *inside*
     * the generator is routed to `handleToolError` and rethrown — the partial
     * work already emitted stays visible, so a consumer can tell a tool that got
     * halfway from one that never started.
     *
     * A failure in the *event handler* is contained here rather than allowed to
     * abort the tool: a broken observer must not change what the tool returns.
     * It is reported through `handleToolError` so the failure is still visible
     * to anything that cares.
     */
    private function execute(mixed $toolInput, ?CallbackManagerForToolRun $runManager, RunnableConfig $config): mixed
    {
        $raw = $this->callTool($toolInput, $runManager, $config);

        if (!$raw instanceof \Generator) {
            return $raw;
        }

        try {
            while ($raw->valid()) {
                $chunk = $raw->current();
                try {
                    $runManager?->handleToolEvent($chunk);
                } catch (\Throwable $handlerError) {
                    $runManager?->handleToolError($handlerError);
                }
                $raw->next();
            }

            return $raw->getReturn();
        } finally {
            // Closing on the way out matters: a generator abandoned mid-stream
            // never runs its own cleanup, so a tool holding a handle or a
            // transaction would leak it.
            if ($raw->valid()) {
                $raw->throw(new \RuntimeException('Tool stream closed early.'));
            }
        }
    }

    /**
     * Split a result into the content the model sees and the artifact it does not.
     *
     * A `content_and_artifact` tool that returns anything other than a two-tuple
     * is a programming error, and it throws rather than guessing: silently
     * dropping one of the two halves would either lose data or send the artifact
     * to the model, and the second of those is exactly what the split exists to
     * prevent.
     *
     * @return array{0: mixed, 1: mixed}
     */
    private function splitResult(mixed $result): array
    {
        if ($this->responseFormat !== 'content_and_artifact') {
            return [$result, null];
        }

        if (is_array($result) && \LangChain\Utils\Js::isList($result) && count($result) === 2) {
            return [$result[0], $result[1]];
        }

        throw new \RuntimeException(
            'Tool response format is "content_and_artifact" but the output was not a two-tuple.'
            . "\nResult: " . json_encode($result, \JSON_PARTIAL_OUTPUT_ON_ERROR | \JSON_UNESCAPED_SLASHES),
        );
    }

    /**
     * What the trace records as this tool's input.
     *
     * Structured arguments stay structured. The alternative — stringifying
     * before the tracer sees it — is what the handler-facing hook does, and
     * doing it here too would make every structured tool's run record
     * unqueryable.
     */
    private function callbackInput(mixed $toolInput, mixed $rawArg): string|array
    {
        if (is_array($toolInput) && !\LangChain\Utils\Js::isList($toolInput)) {
            return $toolInput;
        }

        return is_string($rawArg) ? $rawArg : (string) json_encode($rawArg, \JSON_UNESCAPED_SLASHES | \JSON_PARTIAL_OUTPUT_ON_ERROR);
    }

    /**
     * Merge the tool's default config under the call's.
     *
     * Under, not over: a per-call config is a deliberate override, and letting
     * the tool's defaults win would make it impossible to change them for one
     * call without mutating the tool.
     */
    private function mergeConfig(?RunnableConfig $config): RunnableConfig
    {
        $merged = $config ?? new RunnableConfig();

        if ($this->defaultConfig === null) {
            return $merged;
        }

        $defaults = RunnableConfig::fromArray($this->defaultConfig);
        if ($defaults === null) {
            return $merged;
        }

        $combined = clone $merged;
        if ($merged->tags === []) {
            $combined->tags = $defaults->tags;
        }
        if ($merged->metadata === []) {
            $combined->metadata = $defaults->metadata;
        }
        if ($merged->callbacks === []) {
            $combined->callbacks = $defaults->callbacks;
        }
        if ($merged->options === []) {
            $combined->options = $defaults->options;
        }
        if ($merged->runName === null) {
            $combined->runName = $defaults->runName;
        }
        if ($merged->configurable === []) {
            $combined->configurable = $defaults->configurable;
        }

        return $combined;
    }

    /**
     * The JSON Schema describing this tool's arguments, for a model's benefit.
     *
     * @return array<string, mixed>
     */
    public function toolSchemaJson(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'parameters' => $this->schema->toJsonSchema(),
        ];
    }

    /**
     * Tools stream their final value on the default channel.
     *
     * There is no incremental form here: a tool's output is whatever its body
     * returned, so streaming a tool means streaming the surrounding agent step,
     * not the tool.
     *
     * @return \Generator<int, array{0: string, 1: mixed}>
     */
    public function stream(mixed $input, ?RunnableConfig $config = null): \Generator
    {
        yield [self::CHANNEL_DEFAULT, $this->invoke($input, $config)];
    }

    /**
     * @param list<mixed> $inputs
     * @param array<string, mixed>|null $options
     * @return list<mixed>
     */
    public function batch(array $inputs, ?RunnableConfig $config = null, ?array $options = null): array
    {
        return array_map(
            fn (mixed $input): mixed => $this->invoke($input, $config),
            array_values($inputs),
        );
    }

    public function getName(): string
    {
        return $this->name !== '' ? $this->name : parent::getName();
    }
}
