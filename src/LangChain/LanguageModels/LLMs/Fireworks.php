<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\LLMs;

use LangChain\LanguageModels\Chat\Fireworks\FireworksResponseError;
use LangChain\LanguageModels\LLM;
use LangChain\LanguageModels\Outputs\GenerationChunk;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangChain\Utils\AsyncCaller;
use LangChain\Utils\Env;
use LangChain\Utils\Http\GuzzleHttpClient;
use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Http\SseParser;
use LangChain\Utils\Js;

/**
 * Fireworks text-completion LLM.
 *
 * Port of `Fireworks` (`llms.ts`) from `@langchain/fireworks`, which extends the
 * legacy OpenAI completions LLM. This class is built on {@see LLM} and speaks the
 * `/completions` endpoint itself, so it does not depend on an OpenAI LLM class.
 *
 * Fireworks takes exactly one string prompt per request and rejects
 * `frequency_penalty`, `presence_penalty`, `best_of` and `logit_bias`;
 * {@see self::completionWithRetry()} normalises a one-element prompt list,
 * refuses anything else, and drops those parameters. {@see LLM} already sends one
 * prompt per request, so batching never reaches that check.
 */
class Fireworks extends LLM
{
    public const FIREWORKS_BASE_URL = 'https://api.fireworks.ai/inference/v1';

    public const DEFAULT_FIREWORKS_LLM_MODEL = 'accounts/fireworks/models/llama-v2-13b';

    private const UNSUPPORTED_REQUEST_PARAMS = ['frequency_penalty', 'presence_penalty', 'best_of', 'logit_bias'];

    public string $model = self::DEFAULT_FIREWORKS_LLM_MODEL;

    public ?float $temperature = null;

    public ?int $maxTokens = null;

    public ?float $topP = null;

    /** @var list<string>|null */
    public ?array $stop = null;

    public bool $streaming = false;

    public ?string $apiKey = null;

    public ?string $fireworksApiKey = null;

    public string $baseUrl = self::FIREWORKS_BASE_URL;

    public ?float $timeout = null;

    public ?HttpClient $httpClient = null;

    private AsyncCaller $caller;

    /**
     * @param string|array<string, mixed> $modelOrFields A model name, or the full field bag.
     * @param array<string, mixed>        $fields        Used when the first argument is a model name.
     */
    public function __construct(string|array $modelOrFields = [], array $fields = [])
    {
        $fields = is_string($modelOrFields) ? $fields + ['model' => $modelOrFields] : $modelOrFields;
        parent::__construct($fields);

        $apiKey = self::firstNonEmpty($fields['apiKey'] ?? null, $fields['fireworksApiKey'] ?? null)
            ?? self::firstNonEmpty(Env::getEnvironmentVariable('FIREWORKS_API_KEY'));

        if ($apiKey === null) {
            throw new \InvalidArgumentException(
                'Fireworks API key not found. Please set the FIREWORKS_API_KEY environment variable '
                . 'or pass the key into "apiKey" or "fireworksApiKey".'
            );
        }

        $configuration = is_array($fields['configuration'] ?? null) ? $fields['configuration'] : [];

        $this->apiKey = $apiKey;
        $this->fireworksApiKey = $apiKey;
        $this->model = self::firstNonEmpty($fields['model'] ?? null, $fields['modelName'] ?? null) ?? $this->model;
        $this->temperature = isset($fields['temperature']) ? (float) $fields['temperature'] : null;
        $this->maxTokens = isset($fields['maxTokens']) ? (int) $fields['maxTokens'] : null;
        $this->topP = isset($fields['topP']) ? (float) $fields['topP'] : null;
        $stop = $fields['stop'] ?? $fields['stopSequences'] ?? null;
        $this->stop = $stop === null ? null : array_values((array) $stop);
        $this->streaming = (bool) ($fields['streaming'] ?? false);
        $this->baseUrl = rtrim((string) ($configuration['baseURL'] ?? $fields['baseUrl'] ?? self::FIREWORKS_BASE_URL), '/');
        $this->timeout = isset($fields['timeout']) ? (float) $fields['timeout'] : null;
        $this->httpClient = $fields['httpClient'] ?? null;
        $this->caller = $fields['asyncCaller'] ?? new AsyncCaller(
            maxRetries: (int) ($fields['maxRetries'] ?? 6),
            sleeper: $fields['sleeper'] ?? null,
        );

        // What a rebuilt model needs; the credential and the transport stay out of the trace.
        $this->kwargs += array_filter([
            'model' => $this->model,
            'temperature' => $this->temperature,
            'maxTokens' => $this->maxTokens,
            'topP' => $this->topP,
            'stop' => $this->stop,
            'streaming' => $this->streaming ?: null,
        ], static fn (mixed $v): bool => $v !== null);
    }

    public function llmType(): string
    {
        return 'fireworks';
    }

    /**
     * @return list<string>
     */
    public static function lcNamespace(): array
    {
        return ['langchain', 'llms', 'fireworks'];
    }

    /**
     * @return array<string, string>
     */
    public static function lcSecrets(): array
    {
        return ['fireworksApiKey' => 'FIREWORKS_API_KEY', 'apiKey' => 'FIREWORKS_API_KEY'];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function invocationParams(array $options = []): array
    {
        $stop = $options['stop'] ?? $options['stopSequences'] ?? $this->stop;

        return array_filter([
            'model' => $options['model'] ?? $options['modelName'] ?? $this->model,
            'temperature' => $options['temperature'] ?? $this->temperature,
            'max_tokens' => $options['maxTokens'] ?? $options['max_tokens'] ?? $this->maxTokens,
            'top_p' => $options['topP'] ?? $options['top_p'] ?? $this->topP,
            'stop' => $stop,
        ], static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @return array<string, mixed>
     */
    public function identifyingParams(): array
    {
        return $this->invocationParams();
    }

    /**
     * POST a completions request, after Fireworks' normalisation.
     *
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    public function completionWithRetry(array $request): array
    {
        $request = $this->normaliseRequest($request);

        return $this->caller->call(fn (): array => $this->send($request));
    }

    protected function call(string $prompt, array $options = [], ?CallbackManagerForLLMRun $runManager = null): string
    {
        $response = $this->completionWithRetry($this->invocationParams($options) + ['prompt' => $prompt]);

        $text = $response['choices'][0]['text'] ?? '';

        return is_string($text) ? $text : '';
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return \Generator<int, GenerationChunk>
     */
    protected function streamResponseChunks(
        string $prompt,
        array $options = [],
        ?CallbackManagerForLLMRun $runManager = null,
    ): \Generator {
        $request = $this->normaliseRequest($this->invocationParams($options) + ['prompt' => $prompt, 'stream' => true]);
        $parser = new SseParser();

        try {
            foreach ($this->http()->postStream($this->url(), $this->headers(), Js::encode($request), [], $this->timeout) as $bytes) {
                yield from $this->chunksOf($parser->feed($bytes), $runManager);
            }
            yield from $this->chunksOf($parser->flush(), $runManager);
        } catch (HttpException $e) {
            throw $e->status === 0 ? $e : FireworksResponseError::fromBody($e->status, $e->body);
        }
    }

    /**
     * @param \Generator<int, string> $payloads
     *
     * @return \Generator<int, GenerationChunk>
     */
    private function chunksOf(\Generator $payloads, ?CallbackManagerForLLMRun $runManager): \Generator
    {
        foreach ($payloads as $payload) {
            $decoded = json_decode($payload, true);
            $choice = is_array($decoded) ? ($decoded['choices'][0] ?? null) : null;
            if (!is_array($choice)) {
                continue;
            }

            $text = is_string($choice['text'] ?? null) ? $choice['text'] : '';
            $info = array_filter(['finish_reason' => $choice['finish_reason'] ?? null], static fn (mixed $v): bool => $v !== null);

            yield new GenerationChunk($text, $info);

            if ($text !== '') {
                $runManager?->handleLLMNewToken($text);
            }
        }
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function normaliseRequest(array $request): array
    {
        if (is_array($request['prompt'] ?? null)) {
            if (count($request['prompt']) > 1) {
                throw new \InvalidArgumentException('Multiple prompts are not supported by Fireworks');
            }

            $prompt = array_values($request['prompt'])[0] ?? null;
            if (!is_string($prompt)) {
                throw new \InvalidArgumentException('Only string prompts are supported by Fireworks');
            }

            $request['prompt'] = $prompt;
        }

        return array_diff_key($request, array_flip(self::UNSUPPORTED_REQUEST_PARAMS));
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function send(array $request): array
    {
        try {
            $response = $this->http()->post($this->url(), $this->headers(), Js::encode($request), [], $this->timeout);
        } catch (HttpException $e) {
            throw $e->status === 0 ? $e : FireworksResponseError::fromBody($e->status, $e->body);
        }

        if (!$response->isOk()) {
            throw FireworksResponseError::fromBody($response->status, $response->body);
        }

        return $response->json();
    }

    private function url(): string
    {
        return $this->baseUrl . '/completions';
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->apiKey, 'Content-Type' => 'application/json'];
    }

    private function http(): HttpClient
    {
        return $this->httpClient ??= new GuzzleHttpClient();
    }

    private static function firstNonEmpty(mixed ...$candidates): ?string
    {
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }
}
