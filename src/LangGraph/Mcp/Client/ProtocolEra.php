<?php

declare(strict_types=1);

namespace LangGraph\Mcp\Client;

/**
 * The MCP protocol revisions this client speaks and the era each belongs to.
 *
 * The 2026-07-28 revision moves elicitation in band (`input_required` results); every earlier
 * revision answers it through an `elicitation/create` request from the server.
 */
final class ProtocolEra
{
    public const MODERN = 'modern';

    public const LEGACY = 'legacy';

    public const MODERN_VERSION = '2026-07-28';

    /** Newest first; the first entry is offered when the client is pinned to the legacy era. */
    public const LEGACY_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

    private function __construct()
    {
    }

    /** @return 'modern'|'legacy'|null null when this client does not speak `$version` */
    public static function forVersion(string $version): ?string
    {
        if ($version === self::MODERN_VERSION) {
            return self::MODERN;
        }

        return in_array($version, self::LEGACY_VERSIONS, true) ? self::LEGACY : null;
    }
}
