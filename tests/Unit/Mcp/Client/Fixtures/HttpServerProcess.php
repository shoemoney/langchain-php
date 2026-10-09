<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp\Client\Fixtures;

/**
 * Starts `php -S` on a free loopback port with {@see HttpRouter.php} and logs what it receives.
 */
final class HttpServerProcess
{
    /** @var resource */
    private $process;

    private function __construct($process, public readonly string $baseUrl, private readonly string $dir)
    {
        $this->process = $process;
    }

    public static function start(): self
    {
        $dir = sys_get_temp_dir() . '/lcphp-mcp-' . bin2hex(random_bytes(6));
        mkdir($dir);

        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) strrchr((string) stream_socket_get_name($probe, false), ':'), 1);
        fclose($probe);

        $process = proc_open(
            [\PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . '/HttpRouter.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['LCPHP_MCP_DIR' => $dir, 'PATH' => (string) getenv('PATH')],
        );
        if (!\is_resource($process)) {
            throw new \RuntimeException('Could not start the MCP fixture HTTP server.');
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
        proc_close($process);

        throw new \RuntimeException('The MCP fixture HTTP server did not start.');
    }

    public function url(string $variant): string
    {
        return "{$this->baseUrl}/{$variant}";
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

    /** @return list<array{method: string, path: string, headers: array<string, string>, body: string}> */
    public function requestsWithMethod(string $method): array
    {
        return array_values(array_filter($this->requests(), static fn (array $r): bool => $r['method'] === $method));
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
}
