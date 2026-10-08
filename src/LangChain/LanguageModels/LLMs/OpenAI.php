<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\LLMs;

use LangChain\LanguageModels\BaseLLM;
use LangChain\LanguageModels\Chat\OpenAI\Utils\Azure;
use LangChain\LanguageModels\Chat\OpenAI\Utils\Requester;
use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\LanguageModels\Outputs\Generation;
use LangChain\LanguageModels\Outputs\GenerationChunk;
use LangChain\LanguageModels\Outputs\LLMResult;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangChain\Utils\Env;
use LangChain\Utils\Http\GuzzleHttpClient;
use LangChain\Utils\Http\HttpClient;

/**
 * OpenAI's legacy text-in/text-out completions endpoint.
 *
 * Port of `OpenAI` from `@langchain/openai` (`llms.ts`). To use Azure, use
 * {@see AzureOpenAI}; for chat models, use
 * {@see \LangChain\LanguageModels\Chat\OpenAI\ChatOpenAI}, which this class
 * refuses a chat model name in favour of.
 *
 * Any parameter the endpoint accepts and this class has no field for can be
 * passed in `modelKwargs`; those are applied last and win.
 *
 * Differences from upstream, forced by PHP:
 *
 *  - `timeout` (constructor and call option) is in seconds, as for the chat
 *    clients; the OpenAI SDK counts milliseconds.
 *  - `signal` is a callable that returns true once the call is aborted (see
 *    {@see \LangChain\Utils\AsyncCaller}), checked before each request and
 *    between stream events; an in-flight request is never interrupted.
 *  - `maxTokens: -1` asks for "as many as fit". Upstream measures the prompt
 *    with a tokenizer, which this port does not have; the prompt is estimated at
 *    four characters a token against the model's context size, so the figure is
 *    an approximation.
 */
class OpenAI extends BaseLLM
{
    public const DEFAULT_API_URL = 'https://api.openai.com/v1/completions';

    public ?float $temperature = null;

    public ?int $maxTokens = null;

    public ?float $topP = null;

    public ?float $frequencyPenalty = null;

    public ?float $presencePenalty = null;

    public int $n = 1;

    public ?int $bestOf = null;

    /** @var array<string, float|int>|null */
    public ?array $logitBias = null;

    public string $model = 'gpt-3.5-turbo-instruct';

    /** @deprecated Use $model. */
    public string $modelName = 'gpt-3.5-turbo-instruct';

    /** @var array<string, mixed> */
    public array $modelKwargs = [];

    /** Prompts per request. */
    public int $batchSize = 20;

    public ?float $timeout = null;

    /** @var list<string>|null */
    public ?array $stop = null;

    /** @var list<string>|null */
    public ?array $stopSequences = null;

    public ?string $user = null;

    public bool $streaming = false;

    public ?string $apiKey = null;

    public ?string $organization = null;

    /** The API root or the full `/completions` URL; null means OpenAI's. */
    public ?string $baseUrl = null;

    /** @var array<string, string> */
    public array $defaultHeaders = [];

    public int $maxRetries = 2;

    /** Accepted for parity; nothing runs concurrently in a synchronous runtime. */
    public int $maxConcurrency = 1;

    public ?HttpClient $httpClient = null;

    /** Replaces the sleep between retries; receives the attempt number. */
    public ?\Closure $backoffHandler = null;

    /**
     * @param array<string, mixed> $fields
     */
    public function __construct(array $fields = [])
    {
        parent::__construct($fields);

        $configuration = is_array($fields['configuration'] ?? null) ? $fields['configuration'] : [];

        $this->apiKey = self::string($fields['apiKey'] ?? null)
            ?? self::string($fields['openAIApiKey'] ?? null)
            ?? Env::getEnvironmentVariable('OPENAI_API_KEY');
        $this->organization = self::string($configuration['organization'] ?? null)
            ?? self::string($fields['organization'] ?? null)
            ?? Env::getEnvironmentVariable('OPENAI_ORGANIZATION');

        $this->model = (string) ($fields['model'] ?? $fields['modelName'] ?? $this->model);
        if ($this->isChatModel($this->model)) {
            throw new \InvalidArgumentException(implode("\n", [
                sprintf('Your chosen OpenAI model, "%s", is a chat model and not a text-in/text-out LLM.', $this->model),
                'Passing it into the "OpenAI" class is no longer supported.',
                'Please use the "ChatOpenAI" class instead.',
            ]));
        }
        $this->modelName = $this->model;

        $this->modelKwargs = (array) ($fields['modelKwargs'] ?? []);
        $this->batchSize = (int) ($fields['batchSize'] ?? $this->batchSize);
        $this->timeout = isset($fields['timeout']) ? (float) $fields['timeout'] : null;
        $this->temperature = isset($fields['temperature']) ? (float) $fields['temperature'] : null;
        $this->maxTokens = isset($fields['maxTokens']) ? (int) $fields['maxTokens'] : null;
        $this->topP = isset($fields['topP']) ? (float) $fields['topP'] : null;
        $this->frequencyPenalty = isset($fields['frequencyPenalty']) ? (float) $fields['frequencyPenalty'] : null;
        $this->presencePenalty = isset($fields['presencePenalty']) ? (float) $fields['presencePenalty'] : null;
        $this->n = (int) ($fields['n'] ?? $this->n);
        $this->bestOf = isset($fields['bestOf']) ? (int) $fields['bestOf'] : null;
        $this->logitBias = isset($fields['logitBias']) ? (array) $fields['logitBias'] : null;
        $stop = $fields['stopSequences'] ?? $fields['stop'] ?? null;
        $this->stop = $stop === null ? null : array_values((array) $stop);
        $this->stopSequences = $this->stop;
        $this->user = self::string($fields['user'] ?? null);
        $this->streaming = (bool) ($fields['streaming'] ?? false);
        $this->baseUrl = self::string($configuration['baseURL'] ?? null) ?? self::string($fields['baseUrl'] ?? null);
        $this->defaultHeaders = (array) ($configuration['defaultHeaders'] ?? $fields['defaultHeaders'] ?? []);
        $this->maxRetries = (int) ($fields['maxRetries'] ?? $this->maxRetries);
        $this->maxConcurrency = (int) ($fields['maxConcurrency'] ?? $this->maxConcurrency);
        $this->httpClient = $fields['httpClient'] ?? null;

        if ($this->batchSize < 1) {
            throw new \InvalidArgumentException('batchSize must be at least 1.');
        }
        if ($this->streaming && $this->bestOf !== null && $this->bestOf > 1) {
            throw new \InvalidArgumentException('Cannot stream results when bestOf > 1');
        }

        // What the caller passed, minus the credential and the transport:
        // `kwargs` is what a tracer or a checkpoint serializes.
        $this->kwargs += array_filter(array_intersect_key($fields, array_flip([
            'model', 'temperature', 'maxTokens', 'topP', 'frequencyPenalty', 'presencePenalty', 'n', 'bestOf',
            'logitBias', 'modelKwargs', 'batchSize', 'timeout', 'stop', 'stopSequences', 'user', 'streaming',
            'organization', 'maxRetries',
        ])), static fn (mixed $v): bool => $v !== null);
    }

    public function llmType(): string
    {
        return 'openai';
    }

    /**
     * @return list<string>
     */
    public static function lcNamespace(): array
    {
        return ['langchain', 'llms', 'openai'];
    }

    /**
     * The request parameters, minus `prompt`.
     *
     * Port of `invocationParams`. A value that was never set is omitted, which
     * is what JSON does to upstream's `undefined`.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function invocationParams(array $options = []): array
    {
        $params = [
            'model' => $this->model,
            'temperature' => $this->temperature,
            'max_tokens' => $this->maxTokens,
            'top_p' => $this->topP,
            'frequency_penalty' => $this->frequencyPenalty,
            'presence_penalty' => $this->presencePenalty,
            'n' => $this->n,
            'best_of' => $this->bestOf,
            'logit_bias' => $this->logitBias,
            'stop' => $options['stop'] ?? $this->stopSequences,
            'user' => $this->user,
            'stream' => $this->streaming,
        ];

        return array_merge(array_filter($params, static fn (mixed $v): bool => $v !== null), $this->modelKwargs);
    }

    /**
     * @return array<string, mixed>
     */
    public function identifyingParams(): array
    {
        return ['model_name' => $this->model] + $this->invocationParams() + array_filter([
            'baseURL' => $this->baseUrl,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * Call the endpoint for a batch of prompts, `batchSize` to a request.
     *
     * @param list<string>         $prompts
     * @param array<string, mixed> $options
     */
    protected function generatePrompts(array $prompts, array $options = [], ?CallbackManagerForLLMRun $runManager = null): LLMResult
    {
        $params = $this->invocationParams($options);
        $requester = $this->requester($options);

        if (($params['max_tokens'] ?? null) === -1) {
            if (count($prompts) !== 1) {
                throw new \InvalidArgumentException('max_tokens set to -1 not supported for multiple inputs');
            }
            $params['max_tokens'] = $this->calculateMaxTokens($prompts[0]);
        }

        $choices = [];
        $tokenUsage = [];

        foreach (array_chunk($prompts, $this->batchSize) as $subPrompts) {
            $this->throwIfAborted($options);

            $data = ($params['stream'] ?? false)
                ? $this->streamedCompletion($requester, $params, $subPrompts, $options, $runManager)
                : $requester->post($this->url(), $this->headers(), array_merge($params, ['stream' => false, 'prompt' => $subPrompts]), $this->query());

            array_push($choices, ...array_values((array) ($data['choices'] ?? [])));

            $usage = is_array($data['usage'] ?? null) ? $data['usage'] : [];
            foreach (['completion_tokens' => 'completionTokens', 'prompt_tokens' => 'promptTokens', 'total_tokens' => 'totalTokens'] as $wire => $key) {
                if (!empty($usage[$wire])) {
                    $tokenUsage[$key] = ($tokenUsage[$key] ?? 0) + (int) $usage[$wire];
                }
            }
        }

        $generations = [];
        foreach (array_chunk($choices, max(1, $this->n)) as $promptChoices) {
            $generations[] = array_map(static fn (array $choice): Generation => new Generation(
                (string) ($choice['text'] ?? ''),
                ['finishReason' => $choice['finish_reason'] ?? null, 'logprobs' => $choice['logprobs'] ?? null],
            ), $promptChoices);
        }

        return new LLMResult($generations, ['tokenUsage' => $tokenUsage]);
    }

    /**
     * Stream a completion, one chunk per event.
     *
     * @param array<string, mixed> $options
     *
     * @return \Generator<int, GenerationChunk>
     */
    protected function streamResponseChunks(
        string $prompt,
        array $options = [],
        ?CallbackManagerForLLMRun $runManager = null,
    ): \Generator {
        $params = array_merge($this->invocationParams($options), ['stream' => true, 'prompt' => $prompt]);

        foreach ($this->requester($options)->postStream($this->url(), $this->headers(), $params, $this->query()) as $data) {
            $this->throwIfAborted($options);

            $choice = $data['choices'][0] ?? null;
            if (!is_array($choice)) {
                continue;
            }

            $chunk = new GenerationChunk((string) ($choice['text'] ?? ''), ['finishReason' => $choice['finish_reason'] ?? null]);
            yield $chunk;
            $runManager?->handleLLMNewToken($chunk->text);
        }

        $this->throwIfAborted($options);
    }

    /**
     * One streamed request, folded into the shape a non-streaming response has.
     *
     * Choices are merged by `index`: text is concatenated and the last
     * `finish_reason` and `logprobs` win, as upstream does. Every fragment is
     * reported to the run with the prompt and completion it belongs to.
     *
     * @param list<string>         $prompts
     * @param array<string, mixed> $params
     * @param array<string, mixed> $options
     *
     * @return array{choices: list<array<string, mixed>>}
     */
    private function streamedCompletion(
        Requester $requester,
        array $params,
        array $prompts,
        array $options,
        ?CallbackManagerForLLMRun $runManager,
    ): array {
        $choices = [];
        $params = array_merge($params, ['stream' => true, 'prompt' => $prompts]);

        foreach ($requester->postStream($this->url(), $this->headers(), $params, $this->query()) as $message) {
            $this->throwIfAborted($options);

            foreach ((array) ($message['choices'] ?? []) as $part) {
                $index = (int) ($part['index'] ?? 0);
                if (!isset($choices[$index])) {
                    $choices[$index] = $part;
                } else {
                    $choices[$index]['text'] = ($choices[$index]['text'] ?? '') . ($part['text'] ?? '');
                    $choices[$index]['finish_reason'] = $part['finish_reason'] ?? null;
                    $choices[$index]['logprobs'] = $part['logprobs'] ?? null;
                }

                $runManager?->handleLLMNewToken((string) ($part['text'] ?? ''), [
                    'prompt' => intdiv($index, max(1, $this->n)),
                    'completion' => $index % max(1, $this->n),
                ]);
            }
        }
        $this->throwIfAborted($options);

        ksort($choices);

        return ['choices' => array_values($choices)];
    }

    protected function url(): string
    {
        if ($this->baseUrl === null) {
            return self::DEFAULT_API_URL;
        }

        $root = rtrim($this->baseUrl, '/');

        return str_ends_with($root, '/completions') ? $root : $root . '/completions';
    }

    /**
     * @return array<string, string>
     */
    protected function headers(): array
    {
        if ($this->apiKey === null || $this->apiKey === '') {
            throw new OpenAIException(
                'No OpenAI API key. Pass one to the constructor or set the OPENAI_API_KEY environment variable.',
                0,
                '',
            );
        }

        $headers = ['Content-Type' => 'application/json', 'Authorization' => 'Bearer ' . $this->apiKey];
        if ($this->organization !== null) {
            $headers['OpenAI-Organization'] = $this->organization;
        }

        return $headers + Azure::getHeadersWithUserAgent($this->defaultHeaders);
    }

    /**
     * @return array<string, mixed>
     */
    protected function query(): array
    {
        return [];
    }

    /**
     * @param array<string, mixed> $options `timeout` and `maxRetries` override this instance's for one call.
     */
    private function requester(array $options): Requester
    {
        $this->httpClient ??= new GuzzleHttpClient();

        return new Requester(
            $this->httpClient,
            (int) ($options['maxRetries'] ?? $this->maxRetries),
            isset($options['timeout']) ? (float) $options['timeout'] : $this->timeout,
            $this->backoffHandler,
        );
    }

    /**
     * @param array<string, mixed> $options
     */
    private function throwIfAborted(array $options): void
    {
        $signal = $options['signal'] ?? null;
        if (is_callable($signal) && $signal()) {
            throw new \RuntimeException('AbortError');
        }
    }

    private function isChatModel(string $model): bool
    {
        return (str_starts_with($model, 'gpt-3.5-turbo') || str_starts_with($model, 'gpt-4') || str_starts_with($model, 'o1'))
            && !str_contains($model, '-instruct');
    }

    /**
     * The completion budget left after the prompt, for `maxTokens: -1`.
     */
    private function calculateMaxTokens(string $prompt): int
    {
        $context = str_starts_with($this->model, 'davinci-002') || str_starts_with($this->model, 'babbage-002') ? 16384 : 4097;

        return max(1, $context - (int) ceil(strlen($prompt) / 4));
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
