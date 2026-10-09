<?php

declare(strict_types=1);

namespace LangGraph\Mcp;

use LangChain\Runnables\RunnableConfig;
use LangChain\Tools\DynamicStructuredTool;
use LangChain\Tools\ToolException as ToolInputException;
use LangChain\Tracers\CallbackManagerForToolRun;
use LangGraph\Agents\Nodes\Utils as AbortUtils;
use LangGraph\Errors\Guard;
use LangGraph\Pregel\Utils\Config;

/**
 * Convert MCP tool descriptors into LangChain tools.
 *
 * Port of `langchain-mcp-adapters/src/tools.ts` over {@see McpClientInterface}. This is a conversion
 * layer, not a client: transports, OAuth and `MultiServerMCPClient` (`client.ts`, `connection.ts`)
 * are not ported, so a caller supplies whatever implements the interface.
 *
 * Each MCP tool becomes a {@see DynamicStructuredTool} whose schema is the server's JSON Schema,
 * untouched (`$defs`, `$ref`, `allOf`/`anyOf`/`oneOf`, `$schema`, `unevaluatedProperties` and
 * extensions all survive), with `responseFormat` `content_and_artifact`. Calling it:
 *
 *  1. validates the arguments against that schema, then runs `beforeToolCall` (which may replace
 *     arguments or request extra headers) and validates the effective arguments again;
 *  2. calls the server (answering `input_required` rounds with graph interrupts when the server
 *     negotiated the modern protocol and `elicitation` is on);
 *  3. converts the result with {@see Content::convertCallToolResult()} and runs `afterToolCall`,
 *     which may substitute a string, `[content, artifacts]`, a `ToolMessage` or a `Command`.
 *
 * Streaming progress notifications need a transport; `onProgress` is honoured exactly as the client
 * delivers it through the `onprogress` call option.
 *
 * ## Options
 *
 * `throwOnLoadError` (true), `prefixToolNameWithServerName` (false), `additionalToolNamePrefix` (''),
 * `outputHandling` (see {@see Content}), `defaultToolTimeout` (positive ms; carried to the call as
 * `metadata.timeoutMs`), `beforeToolCall` / `afterToolCall` (see {@see Hooks}), `onProgress`
 * (`callable(array $progress, array $context)`), `logLevel`, `elicitation` (true).
 */
final class McpTools
{
    public const LOG_LEVEL_META_KEY = 'io.modelcontextprotocol/logLevel';

    public const CLIENT_CAPABILITIES_META_KEY = 'io.modelcontextprotocol/clientCapabilities';

    private const LOG_LEVELS = ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

    private const OPTION_KEYS = [
        'throwOnLoadError', 'prefixToolNameWithServerName', 'additionalToolNamePrefix', 'outputHandling',
        'defaultToolTimeout', 'beforeToolCall', 'afterToolCall', 'onProgress', 'logLevel', 'elicitation',
    ];

    private function __construct()
    {
    }

    /**
     * Load every tool a server offers, as LangChain tools.
     *
     * Options are validated BEFORE discovery, so a bad option never costs a round trip.
     *
     * @param array<string, mixed> $options
     *
     * @return list<DynamicStructuredTool>
     *
     * @throws ValidationException on invalid options, or an invalid tool when `throwOnLoadError`
     */
    public static function loadMcpTools(string $serverName, McpClientInterface $client, array $options = []): array
    {
        $parsed = self::parseOptions($options);
        $listed = $client->listTools();

        return self::convertMcpTools($serverName, $client, $listed['tools'] ?? [], $parsed);
    }

    /**
     * Adapt already-discovered descriptors without another discovery request.
     *
     * Tools without a name are dropped. Conversion is sequential where upstream uses
     * `Promise.all`; order is preserved.
     *
     * @param list<array<string, mixed>> $tools
     * @param array<string, mixed>       $options
     *
     * @return list<DynamicStructuredTool>
     */
    public static function convertMcpTools(string $serverName, McpClientInterface $client, array $tools, array $options = []): array
    {
        $parsed = self::parseOptions($options);
        $throwOnLoadError = $parsed['throwOnLoadError'] ?? true;

        $converted = [];
        foreach ($tools as $tool) {
            $name = is_array($tool) ? ($tool['name'] ?? null) : null;
            if (!is_string($name) || $name === '') {
                continue;
            }

            try {
                $converted[] = self::convertMcpToolToLangchainTool($serverName, $client, $tool, $parsed);
            } catch (\Throwable $e) {
                if ($throwOnLoadError) {
                    throw $e;
                }
            }
        }

        return $converted;
    }

    /**
     * Convert ONE MCP tool descriptor.
     *
     * @param array<string, mixed> $tool    the descriptor (`name`, `description`, `inputSchema`, ...)
     * @param array<string, mixed> $options see the class note
     *
     * @throws ValidationException when the descriptor's `inputSchema` is not a JSON object
     */
    public static function convertMcpToolToLangchainTool(string $serverName, McpClientInterface $client, array $tool, array $options = []): DynamicStructuredTool
    {
        $options = self::parseOptions($options);
        $toolName = (string) ($tool['name'] ?? '');
        $inputSchema = $tool['inputSchema'] ?? null;
        if (!is_array($inputSchema) || (array_is_list($inputSchema) && $inputSchema !== [])) {
            throw ValidationException::of('Invalid input: expected object, received ' . Hooks::describe($inputSchema), ['inputSchema']);
        }

        $initialPrefix = ($options['additionalToolNamePrefix'] ?? '') !== '' ? $options['additionalToolNamePrefix'] . '__' : '';
        $serverPrefix = ($options['prefixToolNameWithServerName'] ?? false) ? $serverName . '__' : '';

        $invocation = self::createToolInvocation($client, $serverName, $tool, $options['logLevel'] ?? null, $options['elicitation'] ?? true);

        $fields = [
            'name' => $initialPrefix . $serverPrefix . $toolName,
            'description' => (string) ($tool['description'] ?? ''),
            'schema' => $inputSchema,
            'responseFormat' => 'content_and_artifact',
            'metadata' => array_key_exists('annotations', $tool) ? ['annotations' => $tool['annotations']] : [],
        ];
        if (!empty($options['defaultToolTimeout'])) {
            $fields['defaultConfig'] = ['metadata' => ['timeoutMs' => $options['defaultToolTimeout']]];
        }

        return new DynamicStructuredTool(
            $fields,
            static function (mixed $args, ?CallbackManagerForToolRun $runManager = null, ?RunnableConfig $config = null) use ($invocation, $serverName, $toolName, $inputSchema, $options): array {
                $args = is_array($args) ? $args : [];

                // The schema the tool was built with is checked by core, but only for the keyword
                // subset core implements; the server's full schema (oneOf, if/then/else, $ref,
                // unevaluatedProperties) is enforced here, before any hook or wire call.
                if (JsonSchemaValidator::validate($args, $inputSchema) !== []) {
                    throw new ToolInputException(
                        'Received tool input did not match expected schema',
                        (string) json_encode($args, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR),
                    );
                }

                return self::callMcpTool([
                    'invocation' => $invocation,
                    'serverName' => $serverName,
                    'toolName' => $toolName,
                    'args' => $args,
                    'config' => $config,
                    'outputHandling' => $options['outputHandling'] ?? null,
                    'onProgress' => $options['onProgress'] ?? null,
                    'beforeToolCall' => $options['beforeToolCall'] ?? null,
                    'afterToolCall' => $options['afterToolCall'] ?? null,
                    'inputSchema' => $inputSchema,
                ]);
            },
        );
    }

    /**
     * Validate loader options. Unknown keys are rejected, so a typo cannot silently do nothing.
     *
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function parseOptions(array $options): array
    {
        $issues = [];
        foreach (array_keys($options) as $key) {
            if (!in_array($key, self::OPTION_KEYS, true)) {
                $issues[] = ['path' => [], 'message' => 'Unrecognized key: "' . $key . '"'];
            }
        }

        foreach (['throwOnLoadError', 'prefixToolNameWithServerName', 'elicitation'] as $flag) {
            if (isset($options[$flag]) && !is_bool($options[$flag])) {
                $issues[] = ['path' => [$flag], 'message' => 'Invalid input: expected boolean, received ' . Hooks::describe($options[$flag])];
            }
        }
        if (isset($options['additionalToolNamePrefix']) && !is_string($options['additionalToolNamePrefix'])) {
            $issues[] = ['path' => ['additionalToolNamePrefix'], 'message' => 'Invalid input: expected string, received ' . Hooks::describe($options['additionalToolNamePrefix'])];
        }
        if (isset($options['defaultToolTimeout'])) {
            $timeout = $options['defaultToolTimeout'];
            if (!is_int($timeout) && !is_float($timeout)) {
                $issues[] = ['path' => ['defaultToolTimeout'], 'message' => 'Invalid input: expected number, received ' . Hooks::describe($timeout)];
            } elseif ($timeout <= 0) {
                $issues[] = ['path' => ['defaultToolTimeout'], 'message' => 'Too small: expected number to be >0'];
            }
        }
        if (isset($options['logLevel']) && !in_array($options['logLevel'], self::LOG_LEVELS, true)) {
            $issues[] = ['path' => ['logLevel'], 'message' => 'Invalid option: expected one of ' . implode('|', array_map(static fn (string $l): string => '"' . $l . '"', self::LOG_LEVELS))];
        }
        if (isset($options['onProgress']) && !is_callable($options['onProgress'])) {
            $issues[] = ['path' => ['onProgress'], 'message' => 'Invalid input: expected function'];
        }
        if (isset($options['outputHandling'])) {
            $handling = $options['outputHandling'];
            $valid = is_string($handling)
                ? in_array($handling, ['content', 'artifact'], true)
                : is_array($handling) && array_diff(array_keys($handling), Content::CALL_TOOL_RESULT_CONTENT_TYPES) === []
                    && array_diff(array_filter($handling, static fn (mixed $v): bool => $v !== null), ['content', 'artifact']) === [];
            if (!$valid) {
                $issues[] = ['path' => ['outputHandling'], 'message' => 'Invalid input: expected "content" | "artifact" or a map of content type to "content" | "artifact"'];
            }
        }

        try {
            Hooks::parseToolHooks($options);
        } catch (ValidationException $e) {
            array_push($issues, ...$e->issues);
        }

        if ($issues !== []) {
            throw new ValidationException($issues);
        }

        return $options;
    }

    /**
     * Everything fixed when a tool is discovered: the protocol era, the elicitation decision, the
     * metadata sent with every call and how to reach a header-bound client.
     *
     * @param array<string, mixed> $descriptor
     *
     * @return array{elicitation: bool, output: (callable(array<string, mixed>): void)|null, bind: callable(array<string, string>|null): callable}
     */
    private static function createToolInvocation(McpClientInterface $client, string $serverName, array $descriptor, ?string $logLevel, bool $elicitation): array
    {
        $modern = $client->getProtocolEra() === 'modern';
        // Legacy servers answer elicitation through a registered handler, never in band.
        $inBand = $modern && $elicitation;

        // An `input_required` round carries no structured content, which a client's output
        // validator would reject before the caller can see the question. Withhold the schema from
        // the rounds and validate the terminal result here instead.
        $outputSchema = $descriptor['outputSchema'] ?? null;
        $roundDefinition = $descriptor;
        if ($inBand && $outputSchema !== null) {
            unset($roundDefinition['outputSchema']);
        }

        // Advertised per request, not as a declared capability: declared capabilities are sent
        // during initialisation, before negotiation settles the era.
        $meta = [];
        if ($logLevel !== null && $modern) {
            $meta[self::LOG_LEVEL_META_KEY] = $logLevel;
        }
        if ($inBand) {
            $meta[self::CLIENT_CAPABILITIES_META_KEY] = ['elicitation' => ['form' => [], 'url' => []]];
        }

        $executor = static function (McpClientInterface $connected) use ($meta, $roundDefinition, $inBand): callable {
            return static function (array $params, array $options) use ($connected, $meta, $roundDefinition, $inBand): array {
                $callOptions = [...$options, 'toolDefinition' => $roundDefinition];
                if ($meta !== []) {
                    $callOptions['_meta'] = $meta;
                }
                if ($inBand) {
                    $callOptions['allowInputRequired'] = true;
                }
                foreach (['inputResponses', 'requestState'] as $retry) {
                    if (array_key_exists($retry, $params)) {
                        $callOptions[$retry] = $params[$retry];
                    }
                }

                return $connected->callTool((string) $params['name'], $params['arguments'] ?? [], $callOptions);
            };
        };

        $unbound = $executor($client);

        return [
            'elicitation' => $inBand,
            // Compiled once per tool, not per call.
            'output' => $inBand && $outputSchema !== null
                ? static function (array $result) use ($outputSchema): void {
                    $issues = JsonSchemaValidator::validate($result['structuredContent'] ?? null, $outputSchema);
                    if ($issues !== []) {
                        throw new ValidationException(array_map(
                            static fn (array $issue): array => ['path' => ['structuredContent'], 'message' => $issue['message']],
                            $issues,
                        ));
                    }
                }
                : null,
            'bind' => static function (?array $headers) use ($client, $serverName, $modern, $executor, $unbound): callable {
                if ($headers === null || $headers === []) {
                    return $unbound;
                }
                if (!$client instanceof ForkableMcpClientInterface) {
                    throw new ToolException("MCP client for server \"{$serverName}\" does not support header changes");
                }

                $connected = $client->fork($headers);
                if (($connected->getProtocolEra() === 'modern') !== $modern) {
                    throw new ToolException("MCP connection for server \"{$serverName}\" changed protocol era after tool discovery.");
                }

                return $executor($connected);
            },
        ];
    }

    /**
     * Run the hooks and parse their output, then settle the effective arguments.
     *
     * @param array<string, mixed> $call
     *
     * @return array{request: array{name: string, arguments: array<string, mixed>}, requestOptions: array<string, mixed>, headers: array<string, string>|null, args: array<string, mixed>, state: mixed}
     */
    private static function prepareToolCall(array $call, mixed $state): array
    {
        $serverName = $call['serverName'];
        $toolName = $call['toolName'];
        $args = $call['args'];
        /** @var RunnableConfig|null $config */
        $config = $call['config'];
        $onProgress = $call['onProgress'];

        // Numeric timeout: RunnableConfig has no `timeout`, so it travels as `metadata.timeoutMs`.
        $timeout = $config?->metadata['timeoutMs'] ?? null;
        $requestOptions = [];
        if (is_int($timeout) || is_float($timeout)) {
            if ($timeout) {
                $requestOptions['timeout'] = $timeout;
            }
        } elseif ($timeout !== null) {
            throw ValidationException::of('Invalid input: expected number, received ' . Hooks::describe($timeout));
        }
        if ($config?->signal !== null) {
            $requestOptions['signal'] = $config->signal;
        }
        if ($onProgress !== null) {
            $requestOptions['onprogress'] = static function (array $progress) use ($onProgress, $toolName, $args, $serverName): void {
                try {
                    $onProgress($progress, ['type' => 'tool', 'name' => $toolName, 'args' => $args, 'server' => $serverName]);
                } catch (\Throwable) {
                    // A broken observer must not fail the tool.
                }
            };
        }

        $before = $call['beforeToolCall'];
        $modification = Hooks::parseToolCallModification(
            $before === null ? null : $before(['name' => $toolName, 'args' => $args, 'serverName' => $serverName], $state, $config ?? new RunnableConfig()),
        );

        $merged = $args;
        foreach ($modification['args'] ?? [] as $key => $value) {
            $merged[$key] = $value;
        }

        $issues = JsonSchemaValidator::validate($merged, $call['inputSchema']);
        if ($issues !== []) {
            $error = new ValidationException($issues);

            throw new ToolException(
                "Invalid arguments for MCP tool \"{$toolName}\": " . implode('; ', array_column($issues, 'message')),
                $error,
            );
        }

        return [
            'request' => ['name' => $toolName, 'arguments' => $merged],
            'requestOptions' => $requestOptions,
            'headers' => $modification['headers'] ?? null,
            'args' => $merged,
            'state' => $state,
        ];
    }

    /**
     * Execute a prepared call; only terminal results reach content conversion.
     *
     * @param array<string, mixed> $call
     *
     * @return array{0: mixed, 1: list<array<string, mixed>>}
     */
    private static function callMcpTool(array $call): array
    {
        $serverName = $call['serverName'];
        $toolName = $call['toolName'];
        $invocation = $call['invocation'];
        /** @var RunnableConfig|null $config */
        $config = $call['config'];

        try {
            $prepared = self::prepareToolCall($call, self::graphTaskState($config));
            $execute = $invocation['bind']($prepared['headers']);
            $round = static fn (array $params): array => $execute($params, $prepared['requestOptions']);

            $result = $invocation['elicitation']
                ? Elicitation::callToolWithElicitation($round, $prepared['request'], $serverName, $toolName, $config?->signal)
                : $round($prepared['request']);

            // A client passed to loadMcpTools need not refuse `input_required` itself.
            if (Elicitation::isInputRequiredResult($result)) {
                throw new ToolException(
                    "MCP tool \"{$toolName}\" on server \"{$serverName}\" asked for input, which only a modern server with elicitation enabled can answer",
                );
            }

            if ($invocation['output'] !== null && empty($result['isError'])) {
                try {
                    $invocation['output']($result);
                } catch (ValidationException $e) {
                    throw new ToolException(
                        "MCP tool \"{$toolName}\" on server \"{$serverName}\" returned output its schema rejects: " . $e->prettify(),
                        $e,
                    );
                }
            }

            [$content, $artifacts] = Content::convertCallToolResult($serverName, $toolName, $result, $call['outputHandling']);

            $after = $call['afterToolCall'];
            $intercepted = Hooks::parseToolCallResultModification(
                $after === null ? null : $after(
                    ['name' => $toolName, 'args' => $prepared['args'], 'result' => [$content, $artifacts], 'serverName' => $serverName],
                    $prepared['state'],
                    $config ?? new RunnableConfig(),
                ),
            );

            if ($intercepted === null) {
                return [$content, $artifacts];
            }
            $replacement = $intercepted['result'];
            if (is_array($replacement) && array_is_list($replacement) && count($replacement) === 2 && !\LangGraph\Pregel\Command::isCommand($replacement)) {
                return $replacement;
            }

            return [$replacement, []];
        } catch (\Throwable $e) {
            if (Guard::isGraphInterrupt($e) || ($config?->signal !== null && AbortUtils::isAborted($config->signal))) {
                throw $e;
            }
            if (Errors::isToolException($e)) {
                throw $e;
            }

            throw new ToolException('Error calling tool ' . $toolName . ': ' . self::describeError($e), $e);
        }
    }

    /** Graph task state for this invocation; `[]` for a direct tool call. */
    private static function graphTaskState(?RunnableConfig $config): mixed
    {
        foreach ([$config, null] as $candidate) {
            try {
                return Config::getCurrentTaskInput($candidate);
            } catch (\Throwable) {
                continue;
            }
        }

        return [];
    }

    /** `String(error)`: the class name and message. */
    private static function describeError(\Throwable $e): string
    {
        return (new \ReflectionClass($e))->getShortName() . ': ' . $e->getMessage();
    }
}
