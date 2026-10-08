<?php

declare(strict_types=1);

namespace LangGraph\Checkpoint\Redis;

/**
 * A Redis server error reply, carrying the server's own message.
 *
 * The savers branch on that text (`Index already exists`, `no such index`), so
 * implementations of {@see RedisClientInterface} must not paraphrase it.
 */
final class RedisClientException extends \RuntimeException
{
}
