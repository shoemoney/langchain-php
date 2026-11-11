<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\OutputParsers;

use LangChain\OutputParsers\PartialJsonParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** The reported 2.1 MB truncated array must recover within a fresh 128M PHP process. */
#[CoversClass(PartialJsonParser::class)]
final class PartialJsonMemoryLimitTest extends TestCase
{
    public function testLargeTruncatedArrayFitsTheConcreteMemoryLimit(): void
    {
        $code = <<<'PHP'
require $argv[1];
$input = '[' . str_repeat('12345,', 350000);
$value = \LangChain\OutputParsers\JsonUtils::strictParsePartialJson($input);
if (count($value) !== 350000 || $value[0] !== 12345 || $value[349999] !== 12345) {
    throw new \RuntimeException('Incorrect recovered array endpoints');
}
foreach ($value as $item) {
    if ($item !== 12345) {
        throw new \RuntimeException('Incorrect recovered array element');
    }
}
echo json_encode(['bytes' => strlen($input), 'count' => count($value), 'first' => $value[0], 'last' => $value[349999]], JSON_THROW_ON_ERROR);
PHP;
        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=128M', '-r', $code, dirname(__DIR__, 3) . '/vendor/autoload.php'], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = $stderr = '';
        $deadline = hrtime(true) + 120_000_000_000;
        try {
            do {
                $stdout .= stream_get_contents($pipes[1]);
                $stderr .= stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if (!$status['running']) {
                    break;
                }
                if (hrtime(true) >= $deadline) {
                    proc_terminate($process);
                    self::fail('The bounded parser child process did not finish');
                }
                usleep(10000);
            } while (true);
            $stdout .= stream_get_contents($pipes[1]);
            $stderr .= stream_get_contents($pipes[2]);
            self::assertSame(0, $status['exitcode'], $stderr . $stdout);
            self::assertSame('', $stderr);
            self::assertSame(['bytes' => 2100001, 'count' => 350000, 'first' => 12345, 'last' => 12345], json_decode($stdout, true, flags: JSON_THROW_ON_ERROR));
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }
}
