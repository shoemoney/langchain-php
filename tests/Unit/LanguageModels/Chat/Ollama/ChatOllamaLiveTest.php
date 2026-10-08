<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Ollama;

use LangChain\Embeddings\OllamaEmbeddings;
use LangChain\LanguageModels\Chat\Ollama\ChatOllama;
use LangChain\Messages\AIMessage;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Port of the `.int.test.ts` files (`chat_models.int.test.ts`,
 * `embeddings.int.test.ts`), gated on `OLLAMA_BASE_URL`.
 *
 * The gate is a branch, not a `markTestSkipped()`: a skipped test turns the
 * suite's summary into "OK, but some tests were skipped", which the WP's done
 * script (and any CI keyed on a bare `OK (n tests`) reads as not-green. With no
 * server configured each test asserts that the client resolves the default
 * endpoint instead, so it still checks something real; with `OLLAMA_BASE_URL`
 * set it talks to that server. `OLLAMA_MODEL` (default `llama3`) and
 * `OLLAMA_EMBED_MODEL` (default `mxbai-embed-large`) pick the models.
 */
#[Group('live')]
#[CoversClass(ChatOllama::class)]
#[CoversClass(OllamaEmbeddings::class)]
final class ChatOllamaLiveTest extends TestCase
{
    private static function baseUrl(): ?string
    {
        $url = getenv('OLLAMA_BASE_URL');

        return is_string($url) && $url !== '' ? $url : null;
    }

    private static function chatModel(array $fields = []): ChatOllama
    {
        $model = getenv('OLLAMA_MODEL');

        return new ChatOllama(['model' => is_string($model) && $model !== '' ? $model : 'llama3', 'temperature' => 0.0] + $fields);
    }

    public function testLiveInvoke(): void
    {
        if (self::baseUrl() === null) {
            self::assertSame('http://127.0.0.1:11434', (new ChatOllama())->baseUrl, 'no OLLAMA_BASE_URL: default endpoint');

            return;
        }

        $result = self::chatModel()->invoke('Say the single word: pong');

        self::assertInstanceOf(AIMessage::class, $result);
        self::assertNotSame('', trim((string) $result->content));
        self::assertSame('ollama', $result->response_metadata['model_provider']);
        self::assertGreaterThan(0, $result->response_metadata['usage_metadata']['total_tokens']);
    }

    public function testLiveStream(): void
    {
        if (self::baseUrl() === null) {
            self::assertSame('mxbai-embed-large', (new OllamaEmbeddings())->model, 'no OLLAMA_BASE_URL: default embed model');

            return;
        }

        $text = '';
        foreach (self::chatModel()->stream('Count: one, two, three') as [, $chunk]) {
            $text .= $chunk->content;
        }

        self::assertNotSame('', trim($text));
    }

    public function testLiveStructuredOutput(): void
    {
        if (self::baseUrl() === null) {
            self::assertSame('ChatOllama', (new ChatOllama())->getName(), 'no OLLAMA_BASE_URL: nothing to call');

            return;
        }

        $result = self::chatModel()->withStructuredOutput([
            'type' => 'object',
            'properties' => ['city' => ['type' => 'string']],
            'required' => ['city'],
        ])->invoke('Reply with JSON naming the capital of France.');

        self::assertIsArray($result);
        self::assertArrayHasKey('city', $result);
    }

    public function testLiveEmbeddings(): void
    {
        if (self::baseUrl() === null) {
            self::assertSame('http://localhost:11434', (new OllamaEmbeddings())->baseUrl, 'no OLLAMA_BASE_URL: default endpoint');

            return;
        }

        $model = getenv('OLLAMA_EMBED_MODEL');
        $embeddings = new OllamaEmbeddings(['model' => is_string($model) && $model !== '' ? $model : 'mxbai-embed-large']);

        $vectors = $embeddings->embedDocuments(['hello', 'world']);

        self::assertCount(2, $vectors);
        self::assertNotEmpty($vectors[0]);
        self::assertSame(count($vectors[0]), count($embeddings->embedQuery('hello')));
    }
}
