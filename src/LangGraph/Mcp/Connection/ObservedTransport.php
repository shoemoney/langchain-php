<?php

declare(strict_types=1);

namespace LangGraph\Mcp\Connection;

use LangGraph\Mcp\Client\Transport\TransportInterface;
use LangGraph\Mcp\McpClientError;

/**
 * A transport that tells its owner when it closes, the PHP stand-in for assigning the SDK
 * transport's `onclose`.
 *
 * `$onclose` fires at most once: when the transport is closed, or, with `$watchErrors`, when the
 * channel breaks under a send or a receive (a child process that exited has no event to emit).
 * It is how a restart policy learns that a stdio server went away.
 */
final class ObservedTransport implements TransportInterface
{
    /** @var (callable(): void)|null */
    public $onclose = null;

    private bool $notified = false;

    public function __construct(private readonly TransportInterface $inner, private readonly bool $watchErrors = false)
    {
    }

    public function inner(): TransportInterface
    {
        return $this->inner;
    }

    public function start(): void
    {
        $this->inner->start();
    }

    public function send(array $message): void
    {
        try {
            $this->inner->send($message);
        } catch (McpClientError $e) {
            $this->channelBroke();

            throw $e;
        }
    }

    public function receive(float $timeoutSeconds): ?array
    {
        try {
            return $this->inner->receive($timeoutSeconds);
        } catch (McpClientError $e) {
            $this->channelBroke();

            throw $e;
        }
    }

    public function setRequestTimeout(float $seconds): void
    {
        $this->inner->setRequestTimeout($seconds);
    }

    public function setProtocolVersion(string $version): void
    {
        $this->inner->setProtocolVersion($version);
    }

    public function withHeaders(array $headers): TransportInterface
    {
        return new self($this->inner->withHeaders($headers), $this->watchErrors);
    }

    public function close(): void
    {
        try {
            $this->inner->close();
        } finally {
            $this->notify();
        }
    }

    private function channelBroke(): void
    {
        if ($this->watchErrors) {
            $this->notify();
        }
    }

    private function notify(): void
    {
        if ($this->notified) {
            return;
        }
        $this->notified = true;

        if ($this->onclose !== null) {
            ($this->onclose)();
        }
    }
}
