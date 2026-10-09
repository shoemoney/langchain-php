<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp\Client\Fixtures;

/*
 * A stdio MCP fixture server: newline-delimited JSON-RPC on stdin/stdout.
 *
 *   php StdioServer.php [name] [legacy|modern|crash]
 *
 * `crash` exits with status 3 as soon as it reads a message after the handshake started.
 */

final class StdioServer
{
    /** @param list<string> $argv */
    public static function run(array $argv): void
    {
        $name = $argv[1] ?? 'dummy-server';
        $mode = $argv[2] ?? 'legacy';

        if (($noise = getenv('FIXTURE_STDERR')) !== false) {
            fwrite(STDERR, $noise . "\n");
        }

        $server = new FixtureServer($name, $mode === 'modern' ? 'modern' : 'legacy');
        $emit = static function (array $message): void {
            fwrite(STDOUT, json_encode($message, JSON_UNESCAPED_SLASHES) . "\n");
        };

        $ask = static function (array $request) use ($emit): ?array {
            $emit($request);
            while (($line = fgets(STDIN)) !== false) {
                $reply = json_decode($line, true);
                if (is_array($reply) && ($reply['id'] ?? null) === $request['id'] && !isset($reply['method'])) {
                    return $reply;
                }
            }

            return null;
        };

        while (($line = fgets(STDIN)) !== false) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if ($mode === 'crash') {
                fwrite(STDERR, "fixture crashed\n");
                exit(3);
            }
            $message = json_decode($line, true);
            if (!is_array($message)) {
                continue;
            }
            foreach ($server->handle($message, $ask) as $out) {
                $emit($out);
            }
        }
    }
}

if (\PHP_SAPI === 'cli' && isset($_SERVER['argv'][0]) && realpath($_SERVER['argv'][0]) === __FILE__) {
    require_once __DIR__ . '/../../../../../vendor/autoload.php';
    StdioServer::run($_SERVER['argv']);
}
