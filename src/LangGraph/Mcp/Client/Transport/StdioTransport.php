<?php

declare(strict_types=1);

namespace LangGraph\Mcp\Client\Transport;

use LangGraph\Mcp\Client\JsonRpc;
use LangGraph\Mcp\McpClientError;

/**
 * Newline-delimited JSON-RPC over a child process's stdin/stdout (`StdioClientTransport`).
 *
 * Config: `command`, `args`, `env` (merged over a small safe default environment, as the SDK
 * does), `cwd`, and `stderr` — `inherit` (default), `ignore`, or `pipe` (readable through
 * {@see self::stderrOutput()}).
 */
final class StdioTransport implements TransportInterface
{
    private const DEFAULT_ENV_KEYS = ['HOME', 'LOGNAME', 'PATH', 'SHELL', 'TERM', 'USER', 'TMPDIR'];

    private const SHUTDOWN_GRACE_SECONDS = 2.0;

    /** @var resource|null */
    private $process = null;

    /** @var resource|null */
    private $stdin = null;

    /** @var resource|null */
    private $stdout = null;

    /** @var resource|null */
    private $stderr = null;

    private string $readBuffer = '';

    private string $stderrBuffer = '';

    /**
     * @param array{command: string, args?: list<string>, env?: array<string, string>, cwd?: string|null, stderr?: 'inherit'|'ignore'|'pipe'} $config
     */
    public function __construct(private readonly array $config)
    {
        if (!isset($config['command']) || $config['command'] === '') {
            throw new McpClientError('The stdio transport needs a "command".');
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    public function start(): void
    {
        if ($this->process !== null) {
            throw new McpClientError('The stdio transport is already started.');
        }

        $mode = $this->config['stderr'] ?? 'inherit';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => match ($mode) {
                'pipe' => ['pipe', 'w'],
                'ignore' => ['file', '/dev/null', 'w'],
                default => \defined('STDERR') ? \STDERR : ['file', '/dev/null', 'w'],
            },
        ];

        $env = [];
        foreach (self::DEFAULT_ENV_KEYS as $key) {
            $value = getenv($key);
            if ($value !== false && !str_starts_with($value, '()')) {
                $env[$key] = $value;
            }
        }
        $env = [...$env, ...($this->config['env'] ?? [])];

        $process = @proc_open(
            [$this->config['command'], ...($this->config['args'] ?? [])],
            $descriptors,
            $pipes,
            $this->config['cwd'] ?? null,
            $env,
        );
        if (!\is_resource($process)) {
            throw new McpClientError("Could not start MCP server process \"{$this->config['command']}\".");
        }

        $this->process = $process;
        $this->stdin = $pipes[0];
        $this->stdout = $pipes[1];
        $this->stderr = $pipes[2] ?? null;
        stream_set_blocking($this->stdout, false);
        if ($this->stderr !== null) {
            stream_set_blocking($this->stderr, false);
        }
    }

    public function send(array $message): void
    {
        if ($this->stdin === null) {
            throw new McpClientError('The stdio transport is not connected.');
        }

        $payload = JsonRpc::encode($message) . "\n";
        $length = strlen($payload);
        $written = 0;
        while ($written < $length) {
            $bytes = @fwrite($this->stdin, substr($payload, $written));
            if ($bytes === false || $bytes === 0) {
                throw new McpClientError('Could not write to the MCP server process' . $this->exitDetail());
            }
            $written += $bytes;
        }
    }

    public function receive(float $timeoutSeconds): ?array
    {
        if ($this->stdout === null) {
            throw new McpClientError('The stdio transport is not connected.');
        }

        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            $message = $this->nextBufferedMessage();
            if ($message !== null) {
                return $message;
            }

            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                return null;
            }

            $read = [$this->stdout];
            if ($this->stderr !== null) {
                $read[] = $this->stderr;
            }
            $write = $except = null;
            @stream_select($read, $write, $except, (int) floor($remaining), (int) (fmod($remaining, 1.0) * 1_000_000));

            foreach ($read as $stream) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || $chunk === '') {
                    continue;
                }
                if ($stream === $this->stdout) {
                    $this->readBuffer .= $chunk;
                } else {
                    $this->stderrBuffer .= $chunk;
                }
            }

            if ($this->readBuffer === '' && feof($this->stdout)) {
                throw new McpClientError('MCP server process closed the connection' . $this->exitDetail());
            }
        }
    }

    public function setProtocolVersion(string $version): void
    {
    }

    public function withHeaders(array $headers): TransportInterface
    {
        throw new McpClientError('The stdio transport does not carry request headers.');
    }

    /** Everything the child wrote to stderr so far (empty unless `stderr` is `pipe`). */
    public function stderrOutput(): string
    {
        if ($this->stderr !== null) {
            $chunk = stream_get_contents($this->stderr);
            if (is_string($chunk)) {
                $this->stderrBuffer .= $chunk;
            }
        }

        return $this->stderrBuffer;
    }

    public function isRunning(): bool
    {
        return $this->process !== null && (proc_get_status($this->process)['running'] ?? false);
    }

    public function close(): void
    {
        if ($this->process === null) {
            return;
        }

        $process = $this->process;
        try {
            $this->stderrOutput();
            if (is_resource($this->stdin)) {
                @fclose($this->stdin);
            }
            if (!$this->waitForExit($process, self::SHUTDOWN_GRACE_SECONDS)) {
                proc_terminate($process);
                if (!$this->waitForExit($process, self::SHUTDOWN_GRACE_SECONDS)) {
                    proc_terminate($process, 9);
                    $this->waitForExit($process, self::SHUTDOWN_GRACE_SECONDS);
                }
            }
        } finally {
            foreach ([$this->stdout, $this->stderr] as $stream) {
                if (is_resource($stream)) {
                    @fclose($stream);
                }
            }
            proc_close($process);
            $this->process = $this->stdin = $this->stdout = $this->stderr = null;
        }
    }

    /** @return array<string, mixed>|null */
    private function nextBufferedMessage(): ?array
    {
        while (($newline = strpos($this->readBuffer, "\n")) !== false) {
            $line = trim(substr($this->readBuffer, 0, $newline));
            $this->readBuffer = substr($this->readBuffer, $newline + 1);
            if ($line === '') {
                continue;
            }
            try {
                return JsonRpc::decode($line);
            } catch (\JsonException) {
                // A server may print stray non-protocol lines to stdout; they are not messages.
                continue;
            }
        }

        return null;
    }

    /** @param resource $process */
    private function waitForExit($process, float $seconds): bool
    {
        $deadline = microtime(true) + $seconds;
        while (microtime(true) < $deadline) {
            if (!(proc_get_status($process)['running'] ?? false)) {
                return true;
            }
            usleep(10_000);
        }

        return !(proc_get_status($process)['running'] ?? false);
    }

    private function exitDetail(): string
    {
        $detail = '';
        if ($this->process !== null) {
            $status = proc_get_status($this->process);
            if (!$status['running'] && $status['exitcode'] >= 0) {
                $detail = " (exit code {$status['exitcode']})";
            }
        }
        $stderr = trim($this->stderrOutput());

        return $detail . ($stderr === '' ? '.' : ": {$stderr}");
    }
}
