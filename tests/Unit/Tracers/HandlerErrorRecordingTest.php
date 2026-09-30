<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Tracers;

use LangChain\Tracers\BaseCallbackHandler;
use LangChain\Tracers\BaseRunManager;
use LangChain\Tracers\CallbackManager;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * A raising handler's failure is recorded on BOTH dispatch paths.
 *
 * `CallbackManager::dispatch` used to rethrow BEFORE recording, while
 * `BaseRunManager::dispatch` records first and then rethrows. So a handler that
 * opted into raising appeared in `handlerErrors()` through one path and not the
 * other, and that record is meant to be a reliable account of what happened — an
 * account that varies by route is not one.
 *
 * The test drives `dispatch` on the CallbackManager path rather than a public
 * entry point, because that is the method the fix changed. Only the CHANGED
 * path is asserted: the BaseRunManager side is unchanged by this fix and
 * `new BaseRunManager(...)` needs a run id plus handler set-up, which is
 * fixture work for no additional regression signal.
 */
#[CoversClass(BaseRunManager::class)]
#[CoversClass(CallbackManager::class)]
final class HandlerErrorRecordingTest extends TestCase
{
    protected function setUp(): void
    {
        BaseRunManager::clearHandlerErrors();
    }

    protected function tearDown(): void
    {
        BaseRunManager::clearHandlerErrors();
    }

    private static function dispatchOn(CallbackManager $manager, BaseCallbackHandler $handler): void
    {
        $m = new \ReflectionMethod($manager, 'dispatch');
        // No setAccessible(): a no-op since PHP 8.1, deprecated in 8.5.
        $m->invoke($manager, $handler, 'handleLLMEnd', [
            new \LangChain\LanguageModels\Outputs\LLMResult(),
            'run-1',
        ]);
    }

    public function testTheCallbackManagerPathRecordsBeforeRethrowing(): void
    {
        $loud = new class extends BaseCallbackHandler {
            public bool $raiseError = true;

            public function handleLLMEnd(
                \LangChain\LanguageModels\Outputs\LLMResult $output,
                string $runId,
                ?string $parentRunId = null,
                array $tags = [],
                array $extraParams = [],
            ): void {
                throw new \RuntimeException('handler exploded');
            }
        };

        try {
            self::dispatchOn(CallbackManager::configure([]), $loud);
            self::fail('expected the handler to raise');
        } catch (\RuntimeException $e) {
            self::assertSame('handler exploded', $e->getMessage());
        }

        self::assertCount(
            1,
            BaseRunManager::handlerErrors(),
            'a raising handler\'s failure must be RECORDED before it is rethrown',
        );
    }

    public function testAQuietHandlerIsStillRecordedAndDoesNotPropagate(): void
    {
        $quiet = new class extends BaseCallbackHandler {
            public function handleLLMEnd(
                \LangChain\LanguageModels\Outputs\LLMResult $output,
                string $runId,
                ?string $parentRunId = null,
                array $tags = [],
                array $extraParams = [],
            ): void {
                throw new \RuntimeException('quiet failure');
            }
        };

        self::dispatchOn(CallbackManager::configure([]), $quiet);

        self::assertCount(1, BaseRunManager::handlerErrors());
    }
}
