<?php

declare(strict_types=1);

namespace LangGraph\Mcp;

use LangGraph\Agents\Nodes\Utils as AbortUtils;
use LangGraph\Errors\Guard;

use function LangGraph\Pregel\interrupt;

/**
 * MCP elicitation over LangGraph interrupts.
 *
 * Port of `langchain-mcp-adapters/src/elicitation.ts`. A modern server answers a tool call with an
 * `input_required` result carrying questions; the adapter raises them as ONE graph interrupt and, on
 * resume, re-issues the call with the human's answers. Because `interrupt()` unwinds the whole call,
 * the tool is re-run from its first round on every resume, so a server that works before asking
 * repeats that work once per round and its effects must be idempotent.
 *
 * Questions and answers are the SDK's JSON shapes as decoded arrays:
 *
 *  - form: `['mode' => 'form', 'message' => ..., 'requestedSchema' => [...]]`
 *  - URL:  `['mode' => 'url', 'message' => ..., 'url' => ...]`
 *  - answer: `['action' => 'accept'|'decline'|'cancel', 'content' => [...]]`
 */
final class Elicitation
{
    public const INTERRUPT_TYPE = 'mcp_elicitation';

    private function __construct()
    {
    }

    /** Whether a `tools/call` result is the in-band "I need input" response. */
    public static function isInputRequiredResult(mixed $result): bool
    {
        return is_array($result) && ($result['resultType'] ?? null) === 'input_required';
    }

    /**
     * Port of `modernElicitationRequestSchema`: an `elicitation/create` request projected to the
     * modern question fields, dropping legacy task metadata and extensions.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function parseModernElicitationRequest(mixed $request): array
    {
        if (!is_array($request) || (array_is_list($request) && $request !== [])) {
            throw ValidationException::of('Invalid input: expected object, received ' . Hooks::describe($request));
        }
        if (($request['method'] ?? null) !== 'elicitation/create') {
            throw ValidationException::of('Invalid input: expected "elicitation/create"', ['method']);
        }

        try {
            return self::parseQuestion($request['params'] ?? null);
        } catch (ValidationException $e) {
            throw new ValidationException(array_map(
                static fn (array $issue): array => ['path' => ['params', ...$issue['path']], 'message' => $issue['message']],
                $e->issues,
            ));
        }
    }

    /**
     * Port of `elicitationAnswerFor`: a parser for answers to `$request`.
     *
     * @param array<string, mixed> $request
     * @param bool                 $modern  true for the adapter's stripping `modernElicitationAnswerSchema`
     */
    public static function elicitationAnswerFor(array $request, bool $modern = false): ElicitationAnswerSchema
    {
        return new ElicitationAnswerSchema($request, $modern);
    }

    /**
     * Port of `validateElicitationAnswer`: parse application input with the SDK result contract and
     * the requested form.
     *
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public static function validateElicitationAnswer(array $request, mixed $input): array
    {
        return self::elicitationAnswerFor($request)->parse($input);
    }

    /**
     * Install the legacy `elicitation/create` handler. A no-op without a handler.
     *
     * The handler receives `(params, ['server' => ..., 'signal' => null])` and returns an answer,
     * which is validated against the question before it reaches the server. Install before connect
     * so capabilities and handlers agree during negotiation.
     *
     * @param callable(array<string, mixed>, array<string, mixed>): array<string, mixed>|null $handler
     */
    public static function configureElicitation(McpClientInterface $client, string $server, ?callable $handler = null): void
    {
        if ($handler === null) {
            return;
        }
        if (!$client instanceof ElicitationCapableClientInterface) {
            throw new ToolException("MCP client for server \"{$server}\" cannot register an elicitation handler");
        }

        $client->setElicitationHandler(
            static function (array $params) use ($handler, $server): array {
                $answer = $handler($params, ['server' => $server, 'signal' => null]);

                return self::validateElicitationAnswer($params, $answer);
            },
        );
    }

    /**
     * Port of `createMCPElicitationResume`: build an answer addressed to the task that raised
     * `$pending`.
     *
     * @param array<string, mixed>|object                   $pending   an interrupt: `['id' => ..., 'value' => <mcp_elicitation>]`
     * @param array<string, array<string, mixed>>           $responses answers keyed by the server's input-request keys
     *
     * @return array<string, array{responses: array<string, array<string, mixed>>}>
     *
     * @throws ValidationException when `$pending` is not an MCP elicitation interrupt
     */
    public static function createMCPElicitationResume(array|object $pending, array $responses): array
    {
        $interrupt = is_array($pending) ? $pending : get_object_vars($pending);
        $id = $interrupt['id'] ?? null;
        if (!is_string($id) || $id === '') {
            throw ValidationException::of('Invalid input: expected a non-empty interrupt id', ['id']);
        }

        try {
            self::parseInterruptValue($interrupt['value'] ?? null);
        } catch (ValidationException $e) {
            throw new ValidationException(array_map(
                static fn (array $issue): array => ['path' => ['value', ...$issue['path']], 'message' => $issue['message']],
                $e->issues,
            ));
        }

        return [$id => ['responses' => $responses]];
    }

    /**
     * Call an MCP tool, answering each round of requested input with a graph interrupt.
     *
     * `$round` performs one `tools/call` with the given params (`name`, `arguments`, and on a retry
     * `inputResponses` + `requestState`) and returns the raw result.
     *
     * @param callable(array<string, mixed>): array<string, mixed> $round
     * @param array{name: string, arguments?: array<string, mixed>} $params
     *
     * @return array<string, mixed> the terminal `CallToolResult`
     *
     * @throws ToolException when the server asks something this adapter cannot answer
     */
    public static function callToolWithElicitation(callable $round, array $params, string $server, string $tool, mixed $signal = null): array
    {
        self::throwIfAborted($signal);
        $result = $round($params);

        while (self::isInputRequiredResult($result)) {
            try {
                $requests = self::parseAnswerableRequests($result['inputRequests'] ?? []);
            } catch (ValidationException $e) {
                throw new ToolException(
                    "MCP tool \"{$tool}\" on server \"{$server}\" asked for input this adapter cannot answer: " . $e->prettify(),
                    $e,
                );
            }

            $responses = self::answerFor([
                'type' => self::INTERRUPT_TYPE,
                'server' => $server,
                'tool' => $tool,
                'arguments' => $params['arguments'] ?? [],
                'requests' => $requests,
            ]);

            self::throwIfAborted($signal);
            $next = [...$params, 'inputResponses' => $responses];
            if (array_key_exists('requestState', $result)) {
                $next['requestState'] = $result['requestState'];
            }
            $result = $round($next);
        }

        return $result;
    }

    /**
     * Raise one question set and parse the answer that comes back.
     *
     * `interrupt()` rejects a call made outside a graph, and a graph compiled without a
     * checkpointer, before it ever suspends. Both mean the same thing here, so the refusal is read
     * off the failure. The answer is parsed against the question now being asked: exactly the
     * server's keys, each answer against that question's requested schema. A malformed answer
     * fails the call rather than re-asking, since the caller resuming the graph is code, not the
     * human who filled the form.
     *
     * @param array<string, mixed> $question
     *
     * @return array<string, array<string, mixed>>
     */
    private static function answerFor(array $question): array
    {
        try {
            $resumed = interrupt($question);
        } catch (\Throwable $e) {
            if (Guard::isGraphInterrupt($e)) {
                throw $e;
            }

            throw new ToolException(
                'This MCP tool requested user input. Invoke it inside a LangGraph with a checkpointer to pause and resume elicitation.',
                $e,
            );
        }

        $issues = [];
        $answers = [];
        $responses = is_array($resumed) ? ($resumed['responses'] ?? null) : null;

        if (!is_array($resumed) || (array_is_list($resumed) && $resumed !== [])) {
            $issues[] = ['path' => [], 'message' => 'Invalid input: expected object, received ' . Hooks::describe($resumed)];
        } elseif (!is_array($responses) || (array_is_list($responses) && $responses !== [])) {
            $issues[] = ['path' => ['responses'], 'message' => 'Invalid input: expected object, received ' . Hooks::describe($responses)];
        } else {
            foreach ($question['requests'] as $key => $request) {
                if (!array_key_exists($key, $responses)) {
                    $issues[] = ['path' => ['responses', $key], 'message' => 'Invalid input: expected object, received undefined'];

                    continue;
                }
                $parsed = self::elicitationAnswerFor($request, true)->safeParse($responses[$key]);
                if ($parsed['success']) {
                    $answers[$key] = $parsed['data'];
                } else {
                    foreach ($parsed['error']->issues as $issue) {
                        $issues[] = ['path' => ['responses', $key, ...$issue['path']], 'message' => $issue['message']];
                    }
                }
            }
            foreach (array_keys($responses) as $key) {
                if (!array_key_exists($key, $question['requests'])) {
                    $issues[] = ['path' => ['responses'], 'message' => 'Unrecognized key: "' . $key . '"'];
                }
            }
        }

        if ($issues !== []) {
            $error = new ValidationException($issues);

            throw new ToolException(
                "Resuming MCP tool \"{$question['tool']}\" on server \"{$question['server']}\" needs answers built by createMCPElicitationResume() from the latest interrupt: "
                . $error->prettify(),
                $error,
            );
        }

        return $answers;
    }

    /**
     * Questions a graph interrupt can carry. Sampling and roots fail the `elicitation/create`
     * literal, naming the key that asked; a round with no questions is refused too, because nothing
     * can advance a response that asks nothing and the adapter does not poll for completion.
     *
     * @return array<string, array<string, mixed>>
     *
     * @throws ValidationException
     */
    private static function parseAnswerableRequests(mixed $inputRequests): array
    {
        if (!is_array($inputRequests) || (array_is_list($inputRequests) && $inputRequests !== [])) {
            throw ValidationException::of('Invalid input: expected record, received ' . Hooks::describe($inputRequests));
        }

        $issues = [];
        $requests = [];
        foreach ($inputRequests as $key => $request) {
            try {
                $requests[$key] = self::parseModernElicitationRequest($request);
            } catch (ValidationException $e) {
                foreach ($e->issues as $issue) {
                    $issues[] = ['path' => [$key, ...$issue['path']], 'message' => $issue['message']];
                }
            }
        }
        if ($issues !== []) {
            throw new ValidationException($issues);
        }
        if ($requests === []) {
            throw ValidationException::of('a state-only response carries no question to ask');
        }

        return $requests;
    }

    /**
     * A modern question: the form or URL fields an `elicitation/create` request carries.
     *
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function parseQuestion(mixed $params): array
    {
        if (!is_array($params) || (array_is_list($params) && $params !== [])) {
            throw ValidationException::of('Invalid input: expected object, received ' . Hooks::describe($params));
        }
        if (!is_string($params['message'] ?? null)) {
            throw ValidationException::of('Invalid input: expected string, received ' . Hooks::describe($params['message'] ?? null), ['message']);
        }

        $mode = $params['mode'] ?? null;
        if ($mode === 'url') {
            $url = $params['url'] ?? null;
            if (!is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw ValidationException::of('Invalid URL', ['url']);
            }

            return ['mode' => 'url', 'message' => $params['message'], 'url' => $url];
        }
        if ($mode !== null && $mode !== 'form') {
            throw ValidationException::of('Invalid input: expected "form" or "url"', ['mode']);
        }

        $schema = $params['requestedSchema'] ?? null;
        if (!is_array($schema) || ($schema['type'] ?? null) !== 'object' || !is_array($schema['properties'] ?? null)) {
            throw ValidationException::of('Invalid input: expected an object schema with properties', ['requestedSchema']);
        }

        $question = $mode === null ? [] : ['mode' => 'form'];

        return [...$question, 'message' => $params['message'], 'requestedSchema' => $schema];
    }

    /**
     * @throws ValidationException
     */
    private static function parseInterruptValue(mixed $value): void
    {
        if (!is_array($value) || ($value['type'] ?? null) !== self::INTERRUPT_TYPE) {
            throw ValidationException::of('Invalid input: expected "' . self::INTERRUPT_TYPE . '"', ['type']);
        }
        foreach (['server', 'tool'] as $field) {
            if (!is_string($value[$field] ?? null)) {
                throw ValidationException::of('Invalid input: expected string, received ' . Hooks::describe($value[$field] ?? null), [$field]);
            }
        }
        $requests = $value['requests'] ?? null;
        if (!is_array($requests)) {
            throw ValidationException::of('Invalid input: expected record, received ' . Hooks::describe($requests), ['requests']);
        }
        foreach ($requests as $key => $question) {
            try {
                self::parseQuestion($question);
            } catch (ValidationException $e) {
                throw new ValidationException(array_map(
                    static fn (array $issue): array => ['path' => ['requests', $key, ...$issue['path']], 'message' => $issue['message']],
                    $e->issues,
                ));
            }
        }
    }

    private static function throwIfAborted(mixed $signal): void
    {
        if ($signal !== null && AbortUtils::isAborted($signal)) {
            throw new \RuntimeException('This operation was aborted');
        }
    }
}
