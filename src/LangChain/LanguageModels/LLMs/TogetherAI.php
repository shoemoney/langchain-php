<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\LLMs;

use LangChain\LanguageModels\Chat\TogetherAI\TogetherAIException;
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
 * Together AI text-completion LLM, over the `/inference` endpoint.
 *
 * Port of `TogetherAI` (`llms.ts`) from `@langchain/together-ai`.
 *
 * This is the legacy completion API. A chat or instruct model answers it with a
 * response of the wrong shape, which is reported with a pointer to
 * {@see \LangChain\LanguageModels\Chat\TogetherAI\ChatTogetherAI}.
 */
class TogetherAI extends LLM
{
    public const INFERENCE_API_URL = 'https://api.together.xyz/inference';

    public float $temperature = 0.7;

    public float $topP = 0.7;

    public int $topK = 50;

    public string $modelName;

    public string $model;

    public bool $streaming = false;

    public float $repetitionPenalty = 1;

    public ?int $logprobs = null;

    public ?int $maxTokens = null;

    public ?string $safetyModel = null;

    /** @var list<string>|null */
    public ?array $stop = null;

    public ?float $timeout = null;

    public ?HttpClient $httpClient = null;

    private string $apiKey;

    private AsyncCaller $caller;

    /**
     * @param array<string, mixed> $inputs `apiKey`, `model` / `modelName`, `temperature`, `topP`, `topK`,
     *                                     `repetitionPenalty`, `logprobs`, `safetyModel`, `maxTokens`, `stop`,
     *                                     `streaming`, `maxRetries`, `timeout`, `httpClient`, `asyncCaller`, `sleeper`.
     */
    public function __construct(array $inputs = [])
    {
        parent::__construct($inputs);

        $apiKey = $inputs['apiKey'] ?? Env::getEnvironmentVariable('TOGETHER_AI_API_KEY');
        if (!is_string($apiKey) || $apiKey === '') {
            throw new \InvalidArgumentException('TOGETHER_AI_API_KEY not found.');
        }

        $name = $inputs['model'] ?? $inputs['modelName'] ?? null;
        if (!is_string($name) || $name === '') {
            throw new \InvalidArgumentException('Model name is required for TogetherAI.');
        }

        $this->apiKey = $apiKey;
        $this->temperature = (float) ($inputs['temperature'] ?? $this->temperature);
        $this->topK = (int) ($inputs['topK'] ?? $this->topK);
        $this->topP = (float) ($inputs['topP'] ?? $this->topP);
        $this->modelName = $name;
        $this->model = $name;
        $this->streaming = (bool) ($inputs['streaming'] ?? $this->streaming);
        $this->repetitionPenalty = (float) ($inputs['repetitionPenalty'] ?? $this->repetitionPenalty);
        $this->logprobs = isset($inputs['logprobs']) ? (int) $inputs['logprobs'] : null;
        $this->safetyModel = isset($inputs['safetyModel']) ? (string) $inputs['safetyModel'] : null;
        $this->maxTokens = isset($inputs['maxTokens']) ? (int) $inputs['maxTokens'] : null;
        $this->stop = isset($inputs['stop']) ? array_values((array) $inputs['stop']) : null;
        $this->timeout = isset($inputs['timeout']) ? (float) $inputs['timeout'] : null;
        $this->httpClient = $inputs['httpClient'] ?? null;
        $this->caller = $inputs['asyncCaller'] ?? new AsyncCaller(
            maxRetries: (int) ($inputs['maxRetries'] ?? 6),
            sleeper: $inputs['sleeper'] ?? null,
        );

        // What a rebuilt model needs; the credential and the transport stay out of the trace.
        $this->kwargs += array_filter([
            'model' => $this->model,
            'temperature' => $this->temperature,
            'topP' => $this->topP,
            'topK' => $this->topK,
            'repetitionPenalty' => $this->repetitionPenalty,
            'logprobs' => $this->logprobs,
            'safetyModel' => $this->safetyModel,
            'maxTokens' => $this->maxTokens,
            'stop' => $this->stop,
            'streaming' => $this->streaming ?: null,
        ], static fn (mixed $v): bool => $v !== null);

        if ($this->isChatModel($this->model)) {
            trigger_error(
                "Warning: Model '{$this->model}' appears to be a chat/instruct model. "
                . 'Consider using ChatTogetherAI from the Together AI chat model instead.',
                \E_USER_WARNING,
            );
        }
    }

    public function llmType(): string
    {
        return 'together_ai';
    }

    /**
     * @return list<string>
     */
    public static function lcNamespace(): array
    {
        return ['langchain', 'llms', 'together_ai'];
    }

    /**
     * @return array<string, string>
     */
    public static function lcSecrets(): array
    {
        return ['apiKey' => 'TOGETHER_AI_API_KEY'];
    }

    /**
     * @return array<string, string>
     */
    public static function lcAliases(): array
    {
        return ['apiKey' => 'together_ai_api_key'];
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function invocationParams(array $options = []): array
    {
        return $this->constructBody($options);
    }

    /**
     * @return array<string, mixed>
     */
    public function identifyingParams(): array
    {
        return $this->constructBody();
    }

    /**
     * POST one prompt to the inference endpoint.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function completionWithRetry(string $prompt, array $options = []): array
    {
        $body = Js::encode($this->constructBody($options) + ['prompt' => $prompt]);

        return $this->caller->call(function () use ($body): array {
            try {
                $response = $this->http()->post(self::INFERENCE_API_URL, $this->headers(), $body, [], $this->timeout);
            } catch (HttpException $e) {
                throw $e->status === 0 ? $e : TogetherAIException::fromBody($e->status, $e->body);
            }

            if ($response->status !== 200) {
                throw TogetherAIException::fromBody($response->status, $response->body);
            }

            return $response->json();
        });
    }

    protected function call(string $prompt, array $options = [], ?CallbackManagerForLLMRun $runManager = null): string
    {
        $response = $this->completionWithRetry($prompt, $options);

        if (!isset($response['output']) && !isset($response['choices'])) {
            throw new \UnexpectedValueException(
                "Unexpected response format from Together AI. The model '{$this->model}' may require the "
                . 'ChatTogetherAI class instead of TogetherAI class. Response: '
                . json_encode($response, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)
            );
        }

        $choices = isset($response['output']) ? ($response['output']['choices'] ?? null) : $response['choices'];
        $text = is_array($choices) ? ($choices[0]['text'] ?? '') : '';

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
        // Streaming is what was asked for here, whatever the `streaming` default says.
        $body = Js::encode(['stream_tokens' => true] + $this->constructBody($options) + ['prompt' => $prompt]);
        $parser = new SseParser();

        try {
            foreach ($this->http()->postStream(self::INFERENCE_API_URL, $this->headers(), $body, [], $this->timeout) as $bytes) {
                yield from $this->chunksOf($parser->feed($bytes), $runManager);
            }
            yield from $this->chunksOf($parser->flush(), $runManager);
        } catch (HttpException $e) {
            throw $e->status === 0 ? $e : TogetherAIException::fromBody($e->status, $e->body);
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
            $parsed = json_decode($payload, true);
            $text = is_array($parsed) && is_string($parsed['choices'][0]['text'] ?? null) ? $parsed['choices'][0]['text'] : '';

            yield new GenerationChunk($text);

            $runManager?->handleLLMNewToken($text);
        }
    }

    /**
     * The request body. Call options win over constructor defaults; unset values are dropped.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    private function constructBody(array $options = []): array
    {
        return array_filter([
            'model' => $options['model'] ?? $options['modelName'] ?? $this->model,
            'temperature' => $options['temperature'] ?? $this->temperature,
            'top_k' => $options['topK'] ?? $this->topK,
            'top_p' => $options['topP'] ?? $this->topP,
            'repetition_penalty' => $options['repetitionPenalty'] ?? $this->repetitionPenalty,
            'logprobs' => $options['logprobs'] ?? $this->logprobs,
            'stream_tokens' => $this->streaming,
            'safety_model' => $options['safetyModel'] ?? $this->safetyModel,
            'max_tokens' => $options['maxTokens'] ?? $this->maxTokens,
            'stop' => $options['stop'] ?? $this->stop,
        ], static fn (mixed $v): bool => $v !== null);
    }

    private function isChatModel(string $modelName): bool
    {
        return preg_match('/instruct|chat|vision|turbo/i', $modelName) === 1;
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return [
            'accept' => 'application/json',
            'content-type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->apiKey,
        ];
    }

    private function http(): HttpClient
    {
        return $this->httpClient ??= new GuzzleHttpClient();
    }
}
