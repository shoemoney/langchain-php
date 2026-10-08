<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Utils;

use LangChain\Utils\Env;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Port of `utils/tests/enviroment.test.ts` (sic).
 *
 * Only variables with a test-private name are read or written; no real
 * credential is ever looked up.
 */
#[CoversClass(Env::class)]
final class EnvTest extends TestCase
{
    private const VAR = 'LANGCHAIN_PHP_TEST_ENV_VAR_WP12A';

    protected function tearDown(): void
    {
        putenv(self::VAR);
        unset($_ENV[self::VAR]);
    }

    public function testGetRuntimeEnvironment(): void
    {
        $runtimeEnvironment = Env::getRuntimeEnvironment();

        self::assertSame('php', $runtimeEnvironment['runtime']);
        self::assertSame('langchain-php', $runtimeEnvironment['library']);
    }

    public function testGetRuntimeEnvironmentIsComputedOnce(): void
    {
        self::assertSame(Env::getRuntimeEnvironment(), Env::getRuntimeEnvironment());
    }

    public function testGetEnvNamesThePhpRuntime(): void
    {
        self::assertSame('php', Env::getEnv());
    }

    public function testTheOtherRuntimeProbesAreFalse(): void
    {
        self::assertFalse(Env::isBrowser());
        self::assertFalse(Env::isWebWorker());
        self::assertFalse(Env::isJsDom());
        self::assertFalse(Env::isDeno());
        self::assertFalse(Env::isNode());
    }

    public function testAnUnsetVariableIsNull(): void
    {
        self::assertNull(Env::getEnvironmentVariable(self::VAR));
    }

    public function testASetVariableIsReturned(): void
    {
        putenv(self::VAR . '=value');

        self::assertSame('value', Env::getEnvironmentVariable(self::VAR));
    }

    public function testAnEmptyVariableStaysEmptyRatherThanMissing(): void
    {
        putenv(self::VAR . '=');

        self::assertSame('', Env::getEnvironmentVariable(self::VAR));
    }

    public function testFallsBackToTheEnvSuperglobal(): void
    {
        $_ENV[self::VAR] = 'from-superglobal';

        self::assertSame('from-superglobal', Env::getEnvironmentVariable(self::VAR));
    }
}
