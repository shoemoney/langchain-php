<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI;

use LangChain\LanguageModels\Chat\OpenAI\Utils\Completions;
use LangChain\LanguageModels\Chat\OpenAI\Utils\Tools;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Messages\BaseMessage;
use LangChain\Tracers\CallbackManagerForLLMRun;

/**
 * A chat model backed by OpenAI's Chat Completions API.
 *
 * Port of `ChatOpenAICompletions` (`chat_models/completions.ts`) from
 * `@langchain/openai`.
 *
 * The model owns four translations and nothing else:
 *
 *  1. constructor state and per-call options to the request body ({@see self::invocationParams()});
 *  2. LangChain messages to wire messages ({@see Completions::convertMessages()});
 *  3. response to `AIMessage`;
 *  4. streamed deltas to foldable `AIMessageChunk`s.
 *
 * Everything else (tools, structured output, retries, authentication) is
 * inherited from {@see BaseChatOpenAI}. The Responses API is a different
 * protocol, not a flag: see {@see ChatOpenAIResponses}.
 */
class ChatOpenAICompletions extends BaseChatOpenAI
{
    public const DEFAULT_API_URL = 'https://api.openai.com/v1/chat/completions';

    protected function defaultUrl(): string
    {
        return self::DEFAULT_API_URL;
    }

    protected function url(): string
    {
        $url = parent::url();

        // A facade configured with a Responses URL still has to reach this endpoint.
        return str_ends_with($url, '/responses') ? substr($url, 0, -strlen('/responses')) . '/chat/completions' : $url;
    }

    /**
     * The request body.
     *
     * Precedence, most specific first:
     *
     *  1. `$options` — this call;
     *  2. `$this->kwargs` — what `bindTools()` / `bind()` attached;
     *  3. the constructor properties.
     *
     * The middle layer is what makes `bindTools([...], ['temperature' => 0])`
     * mean anything. The model is not mutated by a bind, so the bound values
     * live in `kwargs` and *this* is the only place they are read back. A
     * client that skipped layer 2 would accept a bind, report it in
     * `kwargs()`, and never send it — the bound tool would simply not reach
     * the provider.
     *
     * `null` fields are omitted rather than sent as null: the API distinguishes
     * "unset" from "explicitly null" for several parameters, and a client that
     * sends every key makes a constructor default indistinguishable from a
     * deliberate choice.
     *
     * @param array<string, mixed> $options
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function invocationParams(array $options = [], array $extra = []): array
    {
        // Checked HERE, not in the constructor, because this is the one place
        // all three layers pass through: constructor fields, bound kwargs and
        // per-call options. A constructor-only check refuses one spelling of a
        // mistake and lets the other two through silently, which is worse than
        // not checking at all — the caller believes the setting applied.
        $this->rejectUnsupported($options);
        $this->rejectUnsupported($this->kwargs);


        // The same key-spelling normalisation for both layers. Applying it to
        // the per-call options only meant a bound `max_tokens` was silently
        // dropped, so `bindTools($t, ['max_tokens' => 50])` did nothing.
        $options = $this->normaliseKeys($options);
        $bound = $this->normaliseKeys($this->kwargs);

        $params = [
            'model' => $this->pickOption($options, 'model') ?? $bound['model'] ?? $this->model,
            'temperature' => $this->pickOption($options, 'temperature') ?? $bound['temperature'] ?? $this->temperature,
            'top_p' => $this->pickOption($options, 'topP', 'top_p') ?? $bound['topP'] ?? $this->topP,
            'frequency_penalty' => $this->pickOption($options, 'frequencyPenalty') ?? $bound['frequencyPenalty'] ?? $this->frequencyPenalty,
            'presence_penalty' => $this->pickOption($options, 'presencePenalty') ?? $bound['presencePenalty'] ?? $this->presencePenalty,
            'stop' => $this->pickOption($options, 'stop', 'stopSequences', 'stop_sequences')
                ?? $bound['stopSequences'] ?? $bound['stop'] ?? $this->stopSequences,
            'max_tokens' => $this->pickOption($options, 'maxTokens', 'max_tokens') ?? $bound['maxTokens'] ?? $this->maxTokens,
            // These read the middle layer like every other parameter. Reading
            // only `$options` meant a constructor-supplied `user` / `seed` /
            // `responseFormat` was recorded in `kwargs` — so it showed up in
            // every serialized trace — and then never went on the wire.
            'user' => $this->pickOption($options, 'user') ?? ($bound['user'] ?? null),
            'seed' => $this->pickOption($options, 'seed') ?? ($bound['seed'] ?? null),
            'response_format' => $this->pickOption($options, 'responseFormat', 'response_format')
                ?? ($bound['responseFormat'] ?? $bound['response_format'] ?? null),
            // Per-call tools go through the SAME conversion as bound ones.
            // Passing a `StructuredTool` here used to serialise it as a
            // constructor blob — `{"lc":1,"type":"constructor",...}` — and
            // send THAT to the provider: a tool the model cannot read, with no
            // error anywhere. The conversion is a no-op for an
            // already-provider-shaped array, so this cannot double-wrap.
            // BOTH arms need converting, not just the per-call one. `bindTools()`
            // converts when it stores, so bound tools arrive pre-converted — but
            // tools handed to the CONSTRUCTOR land in `kwargs` raw. Proved by
            // asserting on the request body: the constructor path sent
            //   [{"lc":1,"type":"constructor","id":["langchain","tools",
            //     "DynamicStructuredTool"],"kwargs":[]}]
            // i.e. a serialised PHP object with the tool's name, description and
            // schema all absent. Converting again is idempotent for an already
            // -shaped array, so both arms can go through the same call.
            'tools' => $this->convertTools($this->pickOption($options, 'tools'))
                ?? $this->convertTools($bound['tools'] ?? null)
                ?? null,
            // The bound value goes through the same formatter as the per-call
            // one. Upstream has no such split — it passes everything through
            // `withConfig`, so a bound `tool_choice` is formatted identically.
            // Reading the bound value raw made the same string mean two
            // different things depending on how it arrived.
            'tool_choice' => $this->toolChoiceOf($options) ?? $this->formatBoundToolChoice($bound),
            'parallel_tool_calls' => $this->pickOption($options, 'parallelToolCalls', 'parallel_tool_calls')
                ?? $bound['parallelToolCalls']
                ?? null,
        ];

        if (($extra['streaming'] ?? false) === true) {
            $params['stream'] = true;
            if ($this->streamUsage) {
                $params['stream_options'] = ['include_usage' => true];
            }
        }

        // An empty `tools` list is not the same as no `tools` key: providers
        // reject `tools: []` outright. `bindTools([], $kwargs)` — binding call
        // options without offering anything — is a legitimate way to use this,
        // so the empty list is dropped rather than sent.
        if (($params['tools'] ?? null) === []) {
            unset($params['tools']);
        }

        return array_filter($params, static fn (mixed $v): bool => $v !== null);
    }

    /**
     * Collapse the wire spelling of each known key onto its canonical name.
     *
     * `max_tokens` and `maxTokens` are the same request parameter; accepting one
     * and silently dropping the other makes a binding look like it did nothing.
     *
     * @param array<string, mixed> $bag
     *
     * @return array<string, mixed>
     */
    private function normaliseKeys(array $bag): array
    {
        return self::canonicalise($bag);
    }

    /**

    /**
     * Convert caller-supplied tools, whatever shape they arrive in.
     *
     * @param list<mixed>|null $tools
     *
     * @return list<array<string, mixed>>|null
     */
    private function convertTools(?array $tools): ?array
    {
        return $tools === null || $tools === [] ? null : Tools::convertAll($tools, $this->supportsStrictToolCalling);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function toolChoiceOf(array $options): mixed
    {
        $choice = $options['toolChoice'] ?? $options['tool_choice'] ?? null;

        return $choice === null ? null : Tools::formatToolChoice($choice);
    }

    /**
     * @param array<string, mixed> $bound
     */
    private function formatBoundToolChoice(array $bound): mixed
    {
        $choice = $bound['toolChoice'] ?? $bound['tool_choice'] ?? null;

        return $choice === null ? null : Tools::formatToolChoice($choice);
    }

    /**
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     */
    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        $params = $this->invocationParams($options);
        $params['messages'] = Completions::convertMessages($messages);

        $payload = $this->post($params);

        $choices = $payload['choices'] ?? [];
        if (!is_array($choices) || $choices === []) {
            throw new OpenAIException('OpenAI returned no choices for this request.', 0, json_encode($payload));
        }

        $generations = [];
        foreach ($choices as $choice) {
            if (!is_array($choice)) {
                continue;
            }

            $message = Completions::choiceToMessage($choice, $payload);
            $text = Completions::stringifyContent($message->content);

            $generationInfo = ['finish_reason' => $choice['finish_reason'] ?? null];
            $generationInfo = array_filter($generationInfo, static fn (mixed $v): bool => $v !== null);

            $generations[] = new ChatGeneration($message, $text, $generationInfo);
        }

        if ($generations === []) {
            throw new OpenAIException('OpenAI returned no usable choices for this request.', 0, json_encode($payload));
        }

        return new ChatResult($generations, $this->llmOutputFromUsage($generations[0]->message));
    }

    /**
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     *
     * @return \Generator<int, ChatGenerationChunk>
     */
    protected function streamResponseChunks(
        array $messages,
        array $options = [],
        ?CallbackManagerForLLMRun $runManager = null,
    ): \Generator {
        $params = $this->invocationParams($options, ['streaming' => true]);
        $params['messages'] = Completions::convertMessages($messages);

        foreach ($this->postStream($params) as $payload) {
            $choices = $payload['choices'] ?? null;

            // A usage-only chunk (what `stream_options.include_usage` sends at
            // the end) has no choices at all. It is still the authoritative
            // token count, so it is folded onto the running total rather than
            // dropped — losing it would leave every streamed call reporting zero.
            if (!is_array($choices) || $choices === []) {
                if (isset($payload['usage']) && is_array($payload['usage'])) {
                    // The response id travels with this chunk, and the usage
                    // rides on it so the fold accumulates it. It carries no
                    // content, so `BaseChatModel::stream()` folds it without
                    // surfacing it as a message of its own — which is what
                    // upstream does, capturing the event in a local and
                    // `continue`ing rather than yielding
                    // (`completions.ts:450-454`).
                    yield new ChatGenerationChunk(
                        new \LangChain\Messages\AIMessageChunk([
                            'content' => '',
                            'id' => $payload['id'] ?? null,
                            'response_metadata' => Completions::responseMetadata($payload),
                        ]),
                        '',
                    );
                }

                continue;
            }

            foreach ($choices as $index => $choice) {
                if (!is_array($choice)) {
                    continue;
                }

                $delta = is_array($choice['delta'] ?? null) ? $choice['delta'] : [];
                $chunk = Completions::deltaToChunk($delta, $payload, (int) $index);

                $generationInfo = array_filter(
                    ['finish_reason' => $choice['finish_reason'] ?? null],
                    static fn (mixed $v): bool => $v !== null,
                );

                $text = is_string($delta['content'] ?? null) ? $delta['content'] : '';

                $generationChunk = new ChatGenerationChunk($chunk, $text, $generationInfo);

                yield $generationChunk;

                if ($text !== '') {
                    $runManager?->handleLLMNewToken($text, null, ['chunk' => $generationChunk]);
                }
            }
        }
    }
}
