<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The CI workflow must not describe work it does not do.
 *
 * The coverage job uploaded `clover.xml` while the test step only produced a
 * text summary, so the artifact was always empty and the job reported coverage
 * it had never generated. `if-no-files-found: ignore` is what hid it: a
 * silently-absent artifact is indistinguishable from a successful upload.
 *
 * This is a static assertion on purpose. The failure mode is a config that
 * reads correctly, so a test is the only thing that can notice the two halves
 * drifting apart.
 */
final class CiWorkflowTest extends TestCase
{
    private static function workflow(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/.github/workflows/ci.yml');
    }

    public function testTheCoverageStepGeneratesTheArtifactItUploads(): void
    {
        $yml = self::workflow();

        self::assertStringContainsString('clover.xml', $yml, 'the job uploads clover.xml');
        self::assertMatchesRegularExpression(
            '/--coverage-clover\s+clover\.xml/',
            $yml,
            'the test step must actually produce clover.xml',
        );
    }

    public function testAMissingCoverageArtifactIsAnError(): void
    {
        self::assertStringNotContainsString(
            'if-no-files-found: ignore',
            self::workflow(),
            'ignoring a missing artifact is what let an empty coverage report pass as coverage',
        );
    }

    public function testBothTestsuitesAreRun(): void
    {
        $yml = self::workflow();

        self::assertStringContainsString('--testsuite unit', $yml);
        self::assertStringContainsString('--testsuite integration', $yml,
            'an integration suite that is declared but never executed is a configuration that looks like coverage and is not');
    }
}
