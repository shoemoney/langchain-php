<?php

declare(strict_types=1);

namespace LangChain\Tests\Unit\Checkpoint;

use PHPUnit\Framework\TestCase;

final class RedisLiveServerIsolationTest extends TestCase
{
    public function testAnUnownedEndpointIsRejectedBeforeConnecting(): void
    {
        $url = getenv('LANGGRAPH_REDIS_URL');
        $runId = getenv('LANGGRAPH_REDIS_TEST_RUN_ID');
        putenv('LANGGRAPH_REDIS_URL=redis://127.0.0.1:1');
        putenv('LANGGRAPH_REDIS_TEST_RUN_ID');
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('disposable .loop/test_backends.py harness');
            RedisLiveServer::freshClient();
        } finally {
            putenv($url === false ? 'LANGGRAPH_REDIS_URL' : 'LANGGRAPH_REDIS_URL=' . $url);
            putenv($runId === false ? 'LANGGRAPH_REDIS_TEST_RUN_ID' : 'LANGGRAPH_REDIS_TEST_RUN_ID=' . $runId);
        }
    }

    public function testAMarkerForAnotherProcessIsRejectedOnTheOwnedFixture(): void
    {
        $runId = getenv('LANGGRAPH_REDIS_TEST_RUN_ID');
        if ($runId === false || $runId === '') {
            // The ordinary suite has no live fixture. The harness exercises the identity check.
            self::assertNull(RedisLiveServer::url());

            return;
        }
        putenv('LANGGRAPH_REDIS_TEST_RUN_ID=' . str_repeat('0', 40));
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('cleanup refused');
            RedisLiveServer::freshClient();
        } finally {
            putenv('LANGGRAPH_REDIS_TEST_RUN_ID=' . $runId);
        }
    }
}
