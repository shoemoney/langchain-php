<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Store;

use LangChain\Utils\MathUtils;
use LangGraph\Store\StoreUtils;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Pinned graph-vector and core-matrix policies, plus an isolated dependency-delegation control. */
#[CoversClass(StoreUtils::class)]
#[CoversClass(MathUtils::class)]
final class StoreCosineDedupeTest extends TestCase
{
    /** @return iterable<string, array{list<int|float>, list<int|float>, float|string}> */
    public static function sourceCases(): iterable
    {
        yield 'same' => [[1, 2, 3], [1, 2, 3], 1.0];
        yield 'opposite' => [[1, 0], [-1, 0], -1.0];
        yield 'orthogonal' => [[1, 0], [0, 1], 0.0];
        yield 'zero' => [[0, 0], [1, 1], 0.0];
        yield 'empty' => [[], [], 0.0];
        yield 'mismatch' => [[1], [1, 2], 'mismatch'];
        yield 'finite overflow' => [[1e308], [1e308], 'NaN'];
        yield 'infinity' => [[INF], [INF], 'NaN'];
        yield 'NaN' => [[NAN], [1], 'NaN'];
        yield 'tiny underflow' => [[1e-300], [1e-300], 0.0];
        yield 'zero plus NaN' => [[0], [NAN], 0.0];
    }

    /**
     * Expected policies come from actual pinned store/utils.ts and core utils/math.ts execution.
     * Neither matrix NaN normalization nor the graph zero policy may replace the other.
     *
     * @param list<int|float> $a
     * @param list<int|float> $b
     */
    #[DataProvider('sourceCases')]
    public function testPinnedVectorAndMatrixPoliciesAndCallerPreservation(array $a, array $b, float|string $expected): void
    {
        $before = serialize([$a, $b]);
        if ($expected === 'mismatch') {
            foreach ([StoreUtils::cosineSimilarity(...), MathUtils::cosineSimilarityVectors(...)] as $vector) {
                try {
                    $vector($a, $b);
                    self::fail('unequal vector lengths must be rejected');
                } catch (\InvalidArgumentException $error) {
                    self::assertSame('Vectors must have the same length', $error->getMessage());
                }
            }
            try {
                MathUtils::cosineSimilarity([$a], [$b]);
                self::fail('unequal matrix columns must be rejected');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Number of columns in X and Y must be the same. X has shape 1,1 and Y has shape 1,2.', $error->getMessage());
            }
        } else {
            $store = StoreUtils::cosineSimilarity($a, $b);
            $vector = MathUtils::cosineSimilarityVectors($a, $b);
            $matrix = MathUtils::cosineSimilarity([$a], [$b]);
            if ($expected === 'NaN') {
                self::assertTrue(is_nan($store), 'graph-vector undefined arithmetic stays NaN');
                self::assertTrue(is_nan($vector), 'the shared vector kernel keeps raw NaN');
                self::assertSame([[0.0]], $matrix, 'only the matrix API normalizes NaN');
            } else {
                self::assertEqualsWithDelta($expected, $store, 1e-12);
                self::assertEqualsWithDelta($expected, $vector, 1e-12);
                self::assertEqualsWithDelta($expected, $matrix[0][0] ?? 0.0, 1e-12);
                if ($a === []) {
                    self::assertSame([[]], $matrix, 'empty matrix shape remains distinct from vector zero');
                }
            }
        }
        self::assertSame($before, serialize([$a, $b]), 'both APIs preserve caller vectors, including non-finite values');
    }

    public function testStoreDelegatesExactVectorsToTheCoreHelperInAFreshProcess(): void
    {
        // This core sentinel measures dependency dispatch, not the numerical algorithm. The real
        // helpers are tested separately above; restoring the old local loop returns 1, not 0.375.
        $code = <<<'PHP'
            <?php
            namespace LangChain\Utils {
                final class MathUtils {
                    public static array $calls = [];
                    public static function cosineSimilarityVectors(array $a, array $b): float {
                        self::$calls[] = [$a, $b];
                        return 0.375;
                    }
                }
            }
            namespace {
                require $argv[1];
                $a = [1.0, 0];
                $b = [1.0, 0];
                $before = serialize([$a, $b]);
                $result = \LangGraph\Store\StoreUtils::cosineSimilarity($a, $b);
                echo json_encode(['result' => $result, 'calls' => \LangChain\Utils\MathUtils::$calls,
                    'callerUnchanged' => $before === serialize([$a, $b])], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
            }
            PHP;
        $directory = sys_get_temp_dir() . '/cosine-dedupe-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $script = $directory . '/sentinel.php';
        $process = null;
        $pipes = [];
        try {
            file_put_contents($script, $code);
            $process = proc_open([PHP_BINARY, '-d', 'display_errors=stderr', $script,
                dirname(__DIR__, 3) . '/src/LangGraph/Store/StoreUtils.php'],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, env_vars: ['TMPDIR' => $directory]);
            self::assertIsResource($process);
            stream_set_blocking($pipes[1], false);
            stream_set_blocking($pipes[2], false);
            $output = '';
            $errors = '';
            $deadline = microtime(true) + 5;
            do {
                $output .= (string) stream_get_contents($pipes[1]);
                $errors .= (string) stream_get_contents($pipes[2]);
                $status = proc_get_status($process);
                if ($status['running'] && microtime(true) > $deadline) {
                    self::fail('core sentinel child exceeded its five-second deadline');
                }
                if ($status['running']) {
                    usleep(1000);
                }
            } while ($status['running']);
            $output .= (string) stream_get_contents($pipes[1]);
            $errors .= (string) stream_get_contents($pipes[2]);
            self::assertSame(0, $status['exitcode'], $errors);
            self::assertSame('', $errors);
            $report = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame(0.375, $report['result']);
            self::assertSame([[[1.0, 0], [1.0, 0]]], $report['calls']);
            self::assertTrue($report['callerUnchanged']);
        } finally {
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            if (is_resource($process)) {
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }
                proc_close($process);
            }
            if (is_file($script)) {
                unlink($script);
            }
            rmdir($directory);
        }
    }
}
