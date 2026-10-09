<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Ollama;

use LangChain\LanguageModels\BaseChatModel;
use LangChain\LanguageModels\Chat\OpenAI\Utils\Tools;
use LangChain\LanguageModels\Outputs\ChatGeneration;
use LangChain\LanguageModels\Outputs\ChatGenerationChunk;
use LangChain\LanguageModels\Outputs\ChatResult;
use LangChain\LanguageModels\StructuredOutput;
use LangChain\Messages\AIMessageChunk;
use LangChain\Messages\BaseMessage;
use LangChain\Runnables\Runnable;
use LangChain\Tracers\CallbackManagerForLLMRun;
use LangChain\Utils\Http\GuzzleHttpClient;
use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Js;

/**
 * A chat model backed by an Ollama server's own `/api/chat` endpoint.
 *
 * Port of `ChatOllama` from `@langchain/ollama`.
 *
 * This is Ollama's native protocol, not its OpenAI-compatible shim: tool
 * `arguments` are JSON objects, sampling parameters live in a nested `options`
 * object, reasoning arrives in a separate `thinking` field, and the response is
 * **newline-delimited JSON, not server-sent events**. {@see NdjsonParser} reads
 * it; reusing the SSE parser would silently yield nothing.
 *
 * Like upstream, every call streams on the wire. `invoke()` is the stream
 * folded into one message, which is what makes `think` and tool calls behave
 * identically on both paths.
 *
 * ## Differences from the TypeScript original
 *
 *  - No `AbortSignal`: PHP has no equivalent, so there is nothing to wire to
 *    `options.signal`.
 *  - `checkOrPullModel` probes `POST /api/show` rather than `GET /api/tags`,
 *    because the {@see HttpClient} seam is POST-only. A 404 means "not
 *    installed" and triggers {@see self::pull()}.
 *  - A constructor `stop` is honoured as the default stop list; upstream
 *    accepts the field and never reads it.
 */
class ChatOllama extends BaseChatModel
{
    public const DEFAULT_BASE_URL = 'http://127.0.0.1:11434';

    public string $model = 'llama3';

    public ?bool $numa = null;

    public ?int $numCtx = null;

    public ?int $numBatch = null;

    public ?int $numGpu = null;

    public ?int $mainGpu = null;

    public ?bool $lowVram = null;

    public ?bool $f16Kv = null;

    public ?bool $logitsAll = null;

    public ?bool $vocabOnly = null;

    public ?bool $useMmap = null;

    public ?bool $useMlock = null;

    public ?bool $embeddingOnly = null;

    public ?int $numThread = null;

    public ?int $numKeep = null;

    public ?int $seed = null;

    public ?int $numPredict = null;

    public ?int $topK = null;

    public ?float $topP = null;

    public ?float $tfsZ = null;

    public ?float $typicalP = null;

    public ?int $repeatLastN = null;

    public ?float $temperature = null;

    public ?float $repeatPenalty = null;

    public ?float $presencePenalty = null;

    public ?float $frequencyPenalty = null;

    public ?int $mirostat = null;

    public ?float $mirostatTau = null;

    public ?float $mirostatEta = null;

    public ?bool $penalizeNewline = null;
    public ?bool $streaming = null;

    /** @var string|array<string, mixed>|null */
    public string|array|null $format = null;

    public string|int|null $keepAlive = null;

    public ?bool $think = null;

    /** @var list<string>|null */
    public ?array $stop = null;

    public bool $checkOrPullModel = false;

    public string $baseUrl = self::DEFAULT_BASE_URL;

    /** @var array<string, string> */
    public array $headers = [];

    public ?float $timeout = null;

    public ?HttpClient $httpClient = null;

    /**
     * @param string|array<string, mixed> $modelOrFields A model name, or the full field map.
     * @param array<string, mixed>        $fieldsArg     Extra fields when the first argument is a model name.
     */
    public function __construct(string|array $modelOrFields = [], array $fieldsArg = [])
    {
        $fields = is_string($modelOrFields)
            ? [...$fieldsArg, 'model' => $modelOrFields]
            : $modelOrFields;

        parent::__construct($fields);

        $env = getenv('OLLAMA_BASE_URL');
        $this->baseUrl = rtrim((string) ($fields['baseUrl'] ?? (is_string($env) && $env !== '' ? $env : $this->baseUrl)), '/');

        $this->model = (string) ($fields['model'] ?? $this->model);
        $this->numa = isset($fields['numa']) ? (bool) $fields['numa'] : null;
        $this->numCtx = isset($fields['numCtx']) ? (int) $fields['numCtx'] : null;
        $this->numBatch = isset($fields['numBatch']) ? (int) $fields['numBatch'] : null;
        $this->numGpu = isset($fields['numGpu']) ? (int) $fields['numGpu'] : null;
        $this->mainGpu = isset($fields['mainGpu']) ? (int) $fields['mainGpu'] : null;
        $this->lowVram = isset($fields['lowVram']) ? (bool) $fields['lowVram'] : null;
        $this->f16Kv = isset($fields['f16Kv']) ? (bool) $fields['f16Kv'] : null;
        $this->logitsAll = isset($fields['logitsAll']) ? (bool) $fields['logitsAll'] : null;
        $this->vocabOnly = isset($fields['vocabOnly']) ? (bool) $fields['vocabOnly'] : null;
        $this->useMmap = isset($fields['useMmap']) ? (bool) $fields['useMmap'] : null;
        $this->useMlock = isset($fields['useMlock']) ? (bool) $fields['useMlock'] : null;
        $this->embeddingOnly = isset($fields['embeddingOnly']) ? (bool) $fields['embeddingOnly'] : null;
        $this->numThread = isset($fields['numThread']) ? (int) $fields['numThread'] : null;
        $this->numKeep = isset($fields['numKeep']) ? (int) $fields['numKeep'] : null;
        $this->seed = isset($fields['seed']) ? (int) $fields['seed'] : null;
        $this->numPredict = isset($fields['numPredict']) ? (int) $fields['numPredict'] : null;
        $this->topK = isset($fields['topK']) ? (int) $fields['topK'] : null;
        $this->topP = isset($fields['topP']) ? (float) $fields['topP'] : null;
        $this->tfsZ = isset($fields['tfsZ']) ? (float) $fields['tfsZ'] : null;
        $this->typicalP = isset($fields['typicalP']) ? (float) $fields['typicalP'] : null;
        $this->repeatLastN = isset($fields['repeatLastN']) ? (int) $fields['repeatLastN'] : null;
        $this->temperature = isset($fields['temperature']) ? (float) $fields['temperature'] : null;
        $this->repeatPenalty = isset($fields['repeatPenalty']) ? (float) $fields['repeatPenalty'] : null;
        $this->presencePenalty = isset($fields['presencePenalty']) ? (float) $fields['presencePenalty'] : null;
        $this->frequencyPenalty = isset($fields['frequencyPenalty']) ? (float) $fields['frequencyPenalty'] : null;
        $this->mirostat = isset($fields['mirostat']) ? (int) $fields['mirostat'] : null;
        $this->mirostatTau = isset($fields['mirostatTau']) ? (float) $fields['mirostatTau'] : null;
        $this->mirostatEta = isset($fields['mirostatEta']) ? (float) $fields['mirostatEta'] : null;
        $this->penalizeNewline = isset($fields['penalizeNewline']) ? (bool) $fields['penalizeNewline'] : null;
        $this->streaming = isset($fields['streaming']) ? (bool) $fields['streaming'] : null;
        $this->format = $fields['format'] ?? null;
        $this->keepAlive = $fields['keepAlive'] ?? null;
        $this->think = isset($fields['think']) ? (bool) $fields['think'] : null;
        $this->stop = isset($fields['stop']) ? array_values((array) $fields['stop']) : null;
        $this->checkOrPullModel = (bool) ($fields['checkOrPullModel'] ?? $this->checkOrPullModel);
        $this->headers = (array) ($fields['headers'] ?? []);
        $this->timeout = isset($fields['timeout']) ? (float) $fields['timeout'] : null;
        $this->httpClient = $fields['httpClient'] ?? null;

        // Caller-supplied values only (a resolved default here would mask a
        // later binding of the same key). `format`, `stop` and `tools` are
        // deliberately absent: invocationParams() reads those from the BOUND
        // layer, and echoing the constructor's copy there would blur the two.
        $this->kwargs += array_filter(
            array_intersect_key($fields, array_flip(['model', 'numa', 'numCtx', 'numBatch', 'numGpu', 'mainGpu', 'lowVram', 'f16Kv', 'logitsAll', 'vocabOnly', 'useMmap', 'useMlock', 'embeddingOnly', 'numThread', 'numKeep', 'seed', 'numPredict', 'topK', 'topP', 'tfsZ', 'typicalP', 'repeatLastN', 'temperature', 'repeatPenalty', 'presencePenalty', 'frequencyPenalty', 'mirostat', 'mirostatTau', 'mirostatEta', 'penalizeNewline', 'keepAlive', 'think'])),
            static fn (mixed $v): bool => $v !== null,
        );
    }

    public function llmType(): string
    {
        return 'ollama';
    }

    /** @return list<string> */
    public static function lcNamespace(): array
    {
        return ['langchain', 'chat_models', 'ollama'];
    }

    public function getName(): string
    {
        return 'ChatOllama';
    }

    /**
     * Download a model onto the server.
     *
     * @param array{stream?: bool, insecure?: bool, logProgress?: bool} $options
     */
    public function pull(string $model, array $options = []): void
    {
        $stream = $options['stream'] ?? true;
        $logProgress = $options['logProgress'] ?? false;

        $body = Js::encode(array_filter(
            ['model' => $model, 'insecure' => $options['insecure'] ?? null, 'stream' => $stream],
            static fn (mixed $v): bool => $v !== null,
        ));

        foreach ($this->records($this->url('/api/pull'), $body) as $record) {
            if ($logProgress) {
                error_log((string) json_encode($record));
            }
        }
    }

    /**
     * Offer tools to the model.
     *
     * Returns a new instance. Tools are rendered in the OpenAI function shape,
     * which is the shape `/api/chat` accepts.
     *
     * @param list<mixed>          $tools
     * @param array<string, mixed> $kwargs
     */
    public function bindTools(array $tools, array $kwargs = []): static
    {
        $next = clone $this;
        $next->kwargs['tools'] = Tools::convertAll($tools);

        foreach ($kwargs as $key => $value) {
            if ($key !== 'tools') {
                $next->kwargs[$key] = $value;
            }
        }

        return $next;
    }

    /**
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    protected function lsParams(array $options): array
    {
        $params = $this->invocationParams($options);

        return [
            'ls_provider' => 'ollama',
            'ls_model_name' => $this->model,
            'ls_model_type' => 'chat',
            'ls_temperature' => $params['options']['temperature'] ?? null,
            'ls_max_tokens' => $params['options']['num_predict'] ?? null,
            'ls_stop' => $options['stop'] ?? null,
        ];
    }

    /**
     * The request body minus `messages`.
     *
     * Layered options -> bound kwargs -> constructor state, the same order the
     * other provider clients use. Unset (null) values are dropped, as
     * `JSON.stringify` drops `undefined`; `think: false` is a real value and is
     * kept.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public function invocationParams(array $options = []): array
    {
        $bound = $this->kwargs;

        $tools = $options['tools'] ?? $bound['tools'] ?? null;

        $params = [
            'model' => $this->model,
            'format' => $options['format'] ?? $bound['format'] ?? $this->format,
            'keep_alive' => $this->keepAlive,
            'think' => $this->think,
            'options' => array_filter([
            'numa' => $this->numa,
            'num_ctx' => $this->numCtx,
            'num_batch' => $this->numBatch,
            'num_gpu' => $this->numGpu,
            'main_gpu' => $this->mainGpu,
            'low_vram' => $this->lowVram,
            'f16_kv' => $this->f16Kv,
            'logits_all' => $this->logitsAll,
            'vocab_only' => $this->vocabOnly,
            'use_mmap' => $this->useMmap,
            'use_mlock' => $this->useMlock,
            'embedding_only' => $this->embeddingOnly,
            'num_thread' => $this->numThread,
            'num_keep' => $this->numKeep,
            'seed' => $this->seed,
            'num_predict' => $this->numPredict,
            'top_k' => $this->topK,
            'top_p' => $this->topP,
            'tfs_z' => $this->tfsZ,
            'typical_p' => $this->typicalP,
            'repeat_last_n' => $this->repeatLastN,
            'temperature' => $this->temperature,
            'repeat_penalty' => $this->repeatPenalty,
            'presence_penalty' => $this->presencePenalty,
            'frequency_penalty' => $this->frequencyPenalty,
            'mirostat' => $this->mirostat,
            'mirostat_tau' => $this->mirostatTau,
            'mirostat_eta' => $this->mirostatEta,
            'penalize_newline' => $this->penalizeNewline,
                'stop' => $options['stop'] ?? $bound['stop'] ?? $this->stop,
            ], static fn (mixed $v): bool => $v !== null),
            'tools' => is_array($tools) && $tools !== [] ? Tools::convertAll($tools) : null,
        ];

        // An empty options object is omitted: Ollama treats it as "no overrides".
        if ($params['options'] === []) {
            unset($params['options']);
        }

        return array_filter($params, static fn (mixed $v): bool => $v !== null);
    }

    /**
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     */
    protected function generate(array $messages, array $options = [], ?CallbackManagerForLLMRun $runManager = null): ChatResult
    {
        $final = null;
        foreach ($this->streamResponseChunks($messages, $options, $runManager) as $chunk) {
            $final = $final === null ? $chunk : $final->concat($chunk);
        }

        // Fold to a finished message: `generate` deals in AIMessage, not chunks.
        $message = $final === null
            ? new \LangChain\Messages\AIMessage('')
            : $final->message->toMessage();
        $text = is_string($message->content) ? $message->content : '';

        return new ChatResult(
            [new ChatGeneration($message, $text)],
            $this->llmOutputFromUsage($message),
        );
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
        $this->ensureModelAvailable();

        $usage = ['input_tokens' => 0, 'output_tokens' => 0, 'total_tokens' => 0];
        $lastMetadata = [];

        foreach ($this->records($this->url('/api/chat'), $this->chatBody($messages, $options)) as $record) {
            $responseMessage = (array) ($record['message'] ?? []);
            unset($record['message']);

            $usage['input_tokens'] += (int) ($record['prompt_eval_count'] ?? 0);
            $usage['output_tokens'] += (int) ($record['eval_count'] ?? 0);
            $usage['total_tokens'] = $usage['input_tokens'] + $usage['output_tokens'];
            $lastMetadata = $record;

            // When think is enabled, show the reasoning first (`??`: only a
            // missing field falls through to the content).
            $token = $this->think === true
                ? ($responseMessage['thinking'] ?? $responseMessage['content'] ?? '')
                : ($responseMessage['content'] ?? '');

            $generation = new ChatGenerationChunk(
                OllamaUtils::convertOllamaMessagesToLangChain($responseMessage),
                (string) $token,
            );

            yield $generation;

            if ($generation->text !== '') {
                $runManager?->handleLLMNewToken($generation->text, null, ['chunk' => $generation]);
            }
        }

        // The final chunk carries the response metadata and the token counts.
        yield new ChatGenerationChunk(
            new AIMessageChunk([
                'content' => '',
                'response_metadata' => $lastMetadata + ['model_provider' => 'ollama'] + ['usage_metadata' => $usage],
            ]),
            '',
        );
    }

    /**
     * Stream the response as protocol-style events.
     *
     * Port of `_streamChatModelEvents`; see {@see OllamaStreamEvents} for the
     * event vocabulary.
     *
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function streamChatModelEvents(array $messages, array $options = []): \Generator
    {
        $this->ensureModelAvailable();

        $records = $this->records($this->url('/api/chat'), $this->chatBody($messages, $options));

        yield from OllamaStreamEvents::convert($records, [
            'streamUsage' => (bool) ($options['streamUsage'] ?? true),
            'think' => $this->think === true,
        ]);
    }

    /**
     * Ask for a value matching a schema.
     *
     * The default method is `jsonSchema`: the schema goes out as the request's
     * `format`, and the reply text is parsed. `jsonMode` sends `format: "json"`
     * instead, and `functionCalling` offers the schema as a tool.
     *
     * Upstream also validates the parsed value through Zod or a Standard
     * Schema; this port is JSON-Schema-native and does not, so a reply that is
     * valid JSON but the wrong shape is returned as parsed.
     *
     * @param array<string, mixed> $schema
     * @param array{name?: string, description?: string, method?: string, includeRaw?: bool, strict?: bool} $config
     */
    public function withStructuredOutput(array $schema, array $config = []): Runnable
    {
        $method = $config['method'] ?? 'jsonSchema';
        $includeRaw = (bool) ($config['includeRaw'] ?? false);

        if ($method === 'functionCalling') {
            $functionName = (string) ($config['name'] ?? 'extract');

            if (is_string($schema['name'] ?? null) && is_array($schema['parameters'] ?? null)) {
                $toolFunction = $schema;
                $functionName = $schema['name'];
            } else {
                $toolFunction = [
                    'name' => $functionName,
                    'description' => (string) ($schema['description'] ?? ''),
                    'parameters' => $schema,
                ];
            }

            $llm = $this->bindTools([['type' => 'function', 'function' => $toolFunction]]);
            $parser = StructuredOutput::createFunctionCallingParser($functionName);
        } elseif ($method === 'jsonMode' || $method === 'jsonSchema') {
            $parser = StructuredOutput::createContentParser();
            // `bind` puts `format` in the per-call options, where
            // invocationParams() reads it first.
            $llm = $this->bind(['format' => $method === 'jsonMode' ? 'json' : $schema]);
        } else {
            throw new \TypeError(
                "Unrecognized structured output method '{$method}'. Expected one of 'functionCalling', 'jsonMode', or 'jsonSchema'"
            );
        }

        return StructuredOutput::assembleStructuredOutputPipeline(
            $llm,
            $parser,
            $includeRaw,
            $includeRaw ? 'StructuredOutputRunnable' : 'ChatOllamaStructuredOutput',
        );
    }

    /**
     * @param list<BaseMessage>    $messages
     * @param array<string, mixed> $options
     */
    private function chatBody(array $messages, array $options): string
    {
        // Assigned outright, after the params, so a model option can never
        // displace the conversation.
        $params = $this->invocationParams($options);
        $params['messages'] = OllamaUtils::convertToOllamaMessages($messages);
        $params['stream'] = true;

        return Js::encode($params);
    }

    /**
     * Whether the configured model is installed, pulling it if not.
     */
    private function ensureModelAvailable(): void
    {
        if (!$this->checkOrPullModel) {
            return;
        }

        $response = $this->send($this->url('/api/show'), Js::encode(['model' => $this->model]));

        if ($response->status === 404) {
            $this->pull($this->model, ['logProgress' => true]);

            return;
        }

        if (!$response->isOk()) {
            throw OllamaException::fromResponse($response->body, $response->status, $this->url('/api/show'));
        }
    }

    private function send(string $url, string $body): \LangChain\Utils\Http\HttpResponse
    {
        try {
            return $this->http()->post($url, $this->requestHeaders(), $body, [], $this->timeout);
        } catch (OllamaException $e) {
            throw $e;
        } catch (HttpException $e) {
            throw OllamaException::fromResponse($e->body, $e->status, $url, $e);
        } catch (\Throwable $e) {
            throw new OllamaException('The HTTP transport raised ' . $e::class . ': ' . $e->getMessage(), 0, '', $e);
        }
    }

    /**
     * POST and decode the NDJSON reply, one record at a time.
     *
     * The transport `try` lives HERE and not in the callers: a consumer's own
     * exception (a callback handler, say) is thrown at the caller's `yield`,
     * outside this generator, and must not be re-labelled as a transport
     * failure. Equally, `postStream()` is a generator function, so a `try`
     * around the call alone would never see a connect failure; the drain has to
     * be inside it.
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function records(string $url, string $body): \Generator
    {
        $parser = new NdjsonParser();

        try {
            foreach ($this->http()->postStream($url, $this->requestHeaders(), $body, [], $this->timeout) as $bytes) {
                yield from $this->checked($parser->feed($bytes));
            }

            yield from $this->checked($parser->flush());
        } catch (OllamaException $e) {
            throw $e;
        } catch (HttpException $e) {
            throw OllamaException::fromResponse($e->body, $e->status, $url, $e);
        } catch (\Throwable $e) {
            if ($e instanceof \Error) {
                throw $e;
            }
            throw new OllamaException('The HTTP transport raised ' . $e::class . ': ' . $e->getMessage(), 0, '', $e);
        }
    }

    /**
     * Ollama reports a mid-stream failure as an `{"error": "..."}` line with a
     * 200 status already sent.
     *
     * @param \Generator<int, array<string, mixed>> $records
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function checked(\Generator $records): \Generator
    {
        foreach ($records as $record) {
            if (isset($record['error']) && is_string($record['error'])) {
                throw new OllamaException($record['error'], 0, (string) json_encode($record));
            }

            yield $record;
        }
    }

    private function http(): HttpClient
    {
        return $this->httpClient ??= new GuzzleHttpClient();
    }

    private function url(string $path): string
    {
        return $this->baseUrl . $path;
    }

    /** @return array<string, string> */
    private function requestHeaders(): array
    {
        return $this->headers + ['Content-Type' => 'application/json'];
    }
}
