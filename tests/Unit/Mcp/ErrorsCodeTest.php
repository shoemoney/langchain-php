<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Mcp;

use LangGraph\Mcp\Errors;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Upstream `getHttpErrorCode` reads `status ?? code`; a PHP exception keeps its code behind `getCode()`.
 */
#[CoversClass(Errors::class)]
final class ErrorsCodeTest extends TestCase
{
    public function testAThrowablesGetCodeInTheHttpRangeIsTheStatus(): void
    {
        self::assertSame(503, Errors::getHttpErrorCode(new \RuntimeException('boom', 503)));
    }

    public function testAThrowablesCodeOutsideTheHttpRangeIsIgnored(): void
    {
        self::assertNull(Errors::getHttpErrorCode(new \RuntimeException('boom', 7)));
        self::assertNull(Errors::getHttpErrorCode(new \RuntimeException('boom', 600)));
    }

    public function testAMessageMentionStillWinsWhenTheCodeIsNotAStatus(): void
    {
        self::assertSame(502, Errors::getHttpErrorCode(new \RuntimeException('Bad gateway (HTTP 502)', 0)));
    }

    public function testAnAuthenticationErrorIsFoundThroughACodedPreviousException(): void
    {
        $error = new \RuntimeException('outer', 0, new \RuntimeException('inner', 401));

        self::assertTrue(Errors::isAuthenticationError($error));
    }
}
