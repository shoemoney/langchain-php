<?php

declare(strict_types=1);

namespace LangChain\LanguageModels\Chat\Fireworks;

use LangChain\Utils\AsyncCaller;
use LangChain\Utils\Js;

/**
 * An error response from the Fireworks API.
 *
 * Port of `createFireworksResponseError` (`utils/errors.ts`) from
 * `@langchain/fireworks`. The status is exposed as a field and the error is
 * stamped with whether a retry can help, so {@see AsyncCaller} does not spend
 * attempts on a request that will fail identically.
 */
final class FireworksResponseError extends \RuntimeException
{
    /**
     * Statuses absent here stay unclassified and keep their default retry behaviour.
     *
     * @var array<int, bool>
     */
    private const STATUS_RETRYABILITY = [
        400 => false,
        401 => false,
        402 => false,
        403 => false,
        404 => false,
        408 => true,
        413 => false,
        429 => true,
        500 => true,
        502 => true,
        503 => true,
        504 => true,
    ];

    public function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message, $status);
    }

    public static function create(int $status, string $detail): self
    {
        $error = new self('Error ' . $status . ': ' . $detail, $status);

        $retryable = self::STATUS_RETRYABILITY[$status] ?? null;

        return $retryable === null ? $error : AsyncCaller::stampRetryable($error, $retryable);
    }

    /**
     * Build the error from a failed response body.
     *
     * The detail is the body's `error` (a string, or an object with a `message`);
     * anything else reads "Unspecified error".
     */
    public static function fromBody(int $status, string $body): self
    {
        $decoded = json_decode($body, true);
        $detail = is_array($decoded) ? ($decoded['error'] ?? null) : null;

        if (is_array($detail) && is_string($detail['message'] ?? null)) {
            $detail = $detail['message'];
        }

        return self::create($status, match (true) {
            is_string($detail) => $detail,
            $detail === null => 'Unspecified error',
            default => Js::encode($detail),
        });
    }
}
