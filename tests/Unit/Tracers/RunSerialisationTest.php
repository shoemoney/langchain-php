<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Tracers;

use LangChain\Tracers\Run;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Every field `Run` documents must survive into its serialised form.
 *
 * `actions` was written by `BaseTracer::handleAgentAction` and read by the
 * console handler, yet missing from `toArray()` — so a persisted run lost every
 * agent step it had taken, while still advertising the field in its constructor.
 */
#[CoversClass(Run::class)]
final class RunSerialisationTest extends TestCase
{
    public function testAgentActionsSurviveSerialisation(): void
    {
        $run = new Run(id: 'r1', runType: 'agent');
        $run->actions[] = ['tool' => 'search', 'input' => 'who'];
        $run->actions[] = ['tool' => 'answer', 'output' => 'me'];

        self::assertSame(
            [
                ['tool' => 'search', 'input' => 'who'],
                ['tool' => 'answer', 'output' => 'me'],
            ],
            $run->toArray()['actions'] ?? null,
        );
    }

    public function testARunWithNoActionsSerialisesAnEmptyList(): void
    {
        self::assertSame([], (new Run(id: 'r1'))->toArray()['actions'] ?? null);
    }
}
