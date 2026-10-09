<?php

declare(strict_types=1);

namespace LangGraph\Errors;

/**
 * Raised when the remote graph server reports an `error` event.
 *
 * Port of `RemoteException` from `langgraph-core/src/errors.ts`. The raw event payload is kept
 * under `fields['data']` so callers can inspect the server's structured error.
 */
class RemoteException extends BaseLangGraphError
{
    public string $name = 'RemoteException';
}
