<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI;

use LangChain\LanguageModels\Chat\OpenAI\Converters\ResponsesTools;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\Messages\BaseMessage;
use LangChain\Tracers\CallbackManagerForLLMRun;

/**
 * The OpenAI chat model: a facade over {@see ChatOpenAICompletions} and
 * {@see ChatOpenAIResponses}.
 *
 * Port of `ChatOpenAI` from `@langchain/openai` (`chat_models/index.ts`). It
 * carries the constructor state and bound options, and for each call picks the
 * protocol that can honour them (see {@see self::shouldUseResponsesApi()}):
 *
 *  - Responses when `useResponsesApi` is set, when a Responses-only option
 *    (`previous_response_id`, `text`, `truncation`, `include`, `reasoning.summary`)
 *    or tool (a built-in, or a custom tool) is present, or when the model is
 *    Responses-only;
 *  - Chat Completions otherwise.
 *
 * The delegate is built per call from this instance's state rather than held, so
 * a property changed after construction, or a `bindTools()` clone, is honoured
 * without the two copies drifting apart. The retry sleep is routed back to this
 * instance's {@see self::backoff()}, so a subclass overriding it governs both.
 *
 * Streaming `ChatModelStreamEvent`s ({@see self::streamChatModelEvents()}) exist
 * for the Responses protocol only.
 */
class ChatOpenAI extends BaseChatOpenAI
{
    public const DEFAULT_API_URL = ChatOpenAICompletions::DEFAULT_API_URL;

    /**
     * Always use the Responses API.
     */
    public bool $useResponsesApi = false;

    /** @var array<string, mixed> */
    private array $constructorFields;

    /** @param array<string, mixed> $fields */
    public function __construct(array $fields = [])
    {
        parent::__construct($fields);

        $this->useResponsesApi = (bool) ($fields['useResponsesApi'] ?? $fields['use_responses_api'] ?? false);
        $this->constructorFields = $fields;
    }

    protected function defaultUrl(): string
    {
        return ChatOpenAICompletions::DEFAULT_API_URL;
    }

    /**
     * Whether Responses-only models are matched by name.
     */
    public static function modelPrefersResponsesApi(string $model): bool
    {
        foreach (['gpt-5.2-pro', 'gpt-5.4-pro', 'gpt-5.5-pro', 'gpt-5.6'] as $needle) {
            if (str_contains($model, $needle)) {
                return true;
            }
        }

        // Codex models are Responses API only.
        return str_contains($model, 'codex');
    }

    /**
     * Which protocol this call uses.
     *
     * Port of `_useResponsesApi`. Options are read from the per-call bag and from
     * what `bindTools()` / `bind()` attached, the PHP stand-in for upstream's
     * `defaultOptions`.
     *
     * @param array<string, mixed> $options
     */
    public function shouldUseResponsesApi(array $options = []): bool
    {
        if ($this->useResponsesApi) {
            return true;
        }

        $layers = [self::canonicalise($options), self::canonicalise($this->kwargs)];

        foreach ($layers as $layer) {
            foreach (is_array($layer['tools'] ?? null) ? $layer['tools'] : [] as $tool) {
                if (ResponsesTools::isBuiltInTool($tool)
                    || ResponsesTools::isOpenAICustomTool($tool)
                    || ResponsesTools::isCustomTool($tool)) {
                    return true;
                }
            }

            foreach (['previous_response_id', 'previousResponseId', 'text', 'truncation', 'include'] as $key) {
                if (($layer[$key] ?? null) !== null) {
                    return true;
                }
            }

            if (is_array($layer['reasoning'] ?? null) && ($layer['reasoning']['summary'] ?? null) !== null) {
                return true;
            }
        }

        if (($this->reasoning['summary'] ?? null) !== null) {
            return true;
        }

        $model = (string) ($layers[0]['model'] ?? $layers[1]['model'] ?? $this->model);

        return self::modelPrefersResponsesApi($model);
    }

    /**
     * @param array<string, mixed> $options
     */
    private function delegate(array $options): ChatOpenAICompletions|ChatOpenAIResponses
    {
        $delegate = $this->shouldUseResponsesApi($options)
            ? new ChatOpenAIResponses($this->constructorFields)
            : new ChatOpenAICompletions($this->constructorFields);

        $delegate->adoptStateFrom($this);
        $delegate->backoffHandler = fn (int $attempt) => $this->backoff($attempt);

        return $delegate;
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    public function invocationParams(array $options = [], array $extra = []): array
    {
        return $this->delegate($options)->invocationParams($options, $extra);
    }

    /**
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     */
    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        return $this->delegate($options)->generate($messages, $options, $runManager);
    }

    /**
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     *
     * @return \Generator<int, \LangChain\LanguageModels\Outputs\ChatGenerationChunk>
     */
    protected function streamResponseChunks(
        array $messages,
        array $options = [],
        ?CallbackManagerForLLMRun $runManager = null,
    ): \Generator {
        yield from $this->delegate($options)->streamResponseChunks($messages, $options, $runManager);
    }

    /**
     * Typed stream events; available when this call routes to the Responses API.
     *
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     *
     * @return \Generator<int, array<string, mixed>>
     *
     * @throws \LogicException on the Chat Completions protocol, which has no event converter here.
     */
    public function streamChatModelEvents(array $messages, array $options = []): \Generator
    {
        $delegate = $this->delegate($options);
        if (!$delegate instanceof ChatOpenAIResponses) {
            throw new \LogicException(
                'streamChatModelEvents() is only available on the Responses API; set useResponsesApi or '
                . 'use a Responses-only option.'
            );
        }

        yield from $delegate->streamChatModelEvents($messages, $options);
    }
}
