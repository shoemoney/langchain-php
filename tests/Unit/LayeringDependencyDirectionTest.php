<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * LangGraph may depend on LangChain's ABSTRACTIONS, never on a provider.
 *
 * Measured: `src/LangGraph/` imports only `LangChain\Runnables` (28 files),
 * `LangChain\Messages` (7) and `LangChain\Schema` (2) — 37 imports, all
 * abstractions, ZERO providers. That matches langgraph-js, which depends on
 * `@langchain/core` and not on any provider, and it is the basis for iteration
 * 111's rejection of the recurring "LangGraph -> LangChain is the wrong direction"
 * claim that several advisories have raised.
 *
 * A provider import creeping in would couple the graph engine to HTTP clients it
 * has no business knowing about, and the damage would be a design smell spread
 * across a dozen files rather than a test failure.
 */
#[CoversNothing]
final class LayeringDependencyDirectionTest extends TestCase
{
    private const FORBIDDEN = [
        'LangChain\LanguageModels',
        'LangChain\Chat\OpenAI',
        'LangChain\Chat\Anthropic',
        'LangChain\Providers',
    ];

    /**
     * The provider-agnostic `initChatModel` port and the wrapper it returns. They name no provider; the
     * registry behind them is the one place a "provider:model" string becomes a client.
     */
    private const ALLOWED = [
        'LangChain\\LanguageModels\\Chat\\Universal\\InitChatModel;',
        'LangChain\\LanguageModels\\Chat\\Universal\\ConfigurableModel;',
    ];

    public function testLangGraphImportsNoProviderNamespace(): void
    {
        $root = dirname(__DIR__, 2) . '/src/LangGraph';
        self::assertDirectoryExists($root, 'the LangGraph source tree moved; update this guard');

        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = str_replace(
                array_map(static fn (string $allowed): string => 'use ' . $allowed, self::ALLOWED),
                '',
                (string) file_get_contents($file->getPathname()),
            );
            foreach (self::FORBIDDEN as $needle) {
                if (str_contains($source, 'use ' . $needle)) {
                    $offenders[] = basename($file->getPathname()) . ' -> ' . $needle;
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            "LangGraph must depend on abstractions, not providers:\n  " . implode("\n  ", $offenders),
        );
    }
}
