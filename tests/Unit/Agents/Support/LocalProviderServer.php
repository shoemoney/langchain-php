<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Support;

/**
 * A throwaway HTTP server on 127.0.0.1 (PHP's built-in one) that answers canned provider replies and logs every
 * request it receives.
 *
 * A "provider:model" string is resolved by `InitChatModel::init()` with no way to pass a transport, so the only seam
 * is the environment the provider clients read their base URL from (`OLLAMA_BASE_URL`, `LANGSMITH_GATEWAY`). Pointing
 * that at this server lets a test assert on the request the resolved model really sent.
 */
final class LocalProviderServer
{
    /** @var resource */
    private $process;

    private function __construct(
        $process,
        public readonly string $baseUrl,
        private readonly string $dir,
    ) {
        $this->process = $process;
    }

    /**
     * @param array<string, array{0: string, 1: string}> $routes request path suffix => [content type, body]
     */
    public static function start(array $routes): self
    {
        $dir = sys_get_temp_dir() . '/lcphp-provider-' . bin2hex(random_bytes(6));
        mkdir($dir);
        file_put_contents($dir . '/routes.json', json_encode($routes, \JSON_THROW_ON_ERROR));
        file_put_contents($dir . '/router.php', <<<'PHP'
            <?php
            $dir = getenv('LCPHP_PROVIDER_DIR');
            $path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
            file_put_contents($dir . '/log.ndjson', json_encode([
                'method' => $_SERVER['REQUEST_METHOD'],
                'path' => $path,
                'headers' => array_change_key_case(getallheaders(), CASE_LOWER),
                'body' => file_get_contents('php://input'),
            ]) . "\n", FILE_APPEND | LOCK_EX);
            foreach (json_decode(file_get_contents($dir . '/routes.json'), true) as $suffix => [$type, $body]) {
                if (str_ends_with($path, $suffix)) {
                    header('Content-Type: ' . $type);
                    echo $body;
                    return;
                }
            }
            http_response_code(404);
            PHP);

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $process = proc_open(
            [\PHP_BINARY, '-S', "127.0.0.1:{$port}", $dir . '/router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['LCPHP_PROVIDER_DIR' => $dir, 'PATH' => (string) getenv('PATH')],
        );
        if (!\is_resource($process)) {
            throw new \RuntimeException('Could not start the local provider server.');
        }

        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.1);
            if ($socket !== false) {
                fclose($socket);

                return new self($process, "http://127.0.0.1:{$port}", $dir);
            }
            usleep(50_000);
        }
        proc_terminate($process);

        throw new \RuntimeException('The local provider server did not start.');
    }

    /** @return list<array{method: string, path: string, headers: array<string, string>, body: string}> */
    public function requests(): array
    {
        $log = $this->dir . '/log.ndjson';
        if (!is_file($log)) {
            return [];
        }

        return array_map(
            static fn (string $line): array => json_decode($line, true, 512, \JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", (string) file_get_contents($log)))),
        );
    }

    /** The decoded JSON body of the n-th request. @return array<string, mixed> */
    public function body(int $n): array
    {
        return (array) json_decode($this->requests()[$n]['body'], true);
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /** An Ollama `/api/chat` NDJSON reply. */
    public static function ollamaReply(string $text): array
    {
        $records = [
            ['model' => 'llama3', 'message' => ['role' => 'assistant', 'content' => $text], 'done' => false],
            ['model' => 'llama3', 'created_at' => '2026-10-08T00:00:00Z', 'message' => ['role' => 'assistant', 'content' => ''], 'done' => true, 'done_reason' => 'stop', 'prompt_eval_count' => 5, 'eval_count' => 5],
        ];

        return ['application/x-ndjson', implode('', array_map(static fn (array $r): string => json_encode($r) . "\n", $records))];
    }

    /** An OpenAI `/moderations` reply. */
    public static function moderationReply(bool $flagged, array $categories = []): array
    {
        return ['application/json', json_encode(['id' => 'modr-local', 'model' => 'omni-moderation-latest', 'results' => [[
            'flagged' => $flagged,
            'categories' => $categories,
            'category_scores' => array_map(static fn (): float => 0.9, $categories),
            'category_applied_input_types' => array_map(static fn (): array => ['text'], $categories),
        ]]])];
    }
}
