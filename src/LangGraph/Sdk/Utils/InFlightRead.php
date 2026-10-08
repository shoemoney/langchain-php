<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Utils;

/**
 * One idempotent read that is on the wire right now, shared by every identical read that arrives
 * before it settles. See `BaseClient::fetch()`.
 */
final class InFlightRead
{
    public bool $settled = false;

    public mixed $result = null;

    public ?\Throwable $error = null;
}
