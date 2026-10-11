<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Agents\Middleware;

use LangGraph\Agents\Middleware\LlmToolSelectorMiddleware;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/** llmToolSelector.ts installs the context schema but does not parse factory options. */
#[CoversClass(LlmToolSelectorMiddleware::class)]
final class LlmToolSelectorNumericNullTest extends TestCase
{
    public function testFactoryNullMaxToolsIsAcceptedRatherThanAddingAnAbsentSourceValidation(): void
    {
        $middleware = LlmToolSelectorMiddleware::create(['maxTools' => null]);

        self::assertSame('LLMToolSelector', $middleware['name']);
        self::assertSame('number', $middleware['contextSchema']['properties']['maxTools']['type']);
    }
}
