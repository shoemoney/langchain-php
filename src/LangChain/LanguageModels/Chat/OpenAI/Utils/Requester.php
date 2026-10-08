<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\OpenAI\Utils;

use LangChain\LanguageModels\Chat\OpenAI\OpenAIException;
use LangChain\Utils\Http\HttpClient;
use LangChain\Utils\Http\HttpException;
use LangChain\Utils\Http\SseParser;
use LangChain\Utils\Js;

/**
 * Retrying JSON transport for the OpenAI clients that are not chat models.
 *
 * The stand-in for the `openai` SDK client plus `wrapOpenAIClientError` that
 * `llms.ts` and `embeddings.ts` use. It follows the retry rules of
 * {@see \LangChain\LanguageModels\Chat\OpenAI\BaseChatOpenAI}: transport errors,
 * 429 and 5xx are retried up to `maxRetries` extra attempts, a 4xx never is,
 * and a stream is retried only until its first byte has been delivered.
 */
final class Requester
{
    /**
     * @param \Closure(int): void|null $backoff Waits before attempt N; null sleeps 1s, 2s, 4s, ... up to 8s.
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly int $maxRetries = 2,
        private readonly ?float $timeout = null,
        private readonly ?\Closure $backoff = null,
    ) {
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $params
     * @param array<string, mixed>  $query
     *
     * @return array<string, mixed>
     */
    public function post(string $url, array $headers, array $params, array $query = []): array
    {
        $body = Js::encode($params);
        $attempt = 0;

        while (true) {
            try {
                $response = $this->http->post($url, $headers, $body, $query, $this->timeout);
            } catch (OpenAIException $e) {
                throw $e;
            } catch (HttpException $e) {
                if (!self::retryable($e->status) || $attempt++ >= $this->maxRetries) {
                    throw OpenAIException::fromResponse($e->body, $e->status, $url, previous: $e);
                }
                $this->wait($attempt);

                continue;
            } catch (\Throwable $e) {
                throw new OpenAIException('The HTTP transport raised ' . $e::class . ': ' . $e->getMessage(), 0, '', previous: $e);
            }

            if ($response->isOk()) {
                return $response->json();
            }

            if (!self::retryable($response->status) || $attempt++ >= $this->maxRetries) {
                throw OpenAIException::fromResponse($response->body, $response->status, $url);
            }
            $this->wait($attempt);
        }
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $params
     * @param array<string, mixed>  $query
     *
     * @return \Generator<int, array<string, mixed>>
     */
    public function postStream(string $url, array $headers, array $params, array $query = []): \Generator
    {
        $body = Js::encode($params);
        $attempt = 0;

        while (true) {
            $parser = new SseParser();
            $delivered = false;

            try {
                foreach ($this->http->postStream($url, $headers, $body, $query, $this->timeout) as $bytes) {
                    if ($bytes !== '') {
                        $delivered = true;
                    }
                    yield from $this->decode($parser->feed($bytes), $url);
                }
                yield from $this->decode($parser->flush(), $url);

                return;
            } catch (OpenAIException $e) {
                throw $e;
            } catch (HttpException $e) {
                if ($delivered || !self::retryable($e->status) || $attempt++ >= $this->maxRetries) {
                    throw OpenAIException::fromResponse($e->body, $e->status, $url, previous: $e);
                }
                $this->wait($attempt);
            } catch (\Throwable $e) {
                throw new OpenAIException('The HTTP transport raised ' . $e::class . ': ' . $e->getMessage(), 0, '', previous: $e);
            }
        }
    }

    /**
     * @param \Generator<int, string> $payloads
     *
     * @return \Generator<int, array<string, mixed>>
     */
    private function decode(\Generator $payloads, string $url): \Generator
    {
        foreach ($payloads as $payload) {
            try {
                $decoded = json_decode($payload, true, 512, \JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw new OpenAIException('Received a malformed event from the OpenAI stream: ' . $e->getMessage() . '. Payload: ' . $payload, 0, $payload);
            }

            if (!is_array($decoded)) {
                continue;
            }
            if (isset($decoded['error']) && is_array($decoded['error'])) {
                throw OpenAIException::fromResponse((string) json_encode($decoded), 0, $url);
            }

            yield $decoded;
        }
    }

    private static function retryable(int $status): bool
    {
        return $status === 0 || $status === 429 || $status >= 500;
    }

    private function wait(int $attempt): void
    {
        if ($this->backoff !== null) {
            ($this->backoff)($attempt);

            return;
        }

        usleep((int) (min(2 ** ($attempt - 1), 8) * 1_000_000));
    }
}
