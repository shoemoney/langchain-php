<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Tools;

use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\Tools\Tool;
use LangChain\Tracers\CallbackManagerForToolRun;
use LangChain\Runnables\RunnableConfig;
use LangChain\Utils\Env;
use LangChain\Utils\Http\GuzzleHttpClient;
use LangChain\Utils\Http\HttpClient;

/**
 * A tool that generates images with OpenAI's images endpoint.
 *
 * Port of `DallEAPIWrapper` from `@langchain/openai`. Input is an image
 * description; output is the image URL (or base64 payload) for `n = 1`, and a
 * list of `image_url` content blocks for `n > 1`. The HTTP client is injectable
 * (`httpClient`) so tests script the endpoint instead of calling it.
 */
final class DallEAPIWrapper extends Tool
{
    public const TOOL_NAME = 'dalle_api_wrapper';

    private const DEFAULT_BASE_URL = 'https://api.openai.com/v1';

    private HttpClient $client;

    private string $apiKey;

    private ?string $organization;

    private ?string $baseUrl;

    private string $model = 'dall-e-3';

    private string $style = 'vivid';

    private string $quality = 'standard';

    private int $n = 1;

    private string $size = '1024x1024';

    private string $dallEResponseFormat = 'url';

    private ?string $user;

    /**
     * @param array<string, mixed> $fields `apiKey`/`openAIApiKey`, `model`/`modelName`, `style`
     *        (natural|vivid), `quality` (standard|hd), `n`, `size`, `dallEResponseFormat`
     *        (url|b64_json; `responseFormat` is accepted as its alias), `user`, `organization`,
     *        `baseUrl`, `httpClient`.
     */
    public function __construct(array $fields = [])
    {
        // A url/b64_json `responseFormat` is this tool's image format; the base tool's own
        // `responseFormat` is then reset to "content", as upstream's constructor shim does.
        if (in_array($fields['responseFormat'] ?? null, ['url', 'b64_json'], true)) {
            $fields['dallEResponseFormat'] = $fields['responseFormat'];
            $fields['responseFormat'] = 'content';
        }

        parent::__construct($fields + [
            'name' => self::TOOL_NAME,
            'description' => 'A wrapper around OpenAI DALL-E API. Useful for when you need to generate images from a text description. Input should be an image description.',
        ]);

        $this->apiKey = (string) ($fields['apiKey'] ?? $fields['openAIApiKey'] ?? Env::getEnvironmentVariable('OPENAI_API_KEY') ?? '');
        $this->organization = $fields['organization'] ?? Env::getEnvironmentVariable('OPENAI_ORGANIZATION');
        $this->baseUrl = $fields['baseUrl'] ?? null;
        $this->client = $fields['httpClient'] ?? new GuzzleHttpClient();
        $this->model = (string) ($fields['model'] ?? $fields['modelName'] ?? $this->model);
        $this->style = (string) ($fields['style'] ?? $this->style);
        $this->quality = (string) ($fields['quality'] ?? $this->quality);
        $this->n = (int) ($fields['n'] ?? $this->n);
        $this->size = (string) ($fields['size'] ?? $this->size);
        $this->dallEResponseFormat = (string) ($fields['dallEResponseFormat'] ?? $this->dallEResponseFormat);
        $this->user = $fields['user'] ?? null;
    }

    public static function lcName(): string
    {
        return 'DallEAPIWrapper';
    }

    protected function callTool(mixed $arg, ?CallbackManagerForToolRun $runManager = null, ?RunnableConfig $parentConfig = null): mixed
    {
        $input = is_array($arg) && array_key_exists('input', $arg) ? $arg['input'] : $arg;
        $prompt = (string) $input;

        if ($this->n > 1) {
            $responses = [];
            for ($i = 0; $i < $this->n; $i++) {
                $responses[] = $this->generate($prompt);
            }

            return $this->processMultipleGeneratedUrls($responses);
        }

        $response = $this->generate($prompt);
        $key = $this->dallEResponseFormat === 'url' ? 'url' : 'b64_json';

        foreach ($response['data'] ?? [] as $item) {
            $value = $item[$key] ?? null;
            if ($value !== null && $value !== 'undefined') {
                return $value;
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    private function generate(string $prompt): array
    {
        $body = array_filter([
            'model' => $this->model,
            'prompt' => $prompt,
            'n' => 1,
            'size' => $this->size,
            'response_format' => $this->dallEResponseFormat,
            'style' => $this->style,
            'quality' => $this->quality,
            'user' => $this->user,
        ], static fn (mixed $v): bool => $v !== null);

        $url = rtrim($this->baseUrl ?? self::DEFAULT_BASE_URL, '/') . '/images/generations';
        $headers = ['Authorization' => 'Bearer ' . $this->apiKey, 'Content-Type' => 'application/json'];
        if ($this->organization !== null) {
            $headers['OpenAI-Organization'] = $this->organization;
        }

        $response = $this->client->post($url, $headers, (string) json_encode($body));
        if (!$response->isOk()) {
            throw OpenAIException::fromResponse($response->body, $response->status, $url);
        }

        return $response->json();
    }

    /**
     * @param list<array<string, mixed>> $responses
     *
     * @return list<array<string, mixed>>
     */
    private function processMultipleGeneratedUrls(array $responses): array
    {
        $blocks = [];
        foreach ($responses as $response) {
            foreach ($response['data'] ?? [] as $item) {
                if ($this->dallEResponseFormat === 'url') {
                    if (is_string($item['url'] ?? null)) {
                        $blocks[] = ['type' => 'image_url', 'image_url' => $item['url']];
                    }
                } elseif (is_string($item['b64_json'] ?? null)) {
                    $blocks[] = ['type' => 'image_url', 'image_url' => ['url' => $item['b64_json']]];
                }
            }
        }

        return $blocks;
    }
}
