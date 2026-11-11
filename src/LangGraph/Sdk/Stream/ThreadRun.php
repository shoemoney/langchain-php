<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Stream;

/** The RemoteGraph-required run.start module. */
final class ThreadRun
{
    public function __construct(private readonly ThreadStream $thread)
    {
    }

    public function start(array $params = []): array
    {
        return $this->thread->startRun($params);
    }
}
