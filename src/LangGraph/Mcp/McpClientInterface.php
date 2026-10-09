<?php

declare(strict_types=1);

namespace LangGraph\Mcp;

/**
 * The seam between this conversion layer and whatever speaks MCP on the wire.
 *
 * The TypeScript adapter calls `Client` from `@modelcontextprotocol/client`. PHP has no MCP SDK,
 * so `client.ts` / `connection.ts` (transports, OAuth, `MultiServerMCPClient`) are not ported:
 * this interface is the whole contract the converted tools need. Results and descriptors are the
 * SDK's JSON shapes as decoded associative arrays (`['content' => [...], 'isError' => ...]`).
 *
 * `callTool()` takes the request as `(name, arguments)` and everything else the SDK carries on
 * the request or in its request options in `$options`:
 *
 *  - `timeout`            numeric milliseconds, from `defaultToolTimeout` / config metadata;
 *  - `signal`             the caller's abort signal;
 *  - `onprogress`         `callable(array $progress): void`;
 *  - `toolDefinition`     the tool's descriptor (the SDK validates output against it);
 *  - `allowInputRequired` true when the caller can answer an `input_required` result;
 *  - `_meta`              protocol metadata (log level, client capabilities);
 *  - `inputResponses`, `requestState`   the in-band retry channel.
 */
interface McpClientInterface
{
    /**
     * @return array{tools: list<array<string, mixed>>}
     */
    public function listTools(): array;

    /**
     * @param array<string, mixed> $arguments
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed> a `CallToolResult`, or an `input_required` result when allowed
     */
    public function callTool(string $name, array $arguments, array $options = []): array;

    /**
     * @return array<string, mixed>|null
     */
    public function getServerCapabilities(): ?array;

    /**
     * `'modern'` for a server negotiated onto the 2026-07-28 in-band elicitation protocol,
     * `'legacy'` otherwise.
     */
    public function getProtocolEra(): string;
}
