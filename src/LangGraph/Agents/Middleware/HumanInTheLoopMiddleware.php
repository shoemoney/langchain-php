<?php

declare(strict_types=1);

namespace LangGraph\Agents\Middleware;

use LangChain\Messages\AIMessage;
use LangChain\Messages\ToolMessage;
use LangGraph\Agents\Middleware;
use LangGraph\Agents\Runtime;

use function LangGraph\Pregel\interrupt;

/**
 * Human-in-the-loop (HITL) middleware for tool approval and oversight.
 *
 * Port of `humanInTheLoopMiddleware` from `langchain/src/agents/middleware/hitl.ts`.
 *
 * The middleware inspects the tool calls of the last AI message in `afterModel` and pauses the graph with a
 * single `interrupt()` for every call whose tool is configured in `interruptOn`; the rest are auto-approved.
 * Resuming with a `HITLResponse` (one decision per interrupted call, in order) approves, edits or
 * rejects each call. Any rejection sends the run back to the model, with the rejected calls answered by an
 * error tool message. A checkpointer is required.
 *
 * Upstream's TypeScript interfaces are plain objects, which is also what crosses the checkpoint, so the wire
 * shapes stay arrays here:
 *
 *  - `ActionRequest`: `['name' => string, 'args' => array, 'description' => string]`;
 *  - `ReviewConfig`: `['actionName' => string, 'allowedDecisions' => list<'approve'|'edit'|'reject'>, 'argsSchema'? => array]`;
 *  - `HITLRequest` (the interrupt value): `['actionRequests' => list<ActionRequest>, 'reviewConfigs' => list<ReviewConfig>]`;
 *  - `Decision`: `['type' => 'approve']`, `['type' => 'edit', 'editedAction' => ['name' => string, 'args' => array]]`
 *    or `['type' => 'reject', 'message'? => string]`;
 *  - `HITLResponse` (the resume value): `['decisions' => list<Decision>]`.
 *
 * These are not the `LangGraph\Prebuilt\HumanInterrupt` types: those port `prebuilt/interrupt.ts`, an older
 * single-action protocol (`action_request`/`config`/`HumanResponse`) that `hitl.ts` does not use.
 *
 * Options (`interruptOn` and `descriptionPrefix` can also be supplied per run through the run context):
 *
 *  - `interruptOn`: tool name => `true` (all three decisions), `false` (auto-approve) or an array with
 *    `allowedDecisions` (required), `description` (a string, or `fn(array $toolCall, array $state, Runtime $runtime): string`),
 *    `argsSchema` (a JSON Schema for the arguments, passed through to the reviewer) and `when`
 *    (`fn(array $request): bool`, where `$request` is `['toolCall' => ..., 'tool' => null, 'state' => ..., 'runtime' => ...]`;
 *    `false` auto-approves the call);
 *  - `descriptionPrefix`: the prefix of the default approval message.
 *
 * ```
 * $hitl = HumanInTheLoopMiddleware::create([
 *     'interruptOn' => [
 *         'write_file' => ['allowedDecisions' => ['approve', 'edit'], 'description' => 'File write needs approval'],
 *         'read_file' => false,
 *     ],
 * ]);
 * $agent->invoke(new Command(resume: ['decisions' => [['type' => 'approve']]]), $config);
 * ```
 */
final class HumanInTheLoopMiddleware
{
    public const ALLOWED_DECISIONS = ['approve', 'edit', 'reject'];

    private const DEFAULT_DESCRIPTION_PREFIX = 'Tool execution requires approval';

    private function __construct()
    {
    }

    /**
     * @param array<string, mixed> $options `interruptOn` and `descriptionPrefix`
     * @return array<string, mixed> the middleware
     */
    public static function create(array $options = []): array
    {
        return Middleware::create([
            'name' => 'HumanInTheLoopMiddleware',
            // `descriptionPrefix` carries no schema default on purpose: a default would always be present in
            // the parsed run context and so override the prefix given to create().
            'contextSchema' => [
                'type' => 'object',
                'properties' => [
                    'interruptOn' => ['type' => 'object', 'additionalProperties' => true],
                    'descriptionPrefix' => ['type' => 'string'],
                ],
            ],
            'afterModel' => [
                'canJumpTo' => ['model'],
                'hook' => static fn (array $state, Runtime $runtime): ?array => self::afterModel($options, $state, $runtime),
            ],
        ]);
    }

    /**
     * @param array<string, mixed> $options
     * @param array<string, mixed> $state
     * @return array<string, mixed>|null
     */
    private static function afterModel(array $options, array $state, Runtime $runtime): ?array
    {
        $config = [...$options, ...(array) ($runtime->context ?? [])];

        $messages = array_values((array) ($state['messages'] ?? []));
        if ($messages === []) {
            return null;
        }

        // Don't do anything if the last AI message has no tool calls.
        $lastMessage = null;
        foreach (array_reverse($messages) as $message) {
            if ($message instanceof AIMessage) {
                $lastMessage = $message;
                break;
            }
        }
        if ($lastMessage === null || $lastMessage->toolCalls === []) {
            return null;
        }

        // If the user omits the interruptOn config, we don't do anything.
        if (!isset($config['interruptOn']) || !\is_array($config['interruptOn'])) {
            return null;
        }

        // Resolve per-tool configs (true -> all decisions allowed; false -> auto-approve).
        $resolvedConfigs = [];
        foreach ($config['interruptOn'] as $toolName => $toolConfig) {
            if (\is_bool($toolConfig)) {
                if ($toolConfig === true) {
                    $resolvedConfigs[(string) $toolName] = ['allowedDecisions' => self::ALLOWED_DECISIONS];
                }
            } elseif (\is_array($toolConfig) && ($toolConfig['allowedDecisions'] ?? null)) {
                $resolvedConfigs[(string) $toolName] = $toolConfig;
            }
        }

        // A tool call is interrupted only when it has a resolved config and its optional `when` predicate
        // doesn't opt it out. Otherwise it is auto-approved.
        $interruptToolCalls = [];
        $autoApprovedToolCalls = [];
        foreach ($lastMessage->toolCalls as $toolCall) {
            $interruptConfig = $resolvedConfigs[$toolCall['name']] ?? null;
            if ($interruptConfig !== null && self::shouldInterrupt($toolCall, $interruptConfig, $state, $runtime)) {
                $interruptToolCalls[] = $toolCall;
            } else {
                $autoApprovedToolCalls[] = $toolCall;
            }
        }

        if ($interruptToolCalls === []) {
            return null;
        }

        // One request with every action and its review config.
        $actionRequests = [];
        $reviewConfigs = [];
        foreach ($interruptToolCalls as $toolCall) {
            [$actionRequest, $reviewConfig] = self::createActionAndConfig(
                $toolCall,
                $resolvedConfigs[$toolCall['name']],
                $config,
                $state,
                $runtime,
            );
            $actionRequests[] = $actionRequest;
            $reviewConfigs[] = $reviewConfig;
        }

        $hitlResponse = interrupt(['actionRequests' => $actionRequests, 'reviewConfigs' => $reviewConfigs]);
        $decisions = \is_array($hitlResponse) ? ($hitlResponse['decisions'] ?? null) : null;

        if (!\is_array($decisions) || !array_is_list($decisions)) {
            throw new \Exception('Invalid HITLResponse: decisions must be a non-empty array');
        }

        if (\count($decisions) !== \count($interruptToolCalls)) {
            throw new \Exception(sprintf(
                'Number of human decisions (%d) does not match number of hanging tool calls (%d).',
                \count($decisions),
                \count($interruptToolCalls),
            ));
        }

        $revisedToolCalls = $autoApprovedToolCalls;
        $artificialToolMessages = [];
        $hasRejectedToolCalls = false;
        foreach ($decisions as $decision) {
            if (\is_array($decision) && ($decision['type'] ?? null) === 'reject') {
                $hasRejectedToolCalls = true;
                break;
            }
        }

        foreach ($decisions as $i => $decision) {
            $toolCall = $interruptToolCalls[$i];

            [$revisedToolCall, $toolMessage] = self::processDecision($decision, $toolCall, $resolvedConfigs[$toolCall['name']]);

            // If any decision is a rejection we go back to the model with only the rejected tool calls, as the
            // results of the approved/edited ones are not known at this point.
            if ($revisedToolCall !== null && (!$hasRejectedToolCalls || (\is_array($decision) && ($decision['type'] ?? null) === 'reject'))) {
                $revisedToolCalls[] = $revisedToolCall;
            }
            if ($toolMessage !== null) {
                $artificialToolMessages[] = $toolMessage;
            }
        }

        // Update the AI message to only include the approved tool calls.
        $updatedMessage = new AIMessage([
            'content' => $lastMessage->content,
            'tool_calls' => $revisedToolCalls,
            'invalid_tool_calls' => $lastMessage->invalidToolCalls,
            'additional_kwargs' => $lastMessage->additional_kwargs,
            'response_metadata' => $lastMessage->response_metadata,
            'id' => $lastMessage->id,
            'name' => $lastMessage->name,
        ]);

        return [
            'messages' => [$updatedMessage, ...$artificialToolMessages],
            'jumpTo' => $hasRejectedToolCalls ? 'model' : null,
        ];
    }

    /**
     * Whether the `when` predicate lets this tool call interrupt: always, when none is configured.
     *
     * @param array<string, mixed> $toolCall
     * @param array<string, mixed> $config
     * @param array<string, mixed> $state
     */
    private static function shouldInterrupt(array $toolCall, array $config, array $state, Runtime $runtime): bool
    {
        $when = $config['when'] ?? null;
        if ($when === null) {
            return true;
        }

        return (bool) $when(['toolCall' => $toolCall, 'tool' => null, 'state' => $state, 'runtime' => $runtime]);
    }

    /**
     * @param array<string, mixed> $toolCall
     * @param array<string, mixed> $interruptConfig
     * @param array<string, mixed> $options
     * @param array<string, mixed> $state
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} the ActionRequest and the ReviewConfig
     */
    private static function createActionAndConfig(array $toolCall, array $interruptConfig, array $options, array $state, Runtime $runtime): array
    {
        $toolName = $toolCall['name'];
        $toolArgs = $toolCall['args'];

        // The description is a string or a callable.
        $descriptionValue = $interruptConfig['description'] ?? null;
        if ($descriptionValue instanceof \Closure || (\is_array($descriptionValue) && \is_callable($descriptionValue))) {
            $description = $descriptionValue($toolCall, $state, $runtime);
        } elseif ($descriptionValue !== null) {
            $description = $descriptionValue;
        } else {
            $prefix = $options['descriptionPrefix'] ?? self::DEFAULT_DESCRIPTION_PREFIX;
            $description = "{$prefix}\n\nTool: {$toolName}\nArgs: " . self::prettyJson($toolArgs);
        }

        $reviewConfig = [
            'actionName' => $toolName,
            'allowedDecisions' => $interruptConfig['allowedDecisions'],
        ];
        if (!empty($interruptConfig['argsSchema'])) {
            $reviewConfig['argsSchema'] = $interruptConfig['argsSchema'];
        }

        return [['name' => $toolName, 'args' => $toolArgs, 'description' => $description], $reviewConfig];
    }

    /**
     * @param array<string, mixed> $toolCall
     * @param array<string, mixed> $config
     * @return array{0: array<string, mixed>|null, 1: ToolMessage|null} the revised tool call and the artificial tool message
     */
    private static function processDecision(mixed $decision, array $toolCall, array $config): array
    {
        $allowedDecisions = $config['allowedDecisions'];
        $type = \is_array($decision) ? ($decision['type'] ?? null) : null;

        if ($type === 'approve' && \in_array('approve', $allowedDecisions, true)) {
            return [$toolCall, null];
        }

        if ($type === 'edit' && \in_array('edit', $allowedDecisions, true)) {
            $editedAction = $decision['editedAction'] ?? null;

            // Validate the edited action structure.
            if (!\is_array($editedAction) || !\is_string($editedAction['name'] ?? null)) {
                throw new \Exception("Invalid edited action for tool \"{$toolCall['name']}\": name must be a string");
            }
            if (!\is_array($editedAction['args'] ?? null)) {
                throw new \Exception("Invalid edited action for tool \"{$toolCall['name']}\": args must be an object");
            }

            return [
                ['type' => 'tool_call', 'name' => $editedAction['name'], 'args' => $editedAction['args'], 'id' => $toolCall['id'] ?? null],
                null,
            ];
        }

        if ($type === 'reject' && \in_array('reject', $allowedDecisions, true)) {
            $message = $decision['message'] ?? null;
            if ($message !== null && !\is_string($message)) {
                throw new \Exception("Tool call response for \"{$toolCall['name']}\" must be a string, got " . Utils::typeOf($message));
            }

            // The human's text goes to the model as the tool's (error) result.
            $content = $message ?? "User rejected the tool call for `{$toolCall['name']}` with id {$toolCall['id']}";

            return [
                $toolCall,
                new ToolMessage([
                    'content' => $content,
                    'name' => $toolCall['name'],
                    'tool_call_id' => (string) ($toolCall['id'] ?? ''),
                    'additional_kwargs' => ['status' => 'error'],
                ]),
            ];
        }

        throw new \Exception(sprintf(
            "Unexpected human decision: %s. Decision type '%s' is not allowed for tool '%s'. Expected one of %s based on the tool's configuration.",
            self::compactJson($decision),
            \is_string($type) ? $type : 'undefined',
            $toolCall['name'],
            self::compactJson($allowedDecisions),
        ));
    }

    /** `JSON.stringify(value)`. */
    private static function compactJson(mixed $value): string
    {
        return (string) json_encode($value, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
    }

    /** `JSON.stringify(value, null, 2)`: two-space indent, `{}` for empty arguments. */
    private static function prettyJson(mixed $value): string
    {
        if ($value === []) {
            return '{}';
        }

        $json = (string) json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

        return (string) preg_replace_callback(
            '/^ +/m',
            static fn (array $m): string => str_repeat(' ', intdiv(\strlen($m[0]), 2)),
            $json,
        );
    }
}
