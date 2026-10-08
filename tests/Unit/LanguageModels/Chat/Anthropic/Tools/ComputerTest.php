<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\LanguageModels\Chat\Anthropic\Tools;

use LangChain\LanguageModels\Chat\Anthropic\Tools\Computer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Computer::class)]
final class ComputerTest extends TestCase
{
    public function testComputer20251124WithRequiredOptions(): void
    {
        $computer = Computer::computer_20251124(['displayWidthPx' => 1024, 'displayHeightPx' => 768]);

        self::assertSame('computer', $computer->name);
        self::assertSame([
            'type' => 'computer_20251124',
            'name' => 'computer',
            'display_width_px' => 1024,
            'display_height_px' => 768,
        ], $computer->extras['providerToolDefinition']);
    }

    public function testComputer20251124WithAllOptions(): void
    {
        $computer = Computer::computer_20251124([
            'displayWidthPx' => 1280,
            'displayHeightPx' => 800,
            'displayNumber' => 1,
            'enableZoom' => true,
        ]);

        self::assertSame([
            'type' => 'computer_20251124',
            'name' => 'computer',
            'display_width_px' => 1280,
            'display_height_px' => 800,
            'display_number' => 1,
            'enable_zoom' => true,
        ], $computer->extras['providerToolDefinition']);
    }

    public function testComputer20251124WithExecuteFunctionAcceptsZoom(): void
    {
        $computer = Computer::computer_20251124([
            'displayWidthPx' => 1024,
            'displayHeightPx' => 768,
            'execute' => static fn (array $args): string => "Executed {$args['action']}",
        ]);

        self::assertSame('Executed zoom', $computer->invoke(['action' => 'zoom', 'region' => [0, 0, 10, 10]]));
    }

    public function testComputer20250124WithRequiredOptions(): void
    {
        $computer = Computer::computer_20250124(['displayWidthPx' => 1024, 'displayHeightPx' => 768]);

        self::assertSame('computer', $computer->name);
        self::assertSame([
            'type' => 'computer_20250124',
            'name' => 'computer',
            'display_width_px' => 1024,
            'display_height_px' => 768,
        ], $computer->extras['providerToolDefinition']);
    }

    public function testComputer20250124WithDisplayNumber(): void
    {
        $computer = Computer::computer_20250124(['displayWidthPx' => 1024, 'displayHeightPx' => 768, 'displayNumber' => 2]);

        self::assertSame([
            'type' => 'computer_20250124',
            'name' => 'computer',
            'display_width_px' => 1024,
            'display_height_px' => 768,
            'display_number' => 2,
        ], $computer->extras['providerToolDefinition']);
    }

    public function testComputer20250124WithExecuteFunction(): void
    {
        $computer = Computer::computer_20250124([
            'displayWidthPx' => 1024,
            'displayHeightPx' => 768,
            'execute' => static fn (array $args): string => "Executed {$args['action']}",
        ]);

        self::assertSame('Executed screenshot', $computer->invoke(['action' => 'screenshot']));
    }
}
