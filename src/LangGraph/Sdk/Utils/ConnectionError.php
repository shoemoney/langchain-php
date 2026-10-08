<?php

declare(strict_types=1);

namespace LangGraph\Sdk\Utils;

/**
 * Port of the `ConnectionError` AsyncCaller raises when the server cannot be reached after every
 * retry is spent.
 */
final class ConnectionError extends \RuntimeException
{
}
